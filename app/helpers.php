<?php

declare(strict_types=1);

function app_config(): array
{
    global $config;
    return $config;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function url(string $path = '', array $params = []): string
{
    $base = rtrim((string)(app_config()['base_path'] ?? ''), '/');
    $target = $base . '/' . ltrim($path, '/');
    if ($target === '/') {
        $target = $base !== '' ? $base . '/' : '/';
    }
    if ($params !== []) {
        $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($params);
    }
    return $target;
}

function current_route(): string
{
    $route = $_GET['r'] ?? 'home';
    return is_string($route) ? trim($route, '/') : 'home';
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function post_string(string $key, int $maxLength = 1000): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim($value), 0, $maxLength);
}

function post_int_or_null(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : null;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Ungueltige Anfrage.');
    }
}

function client_ip(): string
{
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function rate_limit_key(string $value): string
{
    return hash('sha256', mb_strtolower(trim($value)));
}

function rate_limit_exceeded(string $type, string $key, int $maxAttempts, int $windowSeconds): bool
{
    cleanup_security_events();

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM security_events
         WHERE event_type = ? AND event_key = ? AND ip_address = ? AND created_at >= (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$type, $key, client_ip(), $windowSeconds]);
    return (int)$stmt->fetchColumn() >= $maxAttempts;
}

function record_security_event(string $type, string $key): void
{
    $stmt = db()->prepare('INSERT INTO security_events (event_type, event_key, ip_address) VALUES (?, ?, ?)');
    $stmt->execute([$type, $key, client_ip()]);
}

function cleanup_security_events(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec('DELETE FROM security_events WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    } catch (Throwable) {
        // Rate limiting must not take the application down if cleanup fails.
    }
}

function log_app_error(Throwable $e): void
{
    error_log(sprintf(
        '[speiseplan] %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

function parse_lines(string $text): array
{
    $lines = preg_split('/\R/u', $text) ?: [];
    return array_values(array_filter(array_map(static fn(string $line): string => trim($line), $lines), static fn(string $line): bool => $line !== ''));
}

function lines_to_text(?string $json): string
{
    if (!$json) {
        return '';
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return '';
    }
    return implode("\n", array_map('strval', $decoded));
}

function monday_for_week(?string $week): DateTimeImmutable
{
    if ($week && preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches)) {
        return (new DateTimeImmutable())->setISODate((int)$matches[1], (int)$matches[2])->setTime(0, 0);
    }
    return (new DateTimeImmutable('monday this week'))->setTime(0, 0);
}

function iso_week(DateTimeImmutable $date): string
{
    return $date->format('o-\WW');
}

function human_date(DateTimeImmutable $date): string
{
    return $date->format('d.m.');
}

function weekday_short(DateTimeImmutable $date): string
{
    $names = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    return $names[((int)$date->format('N')) - 1];
}

function meal_slot_label(string $slot): string
{
    return $slot === 'mittag' ? 'Mittag' : 'Abendbrot';
}

function valid_meal_slot(string $slot): bool
{
    return in_array($slot, ['mittag', 'abend'], true);
}
