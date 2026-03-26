// =============================================================================
// js/shop.js — Task #3: Shop modal and inventory panel
//
// Exports:
//   initShop()  — called once after dashboard:ready. Loads inventory.
//                 Wires the "Open Shop" button.
//   getInventory() — returns current in-memory inventory array (used by pet.js)
// =============================================================================

import { shopAPI } from './api.js';

// ---------------------------------------------------------------------------
// Module state
// ---------------------------------------------------------------------------
let shopItems  = [];   // full catalogue
let inventory  = [];   // user's owned items

// ---------------------------------------------------------------------------
// Public: current inventory snapshot (read by pet.js for item pickers)
// ---------------------------------------------------------------------------
export function getInventory() {
  return inventory;
}

// ---------------------------------------------------------------------------
// Public: re-fetch inventory (called by pet.js after feed/play)
// ---------------------------------------------------------------------------
export async function refreshInventory() {
  const res = await shopAPI.getInventory();
  if (res?.success) {
    inventory = res.data.inventory ?? [];
    renderInventoryPanel();
    // Notify pet.js that inventory changed (it listens for this)
    document.dispatchEvent(new CustomEvent('inventory:updated', { detail: inventory }));
  }
}

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------
export async function initShop() {
  injectShopHTML();

  // Load inventory immediately (needed by pet panel on first render)
  const invRes = await shopAPI.getInventory();
  if (invRes?.success) {
    inventory = invRes.data.inventory ?? [];
    renderInventoryPanel();
    document.dispatchEvent(new CustomEvent('inventory:updated', { detail: inventory }));
  }

  // Wire open-shop button
  document.getElementById('btn-open-shop')?.addEventListener('click', openShopModal);
  document.getElementById('shop-modal-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'shop-modal-overlay') closeShopModal();
  });
  document.getElementById('btn-close-shop')?.addEventListener('click', closeShopModal);
}

// ---------------------------------------------------------------------------
// Inject static HTML scaffolding into the page
// ---------------------------------------------------------------------------
function injectShopHTML() {
  // Inventory panel — inserted into #inventory-panel-root (in dashboard.php)
  const invRoot = document.getElementById('inventory-panel-root');
  if (invRoot) {
    invRoot.innerHTML = `
      <div class="inv-panel panel" id="inv-panel">
        <div class="inv-panel__header">
          <h2 class="section-title">Inventory</h2>
          <button id="btn-open-shop" class="btn btn-ghost btn-shop-open">🏪 Shop</button>
        </div>
        <div id="inv-list" class="inv-list">
          <div class="inv-empty">Your inventory is empty. Visit the shop!</div>
        </div>
      </div>`;
  }

  // Shop modal — appended to body
  if (!document.getElementById('shop-modal-overlay')) {
    const modal = document.createElement('div');
    modal.id        = 'shop-modal-overlay';
    modal.className = 'shop-overlay';
    modal.innerHTML = `
      <div class="shop-modal panel" role="dialog" aria-modal="true" aria-label="Shop">
        <div class="shop-modal__header">
          <h2 class="shop-modal__title">🏪 The Wandering Merchant</h2>
          <button id="btn-close-shop" class="btn btn-ghost btn-sm" aria-label="Close shop">✕</button>
        </div>
        <p class="shop-modal__sub">
          Crystals: <span class="shop-crystals" id="shop-crystal-count">—</span> 💎
        </p>

        <div class="shop-tabs">
          <button class="shop-tab active" data-filter="ALL">All</button>
          <button class="shop-tab" data-filter="FOOD">🍎 Food</button>
          <button class="shop-tab" data-filter="TOY">🎮 Toys</button>
        </div>

        <div id="shop-item-grid" class="shop-item-grid">
          <p class="shop-loading">Loading wares…</p>
        </div>

        <div id="shop-msg" class="shop-msg" style="display:none"></div>
      </div>`;
    document.body.appendChild(modal);

    // Tab filtering
    modal.querySelectorAll('.shop-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        modal.querySelectorAll('.shop-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderShopGrid(btn.dataset.filter);
      });
    });
  }
}

// ---------------------------------------------------------------------------
// Open shop modal — load items lazily
// ---------------------------------------------------------------------------
async function openShopModal() {
  const overlay = document.getElementById('shop-modal-overlay');
  if (!overlay) return;
  overlay.style.display = 'flex';
  document.body.style.overflow = 'hidden';

  // Sync Crystal count from the topbar display
  const crystalEl = document.getElementById('shop-crystal-count');
  if (crystalEl) {
    crystalEl.textContent = document.getElementById('stat-crystals')?.textContent ?? '—';
  }

  if (shopItems.length === 0) {
    const res = await shopAPI.getItems();
    if (res?.success) {
      shopItems = res.data.items ?? [];
    } else {
      document.getElementById('shop-item-grid').innerHTML =
        '<p class="shop-error">Could not load shop items.</p>';
      return;
    }
  }

  renderShopGrid('ALL');
}

