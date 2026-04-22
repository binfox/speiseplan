<?php

declare(strict_types=1);

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user;
    }

    $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if ($user === null) {
        unset($_SESSION['user_id'], $_SESSION['family_id']);
    }

    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('/?r=login');
    }
    return $user;
}

function login_user(string $email, string $password): bool
{
    $email = mb_strtolower(trim($email));
    $limitKey = rate_limit_key($email !== '' ? $email : client_ip());
    if (rate_limit_exceeded('login_failed', $limitKey, 5, 15 * 60)) {
        return false;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_security_event('login_failed', $limitKey);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    $families = user_families((int)$user['id']);
    if ($families !== []) {
        $_SESSION['family_id'] = (int)$families[0]['id'];
    }

    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function register_with_invite(string $name, string $email, string $password, string $code): array
{
    $name = trim($name);
    $email = mb_strtolower(trim($email));
    $code = mb_strtoupper(trim($code));
    $limitKey = rate_limit_key($email . '|' . $code);

    if (rate_limit_exceeded('register_failed', $limitKey, 6, 30 * 60)) {
        return [false, 'Zu viele Versuche. Bitte spaeter erneut probieren.'];
    }

    if ($name === '' || $email === '' || $password === '' || $code === '') {
        record_security_event('register_failed', $limitKey);
        return [false, 'Bitte alle Felder ausfuellen.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        record_security_event('register_failed', $limitKey);
        return [false, 'Bitte eine gueltige E-Mail-Adresse eingeben.'];
    }
    if (mb_strlen($password) < 8) {
        record_security_event('register_failed', $limitKey);
        return [false, 'Das Passwort muss mindestens 8 Zeichen haben.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $invite = find_invite_code($code, true);
        if ($invite === null) {
            $pdo->rollBack();
            record_security_event('register_failed', $limitKey);
            return [false, 'Der Einladungscode ist ungueltig oder abgelaufen.'];
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existingUserId = $stmt->fetchColumn();

        if ($existingUserId) {
            $userId = (int)$existingUserId;
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('INSERT IGNORE INTO family_members (family_id, user_id, role) VALUES (?, ?, ?)');
        $stmt->execute([(int)$invite['family_id'], $userId, 'member']);

        $stmt = $pdo->prepare('UPDATE invite_codes SET used_count = used_count + 1 WHERE id = ?');
        $stmt->execute([(int)$invite['id']]);

        $pdo->commit();
        $_SESSION['user_id'] = $userId;
        $_SESSION['family_id'] = (int)$invite['family_id'];
        session_regenerate_id(true);
        return [true, 'Registrierung abgeschlossen.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        log_app_error($e);
        return [false, 'Registrierung fehlgeschlagen. Bitte spaeter erneut probieren.'];
    }
}

function find_invite_code(string $code, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM invite_codes WHERE code = ? AND (expires_at IS NULL OR expires_at > NOW()) AND (max_uses IS NULL OR used_count < max_uses)';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([mb_strtoupper($code)]);
    $invite = $stmt->fetch();
    return $invite ?: null;
}

function user_families(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT f.id, f.name, fm.role
         FROM families f
         JOIN family_members fm ON fm.family_id = f.id
         WHERE fm.user_id = ?
         ORDER BY f.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function current_family(): ?array
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $families = user_families((int)$user['id']);
    if ($families === []) {
        return null;
    }

    $selectedId = (int)($_SESSION['family_id'] ?? 0);
    foreach ($families as $family) {
        if ((int)$family['id'] === $selectedId) {
            return $family;
        }
    }

    $_SESSION['family_id'] = (int)$families[0]['id'];
    return $families[0];
}

function require_family(): array
{
    require_login();
    $family = current_family();
    if ($family === null) {
        flash('error', 'Keiner Familie zugeordnet. Bitte mit Einladungscode registrieren.');
        redirect('/?r=logout');
    }
    return $family;
}

function set_current_family(int $familyId): bool
{
    $user = require_login();
    foreach (user_families((int)$user['id']) as $family) {
        if ((int)$family['id'] === $familyId) {
            $_SESSION['family_id'] = $familyId;
            return true;
        }
    }
    return false;
}

function current_family_role(int $familyId, int $userId): ?string
{
    $stmt = db()->prepare('SELECT role FROM family_members WHERE family_id = ? AND user_id = ?');
    $stmt->execute([$familyId, $userId]);
    $role = $stmt->fetchColumn();
    return is_string($role) ? $role : null;
}

function require_family_admin(int $familyId, int $userId): void
{
    if (current_family_role($familyId, $userId) !== 'admin') {
        http_response_code(403);
        throw new RuntimeException('Keine Berechtigung.');
    }
}

function create_invite_code(int $familyId, int $userId): string
{
    require_family_admin($familyId, $userId);

    $code = mb_strtoupper(bin2hex(random_bytes(12)));
    $stmt = db()->prepare('INSERT INTO invite_codes (family_id, code, created_by, expires_at, max_uses) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NULL)');
    $stmt->execute([$familyId, $code, $userId]);
    return $code;
}

function family_invite_codes(int $familyId): array
{
    $stmt = db()->prepare('SELECT * FROM invite_codes WHERE family_id = ? ORDER BY created_at DESC');
    $stmt->execute([$familyId]);
    return $stmt->fetchAll();
}
