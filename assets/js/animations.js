// 动画控制核心逻辑

async function playBattleAnimation(logs, myPrefix) {
    isAnimating = true;
    const enemyPrefix = (myPrefix === 'p1') ? 'p2' : 'p1';
    
    // 简单提示
    const phaseIndicator = document.getElementById('phase-indicator');
    if (phaseIndicator) {
        phaseIndicator.innerText = "战斗进行中...";
        phaseIndicator.style.background = "#d32f2f";
        phaseIndicator.style.color = "white";
    }

    for (const log of logs) {
        // 1. 获取攻击者元素
        const sourcePrefix = (log.source === myPrefix) ? 'player' : 'enemy';
        const sourceSlotEl = document.querySelector(`#${sourcePrefix}-slots .slot[data-index="${log.slot}"]`);
        const sourceCard = sourceSlotEl ? sourceSlotEl.querySelector('.card') : null;
        
        // 2. 获取技能动画类型
        // 尝试从 skillDefinitions 中查找动画类型，如果没有则默认
        // 注意：这里假设 skillDefinitions 是全局变量，在 game.js 中定义
        let animType = 'attack'; // 默认普通攻击
        // 我们需要反查 Skill ID，或者让后端在 log 里带上 animation 字段。
        // 目前 log 里只有 skill name。为了简单，我们通过 name 匹配，或者假设 log.skill 就是 skillDefinitions 里的 key (其实不是)
        // 最稳妥的是遍历 skillDefinitions 找 name，或者后端传 ID。
        // 暂时用简单的关键词匹配
        if (log.skill === '扫射') animType = 'aoe_shot';
        else if (log.skill === '狙击') animType = 'snipe';
        else if (log.effect === 'heal') animType = 'heal';
        else if (log.effect === 'buff') animType = 'buff';

        // 3. 执行攻击动作 (蓄力/震动)
        if (sourceCard) {
            sourceCard.style.transition = "transform 0.1s";
            sourceCard.style.transform = "scale(1.1)";
            sourceCard.style.boxShadow = "0 0 15px red";
            await wait(200);
        }

        // 4. 执行技能特效
        const targetPrefix = (log.source === myPrefix) ? 'enemy' : 'player';
        const targetContainer = document.getElementById(`${targetPrefix}-slots`);
        
        // 收集所有目标元素
        const targetElements = [];
        
        // 兼容新旧日志格式
        const targetsData = log.target_details || log.targets.map(t => ({ slot: t, damage: log.damage, is_dead: false }));

        targetsData.forEach(tData => {
            const targetIdx = tData.slot;
            const slotEl = targetContainer.querySelector(`.slot[data-index="${targetIdx}"]`);
            if (slotEl) {
                // 如果卡牌还在，就以卡牌为目标；如果卡牌没了(被打死了)，就以槽位为目标
                const cardEl = slotEl.querySelector('.card');
                // 将详细数据绑定到对象上
                targetElements.push({ el: cardEl || slotEl, idx: targetIdx, data: tData });
            }
        });

        if (animType === 'aoe_shot' && sourceCard) {
            // --- 机枪扫射特效 ---
            // 对每个目标快速连射
            for (const target of targetElements) {
                // 枪口火光
                showMuzzleFlash(sourceCard);
                // 发射子弹
                await createProjectile(sourceCard, target.el, '#ffd700'); 
                // 受击反馈
                showHitEffect(target.el, log, target.data);
                // 射击间隔
                await wait(100); 
            }
        } else if (animType === 'snipe' && sourceCard && targetElements.length > 0) {
            // --- 狙击特效 (单发高速) ---
            showMuzzleFlash(sourceCard);
            await createProjectile(sourceCard, targetElements[0].el, '#ff4444', 300); // 红色光束，稍慢一点看清轨迹
            showHitEffect(targetElements[0].el, log, targetElements[0].data);
        } else {
            // --- 普通/瞬间特效 (治疗/Buff/近战) ---
            targetElements.forEach(target => {
                showHitEffect(target.el, log, target.data);
            });
            await wait(500);
        }

        // 5. 还原攻击者样式
        if (sourceCard) {
            sourceCard.style.transform = "";
            sourceCard.style.boxShadow = "";
            await wait(100); // 稍微缓冲
        }
    }

    isAnimating = false;
    // 调用 game.js 中的全局函数
    if (typeof finishAnimation === 'function') {
        finishAnimation();
    }
}

