// =============================================================================
// js/pet-boot.js
// =============================================================================

import { initPetSystem }               from './pet.js';
import { initShop }                    from './shop.js';
import { initAdventure }               from './adventure.js';
import { petAPI }                      from './api.js';

let _currentPet = null;

document.addEventListener('dashboard:ready', async () => {
  // Init shop first (loads inventory)
  await initShop();

  // Init pet system
  await initPetSystem();

  // Always init adventure — pass pet if we have one, null otherwise
  // initAdventure handles null gracefully (shows "hatch a pet first" message)
  try {
    const petRes = await petAPI.getMyPet();
    _currentPet = (petRes?.success && petRes.data?.pet) ? petRes.data.pet : null;
  } catch (e) {
    _currentPet = null;
  }
  await initAdventure(_currentPet);

}, { once: true });

document.addEventListener('adventure:rewarded', async () => {
  if (!_currentPet?.id) return;
  try {
    const res = await petAPI.getPetById(_currentPet.id);
    if (res?.success && res.data?.pet) {
      _currentPet = res.data.pet;
      document.dispatchEvent(new CustomEvent('pet:refresh', { detail: _currentPet }));
      await initAdventure(_currentPet);
    }
  } catch (e) { /* silent */ }
});
