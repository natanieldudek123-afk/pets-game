// =============================================================================
// js/pet.js  — Pet System UI state machine  (Task #3 update)
//
// Changes:
//   - Feed/Play buttons now open an item picker (popover) showing owned items.
//   - onFeed/onPlay receive the chosen item_id before calling the API.
//   - Inventory changes are broadcast via 'inventory:updated' event from shop.js.
//   - updateHatchedBars also updates item qty badges in the picker if open.
// =============================================================================

import { petAPI }              from './api.js';
import { getInventory, refreshInventory } from './shop.js';

let currentPet        = null;
let countdownInterval = null;
let pollInterval      = null;

const POLL_MS = 30_000;

const SPECIES = {
  BEAR: { emoji: '🐻', label: 'Bear',  flavour: 'A stalwart guardian of ancient forests.' },
  FOX:  { emoji: '🦊', label: 'Fox',   flavour: 'Swift and cunning, touched by moonlight.' },
  OWL:  { emoji: '🦉', label: 'Owl',   flavour: 'Keeper of forgotten arcane knowledge.' },
};

// ---------------------------------------------------------------------------
// XP tier helper — mirrors TickEngine thresholds exactly
// Returns { label, multiplier, cssClass } for the current pet state
// ---------------------------------------------------------------------------
function getXpTier(hunger, happiness) {
  const above = (v) => v > 75;
  if (above(hunger) && above(happiness)) {
    return { label: 'Perfect (2x XP)',    multiplier: '2x',   cssClass: 'xp-tier--perfect' };
  }
  if (above(hunger) || above(happiness)) {
    return { label: 'Well-cared (1.5x XP)', multiplier: '1.5x', cssClass: 'xp-tier--partial' };
  }
  return   { label: 'Basic (1x XP)',       multiplier: '1x',   cssClass: 'xp-tier--base'    };
}

const root = () => document.getElementById('pet-section-root');

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------
function clearTimers() {
  if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
  if (pollInterval)       { clearInterval(pollInterval);      pollInterval      = null; }
}

function fmt(secs) {
  const s = Math.max(0, Math.floor(secs));
  return `${String(Math.floor(s / 60)).padStart(2,'0')}:${String(s % 60).padStart(2,'0')}`;
}

function flash(text, type = 'success') {
  const el = document.getElementById('pet-msg');
  if (!el) return;
  el.textContent = text;
  el.className   = `pet-msg pet-msg--${type}`;
  el.style.cssText = 'display:block;opacity:1;transition:opacity .5s ease;';
  clearTimeout(el._t1); clearTimeout(el._t2);
  el._t1 = setTimeout(() => { el.style.opacity = '0'; }, 2400);
  el._t2 = setTimeout(() => { el.style.display = 'none'; }, 2950);
}

function setBar(fillId, labelId, value, max = 100, isXp = false) {
  const fill  = document.getElementById(fillId);
  const label = document.getElementById(labelId);
  if (!fill) return;
  const pct = Math.max(0, Math.min(100, (value / max) * 100));
  fill.style.width = `${pct}%`;
  fill.setAttribute('aria-valuenow', value);
  if (isXp) {
    fill.className = 'bar-fill bar-fill--xp';
  } else {
    fill.className = 'bar-fill ' +
      (pct > 60 ? 'bar-fill--high' : pct > 30 ? 'bar-fill--mid' : 'bar-fill--low');
  }
  if (label) label.textContent = `${value}/${max}`;
}

function syncBadge(spanId, isLow, title) {
  const span = document.getElementById(spanId);
  if (!span) return;
  const existing = span.querySelector('.penalty-badge');
  if (isLow && !existing) {
    span.insertAdjacentHTML('beforeend',
      `<span class="penalty-badge" title="${title}">⚠ Low</span>`);
  } else if (!isLow && existing) {
    existing.remove();
  }
}

function setBtnLoading(id, loading, label = '…') {
  const btn = document.getElementById(id);
  if (!btn) return;
  btn.disabled = loading;
  if (loading) { btn.dataset.orig = btn.textContent; btn.textContent = label; }
  else         { btn.textContent = btn.dataset.orig ?? btn.textContent; }
}

