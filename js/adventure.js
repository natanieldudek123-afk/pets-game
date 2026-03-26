// =============================================================================
// js/adventure.js — Expedition & Adventure System
// =============================================================================

import { adventureAPI } from './api.js';

let activePet          = null;
let countdownInterval  = null;
let statusPollInterval = null;

const STATUS_POLL_MS = 15_000;

const DIFFICULTY = {
  1: { label: 'Trivial',   css: 'diff--trivial'   },
  2: { label: 'Easy',      css: 'diff--easy'       },
  3: { label: 'Medium',    css: 'diff--medium'     },
  4: { label: 'Hard',      css: 'diff--hard'       },
  5: { label: 'Legendary', css: 'diff--legendary'  },
};

const root = () => document.getElementById('adventure-section-root');

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------
function clearTimers() {
  if (countdownInterval)  { clearInterval(countdownInterval);  countdownInterval  = null; }
  if (statusPollInterval) { clearInterval(statusPollInterval); statusPollInterval = null; }
}

function fmt(secs) {
  const s = Math.max(0, Math.floor(secs));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  if (h > 0) return `${h}h ${String(m).padStart(2,'0')}m`;
  return `${String(m).padStart(2,'0')}:${String(r).padStart(2,'0')}`;
}

function flashAdv(text, type = 'success') {
  const el = document.getElementById('adv-msg');
  if (!el) return;
  el.textContent = text;
  el.className   = `adv-msg adv-msg--${type}`;
  el.style.cssText = 'display:block;opacity:1;';
  clearTimeout(el._t1); clearTimeout(el._t2);
  el._t1 = setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .5s'; }, 3500);
  el._t2 = setTimeout(() => { el.style.display = 'none'; }, 4100);
}

function setBtnLoading(id, loading, label = '…') {
  const btn = document.getElementById(id);
  if (!btn) return;
  btn.disabled = loading;
  if (loading) { btn.dataset.orig = btn.textContent; btn.textContent = label; }
  else         { btn.textContent = btn.dataset.orig ?? btn.textContent; }
}

// ---------------------------------------------------------------------------
// Render helpers
// ---------------------------------------------------------------------------
function renderSkeleton() {
  if (!root()) return;
  root().innerHTML = `
    <div class="adv-panel panel pet-panel--skeleton">
      <div class="skeleton-line skeleton-line--title"></div>
      <div class="skeleton-line"></div>
      <div class="skeleton-line skeleton-line--short"></div>
    </div>`;
}

function renderNoPet() {
  if (!root()) return;
  root().innerHTML = `
    <div class="adv-panel panel">
      <div class="adv-empty">
        <div class="adv-empty__icon">🗺</div>
        <p>Hatch and raise a companion before embarking on expeditions.</p>
      </div>
    </div>`;
}

function renderError(msg) {
  if (!root()) return;
  root().innerHTML = `
    <div class="adv-panel panel">
      <p style="color:var(--ember);font-family:var(--font-heading);font-size:.85rem;">⚠ ${msg}</p>
    </div>`;
}

