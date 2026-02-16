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
            $p1Slots = array_fill(0, 6, null); $p1Slots[5] = ['id' => 999, 'cd' => 1];
            $p2Slots = array_fill(0, 6, null); $p2Slots[5] = ['id' => 999, 'cd' => 1];

            $this->saveGame($tableId, [
                'game_status' => 1,      // 进行中
                'phase' => 0,            // 阶段 0: 随机事件
                'turn' => 1,
                'event_id' => $eventId,
                'deadline_ts' => time() + 5, // 统一为 5秒 (含弹窗)
                'p1_gold' => 10, // 初始金币 10
                'p2_gold' => 10,
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
            $gold = $game['p2_gold'];
            
            // 确保 slots 长度为 6
            if (count($slots) < 6) $slots = array_pad($slots, 6, null);

            $newHand = [];
            foreach ($hand as $cardId) {
                // 获取卡牌费用 (简单起见，这里假设 AI 知道费用，或者我们加载 cards.json)
                // 这里为了简化 AI 逻辑，暂时让 AI 无限金币或跳过费用检查，或者简单假设都能买得起
                // 正规做法是调用 getCardCost($cardId)
                $cost = $this->getCardCost($cardId);
                
                // 找第一个空位
                $placed = false;
                if ($gold >= $cost) {
                    for ($i = 0; $i < 6; $i++) {
                        if (empty($slots[$i])) {
                            $slots[$i] = ['id' => $cardId, 'cd' => 1]; // 放置卡牌
                            $gold -= $cost;
                            $placed = true;
                            break;
                        }
                    }
                }
                if (!$placed) $newHand[] = $cardId; // 没地方放了，留手里
            }

            $game['p2_slot_cards'] = $slots;
            $game['p2_hand_cards'] = $newHand;
            $game['p2_gold'] = $gold;
            $game['p2_status'] = 1; // 部署完成
            $updated = true;
        }

        if ($updated) {
            $this->saveGame($tableId, [
                'p2_status' => $game['p2_status'],
                'p2_slot_cards' => $game['p2_slot_cards'],
                'p2_hand_cards' => $game['p2_hand_cards'],
                'p2_gold' => $game['p2_gold']
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
        if ($game['phase'] == 3 && $game['p1_status'] == 1 && $game['p2_status'] == 1) $forceNext = true;

        // 检查当前阶段是否超时 或 强制跳转
        if ($now > $game['deadline_ts'] || $forceNext) {
            $nextPhase = $game['phase'];
            $nextDeadline = $now;
            $resetStatus = false;
            $nextTurn = $game['turn'];
            $updateData = [];

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
                    $resetStatus = true;  // 重置状态用于记录动画播放完成情况
                    
                    // 部署结束：处理手牌上限 (弃牌换金币)
                    $this->handleHandLimit($game, $updateData);
                    
                    // 执行战斗结算
                    $this->resolveBattle($tableId, $game, $updateData);
                    break;
                case 4: // Resolution (0s) -> Next Turn Event
                    $nextPhase = 0;
                    $nextDeadline = $now + 5;
                    $nextTurn++; // 回合数 +1
                    // 新回合生成新事件
                    $updateData['event_id'] = $this->getRandomEventId();
                    
                    // 回合开始：增加金币
                    $updateData['p1_gold'] = ($game['p1_gold'] ?? 0) + 2;
                    $updateData['p2_gold'] = ($game['p2_gold'] ?? 0) + 2;

                    // 回合开始：增加所有在场卡牌的 CD
                    $this->incrementCD($game, $updateData);
                    break;
            }

            $updateData['phase'] = $nextPhase;
            $updateData['deadline_ts'] = $nextDeadline;
            $updateData['turn'] = $nextTurn;

            if ($resetStatus) {
                // 如果不是 Phase 4 -> 0 的转换（因为 Phase 4 需要用 status 确认动画），则重置
                // 但这里 Phase 4 -> 0 已经是下一回合了，所以也要重置
                $updateData['p1_status'] = 0; 
                $updateData['p2_status'] = 0;
            }

            $this->saveGame($tableId, $updateData);
        }
    }

    // --- 部署与战斗辅助逻辑 ---

    // 玩家部署卡牌
    public function deployCard($tableId, $seat, $handIndex, $slotIndex) {
        $game = $this->getGame($tableId);
        
        // 1. 验证阶段
        if ($game['phase'] != 3) return false;

        $prefix = ($seat == 1) ? 'p1' : 'p2';
        $hand = $game[$prefix . '_hand_cards'];
        $slots = $game[$prefix . '_slot_cards'];
        $gold = $game[$prefix . '_gold'];

        // 2. 验证索引有效性
        if (!isset($hand[$handIndex])) return false;
        // if (!empty($slots[$slotIndex])) return false; // 允许覆盖，移除此检查

        $cardId = $hand[$handIndex];
        $cost = $this->getCardCost($cardId);

        // 3. 验证金币
        if ($gold < $cost) return false;

        // 4. 执行部署
        // 扣除金币
        $gold -= $cost;
        // 移除手牌 (使用 array_splice 保持索引连续，或者 unset 但需要前端配合，这里用 splice)
        array_splice($hand, $handIndex, 1);
        // 放入槽位 (初始化 CD 为 1)
        $slots[$slotIndex] = ['id' => $cardId, 'cd' => 1];

        $updateData = [
            $prefix . '_hand_cards' => $hand,
            $prefix . '_slot_cards' => $slots,
            $prefix . '_gold' => $gold
        ];

        return $this->saveGame($tableId, $updateData);
    }

    // 玩家标记部署完成
    public function setDeploymentReady($tableId, $seat) {
        $game = $this->getGame($tableId);
        if ($game['phase'] != 3) return false;
        
        $field = ($seat == 1) ? 'p1_status' : 'p2_status';
        return $this->saveGame($tableId, [$field => 1]);
    }

    // 玩家主动弃牌 (回收)
    public function discardCard($tableId, $seat, $handIndex) {
        $game = $this->getGame($tableId);
        
        // 1. 验证阶段 (仅部署阶段允许)
        if ($game['phase'] != 3) return false;

        $prefix = ($seat == 1) ? 'p1' : 'p2';
        $hand = $game[$prefix . '_hand_cards'];
        $gold = $game[$prefix . '_gold'];

        // 2. 验证索引
        if (!isset($hand[$handIndex])) return false;

        // 3. 执行弃牌
        // 移除手牌
        array_splice($hand, $handIndex, 1);
        // 增加 1 金币
        $gold += 1;

        $updateData = [
            $prefix . '_hand_cards' => $hand,
            $prefix . '_gold' => $gold
        ];

        return $this->saveGame($tableId, $updateData);
    }

    // 玩家完成动画播放
    public function finishAnimation($tableId, $seat) {
        $game = $this->getGame($tableId);
        if ($game['phase'] != 4) return false;
        
        $field = ($seat == 1) ? 'p1_status' : 'p2_status';
        return $this->saveGame($tableId, [$field => 1]);
    }

    // 辅助：获取卡牌费用
    private function getCardCost($cardId) {
        // 简单缓存机制，避免频繁读文件
        static $cardsMap = null;
        if ($cardsMap === null) {
            $path = __DIR__ . '/assets/data/cards.json';
            if (file_exists($path)) {
                $cards = json_decode(file_get_contents($path), true);
                foreach ($cards as $c) {
                    $cardsMap[$c['id']] = $c['cost'];
                }
            }
        }
        return $cardsMap[$cardId] ?? 0;
    }

    // 辅助：处理手牌上限 (弃牌换金币)
    private function handleHandLimit($game, &$updateData) {
        foreach (['p1', 'p2'] as $p) {
            $hand = $game[$p . '_hand_cards'];
            $gold = $updateData[$p . '_gold'] ?? $game[$p . '_gold']; // 优先取 updateData 中的值
            
            if (count($hand) > 5) {
                $excessCount = count($hand) - 5;
                // 保留前5张
                $newHand = array_slice($hand, 0, 5);
                // 每弃一张 +1 金币
                $gold += $excessCount;
                
                $updateData[$p . '_hand_cards'] = $newHand;
                $updateData[$p . '_gold'] = $gold;
            }
        }
    }

    // 辅助：增加场上卡牌 CD
    private function incrementCD($game, &$updateData) {
        // 加载技能数据以获取 CD 上限
        static $skillsMap = null;
        if ($skillsMap === null) {
            $path = __DIR__ . '/assets/data/skills.json';
            if (file_exists($path)) {
                $skills = json_decode(file_get_contents($path), true);
                foreach ($skills as $s) {
                    $skillsMap[$s['id']] = $s;
                }
            }
        }
        
        // 加载卡牌数据以获取卡牌对应的 Skill ID
        static $cardsSkillMap = null;
        if ($cardsSkillMap === null) {
            $path = __DIR__ . '/assets/data/cards.json';
            if (file_exists($path)) {
                $cards = json_decode(file_get_contents($path), true);
                foreach ($cards as $c) {
                    $cardsSkillMap[$c['id']] = $c['skill'];
                }
            }
        }

        foreach (['p1', 'p2'] as $p) {
            $slots = $game[$p . '_slot_cards'];
            $changed = false;
            
            foreach ($slots as $k => $card) {
                if (!empty($card) && is_array($card)) {
                    $cardId = $card['id'];
                    $skillId = $cardsSkillMap[$cardId] ?? 0;
                    
                    if ($skillId && isset($skillsMap[$skillId])) {
                        $maxCd = $skillsMap[$skillId]['cd'];
                        $card['cd']++;
                        // 超过上限重置为 1 (根据需求: "超过CD上限则变回1")
                        if ($card['cd'] > $maxCd) {
                            $card['cd'] = 1;
                        }
                        $slots[$k] = $card;
                        $changed = true;
                    }
                }
            }
            
            if ($changed) {
                $updateData[$p . '_slot_cards'] = $slots;
            }
        }
    }

    // --- 技能系统辅助功能 ---

    /**
     * 1. 数据标准化：确保卡牌拥有用于计算的实时属性
     */
    private function ensureCardStats($card, $cardDef) {
        if (empty($card)) return null;
        
        // 初始化 Max HP
        if (!isset($card['max_hp'])) {
            $card['max_hp'] = $cardDef['hp'] ?? 10;
        }
        
        // 初始化当前 HP
        if (!isset($card['hp'])) {
            $card['hp'] = $cardDef['hp'] ?? 10;
        }

        // 初始化护盾
        if (!isset($card['shield'])) {
            $card['shield'] = 0;
        }

        return $card;
    }

    /**
     * 2. 目标定位：根据技能配置寻找目标
     */
    private function findTargetIndices($skillDef, $casterIndex, $mySlots, $enemySlots, $cardsMap) {
        $targetType = $skillDef['target']; // self, front, back, random, all, building...
        $effectType = $skillDef['type'];   // damage, heal, shield...

        // 判定阵营：伤害类技能默认找敌人，增益类默认找自己
        $targetSlots = ($effectType === 'damage') ? $enemySlots : $mySlots;
        
        // 筛选出所有存活的候选目标
        $candidates = [];
        foreach ($targetSlots as $index => $card) {
            if (!empty($card)) {
                $cardDef = $cardsMap[$card['id']] ?? null;
                if ($cardDef) {
                    $candidates[] = ['index' => $index, 'card' => $card, 'def' => $cardDef];
                }
            }
        }

        $indices = [];

        switch ($targetType) {
            case 'self':
                $indices[] = $casterIndex;
                break;
            case 'front':
                if (count($candidates) > 0) {
                    $indices[] = $candidates[0]['index'];
                }
                break;
            case 'back':
                if (count($candidates) > 0) {
                    $indices[] = $candidates[count($candidates) - 1]['index'];
                }
                break;
            case 'random':
                if (count($candidates) > 0) {
                    $randKey = array_rand($candidates);
                    $indices[] = $candidates[$randKey]['index'];
                }
                break;
            case 'all':
                foreach ($candidates as $c) $indices[] = $c['index'];
                break;
            default:
                // 按类型筛选 (hero, building, resource, npc)
                foreach ($candidates as $c) {
                    if (($c['def']['type'] ?? '') === $targetType) {
                        $indices[] = $c['index'];
                    }
                }
                break;
        }

        return [
            'indices' => $indices,
            'side' => ($effectType === 'damage') ? 'enemy' : 'friend'
        ];
    }

    /**
     * 3. 效果计算
     */
    private function applySkillEffect(&$targetCard, $skillDef) {
        $type = $skillDef['type'];
        $amount = $skillDef['amount'];
        $logDetail = '';

        if ($type === 'damage') {
            $damage = $amount;
            
            // 护盾抵消
            if (($targetCard['shield'] ?? 0) > 0) {
                if ($targetCard['shield'] >= $damage) {
                    $targetCard['shield'] -= $damage;
                    $damage = 0;
                    $logDetail = "(Shield blocked)";
                } else {
                    $damage -= $targetCard['shield'];
                    $targetCard['shield'] = 0;
                    $logDetail = "(Shield broke)";
                }
            }

            // 扣除 HP
            $targetCard['hp'] -= $damage;
            
        } elseif ($type === 'heal') {
            $oldHp = $targetCard['hp'];
            $targetCard['hp'] += $amount;
            if ($targetCard['hp'] > $targetCard['max_hp']) {
                $targetCard['hp'] = $targetCard['max_hp'];
            }
            $healed = $targetCard['hp'] - $oldHp;
            $logDetail = "+$healed HP";

        } elseif ($type === 'shield') {
            $targetCard['shield'] = ($targetCard['shield'] ?? 0) + $amount;
            $logDetail = "+$amount Shield";
        }

        return $logDetail;
    }

    // --- 战斗核心逻辑 ---
    private function resolveBattle($tableId, $game, &$updateData) {
        // 1. 加载数据
        $cardsMap = [];
        $skillsMap = [];
        
        $cardsJson = json_decode(file_get_contents(__DIR__ . '/assets/data/cards.json'), true);
        foreach ($cardsJson as $c) $cardsMap[$c['id']] = $c;

        $skillsJson = json_decode(file_get_contents(__DIR__ . '/assets/data/skills.json'), true);
        foreach ($skillsJson as $s) $skillsMap[$s['id']] = $s;

        // 2. 准备战斗数据
        $p1Slots = $updateData['p1_slot_cards'] ?? $game['p1_slot_cards'];
        $p2Slots = $updateData['p2_slot_cards'] ?? $game['p2_slot_cards'];
        
        // 确保数组长度
        $p1Slots = array_pad($p1Slots, 6, null);
        $p2Slots = array_pad($p2Slots, 6, null);

        // 预处理：确保所有卡牌都有战斗属性 (HP, Shield等)
        for ($i = 0; $i < 6; $i++) {
            if (!empty($p1Slots[$i])) $p1Slots[$i] = $this->ensureCardStats($p1Slots[$i], $cardsMap[$p1Slots[$i]['id']] ?? []);
            if (!empty($p2Slots[$i])) $p2Slots[$i] = $this->ensureCardStats($p2Slots[$i], $cardsMap[$p2Slots[$i]['id']] ?? []);
        }

        $battleLog = [];
        $isP1First = ($game['turn'] % 2 != 0); // 当前回合谁是先手
        // 后手玩家 (Second Player) 在同位置先行动
        // 如果 P1 是先手，则 P2 是后手，P2 先动

        // 3. 遍历槽位 (0-5)
        for ($i = 0; $i < 6; $i++) {
            // 确定行动顺序
            // 顺序：后手 -> 先手
            $order = $isP1First ? ['p2', 'p1'] : ['p1', 'p2'];
            
            foreach ($order as $player) {
                // 定义己方和敌方槽位引用
                if ($player == 'p1') {
                    $mySlots = &$p1Slots;
                    $enemySlots = &$p2Slots;
                } else {
                    $mySlots = &$p2Slots;
                    $enemySlots = &$p1Slots;
                }

                $card = $mySlots[$i];

                if (empty($card) || !is_array($card)) continue;

                $cardDef = $cardsMap[$card['id']] ?? null;
                if (!$cardDef) continue;

                $skillId = $cardDef['skill'];
                $skillDef = $skillsMap[$skillId] ?? null;

                // 检查 CD 是否就绪 (当前CD == MaxCD)
                if ($skillDef && $card['cd'] >= $skillDef['cd']) {
                    // 寻找目标
                    $targetResult = $this->findTargetIndices($skillDef, $i, $mySlots, $enemySlots, $cardsMap);
                    $targetIndices = $targetResult['indices'];
                    $targetSide = $targetResult['side']; // 'friend' or 'enemy'

                    // 确定目标槽位数组
                    if ($targetSide === 'friend') {
                        $targetSlots = &$mySlots;
                    } else {
                        $targetSlots = &$enemySlots;
                    }

                    $logTargets = [];

                    foreach ($targetIndices as $tIdx) {
                        if (empty($targetSlots[$tIdx])) continue;

                        // 应用效果
                        $this->applySkillEffect($targetSlots[$tIdx], $skillDef);
                        
                        $logTargets[] = $tIdx;

                        // 死亡判定
                        if ($targetSlots[$tIdx]['hp'] <= 0) {
                            $targetSlots[$tIdx] = null; // 移除
                        }
                    }

                    // 记录日志
                    if (!empty($logTargets)) {
                        $battleLog[] = [
                            'source' => $player,
                            'slot' => $i,
                            'skill' => $skillDef['name'],
                            'targets' => $logTargets,
                            'damage' => $skillDef['amount'], // 前端显示用基础数值
                            'effect' => $skillDef['type']
                        ];
                    }
                }
                
                // 解除引用，防止循环中变量污染
                unset($mySlots, $enemySlots, $targetSlots);
            }
        }

        // 4. 保存战斗结果
        $updateData['p1_slot_cards'] = $p1Slots;
        $updateData['p2_slot_cards'] = $p2Slots;
        $updateData['battle_log'] = $battleLog;

        // 5. 胜负判定
        // 规则：指挥部 (ID 999) 被毁 或 场上无卡
        $p1Alive = false; $p1Base = false;
        foreach ($p1Slots as $s) { if($s) { $p1Alive=true; if($s['id']==999) $p1Base=true; } }
        
        $p2Alive = false; $p2Base = false;
        foreach ($p2Slots as $s) { if($s) { $p2Alive=true; if($s['id']==999) $p2Base=true; } }

        if (!$p1Alive || !$p1Base) {
            $updateData['game_status'] = 2; // 结束
            $updateData['winner'] = 'p2'; // 需在表结构添加 winner 字段，或直接用 status 区分
        } elseif (!$p2Alive || !$p2Base) {
            $updateData['game_status'] = 2;
            $updateData['winner'] = 'p1';
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
