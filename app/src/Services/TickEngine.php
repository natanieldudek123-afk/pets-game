<?php
// =============================================================================
// src/Services/TickEngine.php
//
// XP System rules (applied every tick to every HATCHED active pet):
//   Base     (1x) : Always award 5 XP
//   Partial  (1.5x): hunger > 75 OR happiness > 75  → award 8 XP (round of 7.5)
//   Perfect  (2x) : hunger > 75 AND happiness > 75  → award 10 XP
//
// Bug fixes vs previous version:
//   - Previous code used one bulk UPDATE with a single XP value for all pets.
//     This could not implement tiered rewards per-pet, and because the threshold
//     was set to 90 most pets returned rowCount=0 causing the early-exit guard
//     to skip ALL level-up checks even for pets that did accumulate XP.
//   - New approach: fetch qualifying pets, compute the correct XP tier per row,
//     update each pet individually. Still wrapped in try/catch per pet so one
//     bad row cannot abort the rest of the tick.
//   - applyLevelUp is unchanged — cascading level formula stays the same.
// =============================================================================

declare(strict_types=1);

namespace PBBG\Services;

use PBBG\Utils\DB;
use PDOException;

class TickEngine
{
    // XP tier thresholds and awards
    private const XP_THRESHOLD  = 75;  // Stats must exceed this value
    private const XP_BASE       = 5;   // Both stats <= threshold
    private const XP_PARTIAL    = 8;   // One stat > threshold  (floor of 7.5)
    private const XP_PERFECT    = 10;  // Both stats > threshold

    // =========================================================================
    // Offline Progress
    // =========================================================================

    public static function applyOfflineProgress(int $petId): ?array
    {
        try {
            $db   = DB::get();
            $stmt = $db->prepare(
                'SELECT id, hatch_status, is_active, is_in_combat,
                        last_tick_at, current_hunger, current_happiness
                 FROM pets WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $petId]);
            $pet = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[TickEngine::applyOfflineProgress] DB error: ' . $e->getMessage());
            return null;
        }

        if (!$pet
            || $pet['hatch_status'] !== 'HATCHED'
            || !(bool)$pet['is_active']
            || (bool)$pet['is_in_combat']
        ) {
            return null;
        }

        $now         = time();
        $lastTick    = strtotime($pet['last_tick_at']);
        $elapsed     = $now - $lastTick;
        $missedTicks = (int)floor($elapsed / TICK_INTERVAL_SECS);

        if ($missedTicks <= 0) {
            return null;
        }

        $hungerLoss    = $missedTicks * TICK_HUNGER_DECAY;
        $happinessLoss = $missedTicks * TICK_HAPPINESS_DECAY;

        $newHunger    = max(STAT_MIN, (int)$pet['current_hunger']    - $hungerLoss);
        $newHappiness = max(STAT_MIN, (int)$pet['current_happiness'] - $happinessLoss);

        try {
            $db->prepare(
                'UPDATE pets
                 SET current_hunger    = :op_hunger,
                     current_happiness = :op_happiness,
                     last_tick_at      = NOW(),
                     updated_at        = NOW()
                 WHERE id = :op_id'
            )->execute([
                ':op_hunger'    => $newHunger,
                ':op_happiness' => $newHappiness,
                ':op_id'        => $petId,
            ]);
        } catch (PDOException $e) {
            error_log('[TickEngine::applyOfflineProgress] UPDATE error: ' . $e->getMessage());
            return null;
        }