// 生成并播放投射物动画
function createProjectile(startElem, endElem, color = '#ffd700', duration = 150) {
    return new Promise(resolve => {
        if (!startElem || !endElem) { resolve(); return; }

        const startRect = startElem.getBoundingClientRect();
        const endRect = endElem.getBoundingClientRect();

        // 计算起点和终点中心
        const startX = startRect.left + startRect.width / 2;
        const startY = startRect.top + startRect.height / 2;
        const endX = endRect.left + endRect.width / 2;
        const endY = endRect.top + endRect.height / 2;

        // 计算角度
        const angle = Math.atan2(endY - startY, endX - startX) * (180 / Math.PI) + 90; // +90 因为子弹图片默认可能是垂直的

        // 创建子弹元素
        const bullet = document.createElement('div');
        bullet.className = 'projectile';
        bullet.style.backgroundColor = color;
        bullet.style.left = `${startX}px`;
        bullet.style.top = `${startY}px`;
        bullet.style.transform = `rotate(${angle}deg)`;
        
        const animLayer = document.getElementById('animation-layer') || document.body;
        animLayer.appendChild(bullet);

        // 使用 Web Animations API
        const animation = bullet.animate([
            { left: `${startX}px`, top: `${startY}px` },
            { left: `${endX}px`, top: `${endY}px` }
        ], {
            duration: duration,
            easing: 'linear'
        });

        animation.onfinish = () => {
            bullet.remove();
            resolve();
        };
    });
}

// 显示枪口火光
function showMuzzleFlash(elem) {
    if (!elem) return;
    const rect = elem.getBoundingClientRect();
    const flash = document.createElement('div');
    flash.className = 'muzzle-flash';
    flash.style.left = `${rect.left + rect.width/2 - 10}px`;
    flash.style.top = `${rect.top + rect.height/2 - 10}px`;
    const animLayer = document.getElementById('animation-layer') || document.body;
    animLayer.appendChild(flash);
    
    setTimeout(() => flash.remove(), 150);
}

// 显示受击/治疗效果 (飘字 + 变色)
function showHitEffect(targetCard, log, targetData) {
    if (!targetCard) return;

    let color = 'red';
    const damageVal = targetData ? targetData.damage : log.damage;
    let text = `-${damageVal}`;
    
    if (log.effect === 'heal') { color = '#4caf50'; text = `+${log.damage}`; }
    else if (log.effect === 'shield') { color = '#2196f3'; text = `🛡️+${log.damage}`; }
    else if (log.effect === 'buff') { color = '#ffc107'; text = `🆙`; }
    else if (log.damage === 0 && log.effect === 'damage') { color = '#9e9e9e'; text = 'Blocked'; }

    // 闪烁
    targetCard.style.transition = "background 0.1s";
    const originalBg = targetCard.style.background;
    targetCard.style.background = (color === 'red') ? "#ffcccc" : (color === '#4caf50' ? "#ccffcc" : "#eee");
    setTimeout(() => { targetCard.style.background = ""; }, 200);

    // 飘字
    const dmg = document.createElement('div');
    dmg.className = 'damage-text';
    dmg.innerText = text;
    dmg.style.position = 'absolute';
    dmg.style.color = color;
    dmg.style.fontSize = '24px';
    dmg.style.fontWeight = 'bold';
    
    // 计算相对于视口的坐标，因为现在添加到了 fixed 的 animation-layer 中
    const rect = targetCard.getBoundingClientRect();
    dmg.style.top = `${rect.top}px`;
    dmg.style.left = `${rect.left + rect.width / 2}px`;
    dmg.style.transform = 'translateX(-50%)';
    
    const animLayer = document.getElementById('animation-layer') || document.body;
    animLayer.appendChild(dmg);
    
    // 动画结束后自动移除由 CSS 处理，但为了DOM清洁可以手动移除
    setTimeout(() => dmg.remove(), 1000);

    // 处理死亡逻辑
    if (targetData && targetData.is_dead) {
        setTimeout(() => {
            targetCard.style.transition = "all 0.5s";
            targetCard.style.opacity = "0";
            targetCard.style.transform = "scale(0.5)";
            setTimeout(() => targetCard.remove(), 500);
        }, 300); // 稍微延迟一点，让玩家看清伤害
    }
}

function wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}