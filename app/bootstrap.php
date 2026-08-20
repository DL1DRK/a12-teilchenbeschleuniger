<?php
declare(strict_types=1);

const A12_APP_VERSION = '2.3.2';

$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('A12-Teilchenbeschleuniger ist noch nicht installiert.');
}

/** @var array{database:string,session_name:string,app_key:string} $a12Config */
$a12Config = require $configFile;
if (!is_array($a12Config) || empty($a12Config['database']) || empty($a12Config['session_name'])) {
    http_response_code(500);
    exit('Die A12-Konfiguration ist ungültig.');
}

final class A12NotFoundException extends Exception {}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($a12Config['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function a12Db(): PDO
{
    global $a12Config;
    static $database = null;
    if ($database instanceof PDO) {
        return $database;
    }
    $database = new PDO('sqlite:' . $a12Config['database'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('PRAGMA busy_timeout = 5000');
    return $database;
}

function a12CsrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf'];
}

function a12IsAuthenticated(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function a12CurrentRole(): string
{
    return (string)($_SESSION['role'] ?? '');
}

/** @param list<string> $roles */
function a12HasAnyRole(array $roles): bool
{
    return a12IsAuthenticated() && in_array(a12CurrentRole(), $roles, true);
}

function a12RequireAuthentication(): void
{
    if (!a12IsAuthenticated()) {
        a12Json(['error' => 'Die Sitzung ist abgelaufen.'], 401);
    }
}

/** @param list<string> $roles */
function a12RequireAnyRole(array $roles): void
{
    a12RequireAuthentication();
    if (!a12HasAnyRole($roles)) {
        a12Json(['error' => 'Für diese Aktion fehlt die Berechtigung.'], 403);
    }
}

function a12RequireCsrf(): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(a12CsrfToken(), $provided)) {
        a12Json(['error' => 'Ungültiges Sicherheitstoken. Bitte laden Sie die Seite neu.'], 419);
    }
}

/** @param array<string,mixed> $data */
function a12Json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

/** @return array<string,mixed> */
function a12ReadJson(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        a12Json(['error' => 'Die Anfrage ist zu groß.'], 413);
    }
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        a12Json(['error' => 'Ungültige Anfrage.'], 400);
    }
    return $decoded;
}

/** @return array{parts:list<array<string,mixed>>,movements:list<array<string,mixed>>} */
function a12Snapshot(): array
{
    $parts = a12Db()->query('SELECT id, name, manufacturer, category, value, drawer, quantity, minimum, datasheet FROM parts ORDER BY name COLLATE NOCASE')->fetchAll();
    $movements = a12Db()->query('SELECT id, part_id AS partId, part_name AS name, type, delta, stock, actor_name AS actor, created_at AS date FROM movements ORDER BY id DESC LIMIT 500')->fetchAll();
    foreach ($parts as &$part) {
        $part['id'] = (int)$part['id'];
        $part['quantity'] = (int)$part['quantity'];
        $part['minimum'] = (int)$part['minimum'];
    }
    unset($part);
    foreach ($movements as &$movement) {
        $movement['id'] = (int)$movement['id'];
        $movement['partId'] = $movement['partId'] === null ? null : (int)$movement['partId'];
        $movement['delta'] = (int)$movement['delta'];
        $movement['stock'] = (int)$movement['stock'];
    }
    unset($movement);
    return ['parts' => $parts, 'movements' => $movements];
}

/** @return list<array<string,mixed>> */
function a12Users(): array
{
    $users = a12Db()->query('SELECT id, username, role, active, created_at AS createdAt, last_login_at AS lastLoginAt FROM users ORDER BY active DESC, username COLLATE NOCASE')->fetchAll();
    foreach ($users as &$user) {
        $user['id'] = (int)$user['id'];
        $user['active'] = (bool)$user['active'];
    }
    unset($user);
    return $users;
}

/** @return array<string,mixed> */
function a12SnapshotForCurrentUser(): array
{
    $snapshot = a12Snapshot();
    if (a12CurrentRole() === 'admin') {
        $snapshot['users'] = a12Users();
    }
    return $snapshot;
}

function a12Text(mixed $value, int $maximum, bool $required = false): string
{
    $text = trim((string)$value);
    if ($required && $text === '') {
        throw new InvalidArgumentException('Bitte füllen Sie alle Pflichtfelder aus.');
    }
    if (strlen($text) > $maximum) {
        throw new InvalidArgumentException('Eine Eingabe überschreitet die zulässige Länge.');
    }
    return $text;
}

function a12RefreshAuthenticatedUser(): void
{
    if (!a12IsAuthenticated()) {
        return;
    }
    $statement = a12Db()->prepare('SELECT username, role, active FROM users WHERE id = ?');
    $statement->execute([(int)$_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || (int)$user['active'] !== 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['role'] = (string)$user['role'];
}

a12RefreshAuthenticatedUser();
