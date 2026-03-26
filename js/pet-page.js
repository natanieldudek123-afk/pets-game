// pet-page.js — Pet system for pets.php
import { petAPI, shopAPI } from './api.js';

const root = () => document.getElementById('pet-root');

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmt(secs) {
  const s = Math.max(0, Math.floor(secs));
  return `${String(Math.floor(s/60)).padStart(2,'0')}:${String(s%60).padStart(2,'0')}`;
}

function flash(msg, type = 'success') {
  const el = document.getElementById('pet-msg');
  if (!el) return;
  el.textContent = msg;
  el.className = `flash flash--${type}`;
  el.style.display = 'block';
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.style.display = 'none'; }, 3000);
}

function setBar(fillId, labelId, val, max = 100, xp = false) {
  const fill  = document.getElementById(fillId);
  const label = document.getElementById(labelId);
  if (!fill) return;
  const pct = Math.max(0, Math.min(100, (val/max)*100));
  fill.style.width = pct + '%';
  fill.className = 'bar-fill ' + (xp ? 'bar-fill--xp' : pct > 60 ? 'bar-fill--high' : pct > 30 ? 'bar-fill--mid' : 'bar-fill--low');
  if (label) label.textContent = `${val}/${max}`;
}

function xpTier(h, hp) {
  if (h > 75 && hp > 75) return 'Perfect (2x XP)';
  if (h > 75 || hp > 75) return 'Well-cared (1.5x XP)';
  return 'Basic (1x XP)';
}

let countdownInterval = null;
let inventory = [];

// ── Inventory picker ─────────────────────────────────────────────────────────
function openPicker(anchor, type, onPick) {
  document.getElementById('item-picker')?.remove();
  const items = inventory.filter(i => i.type === type && i.quantity > 0);
  const picker = document.createElement('div');
  picker.id = 'item-picker';
  picker.className = 'picker';

  if (!items.length) {
    picker.innerHTML = `<div class="picker-empty">No ${type === 'FOOD' ? 'food' : 'toys'} in inventory.<br><a href="./inventory.php">Go to shop →</a></div>`;
  } else {
    picker.innerHTML = items.map(i =>
      `<button class="picker-item" data-id="${i.item_id}">
        ${i.icon} <span>${i.name}</span> <span class="picker-qty">${i.quantity} left</span>
      </button>`
    ).join('');
  }

  document.body.appendChild(picker);
  const r = anchor.getBoundingClientRect();
  picker.style.top  = (r.bottom + window.scrollY + 4) + 'px';
  picker.style.left = r.left + 'px';

  const dismiss = (e) => { if (!picker.contains(e.target) && e.target !== anchor) { picker.remove(); document.removeEventListener('click', dismiss); }};
  setTimeout(() => document.addEventListener('click', dismiss), 10);
  picker.querySelectorAll('.picker-item').forEach(btn => {
    btn.addEventListener('click', () => { picker.remove(); document.removeEventListener('click', dismiss); onPick(parseInt(btn.dataset.id)); });
  });
}

// ── Renderers ─────────────────────────────────────────────────────────────────
function renderNoPet() {
  const species = ['BEAR','FOX','OWL'];
  const emoji   = {'BEAR':'🐻','FOX':'🦊','OWL':'🦉'};
  root().innerHTML = `
    <div class="card" id="pet-panel">
      <div id="pet-msg" class="flash" style="display:none"></div>
      <h2 class="card-title">No Companion Yet</h2>
      <p class="card-sub">Name your companion and choose a species to begin.</p>
      <div class="form-group"><label>Name</label><input id="pet-name" type="text" maxlength="32" placeholder="e.g. Shadowpaw…"/></div>
      <div class="form-group">
        <label>Species</label>
        <div class="species-row">
          ${species.map((s,i) => `
            <label class="species-opt">
              <input type="radio" name="sp" value="${s}" ${i===1?'checked':''}>
              <span>${emoji[s]} ${s}</span>
            </label>`).join('')}
        </div>
      </div>
      <button id="btn-hatch" class="btn btn-primary btn-full">🥚 Place Egg</button>
    </div>`;
  document.getElementById('btn-hatch').onclick = async () => {
    const name = document.getElementById('pet-name').value.trim();
    const sp   = document.querySelector('input[name="sp"]:checked')?.value;
    if (!name) { flash('Enter a name first.', 'error'); return; }
    const btn = document.getElementById('btn-hatch');
    btn.disabled = true; btn.textContent = 'Placing…';
    const res = await petAPI.startHatch({ name, species: sp, tier: 1 });
    if (res?.success) { await init(); }
    else { flash(res?.message || 'Error', 'error'); btn.disabled = false; btn.textContent = '🥚 Place Egg'; }
  };
}

