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
            card_pool TEXT DEFAULT '[]',
            draft_cards TEXT DEFAULT '[]',
            draft_picks TEXT DEFAULT '{\"p1\":[], \"p2\":[]}',
            skill_queue TEXT DEFAULT '[]', battle_log TEXT DEFAULT '[]',
            updated_at INTEGER
        )";
        $this->db->exec($sql);

        // 自动迁移：尝试添加新字段 (防止旧数据库缺少字段导致报错)
        $migrations = [
            'card_pool' => "TEXT DEFAULT '[]'",
            'draft_cards' => "TEXT DEFAULT '[]'",
            'draft_picks' => "TEXT DEFAULT '{\"p1\":[], \"p2\":[]}'"
        ];
        foreach ($migrations as $col => $def) {
            try {
                $this->db->exec("ALTER TABLE games ADD COLUMN $col $def");
            } catch (PDOException $e) {
                // 字段已存在，忽略错误
            }
        }

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
            'skill_queue', 'battle_log',
            'card_pool', 'draft_cards', 'draft_picks'
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
            $eventId = $this->getRandomEventId();
            
            // 初始放置指挥部 (ID 999) 到 Slot 5
            $p1Slots = array_fill(0, 6, null); $p1Slots[5] = 999;
            $p2Slots = array_fill(0, 6, null); $p2Slots[5] = 999;

            $this->saveGame($tableId, [
                'game_status' => 1,      // 进行中
                'phase' => 0,            // 阶段 0: 随机事件
                'turn' => 1,
                'event_id' => $eventId,
                'deadline_ts' => time() + 5, // 统一为 5秒 (含弹窗)
                'p1_slot_cards' => $p1Slots,
                'p2_slot_cards' => $p2Slots,
                'card_pool' => [] // 初始为空，会在第一回合自动填充
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
            // 判断是否轮到 AI (P2) 行动
            $isP1First = ($game['turn'] % 2 != 0);
            $activeSeat = ($game['phase'] == 1) ? ($isP1First ? 1 : 2) : ($isP1First ? 2 : 1);
            
            if ($activeSeat == 2) {
                // 模拟 AI 思考时间，标记 AI 为 Ready
                $game['p2_status'] = 1;
                $updated = true;
            }
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
        
        // 计算当前回合的先手逻辑
        $isP1First = ($game['turn'] % 2 != 0);
        $firstSeat = $isP1First ? 1 : 2;
        $secondSeat = $isP1First ? 2 : 1;
        $firstStatus = $isP1First ? 'p1_status' : 'p2_status';
        $secondStatus = $isP1First ? 'p2_status' : 'p1_status';

        // 检查是否满足提前结束阶段的条件 (玩家已操作完毕)
        $forceNext = false;
        if ($game['phase'] == 1 && $game[$firstStatus] == 1) $forceNext = true;
        if ($game['phase'] == 2 && $game[$secondStatus] == 1) $forceNext = true;

        // 检查当前阶段是否超时 或 强制跳转
        if ($now > $game['deadline_ts'] || $forceNext) {
            $nextPhase = $game['phase'];
            $nextDeadline = $now;
            $resetStatus = false;
            $nextTurn = $game['turn'];

            switch ($game['phase']) {
                case 0: // Event (5s) -> Draft P1
                    $nextPhase = 1;
                    $nextDeadline = $now + 13; // 10s 操作 + 3s 弹窗
                    $resetStatus = true;       // 进入新操作阶段，重置玩家准备状态
                    
                    // 抽取本回合的 4 张卡
                    $this->drawDraftCards($tableId, $game);
                    break;

                case 1: // Draft P1 (13s) -> Draft P2
                    $nextPhase = 2;
                    $this->autoPickDraft($tableId, $firstSeat, $game); // 处理先手超时
                    // 15s 操作 + 3s 弹窗 = 18s
                    $nextDeadline = $now + 18; 
                    $resetStatus = true;
                    break;
                case 2: // Draft P2 (18s) -> Deployment
                    $nextPhase = 3;
                    // 30s 操作 + 3s 弹窗 = 33s
                    $nextDeadline = $now + 33; 
                    $this->autoPickDraft($tableId, $secondSeat, $game); // 处理后手超时
                    $this->distributeDraftCards($tableId);    // 分发卡牌
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
                    // 新回合生成新事件
                    $updateData['event_id'] = $this->getRandomEventId();
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

    // --- 卡池与抽卡逻辑 ---

    // 生成/重装填卡池
    private function initCardPool($tableId, $currentGame) {
        $path = __DIR__ . '/assets/data/cards.json';
        if (!file_exists($path)) return [];

        $cardsJson = file_get_contents($path);
        $allCards = json_decode($cardsJson, true);
        
        if (!is_array($allCards)) return [];
        
        $heroes = [];
        $buildings = [];
        $resources = [];
        $npcs = [];

        foreach ($allCards as $c) {
            if ($c['id'] == 999) continue; // 跳过指挥部
            if ($c['type'] == 'hero') $heroes[] = $c['id'];
            elseif ($c['type'] == 'building') $buildings[] = $c['id'];
            elseif ($c['type'] == 'resource') $resources[] = $c['id'];
            elseif ($c['type'] == 'npc') $npcs[] = $c['id'];
        }

        // 1. 统计场上和手牌中的英雄
        $activeHeroes = [];
        $locations = ['p1_slot_cards', 'p1_hand_cards', 'p2_slot_cards', 'p2_hand_cards'];
        foreach ($locations as $loc) {
            if (!empty($currentGame[$loc])) {
                foreach ($currentGame[$loc] as $cid) {
                    if (in_array($cid, $heroes)) $activeHeroes[] = $cid;
                }
            }
        }

        // 2. 构建新池子
        $pool = [];
        
        // 英雄：每种1张，排除已存在的
        foreach ($heroes as $hid) {
            if (!in_array($hid, $activeHeroes)) $pool[] = $hid;
        }
        // 建筑：每种3张
        foreach ($buildings as $bid) {
            for($i=0; $i<3; $i++) $pool[] = $bid;
        }
        // 资源：每种1张
        foreach ($resources as $rid) {
            $pool[] = $rid;
        }

        // 3. 补足或修剪到 60 张
        $currentCount = count($pool);
        $target = 60;

        if ($currentCount < $target && !empty($npcs)) {
            // 补充 NPC
            while (count($pool) < $target) {
                $pool[] = $npcs[array_rand($npcs)];
            }
        } elseif ($currentCount > $target) {
            // 理论上按规则不会超过太多，但如果超过，随机移除 NPC (如果池子里有 NPC 的话，但按构建逻辑此时池子里还没 NPC)
            // 按照用户规则：如果总数超过60张，则随机移除若干张路人卡。
            // 但此时池子里只有英雄建筑资源。如果这些加起来就超过60，那也没法移除路人卡。
            // 这里我们假设基础卡牌不会超过60。如果真超过了，就保持原样。
        }

        shuffle($pool);
        return $pool;
    }

    // 回合开始：抽取4张卡
    private function drawDraftCards($tableId, $game) {
        $pool = $game['card_pool'];
        
        // 如果卡池不足 4 张，重装填
        if (count($pool) < 4) {
            $pool = $this->initCardPool($tableId, $game);
        }

        $draftCards = array_splice($pool, 0, 4);
        
        $this->saveGame($tableId, [
            'card_pool' => $pool,
            'draft_cards' => $draftCards,
            'draft_picks' => ['p1' => [], 'p2' => []] // 重置选择
        ]);
    }

    // 处理玩家选卡 API
    public function processDraftPick($tableId, $seat, $cardIndex) {
        $game = $this->getGame($tableId);
        
        // 增加阶段检查，防止非抽卡阶段操作
        if ($game['phase'] != 1 && $game['phase'] != 2) {
            return false;
        }

        $picks = $game['draft_picks'];
        $playerKey = ($seat == 1) ? 'p1' : 'p2';
        
        $isP1First = ($game['turn'] % 2 != 0);
        $activeSeat = ($game['phase'] == 1) ? ($isP1First ? 1 : 2) : ($isP1First ? 2 : 1);

        // 验证行动权
        if ($seat != $activeSeat) {
            return false;
        }

        // 验证是否已选满
        // 修正：根据阶段决定上限 (Phase 1: 先手选1张, Phase 2: 后手选2张)
        $limit = ($game['phase'] == 1) ? 1 : 2;
        
        if (count($picks[$playerKey]) >= $limit) return false;

        // 验证卡牌是否已被选
        $allPicked = array_merge($picks['p1'], $picks['p2']);
        if (in_array($cardIndex, $allPicked)) return false;

        // 记录选择
        $picks[$playerKey][] = $cardIndex;
        
        // 更新状态
        $updateData = ['draft_picks' => $picks];
        
        // 如果选满了，标记玩家状态为 Ready (用于前端或AI判断)
        if (count($picks[$playerKey]) >= $limit) {
            $statusField = ($seat == 1) ? 'p1_status' : 'p2_status';
            $updateData[$statusField] = 1;
        }

        return $this->saveGame($tableId, $updateData);
    }

    // 超时自动选卡
    private function autoPickDraft($tableId, $seat, $game) {
        $picks = $game['draft_picks'];
        $playerKey = ($seat == 1) ? 'p1' : 'p2';
        
        // 修正：根据先后手决定上限
        $isP1First = ($game['turn'] % 2 != 0);
        $firstSeat = $isP1First ? 1 : 2;
        $limit = ($seat == $firstSeat) ? 1 : 2;
        
        $allPicked = array_merge($picks['p1'], $picks['p2']);
        $available = array_diff([0, 1, 2, 3], $allPicked);
        
        while (count($picks[$playerKey]) < $limit && count($available) > 0) {
            $randKey = array_rand($available);
            $pick = $available[$randKey];
            $picks[$playerKey][] = $pick;
            unset($available[$randKey]);
        }
        
        $this->saveGame($tableId, ['draft_picks' => $picks]);
    }

    // 抽卡阶段结束：分发卡牌
    private function distributeDraftCards($tableId) {
        $game = $this->getGame($tableId);
        $draftCards = $game['draft_cards'];
        $picks = $game['draft_picks'];

        // 剩下的那张给先手玩家
        $isP1First = ($game['turn'] % 2 != 0);
        $firstPlayerKey = $isP1First ? 'p1' : 'p2';

        $allPicked = array_merge($picks['p1'], $picks['p2']);
        $leftover = array_diff([0, 1, 2, 3], $allPicked);
        
        foreach ($leftover as $idx) {
            $picks[$firstPlayerKey][] = $idx;
        }

        // 分发到手牌
        $p1Hand = $game['p1_hand_cards'];
        $p2Hand = $game['p2_hand_cards'];

        foreach ($picks['p1'] as $idx) $p1Hand[] = $draftCards[$idx];
        foreach ($picks['p2'] as $idx) $p2Hand[] = $draftCards[$idx];

        $this->saveGame($tableId, [
            'p1_hand_cards' => $p1Hand,
            'p2_hand_cards' => $p2Hand,
            'draft_cards' => [], // 清空
            'draft_picks' => ['p1' => [], 'p2' => []]
        ]);
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
            card_pool = '[]',
            draft_cards = '[]',
            draft_picks = '{\"p1\":[], \"p2\":[]}',
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
            card_pool = '[]',
            draft_cards = '[]',
            draft_picks = '{\"p1\":[], \"p2\":[]}',
            updated_at = ?
            WHERE table_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([time(), $tableId]);

        if ($this->use_apcu) {
            apcu_delete("game_" . $tableId);
        }
    }

    // 随机获取一个事件ID
    private function getRandomEventId() {
        // 为了极致性能，这里不每次都读文件，而是硬编码范围
        // 如果事件列表变动频繁，可以改为读取 events.json
        // $events = json_decode(file_get_contents(__DIR__ . '/assets/data/events.json'), true);
        // return $events[array_rand($events)]['id'];
        
        // 目前有 4 个事件
        return rand(1, 4);
    }
}
?>
