<?php
// api.php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$gameModel = new GameModel();

$response = ['status' => 'error', 'msg' => 'Unknown action'];

try {
    if ($action === 'get_lobby') {
        // 获取大厅数据
        $response = ['status' => 'success', 'data' => $gameModel->getLobbyStatus()];
    } 
    elseif ($action === 'join_game') {
        // 加入游戏
        $tableId = (int)$_POST['table_id'];
        $seat = (int)$_POST['seat']; // 1 or 2
        $name = trim($_POST['name']);

        if ($gameModel->joinSeat($tableId, $seat, $name)) {
            $_SESSION['player_id'] = $name; // 简单起见用名字做ID
            $_SESSION['table_id'] = $tableId;
            $_SESSION['seat'] = $seat;
            $response = ['status' => 'success'];
        } else {
            $response = ['status' => 'error', 'msg' => 'Seat taken or error'];
        }
    }
    elseif ($action === 'get_state') {
        // 获取游戏实时状态 (轮询用)
        if (!isset($_SESSION['table_id'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Not logged in']);
            exit;
        }
        
        // 检查并更新游戏流程 (超时判断等)
        $gameModel->updateGameFlow($_SESSION['table_id']);

        // 如果是 AI 桌，尝试触发 AI 行动
        if ($_SESSION['table_id'] == 4) {
            $gameModel->processAI(4);
        }

        $gameData = $gameModel->getGame($_SESSION['table_id']);
        
        // 简单的数据脱敏：不要把对方的手牌发给客户端（防止作弊）
        // 这里暂时先全部返回，方便调试，正式上线可以过滤
        
        $response = [
            'status' => 'success', 
            'data' => $gameData,
            'my_seat' => $_SESSION['seat']
        ];
    }
    elseif ($action === 'debug_reset') {
        // 重置所有游戏
        $gameModel->resetAllGames();
        $response = ['status' => 'success', 'msg' => 'All games reset'];
    }
    elseif ($action === 'quit_game') {
        // 退出当前游戏
        if (isset($_SESSION['table_id'])) {
            $gameModel->resetGame($_SESSION['table_id']);
            session_destroy(); // 清除会话
            $response = ['status' => 'success'];
        }
    }
} catch (Exception $e) {
    $response = ['status' => 'error', 'msg' => $e->getMessage()];
}

echo json_encode($response);
?>