function renderIncubating(pet, countdown) {
  if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
  const target = pet.hatch_end_time ? new Date(pet.hatch_end_time).getTime() : Date.now() + countdown * 1000;
  root().innerHTML = `
    <div class="card">
      <div id="pet-msg" class="flash" style="display:none"></div>
      <h2 class="card-title">🥚 Incubating: ${pet.name}</h2>
      <p class="card-sub">Your egg is warming up…</p>
      <div class="incubate-block">
        <div class="egg-big" id="egg-el">🥚</div>
        <div class="countdown-label">Hatches in</div>
        <div class="countdown" id="cdisplay">${fmt(countdown ?? 0)}</div>
        <button id="btn-claim" class="btn btn-primary btn-full" disabled data-id="${pet.id}">Waiting…</button>
      </div>
    </div>`;
  const tick = () => {
    const rem = Math.ceil((target - Date.now()) / 1000);
    const d = document.getElementById('cdisplay');
    const b = document.getElementById('btn-claim');
    if (rem <= 0) {
      clearInterval(countdownInterval);
      if (d) d.textContent = '00:00';
      if (b) { b.disabled = false; b.textContent = '✦ Claim Companion ✦'; }
    } else {
      if (d) d.textContent = fmt(rem);
    }
  };
  tick();
  countdownInterval = setInterval(tick, 1000);
  document.getElementById('btn-claim').onclick = async (e) => {
    const id = parseInt(e.target.dataset.id);
    e.target.disabled = true; e.target.textContent = 'Hatching…';
    const res = await petAPI.claimPet(id);
    if (res?.success) { await init(); }
    else { flash(res?.message || 'Not ready yet', 'error'); e.target.disabled = false; e.target.textContent = '✦ Claim Companion ✦'; }
  };
}

