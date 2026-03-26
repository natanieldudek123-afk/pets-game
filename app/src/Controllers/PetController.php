<?php
// =============================================================================
// src/Controllers/PetController.php
// Task #3 update: feed and play now require item_id in the request body.
// =============================================================================

declare(strict_types=1);

namespace PBBG\Controllers;

use PBBG\Services\PetService;
use PBBG\Middleware\Auth;
use PBBG\Utils\Response;
use PBBG\Utils\Validator;

class PetController
{
    public static function startHatch(array $body): void
    {
        $user = Auth::require();

        $v = new Validator($body);
        $v->required('name')->minLength('name', 1)->maxLength('name', 32)
          ->required('species')->inArray('species', ['BEAR', 'FOX', 'OWL']);

        if (isset($body['tier'])) {
            $v->integer('tier', 1, 5);
        }

        if ($v->fails()) {
            Response::error('Validation failed.', 400, $v->errors());
        }

        try {
            $result = PetService::startHatch(
                $user['id'],
                (string)$v->get('name'),
                (string)$v->get('species'),
                (int)$v->get('tier', 1)
            );
            Response::success(
                $result,
                "{$result['name']}'s egg is incubating. Check back in " .
                    round(HATCH_DURATION_SECS / 60) . ' minutes!',
                201
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public static function claimPet(array $params): void
    {
        $user  = Auth::require();
        $petId = (int)($params['petId'] ?? 0);

        if ($petId <= 0) {
            Response::error('Invalid pet ID.', 400);
        }

        try {
            $pet = PetService::claimPet($petId, $user['id']);
            Response::success(['pet' => $pet], "{$pet['name']} has hatched! Welcome your new companion.");
        } catch (\RuntimeException $e) {
            $extra = isset($e->remainingSeconds) ? ['remaining_seconds' => $e->remainingSeconds] : null;
            Response::error($e->getMessage(), $e->getCode() ?: 400, $extra);
        }
    }

    // POST /api.php?action=pet.feed&petId=N  body: { "item_id": 1 }
    public static function feed(array $params, array $body): void
    {
        $user  = Auth::require();
        $petId = (int)($params['petId'] ?? 0);

        if ($petId <= 0) {
            Response::error('Invalid pet ID.', 400);
        }

        $v = new Validator($body);
        $v->required('item_id')->integer('item_id', 1, PHP_INT_MAX);

        if ($v->fails()) {
            Response::error('Please select a food item from your inventory.', 400, $v->errors());
        }

        try {
            $result = PetService::feedPet($petId, $user['id'], (int)$v->get('item_id'));
            Response::success(
                [
                    'pet'                => $result['pet'],
                    'hunger_restored'    => $result['hunger_restored'],
                    'item_used'          => $result['item_used'],
                    'item_qty_remaining' => $result['item_qty_remaining'],
                ],
                "{$result['pet']['name']} ate the {$result['item_used']['name']} (+{$result['hunger_restored']} hunger)."
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // POST /api.php?action=pet.play&petId=N  body: { "item_id": 4 }
    public static function play(array $params, array $body): void
    {
        $user  = Auth::require();
        $petId = (int)($params['petId'] ?? 0);

        if ($petId <= 0) {
            Response::error('Invalid pet ID.', 400);
        }

        $v = new Validator($body);
        $v->required('item_id')->integer('item_id', 1, PHP_INT_MAX);

        if ($v->fails()) {
            Response::error('Please select a toy from your inventory.', 400, $v->errors());
        }

        try {
            $result = PetService::playWithPet($petId, $user['id'], (int)$v->get('item_id'));
            Response::success(
                [
                    'pet'                  => $result['pet'],
                    'happiness_restored'   => $result['happiness_restored'],
                    'item_used'            => $result['item_used'],
                    'item_qty_remaining'   => $result['item_qty_remaining'],
                ],
                "{$result['pet']['name']} played with the {$result['item_used']['name']} (+{$result['happiness_restored']} happiness)."
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public static function getMyPet(array $params): void
    {
        $user  = Auth::require();
        $petId = isset($params['petId']) ? (int)$params['petId'] : null;

        if ($petId !== null && $petId <= 0) {
            Response::error('Invalid pet ID.', 400);
        }

        try {
            $result = PetService::getMyPet($user['id'], $petId);
            Response::success($result, 'Pet data retrieved.');
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }
}
