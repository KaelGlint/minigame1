<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>卡牌对战 - 大厅</title>
    <style>
        body { font-family: sans-serif; background: #f0f0f0; padding: 20px; }
        .lobby-container { max-width: 800px; margin: 0 auto; }
        .table-card { background: white; border: 1px solid #ccc; padding: 20px; margin-bottom: 15px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .seat { padding: 10px 20px; background: #ddd; cursor: pointer; border-radius: 4px; text-decoration: none; color: #333; }
        .seat.taken { background: #ffcccc; cursor: not-allowed; }
        .seat:hover:not(.taken) { background: #ccc; }
        .debug-btn { margin-top: 20px; padding: 10px; background: #ff4444; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>

<div class="lobby-container">
    <h1>游戏大厅</h1>
    <button class="debug-btn" onclick="debugReset()">[Debug] 重置所有游戏</button>
    <div id="tables-list">加载中...</div>
</div>

<script>
    // 简单的 JS 来加载大厅数据
    async function loadLobby() {
        const res = await fetch('api.php?action=get_lobby');
        const json = await res.json();
        const container = document.getElementById('tables-list');
        container.innerHTML = '';

        json.data.forEach(table => {
            const div = document.createElement('div');
            div.className = 'table-card';
            
            // 特殊处理 AI 桌 (Table 4)
            if (table.table_id == 4) {
                const btnHtml = table.p1_name 
                    ? `<span class="seat taken">正在进行中 (P1: ${table.p1_name})</span>`
                    : `<button class="seat" style="background:#b3e5fc" onclick="joinTable(4, 1)">开始 AI 对战</button>`;
                
                div.innerHTML = `
                    <h3>🤖 AI 训练场 (桌号 #4)</h3>
                    <div>${btnHtml}</div>
                `;
                container.appendChild(div);
                return;
            }

            // 生成座位 HTML
            const p1Html = table.p1_name 
                ? `<span class="seat taken">P1: ${table.p1_name}</span>` 
                : `<button class="seat" onclick="joinTable(${table.table_id}, 1)">加入 P1</button>`;
            
            const p2Html = table.p2_name 
                ? `<span class="seat taken">P2: ${table.p2_name}</span>` 
                : `<button class="seat" onclick="joinTable(${table.table_id}, 2)">加入 P2</button>`;

            div.innerHTML = `
                <h3>桌号 #${table.table_id}</h3>
                <div>${p1Html} VS ${p2Html}</div>
            `;
            container.appendChild(div);
        });
    }

    async function joinTable(tableId, seat) {
        const name = prompt("请输入你的昵称:");
        if (!name) return;

        const formData = new FormData();
        formData.append('table_id', tableId);
        formData.append('seat', seat);
        formData.append('name', name);

        const res = await fetch('api.php?action=join_game', { method: 'POST', body: formData });
        const json = await res.json();

        if (json.status === 'success') {
            window.location.href = 'game.php';
        } else {
            alert("加入失败: " + json.msg);
            loadLobby(); // 刷新状态
        }
    }

    async function debugReset() {
        if (!confirm("确定要强制结束所有游戏并重置吗？")) return;
        const res = await fetch('api.php?action=debug_reset');
        const json = await res.json();
        alert(json.msg);
        loadLobby();
    }

    loadLobby();
    setInterval(loadLobby, 5000); // 每5秒刷新大厅
</script>

</body>
</html>
