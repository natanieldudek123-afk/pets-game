<?php
// =============================================================================
// src/Controllers/AdventureController.php
// Task #4 — Expedition System HTTP layer
// =============================================================================

declare(strict_types=1);

namespace PBBG\Controllers;

use PBBG\Services\AdventureService;
use PBBG\Middleware\Auth;
use PBBG\Utils\Response;
use PBBG\Utils\Validator;

class AdventureController
{
    // GET /api.php?action=adventure.getExpeditions
    public static function getExpeditions(): void
    {
        Auth::require();
        try {
            $expeditions = AdventureService::getAvailableExpeditions();
            Response::success(['expeditions' => $expeditions]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    // GET /api.php?action=adventure.getStatus&petId=N
    public static function getStatus(array $params): void
    {
        $user  = Auth::require();
        $petId = (int)($params['petId'] ?? 0);
        if ($petId <= 0) Response::error('Invalid pet ID.', 400);

        try {
            $status = AdventureService::getAdventureStatus($petId, $user['id']);
            Response::success(['adventure' => $status]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // POST /api.php?action=adventure.start&petId=N  body: { expedition_id }
    public static function start(array $params, array $body): void
    {
        $user  = Auth::require();
        $petId = (int)($params['petId'] ?? 0);
        if ($petId <= 0) Response::error('Invalid pet ID.', 400);

        $v = new Validator($body);
        $v->required('expedition_id')->integer('expedition_id', 1, PHP_INT_MAX);
        if ($v->fails()) Response::error('Validation failed.', 400, $v->errors());

        try {
            $result = AdventureService::startExpedition($petId, $user['id'], (int)$v->get('expedition_id'));
            Response::success($result, "Your companion has set off on: {$result['expedition_name']}!", 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // POST /api.php?action=adventure.collect&petId=N
    public static function collect(array $params): void
    {
        $user  = Auth::require();
        $petId = (int)($params['petId'] ?? 0);
        if ($petId <= 0) Response::error('Invalid pet ID.', 400);

        try {
            $result = AdventureService::collectRewards($petId, $user['id']);
            $msg    = "Rewards collected! +{$result['crystals_earned']} 💎  +{$result['xp_earned']} XP";
            if ($result['exhausted']) {
                $msg .= " (Pet returned exhausted — -{$result['hunger_penalty']} hunger)";
            }
            if ($result['levels_gained'] > 0) {
                $msg .= " ✨ Level up! +{$result['levels_gained']} level(s)!";
            }
            Response::success($result, $msg);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }
}
