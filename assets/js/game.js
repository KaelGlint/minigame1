// game.js

const POLLING_INTERVAL = 2000; // 2秒轮询一次
let mySeat = 0; // 1 或 2
let lastGameState = null;
let eventDefinitions = []; // 存储事件定义
let cardDefinitions = {};  // 存储卡牌定义 (ID -> Data)
let skillDefinitions = {}; // 存储技能定义 (ID -> Data)
let isAnimating = false;   // 动画播放锁

// 初始化
document.addEventListener('DOMContentLoaded', async () => {
    // 必须等待所有定义加载完毕，再获取状态，防止渲染时字典为空
    await Promise.all([
        loadEventDefinitions(),
        loadCardDefinitions(),
        loadSkillDefinitions()
    ]);
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

async function loadSkillDefinitions() {
    try {
        const res = await fetch('assets/data/skills.json');
        const skills = await res.json();
        skills.forEach(s => {
            skillDefinitions[s.id] = s;
        });
    } catch (e) {
        console.error("Failed to load skills:", e);
    }
}

function updateUI(game) {
    // 避免重复渲染 (简单优化)
    // if (JSON.stringify(game) === JSON.stringify(lastGameState)) return;
    
    // 如果正在播放动画，暂停 UI 更新，防止状态跳变
    if (isAnimating) return;

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

    // 战斗结算动画 (Phase 4)
    if (game.phase === 4) {
        const myStatus = game[myPrefix + '_status'];
        // 如果我还没完成动画，且有战斗日志
        if (myStatus === 0 && game.battle_log && game.battle_log.length > 0) {
            playBattleAnimation(game.battle_log, myPrefix);
            return; // 动画期间停止后续渲染
        } else if (myStatus === 0) {
            // 没有日志（无事发生），直接完成
            finishAnimation();
        }
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

    // 处理 "完成部署" 按钮
    const finishBtn = document.getElementById('finish-deploy-btn');
    if (game.phase === 3) {
        finishBtn.style.display = 'inline-block';
        const myStatus = game[myPrefix + '_status'];
        if (myStatus === 1) {
            finishBtn.innerText = '已就绪 (等待对手)';
            finishBtn.disabled = true;
        } else {
            finishBtn.innerText = '完成部署';
            finishBtn.disabled = false;
        }
    } else {
        finishBtn.style.display = 'none';
    }

    // 7. 渲染我方手牌 (示例)
    const handContainer = document.getElementById('player-hand');
    handContainer.innerHTML = '';
    const myHand = game[myPrefix + '_hand_cards'] || [];
    const myGold = game[myPrefix + '_gold'];
    
    myHand.forEach((cardId, index) => {
        const cardDiv = document.createElement('div');
        cardDiv.className = 'card';
        const cardData = cardDefinitions[cardId] || { name: '未知', id: cardId, cost: 0 };
        
        // 使用 innerHTML 以支持技能显示 (保持与置牌区一致的结构)
        cardDiv.innerHTML = `<div class="card-name">${cardData.name} <span style="color:gold">($${cardData.cost})</span></div>`;

        if (cardData.skill && skillDefinitions[cardData.skill]) {
            const skill = skillDefinitions[cardData.skill];
            // 手牌未部署，当前CD显示为 0
            const skillInfo = `<div class="card-skill" title="${skill.des}">
                <span class="skill-name">${skill.name}</span> <span class="skill-cd">0/${skill.cd}</span>
            </div>`;
            cardDiv.innerHTML += skillInfo;
        }
        
        // 部署阶段逻辑
        if (game.phase === 3) {
            if (myGold >= cardData.cost) {
                cardDiv.draggable = true;
                cardDiv.ondragstart = (e) => {
                    e.dataTransfer.setData('text/plain', index); // 传递手牌索引
                };
            } else {
                cardDiv.classList.add('disabled');
                cardDiv.onclick = () => alert(`金币不足！需要 ${cardData.cost} 金币。`);
            }
        }

        handContainer.appendChild(cardDiv);
    });

    // 添加垃圾箱 (仅在部署阶段显示)
    if (game.phase === 3) {
        const trashDiv = document.createElement('div');
        trashDiv.className = 'trash-bin';
        trashDiv.innerHTML = '<div style="font-size:24px">🗑️</div><div style="font-size:10px">回收(+1)</div>';
        
        trashDiv.ondragover = (e) => {
            e.preventDefault();
            trashDiv.style.background = 'rgba(211, 47, 47, 0.5)';
        };
        trashDiv.ondragleave = () => trashDiv.style.background = '';
        trashDiv.ondrop = (e) => handleTrashDrop(e, trashDiv);
        
        handContainer.appendChild(trashDiv);
    }

    // 8. 渲染置牌区 (Slot)
    renderSlots('player-slots', game[myPrefix + '_slot_cards'], game, true);
    renderSlots('enemy-slots', game[enemyPrefix + '_slot_cards'], game, false);

    // 更新本地状态记录
    lastGameState = game;
}

function renderDraftCards(game) {
    const container = document.getElementById('draft-cards');
    container.innerHTML = '';

    const draftCards = game.draft_cards || [];
    const picks = game.draft_picks || { p1: [], p2: [] };
    const myPicks = picks[mySeat === 1 ? 'p1' : 'p2'] || [];
    const enemyPicks = picks[mySeat === 1 ? 'p2' : 'p1'] || [];
    const allPicked = [...myPicks, ...enemyPicks];
    
    const isP1First = (game.turn % 2 !== 0);
    const activeSeat = (game.phase === 1) ? (isP1First ? 1 : 2) : (isP1First ? 2 : 1);
    const isMyTurn = (mySeat === activeSeat);

    draftCards.forEach((cardId, index) => {
        const cardData = cardDefinitions[cardId] || { name: '未知', desc: '...' };
        const div = document.createElement('div');
        div.className = 'draft-card';
        
        // 构建技能信息 HTML
        let skillHtml = '';
        if (cardData.skill && skillDefinitions[cardData.skill]) {
            const skill = skillDefinitions[cardData.skill];
            skillHtml = `<div style="margin-top:5px; border-top:1px solid #ccc; padding-top:2px;">
                <small><strong>${skill.name}</strong> (CD:${skill.cd})</small><br>
                <span style="font-size:9px; color:#555;">${skill.des}</span>
            </div>`;
        }

        div.innerHTML = `
            <div style="text-align:center">
                <strong>${cardData.name}</strong><br>
                <small>${cardData.type}</small><br>
                <span style="font-size:10px">${cardData.desc}</span>
                ${skillHtml}
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

function renderSlots(containerId, slotData, game, canDrop = false) {
    const container = document.getElementById(containerId);
    const slots = container.querySelectorAll('.slot');
    if (!slotData) slotData = [];
    
    slots.forEach((slot, index) => {
        slot.innerHTML = ''; // 清空
        
        // 兼容处理：slotData 可能是 ID 数组 (旧) 或 对象数组 (新，包含状态)
        // 目前部署阶段还没写，暂时还是 ID，但为了后续兼容，这里做个判断
        let cardId = null;
        let currentCd = 0;
        
        const slotItem = slotData[index];
        if (slotItem && typeof slotItem === 'object') {
            cardId = slotItem.id;
            currentCd = slotItem.cd || 0;
            // 如果有 HP 数据，优先显示当前 HP
            // 注意：后端 resolveBattle 中临时加了 hp 字段
            if (slotItem.hp !== undefined) {
                // 这里可以存下来用于显示，但下面 cardData 重新获取了静态数据
            }
        } else {
            cardId = slotItem;
            currentCd = 0; // 默认初始CD
        }
        const currentHp = (slotItem && slotItem.hp !== undefined) ? slotItem.hp : (cardDefinitions[cardId]?.hp || 0);

        if (cardId) {
            const cardDiv = document.createElement('div');
            cardDiv.className = 'card';
            const cardData = cardDefinitions[cardId] || { name: '未知' };
            
            // 渲染卡牌主体
            cardDiv.innerHTML = `<div class="card-name">${cardData.name}</div><div style="text-align:center; color:green; font-weight:bold;">HP: ${currentHp}</div>`;

            // 渲染技能区域 (如果有技能)
            if (cardData.skill && skillDefinitions[cardData.skill]) {
                const skill = skillDefinitions[cardData.skill];
                const skillInfo = `<div class="card-skill" title="${skill.des}">
                    <span class="skill-name">${skill.name}</span> <span class="skill-cd">${currentCd}/${skill.cd}</span>
                </div>`;
                cardDiv.innerHTML += skillInfo;
            }
            
            slot.appendChild(cardDiv);

            // 允许覆盖：即使有卡，在部署阶段也允许拖放
            if (canDrop && game && game.phase === 3) {
                slot.ondragover = (e) => e.preventDefault();
                slot.ondrop = (e) => handleDrop(e, index);
            }
        } else {
            // 空槽位：允许拖放 (仅在部署阶段)
            if (canDrop && game && game.phase === 3) {
                slot.ondragover = (e) => e.preventDefault(); // 允许放置
                slot.ondrop = (e) => handleDrop(e, index);
            }
        }
    });
}

async function handleDrop(e, slotIndex) {
    e.preventDefault();
    const handIndex = e.dataTransfer.getData('text/plain');
    
    const formData = new FormData();
    formData.append('hand_index', handIndex);
    formData.append('slot_index', slotIndex);
    
    await fetch('api.php?action=deploy_card', { method: 'POST', body: formData });
    fetchState(); // 立即刷新
}

async function handleTrashDrop(e, trashDiv) {
    e.preventDefault();
    trashDiv.style.background = '';
    const handIndex = e.dataTransfer.getData('text/plain');
    
    const formData = new FormData();
    formData.append('hand_index', handIndex);
    await fetch('api.php?action=discard_card', { method: 'POST', body: formData });
    fetchState();
}

async function playBattleAnimation(logs, myPrefix) {
    isAnimating = true;
    const enemyPrefix = (myPrefix === 'p1') ? 'p2' : 'p1';
    
    // 简单提示
    const phaseIndicator = document.getElementById('phase-indicator');
    phaseIndicator.innerText = "战斗进行中...";
    phaseIndicator.style.background = "red";
    phaseIndicator.style.color = "white";

    for (const log of logs) {
        // 1. 高亮攻击者
        const sourcePrefix = (log.source === myPrefix) ? 'player' : 'enemy';
        const sourceSlot = document.querySelector(`#${sourcePrefix}-slots .slot[data-index="${log.slot}"] .card`);
        
        if (sourceSlot) {
            sourceSlot.style.boxShadow = "0 0 15px red";
            sourceSlot.style.transform = "scale(1.1)";
            await new Promise(r => setTimeout(r, 500)); // 蓄力
        }

        // 2. 显示技能名
        // 这里可以做一个飘字效果，简单起见用 console 或 alert
        // console.log(`${log.source} uses ${log.skill}`);

        // 3. 攻击效果 (所有目标)
        const targetPrefix = (log.source === myPrefix) ? 'enemy' : 'player';
        const targetContainer = document.getElementById(`${targetPrefix}-slots`);
        
        log.targets.forEach(targetIdx => {
            const targetCard = targetContainer.querySelector(`.slot[data-index="${targetIdx}"] .card`);
            if (targetCard) {
                // 闪烁变红
                targetCard.style.background = "#ffcccc";
                // 飘字伤害
                const dmg = document.createElement('div');
                dmg.innerText = `-${log.damage}`;
                dmg.style.position = 'absolute';
                dmg.style.color = 'red';
                dmg.style.fontSize = '20px';
                dmg.style.fontWeight = 'bold';
                dmg.style.top = '0';
                targetCard.appendChild(dmg);
            }
        });

        await new Promise(r => setTimeout(r, 800)); // 伤害展示时间

        // 还原攻击者样式
        if (sourceSlot) {
            sourceSlot.style.boxShadow = "";
            sourceSlot.style.transform = "";
        }
    }

    isAnimating = false;
    finishAnimation();
}

async function finishAnimation() {
    await fetch('api.php?action=finish_animation');
    fetchState();
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