function renderHatched(pet, penalties, offline) {
  const SPECIES = { BEAR:'🐻', FOX:'🦊', OWL:'🦉' };
  const xpMax = pet.xp_to_next_level > 0 ? pet.xp_to_next_level : 100;
  root().innerHTML = `
    <div class="card">
      <div id="pet-msg" class="flash" style="display:none"></div>
      <div class="pet-header">
        <span class="pet-emo">${SPECIES[pet.species] ?? '🐾'}</span>
        <div>
          <div class="pet-name">${pet.name}</div>
          <div class="card-sub">${pet.species} · Tier ${pet.tier} · Lv.${pet.level}</div>
        </div>
        <div class="lvl-badge">${pet.level}<br><small>LVL</small></div>
      </div>

      <div class="bars">
        <div class="bar-row">
          <div class="bar-labels"><span>🍖 Hunger ${penalties?.hunger_penalty_active ? '<span class="badge-warn">⚠ Low</span>' : ''}</span><span id="lbl-hunger">${pet.current_hunger}/100</span></div>
          <div class="bar-track"><div class="bar-fill" id="bar-hunger" style="width:0%"></div></div>
        </div>
        <div class="bar-row">
          <div class="bar-labels"><span>🎵 Happiness ${penalties?.happiness_penalty_active ? '<span class="badge-warn">⚠ Low</span>' : ''}</span><span id="lbl-happiness">${pet.current_happiness}/100</span></div>
          <div class="bar-track"><div class="bar-fill" id="bar-happiness" style="width:0%"></div></div>
        </div>
        <div class="bar-row">
          <div class="bar-labels"><span>✨ XP</span><span id="lbl-xp">${pet.current_xp}/${xpMax}</span></div>
          <div class="bar-track"><div class="bar-fill bar-fill--xp" id="bar-xp" style="width:0%"></div></div>
          <div class="xp-status" id="xp-status">Status: ${xpTier(pet.current_hunger, pet.current_happiness)}</div>
        </div>
      </div>

      <div class="stats-row">
        <div class="stat-pip" title="Strength">⚔ <strong>${pet.base_str}</strong> STR</div>
        <div class="stat-pip" title="Agility">💨 <strong>${pet.base_agi}</strong> AGI</div>
        <div class="stat-pip" title="Intellect">✨ <strong>${pet.base_int}</strong> INT</div>
        <div class="stat-pip" title="Vitality">❤ <strong>${pet.base_vit}</strong> VIT</div>
      </div>

      <div class="action-row">
        <button id="btn-feed" class="btn btn-primary" data-id="${pet.id}">🍖 Feed</button>
        <button id="btn-play" class="btn btn-ghost"   data-id="${pet.id}">🎵 Play</button>
      </div>
    </div>`;

  requestAnimationFrame(() => {
    setBar('bar-hunger',    'lbl-hunger',    pet.current_hunger,    100);
    setBar('bar-happiness', 'lbl-happiness', pet.current_happiness, 100);
    setBar('bar-xp',        'lbl-xp',        pet.current_xp,        xpMax, true);
  });

  if (offline?.missed_ticks > 0) flash(`Welcome back! ${offline.missed_ticks} tick(s) applied.`, 'info');

  document.getElementById('btn-feed').onclick = async (e) => {
    const id = parseInt(e.target.dataset.id);
    openPicker(e.target, 'FOOD', async (itemId) => {
      e.target.disabled = true;
      const res = await petAPI.feed(id, itemId);
      e.target.disabled = false;
      if (res?.success) {
        const p = res.data.pet;
        setBar('bar-hunger', 'lbl-hunger', p.current_hunger, 100);
        const xs = document.getElementById('xp-status');
        if (xs) xs.textContent = 'Status: ' + xpTier(p.current_hunger, p.current_happiness);
        flash(`Fed with ${res.data.item_used.name} (+${res.data.hunger_restored} hunger)`, 'success');
        // refresh inventory
        const inv = await shopAPI.getInventory();
        if (inv?.success) inventory = inv.data.inventory;
      } else { flash(res?.message || 'Error', 'error'); }
    });
  };

  document.getElementById('btn-play').onclick = async (e) => {
    const id = parseInt(e.target.dataset.id);
    openPicker(e.target, 'TOY', async (itemId) => {
      e.target.disabled = true;
      const res = await petAPI.play(id, itemId);
      e.target.disabled = false;
      if (res?.success) {
        const p = res.data.pet;
        setBar('bar-happiness', 'lbl-happiness', p.current_happiness, 100);
        const xs = document.getElementById('xp-status');
        if (xs) xs.textContent = 'Status: ' + xpTier(p.current_hunger, p.current_happiness);
        flash(`Played with ${res.data.item_used.name} (+${res.data.happiness_restored} happiness)`, 'success');
        const inv = await shopAPI.getInventory();
        if (inv?.success) inventory = inv.data.inventory;
      } else { flash(res?.message || 'Error', 'error'); }
    });
  };
}

// ── Init ─────────────────────────────────────────────────────────────────────
async function init() {
  root().innerHTML = '<div class="loading">Loading companion…</div>';

  // Load inventory and pet in parallel
  const [petRes, invRes] = await Promise.all([
    petAPI.getMyPet(),
    shopAPI.getInventory(),
  ]);

  if (invRes?.success) inventory = invRes.data.inventory ?? [];

  if (!petRes?.success) {
    if (petRes?.message?.toLowerCase().includes('no active')) {
      renderNoPet();
    } else {
      root().innerHTML = `<div class="card"><p class="error">Error: ${petRes?.message}</p></div>`;
    }
    return;
  }

  const { pet, needs_penalties, hatch_countdown, offline_progress } = petRes.data;

  if (pet.hatch_status === 'INCUBATING') {
    renderIncubating(pet, hatch_countdown);
  } else {
    renderHatched(pet, needs_penalties, offline_progress);
  }
}

document.addEventListener('DOMContentLoaded', init);