// ---------------------------------------------------------------------------
// Render: expedition list
// ---------------------------------------------------------------------------
function renderExpeditionList(expeditions, pet) {
  clearTimers();
  if (!root()) return;

  if (!expeditions || expeditions.length === 0) {
    root().innerHTML = `
      <div class="adv-panel panel">
        <div class="adv-empty">
          <div class="adv-empty__icon">🗺</div>
          <p>No expeditions found.</p>
          <p style="font-size:.75rem;margin-top:.5rem;color:var(--text-faint);">
            Make sure <strong>task4_expeditions.sql</strong> has been run in phpMyAdmin.
          </p>
        </div>
      </div>`;
    return;
  }

  const cards = expeditions.map(exp => {
    const locked      = pet.level < exp.min_level;
    const diff        = DIFFICULTY[exp.difficulty] ?? { label: 'Unknown', css: 'diff--medium' };
    const strBonus    = Math.round((1 + Math.max(0, (pet.base_str - 10)) * 0.03) * 100) - 100;

    return `
      <div class="exp-card ${locked ? 'exp-card--locked' : ''}">
        <div class="exp-card__icon" data-icon="${exp.id}"></div>
        <div class="exp-card__body">
          <div class="exp-card__name">${exp.name}</div>
          <div class="exp-card__desc">${exp.description}</div>
          <div class="exp-card__meta">
            <span class="exp-badge ${diff.css}">${diff.label}</span>
            <span class="exp-meta-item">Duration: ${fmt(exp.duration_seconds)}</span>
            <span class="exp-meta-item">Crystals: ${exp.crystal_reward_min}-${exp.crystal_reward_max}${strBonus > 0 ? ` (+${strBonus}% STR)` : ''}</span>
            <span class="exp-meta-item">XP: ${exp.xp_reward}</span>
            ${locked ? `<span class="exp-lock">Lv.${exp.min_level} required</span>` : ''}
          </div>
        </div>
        ${!locked
          ? `<button class="btn btn-primary exp-card__go"
                     id="btn-exp-${exp.id}"
                     data-exp-id="${exp.id}"
                     data-exp-name="${exp.name}"
                     data-pet-id="${pet.id}">Embark</button>`
          : `<div class="exp-card__locked-btn">Locked</div>`}
      </div>`;
  }).join('');

  root().innerHTML = `
    <div class="adv-panel panel" id="adv-panel">
      <div id="adv-msg" class="adv-msg" style="display:none"></div>
      <div class="adv-panel__header">
        <p class="section-subtitle">${pet.name} is rested and ready. Choose a destination.</p>
      </div>
      <div class="exp-list">${cards}</div>
    </div>`;

  // Set icons via textContent (avoids browser treating emoji as resource URLs)
  root().querySelectorAll('[data-icon]').forEach(el => {
    const exp = expeditions.find(e => e.id === parseInt(el.dataset.icon, 10));
    if (exp) el.textContent = exp.icon;
  });

  root().querySelectorAll('.exp-card__go').forEach(btn => {
    btn.addEventListener('click', () => onEmbark(
      parseInt(btn.dataset.petId, 10),
      parseInt(btn.dataset.expId, 10),
      btn.dataset.expName
    ));
  });
}

// ---------------------------------------------------------------------------
// Render: active countdown
// ---------------------------------------------------------------------------
function renderAdventuring(adventure) {
  clearTimers();
  if (!root()) return;

  const targetMs = new Date(adventure.end_time).getTime();

  root().innerHTML = `
    <div class="adv-panel panel" id="adv-panel">
      <div id="adv-msg" class="adv-msg" style="display:none"></div>
      <div class="adv-panel__header">
        <p class="section-subtitle"><span id="adv-exp-icon"></span> <strong>${adventure.expedition_name}</strong></p>
      </div>
      <div class="adv-progress">
        <div class="adv-progress__icon" id="adv-icon-anim">${adventure.expedition_icon}</div>
        <div class="adv-countdown-block">
          <div class="adv-countdown-label">Returns in</div>
          <div class="adv-countdown" id="adv-countdown">—</div>
        </div>
        <div class="adv-progress__desc">${adventure.expedition_desc}</div>
        <button id="btn-collect" class="btn btn-primary btn-full" disabled
                data-pet-id="${adventure.pet_id}">Waiting for return…</button>
      </div>
    </div>`;

  // Set icon safely
  const iconEl = root().querySelector('#adv-exp-icon');
  if (iconEl) iconEl.textContent = adventure.expedition_icon;

  function tick() {
    const rem  = Math.ceil((targetMs - Date.now()) / 1000);
    const disp = document.getElementById('adv-countdown');
    const btn  = document.getElementById('btn-collect');
    const icon = document.getElementById('adv-icon-anim');
    if (rem <= 0) {
      clearInterval(countdownInterval); countdownInterval = null;
      if (disp) disp.textContent = 'Ready!';
      if (btn)  { btn.disabled = false; btn.textContent = '✦ Collect Rewards ✦'; }
      if (icon) icon.classList.add('adv-icon--ready');
    } else {
      if (disp) disp.textContent = fmt(rem);
      if (icon && rem <= 60) icon.classList.add('adv-icon--soon');
    }
  }

  tick();
  countdownInterval  = setInterval(tick, 1000);
  statusPollInterval = setInterval(onStatusPoll, STATUS_POLL_MS);
  document.getElementById('btn-collect')?.addEventListener('click', onCollect);
}

