// explore-page.js — Expedition system for explore.php
import { adventureAPI, petAPI } from './api.js';

const root = () => document.getElementById('explore-root');

const DIFF = {
  1: { label: 'Trivial',   cls: 'diff-trivial'   },
  2: { label: 'Easy',      cls: 'diff-easy'       },
  3: { label: 'Medium',    cls: 'diff-medium'     },
  4: { label: 'Hard',      cls: 'diff-hard'       },
  5: { label: 'Legendary', cls: 'diff-legendary'  },
};

let currentPet = null;
let countdown  = null;
let pollTimer  = null;

function fmt(s) {
  s = Math.max(0, Math.floor(s));
  const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), r = s%60;
  if (h > 0) return `${h}h ${String(m).padStart(2,'0')}m`;
  return `${String(m).padStart(2,'0')}:${String(r).padStart(2,'0')}`;
}

function flash(msg, type = 'success') {
  const el = document.getElementById('exp-msg');
  if (!el) return;
  el.textContent = msg; el.className = `flash flash--${type}`; el.style.display = 'block';
  clearTimeout(el._t); el._t = setTimeout(() => el.style.display = 'none', 4000);
}

function clearTimers() {
  if (countdown) { clearInterval(countdown); countdown = null; }
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

// ── Render: expedition list ───────────────────────────────────────────────────
function renderList(exps, pet) {
  clearTimers();
  const cards = exps.map(e => {
    const locked   = pet.level < e.min_level;
    const d        = DIFF[e.difficulty] ?? { label: 'Unknown', cls: 'diff-medium' };
    const strBonus = Math.round((1 + Math.max(0, (pet.base_str - 10)) * 0.03) * 100) - 100;
    return `
      <div class="exp-card ${locked ? 'exp-card--locked' : ''}">
        <div class="exp-icon" id="eicon-${e.id}"></div>
        <div class="exp-body">
          <div class="exp-name">${e.name}</div>
          <div class="exp-desc">${e.description}</div>
          <div class="exp-meta">
            <span class="diff-badge ${d.cls}">${d.label}</span>
            <span>⏱ ${fmt(e.duration_seconds)}</span>
            <span>💎 ${e.crystal_reward_min}–${e.crystal_reward_max}${strBonus > 0 ? ` (+${strBonus}% STR)` : ''}</span>
            <span>✨ ${e.xp_reward} XP</span>
            ${locked ? `<span class="exp-locked">🔒 Lv.${e.min_level} required</span>` : ''}
          </div>
        </div>
        ${locked
          ? `<div class="exp-locked-btn">Locked</div>`
          : `<button class="btn btn-primary exp-go" data-exp="${e.id}" data-pet="${pet.id}">Embark</button>`}
      </div>`;
  }).join('');

  root().innerHTML = `
    <div id="exp-msg" class="flash" style="display:none"></div>
    <p class="card-sub" style="margin-bottom:1rem;">${pet.name} is ready. Choose a destination.</p>
    <div class="exp-list">${cards || '<p>No expeditions found. Run task4_expeditions.sql in phpMyAdmin.</p>'}</div>`;

  // Set icons safely
  exps.forEach(e => {
    const el = document.getElementById(`eicon-${e.id}`);
    if (el) el.textContent = e.icon;
  });

  root().querySelectorAll('.exp-go').forEach(btn => {
    btn.addEventListener('click', async () => {
      const expId = parseInt(btn.dataset.exp);
      const petId = parseInt(btn.dataset.pet);
      btn.disabled = true; btn.textContent = 'Departing…';
      const res = await adventureAPI.start(petId, expId);
      if (res?.success) {
        await init();
      } else {
        flash(res?.message || 'Error', 'error');
        btn.disabled = false; btn.textContent = 'Embark';
      }
    });
  });
}

// ── Render: active countdown ──────────────────────────────────────────────────
function renderActive(adv) {
  clearTimers();
  const target = new Date(adv.end_time).getTime();

  root().innerHTML = `
    <div id="exp-msg" class="flash" style="display:none"></div>
    <div class="adv-active card">
      <div class="adv-icon" id="adv-icon-el"></div>
      <h2 class="card-title" id="adv-name">${adv.expedition_name}</h2>
      <p class="card-sub">${adv.expedition_desc}</p>
      <div class="countdown-label">Returns in</div>
      <div class="countdown" id="adv-cd">—</div>
      <button id="btn-collect" class="btn btn-primary btn-full" disabled
              data-pet="${adv.pet_id}">Waiting for return…</button>
    </div>`;

  const iconEl = document.getElementById('adv-icon-el');
  if (iconEl) iconEl.textContent = adv.expedition_icon;

  const tick = () => {
    const rem = Math.ceil((target - Date.now()) / 1000);
    const cd  = document.getElementById('adv-cd');
    const btn = document.getElementById('btn-collect');
    if (rem <= 0) {
      clearInterval(countdown); countdown = null;
      if (cd)  cd.textContent = 'Ready!';
      if (btn) { btn.disabled = false; btn.textContent = '✦ Collect Rewards ✦'; }
    } else {
      if (cd) cd.textContent = fmt(rem);
    }
  };
  tick();
  countdown = setInterval(tick, 1000);
  pollTimer = setInterval(async () => {
    const r = await adventureAPI.getStatus(adv.pet_id);
    if (r?.success && r.data?.adventure?.status === 'COMPLETED') await init();
  }, 15000);

  document.getElementById('btn-collect').addEventListener('click', onCollect);
}

// ── Render: collect ───────────────────────────────────────────────────────────
function renderCollect(adv) {
  clearTimers();
  root().innerHTML = `
    <div id="exp-msg" class="flash" style="display:none"></div>
    <div class="adv-active card">
      <div class="adv-icon" id="adv-icon-el"></div>
      <h2 class="card-title">${adv.expedition_name} — Complete!</h2>
      <div class="reward-rows">
        <div class="reward-row"><span>💎 Crystals</span><strong>${adv.crystal_reward ?? '?'}</strong></div>
        <div class="reward-row"><span>✨ XP</span><strong>${adv.xp_reward ?? '?'}</strong></div>
        ${adv.exhausted ? `<div class="reward-row warn"><span>⚠ Exhausted</span><strong>-30 hunger</strong></div>` : ''}
      </div>
      <button id="btn-collect" class="btn btn-primary btn-full" data-pet="${adv.pet_id}">
        ✦ Collect Rewards ✦
      </button>
    </div>`;

  const iconEl = document.getElementById('adv-icon-el');
  if (iconEl) iconEl.textContent = adv.expedition_icon;

  document.getElementById('btn-collect').addEventListener('click', onCollect);
}

async function onCollect(e) {
  const petId = parseInt(e.target.dataset.pet);
  e.target.disabled = true; e.target.textContent = 'Collecting…';
  const res = await adventureAPI.collect(petId);
  if (res?.success) {
    // Update crystal display in topbar
    const newBal = res.data.crystal_balance;
    ['stat-crystals','stat-crystals-card'].forEach(id => {
      const el = document.getElementById(id); if (el) el.textContent = newBal;
    });
    flash(res.message, 'success');
    setTimeout(init, 2000);
  } else {
    flash(res?.message || 'Error', 'error');
    e.target.disabled = false; e.target.textContent = '✦ Collect Rewards ✦';
  }
}

// ── Init ──────────────────────────────────────────────────────────────────────
async function init() {
  root().innerHTML = '<div class="loading">Loading expeditions…</div>';
  clearTimers();

  // Get pet first
  const petRes = await petAPI.getMyPet();
  if (!petRes?.success) {
    root().innerHTML = '<div class="card"><p>You need a hatched companion to go on expeditions. <a href="./pets.php">Go to My Pets →</a></p></div>';
    return;
  }

  currentPet = petRes.data.pet;

  if (!currentPet || currentPet.hatch_status !== 'HATCHED') {
    root().innerHTML = '<div class="card"><p>Your companion needs to hatch first. <a href="./pets.php">Go to My Pets →</a></p></div>';
    return;
  }

  // Get adventure status
  let adventure = null;
  try {
    const statusRes = await adventureAPI.getStatus(currentPet.id);
    if (statusRes?.success) adventure = statusRes.data?.adventure ?? null;
  } catch(e) {}

  if (adventure?.status === 'ACTIVE') { renderActive(adventure); return; }
  if (adventure?.status === 'COMPLETED') { renderCollect(adventure); return; }

  // Load expeditions
  try {
    const expRes = await adventureAPI.getExpeditions();
    if (expRes?.success) {
      renderList(expRes.data.expeditions ?? [], currentPet);
    } else {
      root().innerHTML = `<div class="card"><p class="error">Could not load expeditions: ${expRes?.message}</p></div>`;
    }
  } catch(e) {
    root().innerHTML = '<div class="card"><p class="error">Failed to load expeditions.</p></div>';
  }
}

document.addEventListener('DOMContentLoaded', init);
