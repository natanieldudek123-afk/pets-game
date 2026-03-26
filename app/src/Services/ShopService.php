<?php
// =============================================================================
// src/Services/ShopService.php
// Task #3 — Shop & Inventory
//
// Responsibilities:
//   getShopItems()       — Return all active shop items (catalogue)
//   buyItem()            — Deduct Crystals, increment user_inventory quantity
//   getUserInventory()   — Return user's owned items with quantities
//
// All DB calls use uniquely-named parameters (Home.pl PDO native mode safety).
// Every method wraps DB work in try/catch and converts PDOException ->
// RuntimeException so the controller always returns clean JSON.
// =============================================================================

declare(strict_types=1);

namespace PBBG\Services;

use PBBG\Utils\DB;
use PDOException;

class ShopService
{
    // =========================================================================
    // getShopItems — full catalogue
    // =========================================================================

    /**
     * Return all active shop items ordered by sort_order.
     * No auth required (items are public catalogue data).
     *
     * @return array<int, array>
     * @throws \RuntimeException
     */
    public static function getShopItems(): array
    {
        try {
            $stmt = DB::get()->prepare(
                'SELECT id, name, description, type, price, power, icon
                 FROM shop_items
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[ShopService::getShopItems] DB error: ' . $e->getMessage());
            throw new \RuntimeException('Database error loading shop items.', 500);
        }

        return array_map(fn($r) => self::castItem($r), $rows);
    }

    // =========================================================================
    // buyItem — purchase with Crystal check, atomic transaction
    // =========================================================================

    /**
     * Purchase an item for a user.
     *
     * Sequence (inside a DB transaction):
     *   1. Load item, verify it exists and is active.
     *   2. Lock the user row (SELECT ... FOR UPDATE) and check Crystal balance.
     *   3. Deduct Crystals from users.
     *   4. UPSERT user_inventory row (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * Returns the updated Crystal balance and the new inventory quantity.
     *
     * All parameter names are unique within each statement (Home.pl fix).
     *
     * @throws \RuntimeException
     */
    public static function buyItem(int $userId, int $itemId): array
    {
        $db = DB::get();

        try {
            $db->beginTransaction();

            // ── 1. Load item ──────────────────────────────────────────────────
            $stmt = $db->prepare(
                'SELECT id, name, type, price, power, icon
                 FROM shop_items
                 WHERE id = :item_id AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([':item_id' => $itemId]);
            $item = $stmt->fetch();

            if (!$item) {
                $db->rollBack();
                throw new \RuntimeException('Item not found or no longer available.', 404);
            }

            // ── 2. Lock user row, read Crystal balance ────────────────────────
            $stmt = $db->prepare(
                'SELECT id, premium_currency
                 FROM users
                 WHERE id = :buy_user_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([':buy_user_id' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $db->rollBack();
                throw new \RuntimeException('User not found.', 404);
            }

            $currentCrystals = (int)$user['premium_currency'];
            $itemPrice        = (int)$item['price'];

            if ($currentCrystals < $itemPrice) {
                $db->rollBack();
                throw new \RuntimeException(
                    "Not enough Crystals. You need {$itemPrice} 💎 but have {$currentCrystals} 💎.", 402
                );
            }

            // ── 3. Deduct Crystals ────────────────────────────────────────────
            $newBalance = $currentCrystals - $itemPrice;

            $db->prepare(
                'UPDATE users
                 SET premium_currency = :new_crystals,
                     updated_at       = NOW()
                 WHERE id = :deduct_user_id'
            )->execute([
                ':new_crystals'    => $newBalance,
                ':deduct_user_id'  => $userId,
            ]);

            // ── 4. Upsert inventory row ───────────────────────────────────────
            // INSERT ... ON DUPLICATE KEY UPDATE ensures one row per (user, item).
            // unique key uq_user_item prevents duplicates.
            $db->prepare(
                'INSERT INTO user_inventory (user_id, item_id, quantity)
                 VALUES (:inv_user_id, :inv_item_id, 1)
                 ON DUPLICATE KEY UPDATE
                     quantity   = quantity + 1,
                     updated_at = NOW()'
            )->execute([
                ':inv_user_id'  => $userId,
                ':inv_item_id'  => $itemId,
            ]);

            // Read back the current quantity for the response
            $stmt = $db->prepare(
                'SELECT quantity FROM user_inventory
                 WHERE user_id = :qty_user_id AND item_id = :qty_item_id
                 LIMIT 1'
            );
            $stmt->execute([':qty_user_id' => $userId, ':qty_item_id' => $itemId]);
            $newQty = (int)($stmt->fetchColumn() ?: 1);

            $db->commit();

        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[ShopService::buyItem] DB error: ' . $e->getMessage());
            throw new \RuntimeException('Database error during purchase.', 500);
        }

        return [
            'item'             => self::castItem($item),
            'quantity_owned'   => $newQty,
            'crystals_spent'   => $itemPrice,
            'crystals_remaining' => $newBalance,
        ];
    }

    // =========================================================================
    // getUserInventory — all items the user owns (including qty 0)
    // =========================================================================

    /**
     * Return the user's full inventory joined with shop_item details.
     * Items with quantity = 0 ARE included (shown greyed-out in UI).
     *
     * @return array<int, array>
     * @throws \RuntimeException
     */
    public static function getUserInventory(int $userId): array
    {
        try {
            $stmt = DB::get()->prepare(
                'SELECT
                    ui.id         AS inventory_id,
                    ui.item_id,
                    ui.quantity,
                    si.name,
                    si.description,
                    si.type,
                    si.price,
                    si.power,
                    si.icon
                 FROM user_inventory ui
                 JOIN shop_items si ON si.id = ui.item_id
                 WHERE ui.user_id = :inv_uid
                   AND si.is_active = 1
                 ORDER BY si.type ASC, si.sort_order ASC'
            );
            $stmt->execute([':inv_uid' => $userId]);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[ShopService::getUserInventory] DB error: ' . $e->getMessage());
            throw new \RuntimeException('Database error loading inventory.', 500);
        }

        return array_map(function ($r) {
            return [
                'inventory_id' => (int)$r['inventory_id'],
                'item_id'      => (int)$r['item_id'],
                'quantity'     => (int)$r['quantity'],
                'name'         => (string)$r['name'],
                'description'  => (string)$r['description'],
                'type'         => (string)$r['type'],
                'price'        => (int)$r['price'],
                'power'        => (int)$r['power'],
                'icon'         => (string)$r['icon'],
            ];
        }, $rows);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function castItem(array $r): array
    {
        return [
            'id'          => (int)$r['id'],
            'name'        => (string)$r['name'],
            'description' => (string)($r['description'] ?? ''),
            'type'        => (string)$r['type'],
            'price'       => (int)$r['price'],
            'power'       => (int)$r['power'],
            'icon'        => (string)$r['icon'],
        ];
    }
}
