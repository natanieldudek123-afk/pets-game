// inventory-page.js — Shop and Inventory for inventory.php
import { shopAPI } from './api.js';

const root = () => document.getElementById('inventory-root');

let inventory  = [];
let shopItems  = [];
let filter     = 'ALL';

function flash(msg, type = 'success') {
  const el = document.getElementById('inv-msg');
  if (!el) return;
  el.textContent = msg; el.className = `flash flash--${type}`; el.style.display = 'block';
  clearTimeout(el._t); el._t = setTimeout(() => el.style.display = 'none', 3500);
}

function renderInventory() {
  const el = document.getElementById('inv-list');
  if (!el) return;
  if (!inventory.length) {
    el.innerHTML = '<p class="empty-msg">Your inventory is empty. Buy something from the shop below.</p>';
    return;
  }
  const food = inventory.filter(i => i.type === 'FOOD');
  const toys  = inventory.filter(i => i.type === 'TOY');
  const grp = (title, items) => !items.length ? '' : `
    <div class="inv-group">
      <div class="inv-group-title">${title}</div>
      <div class="inv-items">
        ${items.map(i => `
          <div class="inv-item ${i.quantity === 0 ? 'inv-item--empty' : ''}" title="${i.name} (+${i.power})">
            <span class="inv-item-icon">${i.icon}</span>
            <span class="inv-item-qty">×${i.quantity}</span>
            <span class="inv-item-name">${i.name}</span>
          </div>`).join('')}
      </div>
    </div>`;
  el.innerHTML = grp('🍎 Food', food) + grp('🎮 Toys', toys);
}

function renderShop() {
  const el = document.getElementById('shop-grid');
  if (!el) return;
  const visible = filter === 'ALL' ? shopItems : shopItems.filter(i => i.type === filter);
  el.innerHTML = visible.map(item => {
    const owned = inventory.find(i => i.item_id === item.id)?.quantity ?? 0;
    return `
      <div class="shop-card">
        <div class="shop-card-icon">${item.icon}</div>
        <div class="shop-card-body">
          <div class="shop-card-name">${item.name}</div>
          <div class="shop-card-desc">${item.description}</div>
          <div class="shop-card-meta">
            <span class="shop-power">+${item.power} ${item.type === 'FOOD' ? 'hunger' : 'happiness'}</span>
            <span class="shop-owned">Owned: ${owned}</span>
          </div>
        </div>
        <button class="btn btn-primary shop-buy" data-id="${item.id}" data-name="${item.name}">
          ${item.price} 💎
        </button>
      </div>`;
  }).join('');

  el.querySelectorAll('.shop-buy').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const res = await shopAPI.buy(parseInt(btn.dataset.id));
      btn.disabled = false;
      if (res?.success) {
        const newBal = res.data.crystals_remaining;
        ['stat-crystals','stat-crystals-card'].forEach(id => {
          const el = document.getElementById(id); if (el) el.textContent = newBal;
        });
        document.getElementById('shop-crystals').textContent = newBal;
        flash(`Bought ${btn.dataset.name}! (${newBal} 💎 left)`, 'success');
        const inv = await shopAPI.getInventory();
        if (inv?.success) { inventory = inv.data.inventory ?? []; renderInventory(); renderShop(); }
      } else {
        flash(res?.message || 'Error', 'error');
      }
    });
  });
}

async function init() {
  root().innerHTML = '<div class="loading">Loading inventory…</div>';

  const [invRes, shopRes] = await Promise.all([
    shopAPI.getInventory(),
    shopAPI.getItems(),
  ]);

  if (invRes?.success)  inventory  = invRes.data.inventory  ?? [];
  if (shopRes?.success) shopItems  = shopRes.data.items      ?? [];

  const crystalEl = document.getElementById('stat-crystals');
  const crystals  = crystalEl?.textContent ?? '—';

  root().innerHTML = `
    <div id="inv-msg" class="flash" style="display:none"></div>

    <div class="card">
      <h2 class="card-title">Your Items</h2>
      <div id="inv-list"></div>
    </div>

    <div class="card" style="margin-top:1.5rem;">
      <div class="shop-header">
        <h2 class="card-title">🏪 Shop</h2>
        <span class="shop-balance">💎 <span id="shop-crystals">${crystals}</span> Crystals</span>
      </div>
      <div class="shop-filters">
        <button class="filter-btn ${filter==='ALL'?'active':''}"  data-f="ALL">All</button>
        <button class="filter-btn ${filter==='FOOD'?'active':''}" data-f="FOOD">🍎 Food</button>
        <button class="filter-btn ${filter==='TOY'?'active':''}"  data-f="TOY">🎮 Toys</button>
      </div>
      <div id="shop-grid"></div>
    </div>`;

  renderInventory();
  renderShop();

  root().querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      filter = btn.dataset.f;
      root().querySelectorAll('.filter-btn').forEach(b => b.classList.toggle('active', b.dataset.f === filter));
      renderShop();
    });
  });
}

document.addEventListener('DOMContentLoaded', init);