// ---------------------------------------------------------------------------
// Render: ready to collect
// ---------------------------------------------------------------------------
function renderReadyToCollect(adventure) {
  clearTimers();
  if (!root()) return;

  root().innerHTML = `
    <div class="adv-panel panel" id="adv-panel">
      <div id="adv-msg" class="adv-msg" style="display:none"></div>
      <div class="adv-panel__header">
        <p class="section-subtitle"><span id="adv-exp-icon"></span> ${adventure.expedition_name} — Complete!</p>
      </div>
      <div class="adv-reward-preview">
        <div class="adv-reward-preview__icon" id="adv-reward-icon"></div>
        <div class="adv-reward-preview__rows">
          <div class="adv-reward-row">
            <span class="adv-reward-label">💎 Crystals</span>
            <span class="adv-reward-value">${adventure.crystal_reward ?? '?'}</span>
          </div>
          <div class="adv-reward-row">
            <span class="adv-reward-label">✨ XP</span>
            <span class="adv-reward-value">${adventure.xp_reward ?? '?'}</span>
          </div>
          ${adventure.exhausted ? `
          <div class="adv-reward-row adv-reward-row--warn">
            <span class="adv-reward-label">⚠ Exhausted</span>
            <span class="adv-reward-value">-30 hunger</span>
          </div>` : ''}
        </div>
      </div>
      <button id="btn-collect" class="btn btn-primary btn-full"
              data-pet-id="${adventure.pet_id}">✦ Collect Rewards ✦</button>
    </div>`;

  // Set icons safely via textContent
  const si = root().querySelector('#adv-exp-icon');
  const ri = root().querySelector('#adv-reward-icon');
  if (si) si.textContent = adventure.expedition_icon;
  if (ri) ri.textContent = adventure.expedition_icon;

  document.getElementById('btn-collect')?.addEventListener('click', onCollect);
}

// ---------------------------------------------------------------------------
// Event handlers
// ---------------------------------------------------------------------------
async function onEmbark(petId, expeditionId, expeditionName) {
  setBtnLoading(`btn-exp-${expeditionId}`, true, 'Departing…');
  const res = await adventureAPI.start(petId, expeditionId);
  setBtnLoading(`btn-exp-${expeditionId}`, false);
  if (!res) return;

  if (res.success) {
    const statusRes = await adventureAPI.getStatus(petId);
    if (statusRes?.success && statusRes.data?.adventure) {
      renderAdventuring(statusRes.data.adventure);
    }
  } else {
    flashAdv(res.message, 'error');
  }
}

async function onCollect(e) {
  const petId = parseInt(e.currentTarget.dataset.petId, 10);
  setBtnLoading('btn-collect', true, 'Collecting…');
  const res = await adventureAPI.collect(petId);
  setBtnLoading('btn-collect', false);
  if (!res) return;

  if (res.success) {
    const newBal = res.data.crystal_balance;
    ['stat-crystals', 'stat-crystals-card'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.textContent = newBal;
    });
    flashAdv(res.message, 'success');
    document.dispatchEvent(new CustomEvent('adventure:rewarded', { detail: res.data }));
    setTimeout(() => initAdventure(activePet), 2200);
  } else {
    flashAdv(res.message, 'error');
  }
}

async function onStatusPoll() {
  if (!activePet?.id) return;
  const res = await adventureAPI.getStatus(activePet.id);
  if (res?.success && res.data?.adventure?.status === 'COMPLETED') {
    renderReadyToCollect(res.data.adventure);
  }
}

// ---------------------------------------------------------------------------
// PUBLIC
// ---------------------------------------------------------------------------
export async function initAdventure(pet) {
  const el = root();
  if (!el) return; // mount point missing — bail silently
  

  clearTimers();
  activePet = pet;

  // No hatched pet → show prompt
  if (!pet || pet.hatch_status !== 'HATCHED') {
    renderNoPet();
    return;
  }

  renderSkeleton();

  // Fetch expeditions and status independently so one failure doesn't kill both
  let expRes    = null;
  let statusRes = null;

  try { expRes    = await adventureAPI.getExpeditions(); } catch (e) {}
  try { statusRes = await adventureAPI.getStatus(pet.id); } catch (e) {}

  const adventure = (statusRes?.success) ? (statusRes.data?.adventure ?? null) : null;

  if (adventure?.status === 'ACTIVE') {
    renderAdventuring(adventure);
    return;
  }

  if (adventure?.status === 'COMPLETED') {
    renderReadyToCollect(adventure);
    return;
  }

  if (expRes?.success) {
    renderExpeditionList(expRes.data.expeditions ?? [], pet);
  } else {
    renderError('Could not load expeditions. Check server logs.');
  }
}

export function destroyAdventure() {
  clearTimers();
  activePet = null;
}
