// game.js

const POLLING_INTERVAL = 2000; // 2秒轮询一次
let mySeat = 0; // 1 或 2
let lastGameState = null;
let eventDefinitions = []; // 存储事件定义
let cardDefinitions = {};  // 存储卡牌定义 (ID -> Data)

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    loadEventDefinitions();
    loadCardDefinitions();
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

async function loadEventDefinitions() {
    try {
        const res = await fetch('assets/data/events.json');
        eventDefinitions = await res.json();
    } catch (e) {
        console.error("Failed to load events:", e);
    }
}

async function loadCardDefinitions() {
    try {
        const res = await fetch('assets/data/cards.json');
        const cards = await res.json();
        // 转为对象方便查找
        cards.forEach(c => {
            cardDefinitions[c.id] = c;
        });
    } catch (e) {
        console.error("Failed to load cards:", e);
    }
}

function updateUI(game) {
    // 避免重复渲染 (简单优化)
    // if (JSON.stringify(game) === JSON.stringify(lastGameState)) return;

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

    // 更新随机事件区域显示
    const eventDisplay = document.getElementById('event-display');
    if (eventDisplay) {
        const curEvent = eventDefinitions.find(e => e.id === game.event_id);
        eventDisplay.innerText = curEvent ? `【${curEvent.name}】${curEvent.desc}` : '随机事件区域';
    }

    // 5. 处理阶段通知弹窗 (Phase Change Detection)
    // 如果是首次加载(!lastGameState) 或者 阶段发生了变化
    if (!lastGameState || lastGameState.phase !== game.phase) {
        showAnnouncement(game);
    }

    // 6. 处理抽卡弹窗 (Phase 1 & 2)
    const draftModal = document.getElementById('draft-modal');
    const isDraftPhase = (game.phase === 1 || game.phase === 2);
    
    if (isDraftPhase) {
        draftModal.style.display = 'flex';
        document.getElementById('draft-timer').innerText = `剩余时间: ${timeLeft}s`;
        renderDraftCards(game);
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
        const cardData = cardDefinitions[cardId] || { name: '未知', id: cardId };
        cardDiv.innerText = cardData.name;
        // TODO: 添加拖拽事件
        handContainer.appendChild(cardDiv);
    });

    // 8. 渲染置牌区 (Slot)
    renderSlots('player-slots', game[myPrefix + '_slot_cards']);
    renderSlots('enemy-slots', game[enemyPrefix + '_slot_cards']);

    // 更新本地状态记录
    lastGameState = game;
}

function renderDraftCards(game) {
    const container = document.getElementById('draft-cards');
    container.innerHTML = '';

    const draftCards = game.draft_cards || [];
    const myPicks = game.draft_picks[mySeat === 1 ? 'p1' : 'p2'] || [];
    const enemyPicks = game.draft_picks[mySeat === 1 ? 'p2' : 'p1'] || [];
    const allPicked = [...myPicks, ...enemyPicks];
    
    const isP1First = (game.turn % 2 !== 0);
    const activeSeat = (game.phase === 1) ? (isP1First ? 1 : 2) : (isP1First ? 2 : 1);
    const isMyTurn = (mySeat === activeSeat);

    draftCards.forEach((cardId, index) => {
        const cardData = cardDefinitions[cardId] || { name: '未知', desc: '...' };
        const div = document.createElement('div');
        div.className = 'draft-card';
        div.innerHTML = `
            <div style="text-align:center">
                <strong>${cardData.name}</strong><br>
                <small>${cardData.type}</small><br>
                <span style="font-size:10px">${cardData.desc}</span>
            </div>
        `;

        // 状态处理
        if (allPicked.includes(index)) {
            div.style.opacity = '0.3';
            div.style.cursor = 'not-allowed';
            if (myPicks.includes(index)) div.style.borderColor = 'green';
            else div.style.borderColor = 'red';
        } else {
            if (isMyTurn) {
                div.onclick = () => pickCard(index);
                div.style.cursor = 'pointer';
            } else {
                div.style.cursor = 'not-allowed';
                div.style.opacity = '0.6';
            }
        }

        container.appendChild(div);
    });
}

async function pickCard(index) {
    const formData = new FormData();
    formData.append('index', index);
    await fetch('api.php?action=draft_card', { method: 'POST', body: formData });
    fetchState(); // 立即刷新
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
            const cardData = cardDefinitions[cardId] || { name: '未知' };
            cardDiv.innerText = cardData.name;
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
        case 0: 
            // 查找当前事件
            const event = eventDefinitions.find(e => e.id === game.event_id);
            const eventName = event ? event.name : '未知事件';
            const eventDesc = event ? event.desc : '...';
            msg = `第 ${game.turn} 回合\n【${eventName}】\n${eventDesc}`; 
            break;
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
