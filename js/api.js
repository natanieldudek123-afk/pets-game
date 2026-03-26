// =============================================================================
// js/api.js
// Centralised Fetch wrapper.
// - Same-origin requests to api.php?action=…
// - Separates network failures from JSON parse failures for clear error messages.
// =============================================================================

const API_BASE = './api.php';

async function request(action, { method = 'GET', body, params = {} } = {}) {
  const url = new URL(API_BASE, window.location.href);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) {
    url.searchParams.set(k, v);
  }

  const fetchOptions = {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
  };

  if (body && method !== 'GET') {
    fetchOptions.body = JSON.stringify(body);
  }

  let res;

  // ── Step 1: fetch ──────────────────────────────────────────────────────────
  try {
    res = await fetch(url.toString(), fetchOptions);
  } catch (err) {
    // True network failure (server unreachable, DNS error, etc.)
    console.error('[API] Fetch failed:', action, err);
    return { success: false, message: 'Could not reach the server. Please try again.' };
  }

  // ── Step 2: parse JSON ─────────────────────────────────────────────────────
  // Done separately so a PHP warning/HTML error page gives a useful message
  // instead of the generic "network error".
  let json;
  try {
    json = await res.json();
  } catch (err) {
    // Server returned non-JSON (PHP fatal, notice, HTML error page, etc.)
    // Read the raw text so we can log it for debugging.
    console.error('[API] JSON parse failed for action:', action,
      '| status:', res.status, '| url:', url.toString(), '| err:', err);
    return {
      success: false,
      message: `Server error (HTTP ${res.status}). Check server logs for details.`,
    };
  }

  // ── Step 3: session check ──────────────────────────────────────────────────
  if (res.status === 401 && action !== 'auth.login') {
    window.location.href = './index.php';
    return null;
  }

  return json;
}

// ---------------------------------------------------------------------------
// Auth API
// ---------------------------------------------------------------------------
export const authAPI = {
  register: (data) => request('auth.register', { method: 'POST', body: data }),
  login:    (data) => request('auth.login',    { method: 'POST', body: data }),
  logout:   ()     => request('auth.logout',   { method: 'POST' }),
  getMe:    ()     => request('auth.me'),
};

// ---------------------------------------------------------------------------
// Pet API
// ---------------------------------------------------------------------------
export const petAPI = {
  getMyPet:   ()              => request('pet.getMyPet'),
  getPetById: (id)            => request('pet.getMyPet',   { params: { petId: id } }),
  startHatch: (data)          => request('pet.startHatch', { method: 'POST', body: data }),
  claimPet:   (id)            => request('pet.claimPet',   { method: 'POST', params: { petId: id } }),
  feed:       (id, item_id)   => request('pet.feed',       { method: 'POST', params: { petId: id }, body: { item_id } }),
  play:       (id, item_id)   => request('pet.play',       { method: 'POST', params: { petId: id }, body: { item_id } }),
};

// ---------------------------------------------------------------------------
// Shop & Inventory API
// ---------------------------------------------------------------------------
export const shopAPI = {
  getItems:     ()        => request('shop.getItems'),
  buy:          (item_id) => request('shop.buy',          { method: 'POST', body: { item_id } }),
  getInventory: ()        => request('shop.getInventory'),
};

// ---------------------------------------------------------------------------
// Adventure / Expedition API
// ---------------------------------------------------------------------------
export const adventureAPI = {
  getExpeditions: ()                     => request('adventure.getExpeditions'),
  getStatus:      (petId)                => request('adventure.getStatus', { params: { petId } }),
  start:          (petId, expedition_id) => request('adventure.start',     { method: 'POST', params: { petId }, body: { expedition_id } }),
  collect:        (petId)                => request('adventure.collect',   { method: 'POST', params: { petId } }),
};