        return [
            'pet_id'        => $petId,
            'missed_ticks'  => $missedTicks,
            'hunger_lost'   => $hungerLoss,
            'happiness_lost'=> $happinessLoss,
            'new_hunger'    => $newHunger,
            'new_happiness' => $newHappiness,
        ];
    }

    // =========================================================================
    // Step 1: Hatch eligible eggs
    // =========================================================================

    public static function hatchEligibleEggs(): int
    {
        try {
            $stmt = DB::get()->prepare(
                "UPDATE pets
                 SET hatch_status      = 'HATCHED',
                     current_hunger    = :he_h_val,
                     current_happiness = :he_a_val,
                     last_tick_at      = NOW(),
                     updated_at        = NOW()
                 WHERE hatch_status  = 'INCUBATING'
                   AND hatch_end_time <= NOW()"
            );
            $stmt->execute([':he_h_val' => STAT_MAX, ':he_a_val' => STAT_MAX]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('[TickEngine::hatchEligibleEggs] error: ' . $e->getMessage());
            return 0;
        }
    }

    // =========================================================================
    // Step 2: Decay needs
    // =========================================================================

    public static function decayPetNeeds(): int
    {
        try {
            $stmt = DB::get()->prepare(
                "UPDATE pets
                 SET current_hunger    = GREATEST(:dn_min_h, current_hunger    - :dn_h_decay),
                     current_happiness = GREATEST(:dn_min_a, current_happiness - :dn_a_decay),
                     last_tick_at      = NOW(),
                     updated_at        = NOW()
                 WHERE hatch_status = 'HATCHED'
                   AND is_active    = 1
                   AND is_in_combat = 0"
            );
            $stmt->execute([
                ':dn_min_h'  => STAT_MIN,
                ':dn_min_a'  => STAT_MIN,
                ':dn_h_decay'=> TICK_HUNGER_DECAY,
                ':dn_a_decay'=> TICK_HAPPINESS_DECAY,
            ]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('[TickEngine::decayPetNeeds] error: ' . $e->getMessage());
            return 0;
        }
    }

    // =========================================================================
    // Step 3: Award tiered XP — FIXED
    //
    // Every HATCHED active non-combat pet receives XP each tick.
    // The amount depends on their individual hunger/happiness values:
    //
    //   Perfect  (2x) : hunger > 75 AND happiness > 75 → 10 XP
    //   Partial  (1.5x): hunger > 75 OR  happiness > 75 → 8 XP
    //   Base     (1x) : everything else                 → 5 XP
    //
    // FIX: Previous version used a single bulk UPDATE that could not handle
    // per-pet tiered amounts, and its early-exit guard (xpAwarded === 0)
    // suppressed level-up checks even when pets had accumulated enough XP.
    // New approach: fetch all qualifying pets, compute XP tier per row,
    // update individually. Level-up is always checked regardless of this tick's
    // award (fixes the case where XP was already near xp_to_next_level).
    // =========================================================================

    public static function awardHappinessXP(): array
    {
        $db           = DB::get();
        $totalXp      = 0;
        $levelsGained = 0;

        // ── 3a: Fetch all HATCHED active non-combat pets ──────────────────────
        try {
            $stmt = $db->prepare(
                "SELECT id, level, current_xp, xp_to_next_level,
                        current_hunger, current_happiness
                 FROM pets
                 WHERE hatch_status = 'HATCHED'
                   AND is_active    = 1
                   AND is_in_combat = 0"
            );
            $stmt->execute();
            $pets = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[TickEngine::awardHappinessXP] fetch error: ' . $e->getMessage());
            return ['xp_awarded' => 0, 'levels_gained' => 0];
        }

        if (empty($pets)) {
            return ['xp_awarded' => 0, 'levels_gained' => 0];
        }

        // ── 3b: Compute and apply XP per pet ──────────────────────────────────
        foreach ($pets as $pet) {
            $hunger    = (int)$pet['current_hunger'];
            $happiness = (int)$pet['current_happiness'];
            $petId     = (int)$pet['id'];

            // Determine XP tier
            $hungerAbove    = $hunger    > self::XP_THRESHOLD;
            $happinessAbove = $happiness > self::XP_THRESHOLD;

            if ($hungerAbove && $happinessAbove) {
                $xp = self::XP_PERFECT;   // 10 — both above 75
            } elseif ($hungerAbove || $happinessAbove) {
                $xp = self::XP_PARTIAL;   //  8 — one above 75
            } else {
                $xp = self::XP_BASE;      //  5 — neither above 75
            }

            $newXp = (int)$pet['current_xp'] + $xp;
            $totalXp += $xp;

            try {
                $db->prepare(
                    'UPDATE pets
                     SET current_xp = :xp_new,
                         updated_at = NOW()
                     WHERE id = :xp_pet_id'
                )->execute([
                    ':xp_new'     => $newXp,
                    ':xp_pet_id'  => $petId,
                ]);
            } catch (PDOException $e) {
                error_log('[TickEngine::awardHappinessXP] XP update failed for pet '
                    . $petId . ': ' . $e->getMessage());
                continue;
            }
        }

        // ── 3c: Level-up check for ALL pets (not just those that just earned XP)
        // This ensures pets that were already near the threshold get promoted even
        // if they had accumulated XP from a previous tick where the check was skipped.
        try {
            $stmt = $db->prepare(
                "SELECT id, level, current_xp, xp_to_next_level
                 FROM pets
                 WHERE hatch_status  = 'HATCHED'
                   AND is_active     = 1
                   AND current_xp   >= xp_to_next_level
                 LIMIT 200"
            );
            $stmt->execute();
            $readyToLevel = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[TickEngine::awardHappinessXP] level-up fetch error: ' . $e->getMessage());
            return ['xp_awarded' => $totalXp, 'levels_gained' => 0];
        }

        foreach ($readyToLevel as $pet) {
            try {
                $levelsGained += self::applyLevelUp(
                    (int)$pet['id'],
                    (int)$pet['level'],
                    (int)$pet['current_xp'],
                    (int)$pet['xp_to_next_level']
                );
            } catch (\Throwable $e) {
                error_log('[TickEngine] Level-up failed for pet ' . $pet['id']
                    . ': ' . $e->getMessage());
            }
        }

        return ['xp_awarded' => $totalXp, 'levels_gained' => $levelsGained];
    }

    // =========================================================================
    // applyLevelUp — cascade-safe level promotion
    // =========================================================================

    private static function applyLevelUp(
        int $petId,
        int $currentLevel,
        int $currentXp,
        int $xpToNext
    ): int {
        $levelsGained = 0;
        $level        = $currentLevel;
        $xp           = $currentXp;
        $xpRequired   = $xpToNext;

        while ($xp >= $xpRequired && $level < 100) {
            $xp     -= $xpRequired;
            $level++;
            $levelsGained++;
            $xpRequired = (int)floor(100 * ($level ** 1.5));
        }

        if ($levelsGained === 0) {
            return 0;
        }

        try {
            DB::get()->prepare(
                'UPDATE pets
                 SET level            = :lu_lvl,
                     current_xp       = :lu_xp,
                     xp_to_next_level = :lu_next_xp,
                     updated_at       = NOW()
                 WHERE id = :lu_pet_id'
            )->execute([
                ':lu_lvl'     => $level,
                ':lu_xp'      => $xp,
                ':lu_next_xp' => $xpRequired,
                ':lu_pet_id'  => $petId,
            ]);
        } catch (PDOException $e) {
            error_log('[TickEngine::applyLevelUp] UPDATE error for pet '
                . $petId . ': ' . $e->getMessage());
        }

        return $levelsGained;
    }

    // =========================================================================
    // runTick — main cron entry point
    // =========================================================================

    public static function runTick(): string
    {
        $start = microtime(true);

        $hatched  = self::hatchEligibleEggs();
        $decayed  = self::decayPetNeeds();
        $xpResult = self::awardHappinessXP();

        $elapsed = (int)round((microtime(true) - $start) * 1000);

        return sprintf(
            '[%s] Tick OK — hatched: %d | decayed: %d | xp_awarded: %d | levels_gained: %d [%dms]',
            date('Y-m-d H:i:s'),
            $hatched,
            $decayed,
            $xpResult['xp_awarded'],
            $xpResult['levels_gained'],
            $elapsed
        );
    }
}
