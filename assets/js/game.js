// game.js

const POLLING_INTERVAL = 2000; // 2秒轮询一次
let mySeat = 0; // 1 或 2
let lastGameState = null;

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    fetchState();
    setInterval(fetchState, POLLING_INTERVAL);
});

async function fetchState() {
    try {
        const res = await fetch('api.php?action=get_state');
        const json = await res.json();

        if (json.status === 'success') {
            mySeat = json.my_seat;
            updateUI(json.data);
        } else {
            console.error("Error fetching state:", json.msg);
        }
    } catch (e) {
        console.error("Network error:", e);
    }
}

function updateUI(game) {
    // 避免重复渲染 (简单优化)
    // if (JSON.stringify(game) === JSON.stringify(lastGameState)) return;
    lastGameState = game;

    // 1. 确定敌我数据前缀
    const myPrefix = (mySeat === 1) ? 'p1' : 'p2';
    const enemyPrefix = (mySeat === 1) ? 'p2' : 'p1';

    // 2. 更新顶部状态栏 (敌方)
    document.getElementById('enemy-name').innerText = game[enemyPrefix + '_name'] || '等待加入...';
    document.getElementById('enemy-hp').innerText = game[enemyPrefix + '_hp'];
    document.getElementById('enemy-shield').innerText = game[enemyPrefix + '_shield'];
    document.getElementById('enemy-gold').innerText = game[enemyPrefix + '_gold'];

    // 3. 更新底部状态栏 (我方)
    document.getElementById('my-hp').innerText = game[myPrefix + '_hp'];
    document.getElementById('my-shield').innerText = game[myPrefix + '_shield'];
    document.getElementById('my-gold').innerText = game[myPrefix + '_gold'];

    // 4. 更新阶段提示
    // 0:Event, 1:DraftP1, 2:DraftP2, 3:Deploy, 4:Result
    const phases = ['随机事件', '抽卡: 先手', '抽卡: 后手', '部署阶段', '战斗结算'];
    const timeLeft = Math.max(0, game.deadline_ts - Math.floor(Date.now() / 1000));
    
    document.getElementById('phase-indicator').innerText = 
        `回合 ${game.turn} - ${phases[game.phase] || '未知'} (${timeLeft}s)`;

    // 5. 处理阶段通知弹窗 (Phase Change Detection)
    if (lastGameState && lastGameState.phase !== game.phase) {
        showAnnouncement(game);
    }

    // 6. 处理抽卡弹窗 (Phase 1 & 2)
    const draftModal = document.getElementById('draft-modal');
    // 只有在抽卡阶段，且轮到自己时才显示选卡界面
    // Phase 1: P1 Draft, Phase 2: P2 Draft
    const isMyDraftTurn = (game.phase === 1 && mySeat === 1) || (game.phase === 2 && mySeat === 2);
    
    if (isMyDraftTurn) {
        draftModal.style.display = 'flex';
        document.getElementById('draft-timer').innerText = `剩余时间: ${timeLeft}s`;
        // TODO: 这里之后会根据服务器返回的候选卡牌渲染内容
    } else {
        draftModal.style.display = 'none';
    }

    // 7. 渲染我方手牌 (示例)
    const handContainer = document.getElementById('player-hand');
    handContainer.innerHTML = '';
    const myHand = game[myPrefix + '_hand_cards'];
    
    myHand.forEach(cardId => {
        const cardDiv = document.createElement('div');
        cardDiv.className = 'card';
        cardDiv.innerText = `Card ${cardId}`;
        // TODO: 添加拖拽事件
        handContainer.appendChild(cardDiv);
    });

    // 8. 渲染置牌区 (Slot)
    renderSlots('player-slots', game[myPrefix + '_slot_cards']);
    renderSlots('enemy-slots', game[enemyPrefix + '_slot_cards']);
}

function renderSlots(containerId, slotData) {
    const container = document.getElementById(containerId);
    const slots = container.querySelectorAll('.slot');
    
    slots.forEach((slot, index) => {
        slot.innerHTML = ''; // 清空
        const cardId = slotData[index];
        if (cardId) {
            const cardDiv = document.createElement('div');
            cardDiv.className = 'card';
            cardDiv.innerText = `Card ${cardId}`;
            slot.appendChild(cardDiv);
        }
    });
}

function showAnnouncement(game) {
    const modal = document.getElementById('announcement-modal');
    const text = document.getElementById('announcement-text');
    let msg = '';

    // 计算先手玩家名字
    const isP1First = (game.turn % 2 !== 0);
    const firstPlayer = isP1First ? (game.p1_name || 'P1') : (game.p2_name || 'P2');
    const secondPlayer = isP1First ? (game.p2_name || 'P2') : (game.p1_name || 'P1');

    switch(game.phase) {
        case 0: msg = `第 ${game.turn} 回合\n随机事件阶段`; break;
        case 1: msg = `抽卡阶段\n${firstPlayer}`; break;
        case 2: msg = `抽卡阶段\n${secondPlayer}`; break;
        case 3: msg = `部署阶段`; break;
        case 4: msg = `战斗结算`; break;
    }

    if (msg) {
        text.innerText = msg;
        modal.style.display = 'flex';
        // 3秒后自动消失
        setTimeout(() => { modal.style.display = 'none'; }, 3000);
    }
}