// ---------------------------------------------------------------------------
// Item picker popover — shown when Feed or Play is pressed
// ---------------------------------------------------------------------------
function openItemPicker(anchorBtn, itemType, petId, onPick) {
  // Remove any existing picker
  document.getElementById('item-picker')?.remove();

  const items = getInventory().filter(i => i.type === itemType && i.quantity > 0);

  const picker = document.createElement('div');
  picker.id        = 'item-picker';
  picker.className = 'item-picker';
  picker.setAttribute('role', 'listbox');
  picker.setAttribute('aria-label', `Choose a ${itemType === 'FOOD' ? 'food' : 'toy'}`);

  if (!items.length) {
    picker.innerHTML = `
      <div class="item-picker__empty">
        No ${itemType === 'FOOD' ? 'food' : 'toys'} in inventory.<br>
        <a href="#" id="picker-shop-link">Visit the shop →</a>
      </div>`;
  } else {
    picker.innerHTML = `
      <div class="item-picker__title">${itemType === 'FOOD' ? 'Choose food' : 'Choose toy'}</div>
      ${items.map(i => `
        <button class="item-picker__item" data-item-id="${i.item_id}" role="option">
          <span class="item-picker__icon">${i.icon}</span>
          <span class="item-picker__name">${i.name}</span>
          <span class="item-picker__meta">+${i.power} &nbsp;·&nbsp; ${i.quantity} left</span>
        </button>`).join('')}`;
  }

  // Position near the anchor button
  document.body.appendChild(picker);
  const rect = anchorBtn.getBoundingClientRect();
  picker.style.top  = `${rect.bottom + window.scrollY + 6}px`;
  picker.style.left = `${rect.left  + window.scrollX}px`;

  // Dismiss on outside click
  const dismiss = (e) => {
    if (!picker.contains(e.target) && e.target !== anchorBtn) {
      picker.remove();
      document.removeEventListener('click', dismiss);
    }
  };
  setTimeout(() => document.addEventListener('click', dismiss), 10);

  picker.querySelector('#picker-shop-link')?.addEventListener('click', e => {
    e.preventDefault();
    picker.remove();
    document.getElementById('btn-open-shop')?.click();
  });

  picker.querySelectorAll('.item-picker__item').forEach(btn => {
    btn.addEventListener('click', () => {
      picker.remove();
      document.removeEventListener('click', dismiss);
      onPick(parseInt(btn.dataset.itemId, 10));
    });
  });
}

// ============================================================================
// STATE 1 — No Pet
// ============================================================================
function renderNoPet() {
  clearTimers();
  root().innerHTML = `
    <div class="pet-panel panel" id="pet-panel">
      <div id="pet-msg" class="pet-msg" style="display:none"></div>
      <div class="pet-panel__header">
        <h2 class="section-title">Your Companion</h2>
        <p class="section-subtitle">No companion yet. Place an egg to begin your bond.</p>
      </div>
      <div class="hatch-form">
        <div class="hatch-form__egg">🥚</div>
        <div class="form-group">
          <label for="pet-name">Name Your Companion</label>
          <input id="pet-name" type="text" placeholder="e.g. Emberclaw, Duskwing…"
                 maxlength="32" autocomplete="off" />
        </div>
        <div class="form-group">
          <label>Choose a Species</label>
          <div class="species-picker" role="radiogroup">
            ${Object.entries(SPECIES).map(([k, m]) => `
              <label class="species-option" title="${m.flavour}">
                <input type="radio" name="species" value="${k}" ${k === 'FOX' ? 'checked' : ''} />
                <span class="species-option__inner">
                  <span class="species-option__emoji">${m.emoji}</span>
                  <span class="species-option__label">${m.label}</span>
                </span>
              </label>`).join('')}
          </div>
        </div>
        <button id="btn-hatch" class="btn btn-primary btn-full">Place Egg in Incubator</button>
      </div>
    </div>`;

  document.getElementById('btn-hatch').addEventListener('click', onStartHatch);
}

