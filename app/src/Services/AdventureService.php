<?php
// =============================================================================
// src/Services/AdventureService.php
// Task #4 — Expedition & Adventure System
//
// startExpedition($petId, $userId, $expeditionId)
//   - Validates pet ownership, level requirement, and that the pet is free.
//   - Marks pet is_in_combat = 1 while adventuring (blocks feed/play/another adventure).
//   - Inserts a pet_adventures row with calculated end_time.
//
// getAdventureStatus($petId, $userId)
//   - Returns the active/completed adventure row, if any.
//   - If ACTIVE and end_time has passed, auto-transitions to COMPLETED and
//     calculates reward (stored on the row) so it's ready to collect.
//
// collectRewards($petId, $userId)
//   - Validates a COMPLETED adventure exists.
//   - Awards Crystals to the user (str_bonus multiplier).
//   - Awards XP to the pet.
//   - If exhausted flag is set, applies an extra hunger penalty.
//   - Marks adventure COLLECTED, clears is_in_combat.
//
// getAvailableExpeditions()
//   - Returns the full expedition catalogue.
//
// Reward formula:
//   base_crystals = rand(crystal_reward_min, crystal_reward_max)
//   final_crystals = round(base_crystals * (1 + (base_str - 10) * 0.03))
//   exhausted = (base_vit < expedition.vit_exhaustion_cap)
//   hunger_penalty_if_exhausted = -30 (on top of normal tick decay)
//
// All param names are unique within each statement (Home.pl PDO native fix).
// =============================================================================

declare(strict_types=1);

namespace PBBG\Services;

use PBBG\Utils\DB;
use PDOException;

class AdventureService
{
    // Hunger penalty applied to exhausted pets when they return
    private const EXHAUSTION_HUNGER_PENALTY = 30;

    // =========================================================================
    // getAvailableExpeditions
    // =========================================================================

    /**
     * Return the full expedition catalogue ordered by difficulty / min_level.
     * @throws \RuntimeException
     */
    public static function getAvailableExpeditions(): array
    {
        try {
            $stmt = DB::get()->prepare(
                'SELECT id, name, description, icon, duration_seconds, min_level,
                        crystal_reward_min, crystal_reward_max, xp_reward,
                        difficulty, vit_exhaustion_cap
                 FROM expeditions
                 WHERE is_active = 1
                 ORDER BY difficulty ASC, min_level ASC'
            );
            $stmt->execute();
            return array_map([self::class, 'castExpedition'], $stmt->fetchAll());
        } catch (PDOException $e) {
            error_log('[AdventureService::getAvailableExpeditions] ' . $e->getMessage());
            throw new \RuntimeException('Could not load expedition catalogue.', 500);
        }
    }

    // =========================================================================
    // startExpedition
    // =========================================================================

