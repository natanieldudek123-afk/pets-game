<?php
// =============================================================================
// src/Services/PetService.php
// Task #3 update:
//   - feedPet(petId, userId, itemId)  — consumes a FOOD item from inventory
//   - playWithPet(petId, userId, itemId) — consumes a TOY item from inventory
//   - Item power replaces the flat FEED_AMOUNT / PLAY_AMOUNT constants.
//   - All named params remain unique within each statement (Home.pl fix).
// =============================================================================

declare(strict_types=1);

namespace PBBG\Services;

use PBBG\Utils\DB;
use PBBG\Services\TickEngine;
use PDOException;

class PetService
{
    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private static function clamp(int $value): int
    {
        return max(STAT_MIN, min(STAT_MAX, $value));
    }

    private static function requireOwnedPet(int $petId, int $userId): array
    {
        try {
            $stmt = DB::get()->prepare(
                'SELECT * FROM pets
                 WHERE id = :pet_id AND user_id = :pet_uid AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([':pet_id' => $petId, ':pet_uid' => $userId]);
            $pet = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[PetService::requireOwnedPet] ' . $e->getMessage());
            throw new \RuntimeException('Database error while fetching pet.', 500);
        }

        if (!$pet) {
            throw new \RuntimeException('Pet not found or does not belong to your account.', 404);
        }

        return self::castPet($pet);
    }

    private static function refetchPet(int $petId): array
    {
        try {
            $stmt = DB::get()->prepare('SELECT * FROM pets WHERE id = :refetch_id LIMIT 1');
            $stmt->execute([':refetch_id' => $petId]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[PetService::refetchPet] ' . $e->getMessage());
            throw new \RuntimeException('Database error while reading updated pet.', 500);
        }

        if (!$row) {
            throw new \RuntimeException('Pet record not found after update.', 500);
        }

        return self::castPet($row);
    }

    private static function castPet(array $row): array
    {
        return [
            'id'                => (int)$row['id'],
            'user_id'           => (int)$row['user_id'],
            'name'              => (string)$row['name'],
            'species'           => (string)$row['species'],
            'tier'              => (int)$row['tier'],
            'level'             => (int)$row['level'],
            'current_xp'        => (int)$row['current_xp'],
            'xp_to_next_level'  => (int)$row['xp_to_next_level'],
            'base_str'          => (int)$row['base_str'],
            'base_agi'          => (int)$row['base_agi'],
            'base_int'          => (int)$row['base_int'],
            'base_vit'          => (int)$row['base_vit'],
            'current_hunger'    => (int)$row['current_hunger'],
            'current_happiness' => (int)$row['current_happiness'],
            'hatch_status'      => (string)$row['hatch_status'],
            'hatch_end_time'    => $row['hatch_end_time'],
            'last_tick_at'      => (string)$row['last_tick_at'],
            'is_active'         => (bool)$row['is_active'],
            'is_in_combat'      => (bool)$row['is_in_combat'],
            'created_at'        => (string)$row['created_at'],
            'updated_at'        => (string)$row['updated_at'],
        ];
    }

    // =========================================================================
    // Inventory helpers (shared by feedPet and playWithPet)
    // =========================================================================

    /**
     * Load and validate an inventory item the user intends to use.
     * Throws if:
     *   - item doesn't exist / isn't active
     *   - item type doesn't match $expectedType ('FOOD' or 'TOY')
     *   - user has 0 quantity
     *
     * Returns the item row.
     */
    private static function requireInventoryItem(
        int    $userId,
        int    $itemId,
        string $expectedType
    ): array {
        try {
            $stmt = DB::get()->prepare(
                'SELECT ui.quantity, si.id, si.name, si.type, si.power, si.icon
                 FROM user_inventory ui
                 JOIN shop_items si ON si.id = ui.item_id
                 WHERE ui.user_id = :inv_check_uid
                   AND ui.item_id = :inv_check_iid
                   AND si.is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([
                ':inv_check_uid' => $userId,
                ':inv_check_iid' => $itemId,
            ]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[PetService::requireInventoryItem] ' . $e->getMessage());
            throw new \RuntimeException('Database error checking inventory.', 500);
        }

        if (!$row) {
            throw new \RuntimeException(
                'You do not own this item. Visit the shop to buy some!', 400
            );
        }

        if ((string)$row['type'] !== $expectedType) {
            $friendly = $expectedType === 'FOOD' ? 'food item' : 'toy';
            throw new \RuntimeException(
                "That item is not a {$friendly}. Please select the correct type.", 400
            );
        }

        if ((int)$row['quantity'] <= 0) {
            throw new \RuntimeException(
                "You have no more {$row['name']} left. Buy more from the shop!", 400
            );
        }

        return [
            'id'       => (int)$row['id'],
            'name'     => (string)$row['name'],
            'type'     => (string)$row['type'],
            'power'    => (int)$row['power'],
            'icon'     => (string)$row['icon'],
            'quantity' => (int)$row['quantity'],
        ];
    }

    /**
     * Decrement the inventory quantity by 1 for a (user, item) pair.
     * Throws on DB error; does NOT throw if quantity would go negative
     * (requireInventoryItem is always called first in the same transaction).
     */
    private static function consumeInventoryItem(int $userId, int $itemId): int
    {
        try {
            $stmt = DB::get()->prepare(
                'UPDATE user_inventory
                 SET quantity   = GREATEST(0, quantity - 1),
                     updated_at = NOW()
                 WHERE user_id = :consume_uid
                   AND item_id = :consume_iid'
            );
            $stmt->execute([
                ':consume_uid' => $userId,
                ':consume_iid' => $itemId,
            ]);

            // Return new quantity
            $stmt2 = DB::get()->prepare(
                'SELECT quantity FROM user_inventory
                 WHERE user_id = :qty_uid AND item_id = :qty_iid LIMIT 1'
            );
            $stmt2->execute([':qty_uid' => $userId, ':qty_iid' => $itemId]);
            return (int)($stmt2->fetchColumn() ?? 0);
        } catch (PDOException $e) {
            error_log('[PetService::consumeInventoryItem] ' . $e->getMessage());
            throw new \RuntimeException('Database error consuming item.', 500);
        }
    }

    // =========================================================================
    // startHatch
    // =========================================================================

    public static function startHatch(
        int    $userId,
        string $name,
        string $species,
        int    $tier = 1
    ): array {
        $allowedSpecies = array_keys(SPECIES_BASE_STATS);
        if (!in_array($species, $allowedSpecies, true)) {
            throw new \RuntimeException(
                'Unknown species. Must be: ' . implode(', ', $allowedSpecies) . '.', 400
            );
        }

        if (!isset(TIER_MULTIPLIERS[$tier])) {
            throw new \RuntimeException('Invalid tier. Must be 1-5.', 400);
        }

        $db = DB::get();

        try {
            $stmt = $db->prepare(
                "SELECT id FROM pets
                 WHERE user_id = :sh_uid AND hatch_status = 'INCUBATING' AND is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([':sh_uid' => $userId]);

            if ($stmt->fetch()) {
                throw new \RuntimeException(
                    'You already have an egg incubating. Wait for it to hatch first.', 409
                );
            }

            $hatchEndTime = date('Y-m-d H:i:s', time() + HATCH_DURATION_SECS);
            $stats        = SPECIES_BASE_STATS[$species];

            $stmt = $db->prepare(
                "INSERT INTO pets
                    (user_id, name, species, tier, hatch_status, hatch_end_time,
                     current_hunger, current_happiness,
                     base_str, base_agi, base_int, base_vit, last_tick_at)
                 VALUES
                    (:sh_user_id, :sh_name, :sh_species, :sh_tier, 'INCUBATING', :sh_hatch_end,
                     0, 0,
                     :sh_base_str, :sh_base_agi, :sh_base_int, :sh_base_vit, NOW())"
            );
            $stmt->execute([
                ':sh_user_id'   => $userId,
                ':sh_name'      => trim($name),
                ':sh_species'   => $species,
                ':sh_tier'      => $tier,
                ':sh_hatch_end' => $hatchEndTime,
                ':sh_base_str'  => $stats['base_str'],
                ':sh_base_agi'  => $stats['base_agi'],
                ':sh_base_int'  => $stats['base_int'],
                ':sh_base_vit'  => $stats['base_vit'],
            ]);

            $petId = (int)$db->lastInsertId();

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (PDOException $e) {
            error_log('[PetService::startHatch] ' . $e->getMessage());
            throw new \RuntimeException('Database error while creating egg.', 500);
        }

        return [
            'id'                     => $petId,
            'name'                   => trim($name),
            'species'                => $species,
            'tier'                   => $tier,
            'hatch_status'           => 'INCUBATING',
            'hatch_end_time'         => $hatchEndTime,
            'hatch_duration_seconds' => HATCH_DURATION_SECS,
        ];
    }

    // =========================================================================
    // claimPet
    // =========================================================================

    public static function claimPet(int $petId, int $userId): array
    {
        $pet = self::requireOwnedPet($petId, $userId);

        if ($pet['hatch_status'] === 'HATCHED') {
            throw new \RuntimeException('This pet has already hatched!', 409);
        }

        $hatchEndTime = strtotime((string)$pet['hatch_end_time']);
        $now          = time();

        if ($hatchEndTime > $now) {
            $remaining            = $hatchEndTime - $now;
            $min                  = (int)floor($remaining / 60);
            $sec                  = $remaining % 60;
            $ex                   = new \RuntimeException(
                "Your egg isn't ready yet! {$min}m {$sec}s remaining.", 400
            );
            $ex->remainingSeconds = $remaining;
            throw $ex;
        }

        try {
            $stmt = DB::get()->prepare(
                "UPDATE pets
                 SET hatch_status      = 'HATCHED',
                     current_hunger    = :cp_h_val,
                     current_happiness = :cp_a_val,
                     last_tick_at      = NOW(),
                     updated_at        = NOW()
                 WHERE id = :cp_pet_id"
            );
            $stmt->execute([
                ':cp_h_val'  => STAT_MAX,
                ':cp_a_val'  => STAT_MAX,
                ':cp_pet_id' => $petId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException('Hatch update had no effect.', 500);
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (PDOException $e) {
            error_log('[PetService::claimPet] ' . $e->getMessage());
            throw new \RuntimeException('Database error while hatching pet.', 500);
        }

        return self::refetchPet($petId);
    }

    // =========================================================================
    // feedPet — Task #3: requires a FOOD item from inventory
    // =========================================================================

    /**
     * Feed the pet using a FOOD item from the user's inventory.
     * $itemId is mandatory. Power comes from the item, not FEED_AMOUNT.
     *
     * @throws \RuntimeException
     */
    public static function feedPet(int $petId, int $userId, int $itemId): array
    {
        $pet  = self::requireOwnedPet($petId, $userId);
        $item = self::requireInventoryItem($userId, $itemId, 'FOOD');

        if ($pet['hatch_status'] !== 'HATCHED') {
            throw new \RuntimeException('You cannot feed an egg. Wait for it to hatch!', 400);
        }

        if ($pet['current_hunger'] >= STAT_MAX) {
            throw new \RuntimeException(
                "{$pet['name']} is already full! ({$pet['current_hunger']}/100)", 400
            );
        }

        $newHunger = self::clamp($pet['current_hunger'] + $item['power']);

        try {
            DB::get()->prepare(
                'UPDATE pets
                 SET current_hunger = :fp_hunger,
                     updated_at     = NOW()
                 WHERE id = :fp_pet_id'
            )->execute([':fp_hunger' => $newHunger, ':fp_pet_id' => $petId]);
        } catch (PDOException $e) {
            error_log('[PetService::feedPet] ' . $e->getMessage());
            throw new \RuntimeException('Database error while feeding pet.', 500);
        }

        $remainingQty = self::consumeInventoryItem($userId, $itemId);
        $updated      = self::refetchPet($petId);

        return [
            'pet'              => $updated,
            'hunger_restored'  => $updated['current_hunger'] - $pet['current_hunger'],
            'item_used'        => ['id' => $item['id'], 'name' => $item['name'], 'icon' => $item['icon']],
            'item_qty_remaining' => $remainingQty,
        ];
    }

    // =========================================================================
    // playWithPet — Task #3: requires a TOY item from inventory
    // =========================================================================

    /**
     * Play with the pet using a TOY item from the user's inventory.
     * $itemId is mandatory. Power comes from the item, not PLAY_AMOUNT.
     *
     * @throws \RuntimeException
     */
    public static function playWithPet(int $petId, int $userId, int $itemId): array
    {
        $pet  = self::requireOwnedPet($petId, $userId);
        $item = self::requireInventoryItem($userId, $itemId, 'TOY');

        if ($pet['hatch_status'] !== 'HATCHED') {
            throw new \RuntimeException('You cannot play with an egg. Wait for it to hatch!', 400);
        }

        if ($pet['current_happiness'] >= STAT_MAX) {
            throw new \RuntimeException(
                "{$pet['name']} is already as happy as can be! ({$pet['current_happiness']}/100)", 400
            );
        }

        $newHappiness = self::clamp($pet['current_happiness'] + $item['power']);

        try {
            DB::get()->prepare(
                'UPDATE pets
                 SET current_happiness = :pp_happiness,
                     updated_at        = NOW()
                 WHERE id = :pp_pet_id'
            )->execute([':pp_happiness' => $newHappiness, ':pp_pet_id' => $petId]);
        } catch (PDOException $e) {
            error_log('[PetService::playWithPet] ' . $e->getMessage());
            throw new \RuntimeException('Database error while playing with pet.', 500);
        }

        $remainingQty = self::consumeInventoryItem($userId, $itemId);
        $updated      = self::refetchPet($petId);

        return [
            'pet'                  => $updated,
            'happiness_restored'   => $updated['current_happiness'] - $pet['current_happiness'],
            'item_used'            => ['id' => $item['id'], 'name' => $item['name'], 'icon' => $item['icon']],
            'item_qty_remaining'   => $remainingQty,
        ];
    }

    // =========================================================================
    // getMyPet
    // =========================================================================

    public static function getMyPet(int $userId, ?int $petId = null): array
    {
        $db = DB::get();

        try {
            if ($petId !== null) {
                $pet = self::requireOwnedPet($petId, $userId);
            } else {
                $stmt = $db->prepare(
                    'SELECT * FROM pets
                     WHERE user_id = :gmp_uid AND is_active = 1
                     ORDER BY created_at DESC
                     LIMIT 1'
                );
                $stmt->execute([':gmp_uid' => $userId]);
                $row = $stmt->fetch();

                if (!$row) {
                    throw new \RuntimeException(
                        'You have no active pets. Start hatching an egg!', 404
                    );
                }

                $pet = self::castPet($row);
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (PDOException $e) {
            error_log('[PetService::getMyPet] ' . $e->getMessage());
            throw new \RuntimeException('Database error while fetching pet.', 500);
        }

        $offlineProgress = null;
        if ($pet['hatch_status'] === 'HATCHED') {
            try {
                $offlineProgress = TickEngine::applyOfflineProgress($pet['id']);
                if ($offlineProgress !== null) {
                    $pet = self::refetchPet($pet['id']);
                }
            } catch (\Throwable $e) {
                error_log('[PetService::getMyPet] Offline progress error: ' . $e->getMessage());
            }
        }

        $needsPenalties = null;
        $hatchCountdown = null;

        if ($pet['hatch_status'] === 'HATCHED') {
            $needsPenalties = [
                'hunger_penalty_active'    => $pet['current_hunger']    < 30,
                'happiness_penalty_active' => $pet['current_happiness'] < 30,
            ];
        } elseif ($pet['hatch_status'] === 'INCUBATING' && $pet['hatch_end_time'] !== null) {
            $hatchCountdown = max(0, strtotime($pet['hatch_end_time']) - time());
        }

        return [
            'pet'              => $pet,
            'needs_penalties'  => $needsPenalties,
            'hatch_countdown'  => $hatchCountdown,
            'offline_progress' => $offlineProgress,
        ];
    }
}
