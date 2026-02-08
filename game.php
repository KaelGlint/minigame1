<?php
session_start();
if (!isset($_SESSION['table_id'])) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>对战中 - 桌号 <?php echo $_SESSION['table_id']; ?></title>
    <style>
        /* 基础布局样式 */
        body { margin: 0; padding: 0; font-family: sans-serif; background: #333; color: white; height: 100vh; display: flex; flex-direction: column; }
        
        /* 顶部：敌方区域 */
        #enemy-area { flex: 1; background: #444; display: flex; flex-direction: column; align-items: center; justify-content: center; border-bottom: 2px solid #222; }
        
        /* 中间：技能/公共区 */
        #skill-area { height: 100px; background: #2a2a2a; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #222; position: relative; }
        
        /* 底部：我方区域 */
        #player-area { flex: 1; background: #3a3a3a; display: flex; flex-direction: column; align-items: center; justify-content: center; }

        /* 通用组件样式 */
        .status-bar { width: 100%; padding: 5px 20px; display: flex; justify-content: space-between; box-sizing: border-box; background: rgba(0,0,0,0.3); }
        
        .card-slots { display: flex; gap: 10px; margin: 10px 0; }
        .slot { width: 80px; height: 100px; background: rgba(255,255,255,0.1); border: 2px dashed #666; display: flex; align-items: center; justify-content: center; position: relative; }
        .card { width: 70px; height: 90px; background: #eee; color: #333; border-radius: 4px; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 12px; cursor: grab; }
        
        .hand-area { margin-top: auto; padding: 10px; background: rgba(0,0,0,0.2); width: 100%; display: flex; justify-content: center; gap: 10px; min-height: 120px; }
        
        /* 阶段提示 */
        #phase-indicator { position: absolute; top: 5px; left: 50%; transform: translateX(-50%); background: gold; color: black; padding: 2px 10px; border-radius: 4px; font-weight: bold; }
        
        /* 按钮 */
        #action-btn { padding: 10px 30px; font-size: 16px; cursor: pointer; background: #4CAF50; color: white; border: none; border-radius: 4px; margin-bottom: 10px; }
        #action-btn:disabled { background: #555; cursor: not-allowed; }

        /* 弹窗样式 */
        #draft-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:100; flex-direction:column; align-items:center; justify-content:center; }
        #draft-cards { display:flex; gap:20px; margin-top: 20px; }
        .draft-card { width: 120px; height: 160px; background: #fff; color: #333; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 2px solid #aaa; transition: transform 0.2s; }
        .draft-card:hover { transform: scale(1.1); border-color: gold; }
        #announcement-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:200; align-items:center; justify-content:center; pointer-events: none; }
        #announcement-text { font-size: 48px; font-weight: bold; color: gold; text-shadow: 0 0 10px black; text-align: center; }
        #quit-btn { position: absolute; top: 10px; right: 10px; background: #d32f2f; color: white; border: none; padding: 5px 10px; cursor: pointer; }
    </style>
</head>
<body>

    <!-- 敌方区域 -->
    <div id="enemy-area">
        <div class="status-bar">
            <span>敌方: <span id="enemy-name">Waiting...</span></span>
            <span>HP: <span id="enemy-hp">100</span> | 🛡️ <span id="enemy-shield">0</span> | 💰 <span id="enemy-gold">0</span></span>
        </div>
        <!-- 敌方手牌 (背面) -->
        <div id="enemy-hand" style="display:flex; gap:5px; margin-bottom: 10px; opacity: 0.5;">
            <!-- JS 生成卡背 -->
        </div>
        <!-- 敌方置牌区 -->
        <div class="card-slots" id="enemy-slots">
            <!-- 6个槽位 -->
            <div class="slot" data-index="0"></div>
            <div class="slot" data-index="1"></div>
            <div class="slot" data-index="2"></div>
            <div class="slot" data-index="3"></div>
            <div class="slot" data-index="4"></div>
            <div class="slot" data-index="5"></div>
        </div>
    </div>

    <!-- 中间技能/信息区 -->
    <div id="skill-area">
        <div id="phase-indicator">等待开始...</div>
        <div id="event-display">随机事件区域</div>
        <button id="quit-btn" onclick="quitGame()">退出游戏</button>
    </div>

    <!-- 我方区域 -->
    <div id="player-area">
        <!-- 我方置牌区 -->
        <div class="card-slots" id="player-slots">
            <div class="slot" data-index="0"></div>
            <div class="slot" data-index="1"></div>
            <div class="slot" data-index="2"></div>
            <div class="slot" data-index="3"></div>
            <div class="slot" data-index="4"></div>
            <div class="slot" data-index="5"></div>
        </div>

        <!-- 操作按钮 -->
        <button id="action-btn" disabled>等待对手...</button>

        <!-- 我方手牌区 -->
        <div class="hand-area" id="player-hand">
            <!-- JS 填充手牌 -->
        </div>

        <div class="status-bar">
            <span>我方: <span id="my-name"><?php echo $_SESSION['player_id']; ?></span></span>
            <span>HP: <span id="my-hp">100</span> | 🛡️ <span id="my-shield">0</span> | 💰 <span id="my-gold">0</span></span>
        </div>
    </div>

    <!-- 抽卡弹窗 -->
    <div id="draft-modal">
        <h2>请选择一张卡牌</h2>
        <div id="draft-cards">
            <div class="draft-card">卡牌 A</div>
            <div class="draft-card">卡牌 B</div>
            <div class="draft-card">卡牌 C</div>
        </div>
        <div id="draft-timer" style="color:white; margin-top:20px; font-size: 18px;">剩余时间: --</div>
    </div>

    <!-- 通用通知弹窗 -->
    <div id="announcement-modal">
        <div id="announcement-text">回合 1</div>
    </div>

    <script src="game.js"></script>
    <script>
        async function quitGame() {
            if (!confirm("确定要退出并结束本局游戏吗？")) return;
            const res = await fetch('api.php?action=quit_game');
            const json = await res.json();
            if (json.status === 'success') {
                window.location.href = 'index.php';
            }
        }
    </script>
</body>
</html>