// ============================================================================
// STATE 2 — Incubating
// ============================================================================
function renderIncubating(pet, countdown) {
  clearTimers();
  const m = SPECIES[pet.species] ?? { emoji: '🥚', label: pet.species };

  root().innerHTML = `
    <div class="pet-panel panel" id="pet-panel">
      <div id="pet-msg" class="pet-msg" style="display:none"></div>
      <div class="pet-panel__header">
        <h2 class="section-title">Incubating</h2>
        <p class="section-subtitle">${m.emoji} <em>${pet.name}</em> — ${m.label}, Tier ${pet.tier}</p>
      </div>
      <div class="incubating-wrap">
        <div class="egg-anim" id="egg-el">🥚</div>
        <div class="countdown-block">
          <div class="countdown-label">Hatches in</div>
          <div class="countdown-timer" id="countdown" aria-live="polite">${fmt(countdown ?? 0)}</div>
        </div>
        <button id="btn-claim" class="btn btn-primary btn-full" disabled
                data-pet-id="${pet.id}">Egg is still incubating…</button>
      </div>
    </div>`;

  const targetMs = pet.hatch_end_time
    ? new Date(pet.hatch_end_time).getTime()
    : Date.now() + (countdown ?? 0) * 1000;

  function tick() {
    const rem  = Math.ceil((targetMs - Date.now()) / 1000);
    const disp = document.getElementById('countdown');
    const btn  = document.getElementById('btn-claim');
    const egg  = document.getElementById('egg-el');
    if (rem <= 0) {
      clearInterval(countdownInterval); countdownInterval = null;
      if (disp) disp.textContent = '00:00';
      if (btn)  { btn.disabled = false; btn.textContent = '✦ Claim Your Companion ✦'; }
      if (egg)  egg.classList.add('egg--ready');
    } else {
      if (disp) disp.textContent = fmt(rem);
      if (egg && rem <= 30) egg.classList.add('egg--soon');
    }
  }

  tick();
  countdownInterval = setInterval(tick, 1000);
  document.getElementById('btn-claim').addEventListener('click', onClaimPet);
}

// ============================================================================
// STATE 3 — Hatched
// ============================================================================
function renderHatched(pet, penalties, offline) {
  clearTimers();
  const m      = SPECIES[pet.species] ?? { emoji: '🐾', label: pet.species };
  const xpMax  = pet.xp_to_next_level > 0 ? pet.xp_to_next_level : 100;

  root().innerHTML = `
    <div class="pet-panel panel" id="pet-panel">
      <div id="pet-msg" class="pet-msg" style="display:none"></div>

      <div class="pet-header-row">
        <div class="pet-avatar">${m.emoji}</div>
        <div class="pet-identity">
          <h2 class="pet-name">${pet.name}</h2>
          <p class="pet-meta">${m.label} &nbsp;·&nbsp; Tier ${pet.tier} &nbsp;·&nbsp; Lv.&nbsp;<span id="pet-level-val">${pet.level}</span></p>
        </div>
        <div class="pet-level-badge">
          <span class="pet-level-badge__num" id="pet-level-badge-num">${pet.level}</span>
          <span class="pet-level-badge__lbl">LVL</span>
        </div>
      </div>

      <div class="needs-section">
        <div class="need-row">
          <div class="need-row__labels">
            <span class="need-row__name" id="span-hunger">🍖 Hunger
              ${penalties?.hunger_penalty_active ? '<span class="penalty-badge" title="Combat stats penalised">⚠ Low</span>' : ''}
            </span>
            <span class="need-row__value" id="bar-hunger-label">${pet.current_hunger}/100</span>
          </div>
          <div class="stat-bar" role="progressbar" aria-valuenow="${pet.current_hunger}" aria-valuemin="0" aria-valuemax="100">
            <div class="bar-fill" id="bar-hunger" style="width:0%"></div>
          </div>
        </div>

        <div class="need-row">
          <div class="need-row__labels">
            <span class="need-row__name" id="span-happiness">🎵 Happiness
              ${penalties?.happiness_penalty_active ? '<span class="penalty-badge" title="XP gain penalised">⚠ Low</span>' : ''}
            </span>
            <span class="need-row__value" id="bar-happiness-label">${pet.current_happiness}/100</span>
          </div>
          <div class="stat-bar" role="progressbar" aria-valuenow="${pet.current_happiness}" aria-valuemin="0" aria-valuemax="100">
            <div class="bar-fill" id="bar-happiness" style="width:0%"></div>
          </div>
        </div>

        <div class="need-row">
          <div class="need-row__labels">
            <span class="need-row__name">✨ Experience</span>
            <span class="need-row__value" id="bar-xp-label">${pet.current_xp}/${xpMax}</span>
          </div>
          <div class="stat-bar" role="progressbar" aria-valuenow="${pet.current_xp}" aria-valuemin="0" aria-valuemax="${xpMax}">
            <div class="bar-fill bar-fill--xp" id="bar-xp" style="width:0%"></div>
          </div>
          <p class="xp-hint" id="xp-hint"></p>
        </div>
      </div>

      <div class="base-stats-strip">
        <div class="base-stat" title="Strength"><span class="base-stat__icon">⚔</span><span class="base-stat__label">STR</span><span class="base-stat__val">${pet.base_str}</span></div>
        <div class="base-stat" title="Agility"> <span class="base-stat__icon">💨</span><span class="base-stat__label">AGI</span><span class="base-stat__val">${pet.base_agi}</span></div>
        <div class="base-stat" title="Intellect"><span class="base-stat__icon">✨</span><span class="base-stat__label">INT</span><span class="base-stat__val">${pet.base_int}</span></div>
        <div class="base-stat" title="Vitality"> <span class="base-stat__icon">❤</span><span class="base-stat__label">VIT</span><span class="base-stat__val">${pet.base_vit}</span></div>
      </div>

      <div class="pet-actions">
        <button id="btn-feed" class="btn btn-primary" data-pet-id="${pet.id}">🍖 Feed</button>
        <button id="btn-play" class="btn btn-ghost"   data-pet-id="${pet.id}">🎵 Play</button>
      </div>
    </div>`;

  requestAnimationFrame(() => {
    setBar('bar-hunger',    'bar-hunger-label',    pet.current_hunger,    100);
    setBar('bar-happiness', 'bar-happiness-label', pet.current_happiness, 100);
    setBar('bar-xp',        'bar-xp-label',        pet.current_xp,        xpMax, true);
    // Set XP tier hint immediately on first render
    const hint = document.getElementById('xp-hint');
    if (hint) {
      const tier = getXpTier(pet.current_hunger, pet.current_happiness);
      hint.textContent = `Status: ${tier.label}`;
      hint.className   = `xp-hint ${tier.cssClass}`;
    }
  });

  if (offline?.missed_ticks > 0) {
    flash(`Welcome back! ${offline.missed_ticks} tick(s) applied while you were away.`, 'info');
  }

  document.getElementById('btn-feed').addEventListener('click', onFeedClick);
  document.getElementById('btn-play').addEventListener('click', onPlayClick);

  pollInterval = setInterval(onPollRefresh, POLL_MS);
}