    /**
     * Send a pet on an expedition.
     * Returns the newly created pet_adventures row.
     * @throws \RuntimeException
     */
    public static function startExpedition(int $petId, int $userId, int $expeditionId): array
    {
        $db = DB::get();

        // ── Load and verify pet ───────────────────────────────────────────────
        try {
            $stmt = $db->prepare(
                "SELECT id, name, level, base_str, base_vit,
                        is_active, is_in_combat, hatch_status
                 FROM pets
                 WHERE id = :se_pet_id AND user_id = :se_uid AND is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([':se_pet_id' => $petId, ':se_uid' => $userId]);
            $pet = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[AdventureService::startExpedition] pet fetch: ' . $e->getMessage());
            throw new \RuntimeException('Database error loading pet.', 500);
        }

        if (!$pet) {
            throw new \RuntimeException('Pet not found or does not belong to your account.', 404);
        }
        if ($pet['hatch_status'] !== 'HATCHED') {
            throw new \RuntimeException('Your pet must be hatched before going on expeditions.', 400);
        }
        if ((bool)$pet['is_in_combat']) {
            throw new \RuntimeException(
                "{$pet['name']} is already busy (adventuring or in combat). Wait for them to return.", 409
            );
        }

        // ── Load and verify expedition ────────────────────────────────────────
        try {
            $stmt = $db->prepare(
                'SELECT id, name, duration_seconds, min_level,
                        crystal_reward_min, crystal_reward_max, xp_reward, vit_exhaustion_cap
                 FROM expeditions
                 WHERE id = :se_exp_id AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([':se_exp_id' => $expeditionId]);
            $expedition = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[AdventureService::startExpedition] expedition fetch: ' . $e->getMessage());
            throw new \RuntimeException('Database error loading expedition.', 500);
        }

        if (!$expedition) {
            throw new \RuntimeException('Expedition not found.', 404);
        }
        if ((int)$pet['level'] < (int)$expedition['min_level']) {
            throw new \RuntimeException(
                "{$pet['name']} needs to be level {$expedition['min_level']} for this expedition "
                . "(currently level {$pet['level']}).", 400
            );
        }

        // ── Create adventure row & lock pet ───────────────────────────────────
        try {
            $db->beginTransaction();

            $now     = new \DateTime();
            $endTime = (clone $now)->modify('+' . (int)$expedition['duration_seconds'] . ' seconds');

            $db->prepare(
                'INSERT INTO pet_adventures
                    (pet_id, expedition_id, start_time, end_time, status)
                 VALUES
                    (:adv_pet_id, :adv_exp_id, :adv_start, :adv_end, \'ACTIVE\')'
            )->execute([
                ':adv_pet_id' => $petId,
                ':adv_exp_id' => $expeditionId,
                ':adv_start'  => $now->format('Y-m-d H:i:s'),
                ':adv_end'    => $endTime->format('Y-m-d H:i:s'),
            ]);

            $adventureId = (int)$db->lastInsertId();

            // Lock pet: is_in_combat = 1 blocks feed/play/another expedition
            $db->prepare(
                'UPDATE pets SET is_in_combat = 1, updated_at = NOW()
                 WHERE id = :lock_pet_id'
            )->execute([':lock_pet_id' => $petId]);

            $db->commit();
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AdventureService::startExpedition] insert: ' . $e->getMessage());
            throw new \RuntimeException('Database error starting expedition.', 500);
        }

        return [
            'adventure_id'    => $adventureId,
            'pet_id'          => $petId,
            'expedition_id'   => $expeditionId,
            'expedition_name' => $expedition['name'],
            'start_time'      => $now->format('Y-m-d H:i:s'),
            'end_time'        => $endTime->format('Y-m-d H:i:s'),
            'duration_seconds'=> (int)$expedition['duration_seconds'],
            'status'          => 'ACTIVE',
        ];
    }

    // =========================================================================
    // getAdventureStatus
    // =========================================================================

    /**
     * Return the current adventure state for a pet.
     * If the adventure is ACTIVE and end_time has passed, auto-transitions to
     * COMPLETED and calculates rewards so they are ready to collect.
     * Returns null if the pet has no active/completed adventure.
     * @throws \RuntimeException
     */
    public static function getAdventureStatus(int $petId, int $userId): ?array
    {
        $db = DB::get();

        // Verify pet ownership first
        try {
            $stmt = $db->prepare(
                'SELECT id, name, level, base_str, base_vit
                 FROM pets
                 WHERE id = :gs_pet_id AND user_id = :gs_uid AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([':gs_pet_id' => $petId, ':gs_uid' => $userId]);
            $pet = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[AdventureService::getAdventureStatus] pet fetch: ' . $e->getMessage());
            throw new \RuntimeException('Database error.', 500);
        }

        if (!$pet) {
            throw new \RuntimeException('Pet not found.', 404);
        }

        // Fetch the most recent non-COLLECTED adventure
        // Wrapped separately: if the table doesn't exist yet (migration not run),
        // return null gracefully instead of throwing a 500.
        try {
            $stmt = $db->prepare(
                "SELECT pa.id, pa.pet_id, pa.expedition_id, pa.start_time, pa.end_time,
                        pa.status, pa.crystal_reward, pa.xp_reward, pa.exhausted,
                        e.name AS expedition_name, e.description AS expedition_desc,
                        e.icon AS expedition_icon, e.difficulty
                 FROM pet_adventures pa
                 JOIN expeditions e ON e.id = pa.expedition_id
                 WHERE pa.pet_id = :stat_pet_id
                   AND pa.status IN ('ACTIVE', 'COMPLETED')
                 ORDER BY pa.created_at DESC
                 LIMIT 1"
            );
            $stmt->execute([':stat_pet_id' => $petId]);
            $adventure = $stmt->fetch();
        } catch (PDOException $e) {
            // Table missing or query error — treat as no active adventure
            error_log('[AdventureService::getAdventureStatus] adventure fetch: ' . $e->getMessage());
            return null;
        }

        if (!$adventure) {
            return null; // No ongoing adventure
        }

        // Auto-complete if time has elapsed and status is still ACTIVE
        if ($adventure['status'] === 'ACTIVE' && strtotime($adventure['end_time']) <= time()) {
            $adventure = self::completeAdventure(
                (int)$adventure['id'],
                $pet,
                $adventure
            );
        }

        return self::castAdventure($adventure);
    }

    // =========================================================================
    // collectRewards
    // =========================================================================

    /**
     * Collect rewards from a COMPLETED adventure.
     * - Awards Crystals to user (str-scaled).
     * - Awards XP to pet.
     * - Applies hunger penalty if exhausted.
     * - Marks adventure COLLECTED, clears pet is_in_combat.
     *
     * @throws \RuntimeException
     */
    public static function collectRewards(int $petId, int $userId): array
    {
        $db = DB::get();

        // Verify ownership
        try {
            $stmt = $db->prepare(
                'SELECT id, name, current_xp, xp_to_next_level, level,
                        current_hunger, user_id
                 FROM pets
                 WHERE id = :cr_pet_id AND user_id = :cr_uid AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([':cr_pet_id' => $petId, ':cr_uid' => $userId]);
            $pet = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[AdventureService::collectRewards] pet fetch: ' . $e->getMessage());
            throw new \RuntimeException('Database error.', 500);
        }

        if (!$pet) {
            throw new \RuntimeException('Pet not found.', 404);
        }

        // Fetch the COMPLETED adventure
        try {
            $stmt = $db->prepare(
                "SELECT id, crystal_reward, xp_reward, exhausted, expedition_id
                 FROM pet_adventures
                 WHERE pet_id = :cr_adv_pet AND status = 'COMPLETED'
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute([':cr_adv_pet' => $petId]);
            $adventure = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[AdventureService::collectRewards] adventure fetch: ' . $e->getMessage());
            throw new \RuntimeException('Database error.', 500);
        }

        if (!$adventure) {
            throw new \RuntimeException('No completed adventure to collect. Check back later!', 400);
        }

        $crystals  = (int)$adventure['crystal_reward'];
        $xp        = (int)$adventure['xp_reward'];
        $exhausted = (bool)$adventure['exhausted'];
        $advId     = (int)$adventure['id'];

        try {
            $db->beginTransaction();

            // Award Crystals
            $db->prepare(
                'UPDATE users
                 SET premium_currency = premium_currency + :col_crystals,
                     updated_at       = NOW()
                 WHERE id = :col_uid'
            )->execute([':col_crystals' => $crystals, ':col_uid' => $userId]);

            // Award XP to pet
            $newXp      = (int)$pet['current_xp'] + $xp;
            $newHunger  = (int)$pet['current_hunger'];

            if ($exhausted) {
                $newHunger = max(STAT_MIN, $newHunger - self::EXHAUSTION_HUNGER_PENALTY);
            }

            $db->prepare(
                'UPDATE pets
                 SET current_xp    = :col_xp,
                     current_hunger = :col_hunger,
                     is_in_combat   = 0,
                     updated_at     = NOW()
                 WHERE id = :col_pet_id'
            )->execute([
                ':col_xp'      => $newXp,
                ':col_hunger'  => $newHunger,
                ':col_pet_id'  => $petId,
            ]);

            // Mark adventure collected
            $db->prepare(
                "UPDATE pet_adventures
                 SET status = 'COLLECTED', updated_at = NOW()
                 WHERE id = :col_adv_id"
            )->execute([':col_adv_id' => $advId]);

            // Level-up check
            $levelsGained = self::checkLevelUp($petId, $newXp, (int)$pet['xp_to_next_level'], (int)$pet['level']);

            $db->commit();
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AdventureService::collectRewards] commit: ' . $e->getMessage());
            throw new \RuntimeException('Database error collecting rewards.', 500);
        }

        // Read final crystal balance
        try {
            $stmt = $db->prepare('SELECT premium_currency FROM users WHERE id = :bal_uid LIMIT 1');
            $stmt->execute([':bal_uid' => $userId]);
            $newBalance = (int)($stmt->fetchColumn() ?? 0);
        } catch (PDOException $e) {
            $newBalance = 0;
        }

        return [
            'crystals_earned'     => $crystals,
            'xp_earned'           => $xp,
            'exhausted'           => $exhausted,
            'hunger_penalty'      => $exhausted ? self::EXHAUSTION_HUNGER_PENALTY : 0,
            'levels_gained'       => $levelsGained,
            'crystal_balance'     => $newBalance,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Auto-complete an ACTIVE adventure: calculate rewards and store them.
     * Returns the updated adventure row array.
     */
    private static function completeAdventure(int $advId, array $pet, array $adventure): array
    {
        $db = DB::get();

        try {
            $stmt = $db->prepare(
                'SELECT crystal_reward_min, crystal_reward_max, xp_reward, vit_exhaustion_cap
                 FROM expeditions WHERE id = :comp_exp_id LIMIT 1'
            );
            $stmt->execute([':comp_exp_id' => (int)$adventure['expedition_id']]);
            $exp = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[AdventureService::completeAdventure] exp fetch: ' . $e->getMessage());
            return $adventure; // Return unchanged on error
        }

        // Calculate rewards
        $baseMin     = (int)$exp['crystal_reward_min'];
        $baseMax     = (int)$exp['crystal_reward_max'];
        $baseCrystals = rand($baseMin, $baseMax);

        // STR bonus: each point above 10 adds +3% crystals
        $strBonus    = 1 + max(0, ((int)$pet['base_str'] - 10)) * 0.03;
        $crystals    = (int)round($baseCrystals * $strBonus);

        $xp          = (int)$exp['xp_reward'];
        $exhausted   = (int)$pet['base_vit'] < (int)$exp['vit_exhaustion_cap'] ? 1 : 0;

        try {
            $db->prepare(
                "UPDATE pet_adventures
                 SET status         = 'COMPLETED',
                     crystal_reward = :comp_crystals,
                     xp_reward      = :comp_xp,
                     exhausted      = :comp_exh,
                     updated_at     = NOW()
                 WHERE id = :comp_adv_id"
            )->execute([
                ':comp_crystals' => $crystals,
                ':comp_xp'       => $xp,
                ':comp_exh'      => $exhausted,
                ':comp_adv_id'   => $advId,
            ]);
        } catch (PDOException $e) {
            error_log('[AdventureService::completeAdventure] update: ' . $e->getMessage());
        }

        return array_merge($adventure, [
            'status'         => 'COMPLETED',
            'crystal_reward' => $crystals,
            'xp_reward'      => $xp,
            'exhausted'      => $exhausted,
        ]);
    }

    /**
     * Apply level-up(s) to a pet if XP threshold is met.
     * Returns number of levels gained.
     */
    private static function checkLevelUp(int $petId, int $currentXp, int $xpToNext, int $currentLevel): int
    {
        if ($currentXp < $xpToNext) {
            return 0;
        }

        $level    = $currentLevel;
        $xp       = $currentXp;
        $required = $xpToNext;
        $gained   = 0;

        while ($xp >= $required && $level < 100) {
            $xp     -= $required;
            $level++;
            $gained++;
            $required = (int)floor(100 * ($level ** 1.5));
        }

        if ($gained === 0) return 0;

        try {
            DB::get()->prepare(
                'UPDATE pets
                 SET level            = :lu_lvl,
                     current_xp       = :lu_xp,
                     xp_to_next_level = :lu_next,
                     updated_at       = NOW()
                 WHERE id = :lu_id'
            )->execute([
                ':lu_lvl'  => $level,
                ':lu_xp'   => $xp,
                ':lu_next' => $required,
                ':lu_id'   => $petId,
            ]);
        } catch (PDOException $e) {
            error_log('[AdventureService::checkLevelUp] ' . $e->getMessage());
        }

        return $gained;
    }

    private static function castExpedition(array $r): array
    {
        return [
            'id'                 => (int)$r['id'],
            'name'               => (string)$r['name'],
            'description'        => (string)$r['description'],
            'icon'               => (string)$r['icon'],
            'duration_seconds'   => (int)$r['duration_seconds'],
            'min_level'          => (int)$r['min_level'],
            'crystal_reward_min' => (int)$r['crystal_reward_min'],
            'crystal_reward_max' => (int)$r['crystal_reward_max'],
            'xp_reward'          => (int)$r['xp_reward'],
            'difficulty'         => (int)$r['difficulty'],
            'vit_exhaustion_cap' => (int)$r['vit_exhaustion_cap'],
        ];
    }

    private static function castAdventure(array $r): array
    {
        return [
            'id'               => (int)$r['id'],
            'pet_id'           => (int)$r['pet_id'],
            'expedition_id'    => (int)$r['expedition_id'],
            'expedition_name'  => (string)$r['expedition_name'],
            'expedition_desc'  => (string)$r['expedition_desc'],
            'expedition_icon'  => (string)$r['expedition_icon'],
            'difficulty'       => (int)$r['difficulty'],
            'start_time'       => (string)$r['start_time'],
            'end_time'         => (string)$r['end_time'],
            'status'           => (string)$r['status'],
            'crystal_reward'   => $r['crystal_reward'] !== null ? (int)$r['crystal_reward'] : null,
            'xp_reward'        => $r['xp_reward']      !== null ? (int)$r['xp_reward']      : null,
            'exhausted'        => (bool)$r['exhausted'],
        ];
    }
}
