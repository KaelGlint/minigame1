<?php
// db.php
class GameModel {
    private $db;
    private $db_file;
    private $use_apcu = false;

    public function __construct() {
        // 数据库文件路径
        $this->db_file = __DIR__ . '/game_data.db'; 
        $this->use_apcu = extension_loaded('apcu') && ini_get('apc.enabled');
        
        try {
            $this->db = new PDO("sqlite:" . $this->db_file);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // 开启 WAL 模式提升并发性能
            $this->db->exec('PRAGMA journal_mode = WAL;');
            $this->initTable();
        } catch (PDOException $e) {
            die("DB Connection failed: " . $e->getMessage());
        }
    }

    private function initTable() {
        // 创建游戏表
        $sql = "CREATE TABLE IF NOT EXISTS games (
            table_id INTEGER PRIMARY KEY, -- 不自增，固定桌号 1, 2, 3
            p1_name TEXT, p2_name TEXT,
            game_status INTEGER DEFAULT 0, turn INTEGER DEFAULT 1, phase INTEGER DEFAULT 0, event_id INTEGER DEFAULT 0, deadline_ts INTEGER DEFAULT 0,
            p1_status INTEGER DEFAULT 0, p2_status INTEGER DEFAULT 0,
            p1_hp INTEGER DEFAULT 100, p1_shield INTEGER DEFAULT 0, p1_gold INTEGER DEFAULT 0,
            p1_slot_cards TEXT DEFAULT '[]', p1_hand_cards TEXT DEFAULT '[]', p1_skill_cards TEXT DEFAULT '[]', p1_buff TEXT DEFAULT '[]',
            p2_hp INTEGER DEFAULT 100, p2_shield INTEGER DEFAULT 0, p2_gold INTEGER DEFAULT 0,
            p2_slot_cards TEXT DEFAULT '[]', p2_hand_cards TEXT DEFAULT '[]', p2_skill_cards TEXT DEFAULT '[]', p2_buff TEXT DEFAULT '[]',
            skill_queue TEXT DEFAULT '[]', battle_log TEXT DEFAULT '[]',
            updated_at INTEGER
        )";
        $this->db->exec($sql);

