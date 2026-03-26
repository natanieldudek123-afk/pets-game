// topbar.js — runs on every page, populates currency badges
import { authAPI } from './api.js';

document.addEventListener('DOMContentLoaded', async () => {
  const res = await authAPI.getMe();
  if (!res?.success) { window.location.href = './index.php'; return; }
  const u = res.data;
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('stat-crystals',   u.premium_currency);
  set('stat-prestige',   u.prestige_points);
  set('stat-level',      u.account_level);
  set('stat-pets',       u.pet_count ?? 0);
  set('stat-prestige-card',  u.prestige_points);
  set('stat-crystals-card',  u.premium_currency);
  document.title = `${u.username}'s Realm — Realm of Echoes`;
});