// ---------------------------------------------------------------------------
// In-place bar/level update (no re-render)
// ---------------------------------------------------------------------------
function updateHatchedBars(pet) {
  const xpMax = pet.xp_to_next_level > 0 ? pet.xp_to_next_level : 100;
  setBar('bar-hunger',    'bar-hunger-label',    pet.current_hunger,    100);
  setBar('bar-happiness', 'bar-happiness-label', pet.current_happiness, 100);
  setBar('bar-xp',        'bar-xp-label',        pet.current_xp,        xpMax, true);
  syncBadge('span-hunger',    pet.current_hunger    < 30, 'Combat stats penalised');
  syncBadge('span-happiness', pet.current_happiness < 30, 'XP gain penalised');

  const lvlVal   = document.getElementById('pet-level-val');
  const lvlBadge = document.getElementById('pet-level-badge-num');
  if (lvlVal   && lvlVal.textContent   !== String(pet.level)) lvlVal.textContent   = pet.level;
  if (lvlBadge && lvlBadge.textContent !== String(pet.level)) lvlBadge.textContent = pet.level;

  const hint = document.getElementById('xp-hint');
  if (hint) {
    const tier = getXpTier(pet.current_hunger, pet.current_happiness);
    hint.textContent = `Status: ${tier.label}`;
    hint.className   = `xp-hint ${tier.cssClass}`;
  }
}

// ============================================================================
// EVENT HANDLERS
// ============================================================================

async function onStartHatch() {
  const name    = document.getElementById('pet-name')?.value.trim();
  const species = document.querySelector('input[name="species"]:checked')?.value;
  if (!name) { flash('Please give your companion a name first.', 'error'); return; }

  setBtnLoading('btn-hatch', true, 'Placing egg…');
  const res = await petAPI.startHatch({ name, species, tier: 1 });
  setBtnLoading('btn-hatch', false);
  if (!res) return;

  if (res.success) {
    currentPet = res.data;
    renderIncubating(currentPet, res.data.hatch_duration_seconds);
  } else {
    flash(res.errors ? Object.values(res.errors).flat().join(' ') : res.message, 'error');
  }
}