        // 初始化 4 张桌子 (如果不存在)，其中桌号 4 为 AI 桌
        for ($i = 1; $i <= 4; $i++) {
            $stmt = $this->db->prepare("INSERT OR IGNORE INTO games (table_id, updated_at) VALUES (?, ?)");
            $stmt->execute([$i, time()]);
        }
    }

    public function getGame($tableId) {
        $key = "game_" . $tableId;
        if ($this->use_apcu && apcu_exists($key)) {
            return apcu_fetch($key);
        }

        $stmt = $this->db->prepare("SELECT * FROM games WHERE table_id = ?");
        $stmt->execute([$tableId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$game) return null;

        // 自动解码 JSON 字段
        $jsonFields = [
            'p1_slot_cards', 'p1_hand_cards', 'p1_skill_cards', 'p1_buff',
            'p2_slot_cards', 'p2_hand_cards', 'p2_skill_cards', 'p2_buff',
            'skill_queue', 'battle_log'
        ];
        foreach ($jsonFields as $field) {
            if (isset($game[$field]) && is_string($game[$field])) {
                $game[$field] = json_decode($game[$field], true) ?: [];
            }
        }

        if ($game && $this->use_apcu) {
            apcu_store($key, $game);
        }
        return $game;
    }

    public function saveGame($tableId, $data) {
        // 为了保持 APCu 数据完整，先获取当前状态进行合并
        // 注意：这里如果高并发可能会有竞态条件，但作为小游戏可接受
        $currentGame = $this->use_apcu ? $this->getGame($tableId) : [];
        
        $fields = [];
        $values = [];
        $ts = time();

        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = is_array($value) ? json_encode($value) : $value;
            
            // 更新内存中的状态用于 APCu
            if ($currentGame) {
                $currentGame[$key] = $value;
            }
        }
        $fields[] = "updated_at = ?";
        $values[] = $ts;
        $values[] = $tableId;

        $sql = "UPDATE games SET " . implode(", ", $fields) . " WHERE table_id = ?";
        $stmt = $this->db->prepare($sql);
        $res = $stmt->execute($values);

        if ($res && $this->use_apcu && $currentGame) {
            $currentGame['updated_at'] = $ts;
            apcu_store("game_" . $tableId, $currentGame);
        }
        return $res;
    }

    // 获取大厅列表状态
    public function getLobbyStatus() {
        $stmt = $this->db->query("SELECT table_id, p1_name, p2_name, game_status FROM games ORDER BY table_id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 尝试加入座位
    public function joinSeat($tableId, $seat, $playerName) {
        // AI 桌 (Table 4) 特殊逻辑
        if ($tableId == 4) {
            if ($seat != 1) return false; // AI桌只能坐 P1
            
            // 玩家坐 P1，AI 自动坐 P2
            $sql = "UPDATE games SET p1_name = ?, p2_name = 'AI-Bot' WHERE table_id = ? AND (p1_name IS NULL OR p1_name = '')";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$playerName, $tableId]);
            $success = $stmt->rowCount() > 0;
            if ($success && $this->use_apcu) {
                apcu_delete("game_" . $tableId); // 清除缓存，强制下次读库
            }
            if ($success) $this->checkStartGame($tableId);
            return $success;
        }

        $col = ($seat == 1) ? 'p1_name' : 'p2_name';
        // 只有当该座位为空时才写入
        $sql = "UPDATE games SET $col = ? WHERE table_id = ? AND ($col IS NULL OR $col = '')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$playerName, $tableId]);
        $success = $stmt->rowCount() > 0;
        if ($success && $this->use_apcu) {
            apcu_delete("game_" . $tableId);
        }
        if ($success) $this->checkStartGame($tableId);
        return $success;
    }

    // 检查是否满足开局条件
    private function checkStartGame($tableId) {
        $game = $this->getGame($tableId);
        // 如果双方都在，且游戏未开始
        if (!empty($game['p1_name']) && !empty($game['p2_name']) && $game['game_status'] == 0) {
            $this->saveGame($tableId, [
                'game_status' => 1,      // 进行中
                'phase' => 0,            // 阶段 0: 随机事件
                'turn' => 1,
                'deadline_ts' => time() + 3 // 初始进入 Turn Start Popup (3s)
            ]);
        }
    }

    // 处理 AI 逻辑
    public function processAI($tableId) {
        // 简单检查：只处理桌号4且对手是 AI-Bot 的情况
        if ($tableId != 4) return;
        
        $game = $this->getGame($tableId);
        if (!$game || $game['p2_name'] !== 'AI-Bot') return;

        // AI 只在自己的回合/状态未准备时行动
        if ($game['p2_status'] == 1) return;

        $updated = false;

        // 阶段 1: 抽卡 (Drafting)
        // 简单模拟：AI 假装选好了卡 (实际卡牌生成逻辑在抽卡接口中，这里假设 AI 已经拿到牌或跳过选择)
        if ($game['phase'] == 1 || $game['phase'] == 2) {
            // 模拟 AI 思考时间
            // 在这里我们直接标记 AI 为 Ready，表示它完成了选卡
            $game['p2_status'] = 1;
            $updated = true;
        }

        // 阶段 2: 部署 (Deployment)
        if ($game['phase'] == 3) {
            // 简单的 AI 策略：有空位就放，有牌就放
            $hand = $game['p2_hand_cards'];
            $slots = $game['p2_slot_cards'];
            // 确保 slots 长度为 6
            if (count($slots) < 6) $slots = array_pad($slots, 6, null);

            $newHand = [];
            foreach ($hand as $cardId) {
                // 找第一个空位
                $placed = false;
                for ($i = 0; $i < 6; $i++) {
                    if (empty($slots[$i])) {
                        $slots[$i] = $cardId; // 放置卡牌
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) $newHand[] = $cardId; // 没地方放了，留手里
            }

            $game['p2_slot_cards'] = $slots;
            $game['p2_hand_cards'] = $newHand;
            $game['p2_status'] = 1; // 部署完成
            $updated = true;
        }

        if ($updated) {
            $this->saveGame($tableId, [
                'p2_status' => $game['p2_status'],
                'p2_slot_cards' => $game['p2_slot_cards'],
                'p2_hand_cards' => $game['p2_hand_cards']
            ]);
        }
    }

    // 更新游戏流程 (心跳逻辑)
    public function updateGameFlow($tableId) {
        $game = $this->getGame($tableId);
        
        // 自愈机制：如果双方都在座，但游戏状态仍为 0，强制开始游戏
        if ($game['game_status'] == 0 && !empty($game['p1_name']) && !empty($game['p2_name'])) {
            $this->checkStartGame($tableId);
            $game = $this->getGame($tableId); // 刷新数据
        }

        if ($game['game_status'] != 1) return;

        $now = time();
        // 检查当前阶段是否超时
        if ($now > $game['deadline_ts']) {
            $nextPhase = $game['phase'];
            $nextDeadline = $now;
            $resetStatus = false;
            $nextTurn = $game['turn'];

            switch ($game['phase']) {
                case 0: // Event (5s) -> Draft P1
                    $nextPhase = 1;
                    // 10s 操作 + 3s 弹窗 = 13s
                    $nextDeadline = $now + 13; 
                    $resetStatus = true;       // 进入新操作阶段，重置玩家准备状态
                    break;
                case 1: // Draft P1 (13s) -> Draft P2
                    $nextPhase = 2;
                    // 15s 操作 + 3s 弹窗 = 18s
                    $nextDeadline = $now + 18; 
                    $resetStatus = true;
                    break;
                case 2: // Draft P2 (18s) -> Deployment
                    $nextPhase = 3;
                    // 30s 操作 + 3s 弹窗 = 33s
                    $nextDeadline = $now + 33; 
                    $resetStatus = true;
                    break;
                case 3: // Deployment (33s) -> Resolution
                    $nextPhase = 4;
                    $nextDeadline = $now; // 0s (立即结算)
                    break;
                case 4: // Resolution (0s) -> Next Turn Event
                    $nextPhase = 0;
                    $nextDeadline = $now + 5;
                    $nextTurn++; // 回合数 +1
                    break;
            }

            $updateData = [
                'phase' => $nextPhase,
                'deadline_ts' => $nextDeadline,
                'turn' => $nextTurn
            ];

            if ($resetStatus) {
                $updateData['p1_status'] = 0;
                $updateData['p2_status'] = 0;
            }

            $this->saveGame($tableId, $updateData);
        }
    }

    // Debug: 重置所有游戏
    public function resetAllGames() {
        $sql = "UPDATE games SET 
            p1_name = NULL, p2_name = NULL,
            game_status = 0, turn = 1, phase = 0, event_id = 0, deadline_ts = 0,
            p1_status = 0, p2_status = 0,
            p1_hp = 100, p1_shield = 0, p1_gold = 0,
            p1_slot_cards = '[]', p1_hand_cards = '[]', p1_skill_cards = '[]', p1_buff = '[]',
            p2_hp = 100, p2_shield = 0, p2_gold = 0,
            p2_slot_cards = '[]', p2_hand_cards = '[]', p2_skill_cards = '[]', p2_buff = '[]',
            skill_queue = '[]', battle_log = '[]',
            updated_at = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([time()]);

        if ($this->use_apcu) {
            apcu_clear_cache(); // 简单粗暴清除所有缓存
        }
    }

    // 退出/重置指定桌子
    public function resetGame($tableId) {
        $sql = "UPDATE games SET 
            p1_name = NULL, p2_name = NULL,
            game_status = 0, turn = 1, phase = 0, event_id = 0, deadline_ts = 0,
            p1_status = 0, p2_status = 0,
            p1_hp = 100, p1_shield = 0, p1_gold = 0,
            p1_slot_cards = '[]', p1_hand_cards = '[]', p1_skill_cards = '[]', p1_buff = '[]',
            p2_hp = 100, p2_shield = 0, p2_gold = 0,
            p2_slot_cards = '[]', p2_hand_cards = '[]', p2_skill_cards = '[]', p2_buff = '[]',
            skill_queue = '[]', battle_log = '[]',
            updated_at = ?
            WHERE table_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([time(), $tableId]);

        if ($this->use_apcu) {
            apcu_delete("game_" . $tableId);
        }
    }
}
?>
