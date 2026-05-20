<?php

declare(strict_types=1);

namespace DriveMaster\Support;

use PDO;

final class Auth
{
    public static function ensureTable(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_users_username (username),
            KEY idx_users_active_role (is_active, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('DRIVEMASTERSESSID');
            session_start();
        }
    }

    public static function userCount(PDO $db): int
    {
        self::ensureTable($db);
        return (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function currentUser(PDO $db): ?array
    {
        self::startSession();
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        self::ensureTable($db);
        $stmt = $db->prepare('SELECT id, username, display_name, role, is_active, last_login_at, created_at FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        return $user;
    }

    public static function checkLogin(PDO $db, string $username, string $secret): bool
    {
        self::startSession();
        self::ensureTable($db);
        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1');
        $stmt->execute([trim($username)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($secret, (string)$user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
        return true;
    }

    public static function signOut(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function requireAdmin(PDO $db): void
    {
        $user = self::currentUser($db);
        if (!$user || (string)$user['role'] !== 'admin') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    public static function createUser(PDO $db, string $username, string $displayName, string $secret, string $role, int $isActive): int
    {
        self::ensureTable($db);
        $username = trim($username);
        $displayName = trim($displayName) !== '' ? trim($displayName) : $username;
        $role = $role === 'admin' ? 'admin' : 'user';
        if ($username === '' || $secret === '') {
            throw new \RuntimeException('Username and password are required.');
        }
        $stmt = $db->prepare('INSERT INTO users (username, display_name, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $displayName, password_hash($secret, PASSWORD_DEFAULT), $role, $isActive ? 1 : 0]);
        return (int)$db->lastInsertId();
    }
}