async function onClaimPet(e) {
  const petId = parseInt(e.currentTarget.dataset.petId, 10);
  setBtnLoading('btn-claim', true, 'Hatching…');
  const res = await petAPI.claimPet(petId);
  setBtnLoading('btn-claim', false);
  if (!res) return;

  if (res.success) {
    currentPet = res.data.pet;
    renderHatched(currentPet, null, null);
    flash(`${currentPet.name} has hatched! Welcome your new companion. 🎉`, 'success');
  } else if (res.message?.includes('already hatched')) {
    const refetch = await petAPI.getPetById(petId);
    if (refetch?.success) {
      const { pet, needs_penalties, offline_progress } = refetch.data;
      currentPet = pet;
      renderHatched(pet, needs_penalties, offline_progress);
    }
  } else {
    const rem = res.errors?.remaining_seconds;
    flash(rem ? `${res.message} (${fmt(rem)} remaining)` : res.message, 'error');
  }
}

function onFeedClick(e) {
  const petId = parseInt(e.currentTarget.dataset.petId, 10);
  openItemPicker(e.currentTarget, 'FOOD', petId, (itemId) => doFeed(petId, itemId));
}

function onPlayClick(e) {
  const petId = parseInt(e.currentTarget.dataset.petId, 10);
  openItemPicker(e.currentTarget, 'TOY', petId, (itemId) => doPlay(petId, itemId));
}

async function doFeed(petId, itemId) {
  setBtnLoading('btn-feed', true, '…');
  const res = await petAPI.feed(petId, itemId);
  setBtnLoading('btn-feed', false);
  if (!res) return;

  if (res.success) {
    const { pet, hunger_restored, item_used, item_qty_remaining } = res.data;
    updateHatchedBars(pet);
    currentPet = pet;
    flash(`${pet.name} ate the ${item_used.icon} ${item_used.name} (+${hunger_restored} hunger). ${item_qty_remaining} left.`, 'success');
    await refreshInventory();
  } else {
    flash(res.message, 'error');
  }
}

async function doPlay(petId, itemId) {
  setBtnLoading('btn-play', true, '…');
  const res = await petAPI.play(petId, itemId);
  setBtnLoading('btn-play', false);
  if (!res) return;

  if (res.success) {
    const { pet, happiness_restored, item_used, item_qty_remaining } = res.data;
    updateHatchedBars(pet);
    currentPet = pet;
    flash(`${pet.name} played with the ${item_used.icon} ${item_used.name} (+${happiness_restored} happiness). ${item_qty_remaining} left.`, 'success');
    await refreshInventory();
  } else {
    flash(res.message, 'error');
  }
}

async function onPollRefresh() {
  if (!currentPet?.id) return;
  const res = await petAPI.getPetById(currentPet.id);
  if (!res?.success) return;
  updateHatchedBars(res.data.pet);
  currentPet = res.data.pet;
}

// ============================================================================
// INIT / DESTROY
// ============================================================================

export async function initPetSystem() {
  if (!root()) return;

  root().innerHTML = `
    <div class="panel pet-panel--skeleton">
      <div class="skeleton-line skeleton-line--title"></div>
      <div class="skeleton-line"></div>
      <div class="skeleton-line skeleton-line--short"></div>
    </div>`;

  const res = await petAPI.getMyPet();
  if (!res) return;

  if (!res.success) {
    if (res.message?.toLowerCase().includes('no active') ||
        res.message?.toLowerCase().includes('not found')) {
      renderNoPet();
    } else {
      root().innerHTML = `
        <div class="panel">
          <p style="color:var(--ember);font-family:var(--font-heading);font-size:.85rem;">
            ⚠ Could not load companion: ${res.message}
          </p>
        </div>`;
    }
    return;
  }

  const { pet, needs_penalties, hatch_countdown, offline_progress } = res.data;
  currentPet = pet;

  if (pet.hatch_status === 'INCUBATING') {
    renderIncubating(pet, hatch_countdown);
  } else {
    renderHatched(pet, needs_penalties, offline_progress);
  }
}

// Listen for reward collection from adventure system — update bars in-place
document.addEventListener('pet:refresh', (e) => {
  const pet = e.detail;
  if (!pet) return;
  currentPet = pet;
  updateHatchedBars(pet);
});

export function destroyPetSystem() {
  clearTimers();
  currentPet = null;
}
