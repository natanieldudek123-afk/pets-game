<?php
// =============================================================================
// src/Services/AuthService.php
//
// Fix: login() and register() used duplicate named params (:u/:e/:id) in
// WHERE ... OR ... clauses. PDO with ATTR_EMULATE_PREPARES=false (Home.pl)
// throws a fatal on duplicate named params. Each param now has a unique name.
// =============================================================================

declare(strict_types=1);

namespace PBBG\Services;

use PBBG\Utils\DB;
use PDO;
use PDOException;

class AuthService
{
    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------
    public static function register(string $username, string $email, string $password): array
    {
        $db = DB::get();

        // Unique param names: :reg_u and :reg_e
        $stmt = $db->prepare(
            'SELECT id, username, email FROM users
             WHERE LOWER(username) = LOWER(:reg_u) OR LOWER(email) = LOWER(:reg_e)
             LIMIT 1'
        );
        $stmt->execute([':reg_u' => $username, ':reg_e' => $email]);
        $existing = $stmt->fetch();

        if ($existing) {
            $field = strtolower($existing['username']) === strtolower($username)
                ? 'username' : 'email';
            throw new \RuntimeException("An account with that {$field} already exists.", 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $stmt = $db->prepare(
            'INSERT INTO users (username, email, password_hash)
             VALUES (:ins_username, :ins_email, :ins_hash)'
        );
        $stmt->execute([
            ':ins_username' => $username,
            ':ins_email'    => $email,
            ':ins_hash'     => $hash,
        ]);

        $id = (int)$db->lastInsertId();
        return ['id' => $id, 'username' => $username, 'email' => $email];
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------
    public static function login(string $identifier, string $password): array
    {
        $db = DB::get();

        // FIX: was WHERE ... = LOWER(:id) OR ... = LOWER(:id) — duplicate :id
        // PDO native mode rejects duplicate named params → fatal → empty 500.
        // Use :log_u and :log_e as distinct names for the same value.
        $stmt = $db->prepare(
            'SELECT id, username, email, password_hash
             FROM users
             WHERE LOWER(username) = LOWER(:log_u) OR LOWER(email) = LOWER(:log_e)
             LIMIT 1'
        );
        $stmt->execute([':log_u' => $identifier, ':log_e' => $identifier]);
        $user = $stmt->fetch();

        $dummyHash = '$2y$12$invalidhashfortimingprotection0000000000000000000000000';
        $valid = password_verify($password, $user ? $user['password_hash'] : $dummyHash);

        if (!$user || !$valid) {
            throw new \RuntimeException('Invalid username/email or password.', 401);
        }

        // Fire-and-forget last login update
        try {
            $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :upd_id')
               ->execute([':upd_id' => $user['id']]);
        } catch (PDOException $e) {
            error_log('[AuthService::login] last_login update failed: ' . $e->getMessage());
        }

        return ['id' => (int)$user['id'], 'username' => $user['username'], 'email' => $user['email']];
    }

    // -------------------------------------------------------------------------
    // Profile
    // -------------------------------------------------------------------------
    public static function getProfile(int $userId): array
    {
        $stmt = DB::get()->prepare(
            'SELECT
                u.id, u.username, u.email,
                u.account_level, u.prestige_points, u.premium_currency,
                u.xp_multiplier, u.gold_multiplier,
                u.created_at, u.last_login_at,
                (SELECT COUNT(*) FROM pets p WHERE p.user_id = u.id AND p.is_active = 1) AS pet_count
             FROM users u
             WHERE u.id = :prof_id
             LIMIT 1'
        );
        $stmt->execute([':prof_id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new \RuntimeException('User not found.', 404);
        }

        $user['id']               = (int)$user['id'];
        $user['account_level']    = (int)$user['account_level'];
        $user['prestige_points']  = (int)$user['prestige_points'];
        $user['premium_currency'] = (int)$user['premium_currency'];
        $user['xp_multiplier']    = (float)$user['xp_multiplier'];
        $user['gold_multiplier']  = (float)$user['gold_multiplier'];
        $user['pet_count']        = (int)$user['pet_count'];

        return $user;
    }
}
