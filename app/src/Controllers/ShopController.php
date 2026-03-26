<?php
// =============================================================================
// src/Controllers/ShopController.php
// Task #3 — Shop & Inventory HTTP layer
//
// Routes (all require auth):
//   GET  shop.getItems      — catalogue
//   POST shop.buy           — purchase (body: { item_id })
//   GET  shop.getInventory  — user's owned items
// =============================================================================

declare(strict_types=1);

namespace PBBG\Controllers;

use PBBG\Services\ShopService;
use PBBG\Middleware\Auth;
use PBBG\Utils\Response;
use PBBG\Utils\Validator;

class ShopController
{
    // -------------------------------------------------------------------------
    // GET /api.php?action=shop.getItems
    // Public catalogue — no auth strictly required but we keep it behind auth
    // so item prices can't be scraped without an account.
    // -------------------------------------------------------------------------
    public static function getItems(): void
    {
        Auth::require();

        try {
            $items = ShopService::getShopItems();
            Response::success(['items' => $items]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api.php?action=shop.buy
    // Body: { "item_id": 1 }
    // -------------------------------------------------------------------------
    public static function buy(array $body): void
    {
        $user = Auth::require();

        $v = new Validator($body);
        $v->required('item_id')->integer('item_id', 1, PHP_INT_MAX);

        if ($v->fails()) {
            Response::error('Validation failed.', 400, $v->errors());
        }

        $itemId = (int)$v->get('item_id');

        try {
            $result = ShopService::buyItem($user['id'], $itemId);
            Response::success(
                $result,
                "You purchased {$result['item']['name']}! " .
                "({$result['crystals_remaining']} 💎 remaining)"
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api.php?action=shop.getInventory
    // -------------------------------------------------------------------------
    public static function getInventory(): void
    {
        $user = Auth::require();

        try {
            $inventory = ShopService::getUserInventory($user['id']);
            Response::success(['inventory' => $inventory]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