function closeShopModal() {
  const overlay = document.getElementById('shop-modal-overlay');
  if (overlay) overlay.style.display = 'none';
  document.body.style.overflow = '';
  clearShopMsg();
}

// ---------------------------------------------------------------------------
// Render shop item grid with optional type filter
// ---------------------------------------------------------------------------
function renderShopGrid(filter = 'ALL') {
  const grid    = document.getElementById('shop-item-grid');
  if (!grid) return;

  const visible = filter === 'ALL'
    ? shopItems
    : shopItems.filter(i => i.type === filter);

  if (!visible.length) {
    grid.innerHTML = '<p class="shop-empty">No items in this category.</p>';
    return;
  }

  grid.innerHTML = visible.map(item => {
    const owned = (inventory.find(i => i.item_id === item.id)?.quantity ?? 0);
    return `
      <div class="shop-card" data-item-id="${item.id}">
        <div class="shop-card__icon">${item.icon}</div>
        <div class="shop-card__body">
          <div class="shop-card__name">${item.name}</div>
          <div class="shop-card__desc">${item.description}</div>
          <div class="shop-card__meta">
            <span class="shop-card__power">+${item.power} ${item.type === 'FOOD' ? 'hunger' : 'happiness'}</span>
            <span class="shop-card__owned">Owned: ${owned}</span>
          </div>
        </div>
        <button class="btn btn-primary shop-card__buy"
                data-item-id="${item.id}"
                data-item-name="${item.name}">
          ${item.price} 💎
        </button>
      </div>`;
  }).join('');

  grid.querySelectorAll('.shop-card__buy').forEach(btn => {
    btn.addEventListener('click', () => handleBuy(parseInt(btn.dataset.itemId, 10), btn.dataset.itemName));
  });
}

// ---------------------------------------------------------------------------
// Buy handler
// ---------------------------------------------------------------------------
async function handleBuy(itemId, itemName) {
  clearShopMsg();
  // Disable all buy buttons during request
  document.querySelectorAll('.shop-card__buy').forEach(b => { b.disabled = true; });

  const res = await shopAPI.buy(itemId);

  document.querySelectorAll('.shop-card__buy').forEach(b => { b.disabled = false; });

  if (!res) return;

  if (res.success) {
    // Update Crystal display everywhere
    const newCrystals = res.data.crystals_remaining;
    ['stat-crystals','stat-crystals-card'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.textContent = newCrystals;
    });
    document.getElementById('shop-crystal-count').textContent = newCrystals;

    showShopMsg(`Purchased ${itemName}! (${res.data.crystals_remaining} 💎 left)`, 'success');

    // Refresh inventory and re-render grid
    await refreshInventory();
    renderShopGrid(
      document.querySelector('.shop-tab.active')?.dataset.filter ?? 'ALL'
    );
  } else {
    showShopMsg(res.message, 'error');
  }
}

// ---------------------------------------------------------------------------
// Render inventory panel (below pet section)
// ---------------------------------------------------------------------------
function renderInventoryPanel() {
  const list = document.getElementById('inv-list');
  if (!list) return;

  if (!inventory.length) {
    list.innerHTML = '<div class="inv-empty">Your inventory is empty. Visit the shop!</div>';
    return;
  }

  // Group by type for cleaner display
  const food = inventory.filter(i => i.type === 'FOOD');
  const toys  = inventory.filter(i => i.type === 'TOY');

  const renderGroup = (title, items) => {
    if (!items.length) return '';
    return `
      <div class="inv-group">
        <div class="inv-group__label">${title}</div>
        <div class="inv-group__items">
          ${items.map(item => `
            <div class="inv-item ${item.quantity === 0 ? 'inv-item--empty' : ''}"
                 data-item-id="${item.item_id}"
                 data-item-type="${item.type}"
                 title="${item.name} — +${item.power} ${item.type === 'FOOD' ? 'hunger' : 'happiness'}">
              <span class="inv-item__icon">${item.icon}</span>
              <span class="inv-item__qty">${item.quantity}</span>
            </div>
          `).join('')}
        </div>
      </div>`;
  };

  list.innerHTML =
    renderGroup('🍎 Food', food) +
    renderGroup('🎮 Toys', toys);
}

// ---------------------------------------------------------------------------
// Shop message helpers
// ---------------------------------------------------------------------------
function showShopMsg(text, type = 'success') {
  const el = document.getElementById('shop-msg');
  if (!el) return;
  el.textContent    = text;
  el.className      = `shop-msg shop-msg--${type}`;
  el.style.display  = 'block';
}

function clearShopMsg() {
  const el = document.getElementById('shop-msg');
  if (el) { el.style.display = 'none'; el.textContent = ''; }
}
