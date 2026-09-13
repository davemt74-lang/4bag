<?php

declare(strict_types=1);

namespace FourBag;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class AuthService
{
    private const SESSION_TTL_SECONDS = 1209600; // 14 days

    public function __construct(private PDO $db) {}

    public function register(string $displayName, string $email, string $password): array
    {
        return $this->createUser($displayName, $email, $password, 'user');
    }

    public function createUser(string $displayName, string $email, string $password, string $systemRole = 'user'): array
    {
        $displayName = trim($displayName);
        $email = strtolower(trim($email));
        if ($displayName === '' || mb_strlen($displayName) > 150) {
            throw new RuntimeException('Display name is required and must be 150 characters or fewer.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new RuntimeException('A valid email address is required.');
        }
        if (strlen($password) < 10 || strlen($password) > 4096) {
            throw new RuntimeException('Password must be at least 10 characters.');
        }
        if (!in_array($systemRole, ['user', 'crew', 'admin'], true)) {
            throw new RuntimeException('Invalid system role.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to secure the password.');
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO users(email,display_name,password_hash,system_role,status,created_at,updated_at) VALUES(:email,:display_name,:password_hash,:system_role,'active',NOW(),NOW())");
            $stmt->execute([
                'email' => $email,
                'display_name' => $displayName,
                'password_hash' => $hash,
                'system_role' => $systemRole,
            ]);
        } catch (Throwable $e) {
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('An account already exists for this email address.');
            }
            throw $e;
        }

        return $this->userById((int)$this->db->lastInsertId());
    }

    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email=:email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
            throw new RuntimeException('Invalid email or password.');
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if ($newHash !== false) {
                $this->db->prepare('UPDATE users SET password_hash=:hash,updated_at=NOW() WHERE id=:id')->execute([
                    'hash' => $newHash,
                    'id' => (int)$user['id'],
                ]);
            }
        }

        $this->db->prepare('UPDATE users SET last_login_at=NOW(),updated_at=NOW() WHERE id=:id')->execute(['id' => (int)$user['id']]);
        $session = $this->createSession((int)$user['id']);

        return [
            'user' => $this->userById((int)$user['id']),
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
        ];
    }

    public function createSession(int $userId): array
    {
        $this->purgeExpiredSessions($userId);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires = (new DateTimeImmutable())->modify('+' . self::SESSION_TTL_SECONDS . ' seconds');

        $stmt = $this->db->prepare('INSERT INTO auth_sessions(user_id,token_hash,expires_at,last_seen_at,created_at) VALUES(:user_id,:token_hash,:expires_at,NOW(),NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'expires_at' => $expires->format(DATE_ATOM)];
    }

    public function currentUser(?string $token): ?array
    {
        $token = trim((string)$token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT u.id,u.email,u.display_name,u.system_role,u.status,s.id session_id,s.expires_at FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=:token_hash AND s.expires_at>NOW() AND u.status='active' LIMIT 1");
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }

        $this->db->prepare('UPDATE auth_sessions SET last_seen_at=NOW() WHERE id=:id')->execute(['id' => (int)$user['session_id']]);
        unset($user['session_id']);
        return $user;
    }

    public function logout(?string $token): void
    {
        $token = trim((string)$token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }
        $this->db->prepare('DELETE FROM auth_sessions WHERE token_hash=:token_hash')->execute(['token_hash' => hash('sha256', $token)]);
    }

    public function userByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT id,email,display_name,system_role,status,last_login_at,created_at,updated_at FROM users WHERE email=:email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function userById(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id,email,display_name,system_role,status,last_login_at,created_at,updated_at FROM users WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('User account not found.');
        }
        return $user;
    }

    private function purgeExpiredSessions(int $userId): void
    {
        $this->db->prepare('DELETE FROM auth_sessions WHERE user_id=:user_id AND expires_at<=NOW()')->execute(['user_id' => $userId]);
    }
}
