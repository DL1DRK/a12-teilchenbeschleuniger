<?php
declare(strict_types=1);

const A12_UPDATER_VERSION = '2.2.0';
const A12_UPDATE_PAYLOAD = '__A12_UPDATE_PAYLOAD_BASE64__';

if (!is_file(__DIR__ . DIRECTORY_SEPARATOR . 'config.php') || !is_file(__DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    http_response_code(409);
    exit('Dieser Updater muss direkt in den Ordner einer bestehenden A12-Installation geladen werden.');
}

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if (!a12IsAuthenticated() || (string)($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Bitte zuerst als Administrator bei A12 anmelden und den Updater anschließend erneut öffnen.');
}

$lockFile = __DIR__ . DIRECTORY_SEPARATOR . '.a12-updater-' . A12_UPDATER_VERSION . '.lock';
$errors = [];
$success = false;
$selfDeleted = false;

function updaterH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<string,array{sha256:string,size:int,data:string}> */
function updatePayload(): array
{
    $json = base64_decode(A12_UPDATE_PAYLOAD, true);
    if ($json === false) {
        throw new RuntimeException('Das Updatepaket ist beschädigt.');
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Das Updatepaket ist ungültig.');
    }
    return $payload;
}

function migrateDatabase(PDO $database): void
{
    $database->exec('PRAGMA busy_timeout = 15000');
    $statement = $database->prepare("SELECT value FROM settings WHERE key = 'schema_version'");
    $statement->execute();
    $version = (int)($statement->fetchColumn() ?: 1);
    $statement->closeCursor();
    unset($statement);
    if ($version < 2) {
        $database->exec('PRAGMA foreign_keys = ON');
        $database->beginTransaction();
        try {
            $database->exec(<<<'SQL'
CREATE TABLE users_v2 (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL COLLATE NOCASE UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin','storekeeper','member')),
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TEXT
);
INSERT INTO users_v2 (id, username, password_hash, role, active, created_at, last_login_at)
SELECT id, username, password_hash, CASE WHEN role = 'admin' THEN 'admin' ELSE 'member' END, active, created_at, last_login_at FROM users;
DROP TABLE users;
ALTER TABLE users_v2 RENAME TO users;
ALTER TABLE movements ADD COLUMN actor_user_id INTEGER;
ALTER TABLE movements ADD COLUMN actor_name TEXT NOT NULL DEFAULT 'Altsystem';
UPDATE settings SET value = '2' WHERE key = 'schema_version';
SQL);
            $database->commit();
            $version = 2;
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    if ($version < 3) {
        $database->beginTransaction();
        try {
            $database->exec(<<<'SQL'
INSERT OR IGNORE INTO settings (key, value) VALUES ('github_repository', 'DL1DRK/a12-teilchenbeschleuniger');
INSERT OR IGNORE INTO settings (key, value) VALUES ('update_cache', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('update_checked_at', '0');
UPDATE settings SET value = '3' WHERE key = 'schema_version';
SQL);
            $database->commit();
            $version = 3;
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }
}

function installUpdateFiles(): void
{
    foreach (updatePayload() as $name => $asset) {
        if (!preg_match('~^[A-Za-z0-9._-]+$~', $name)) {
            throw new RuntimeException('Das Update enthält einen ungültigen Dateinamen.');
        }
        $compressed = base64_decode((string)$asset['data'], true);
        $contents = $compressed === false ? false : gzdecode($compressed);
        if ($contents === false || !hash_equals((string)$asset['sha256'], hash('sha256', $contents))) {
            throw new RuntimeException('Integritätsprüfung für ' . $name . ' fehlgeschlagen.');
        }
        $destination = __DIR__ . DIRECTORY_SEPARATOR . $name;
        $temporary = $destination . '.a12-update-' . bin2hex(random_bytes(5)) . '.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) === false || !@rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException($name . ' konnte nicht aktualisiert werden.');
        }
        @chmod($destination, 0644);
    }
}

$requirements = [
    ['PHP 8.1 oder neuer', version_compare(PHP_VERSION, '8.1.0', '>=')],
    ['PDO-SQLite verfügbar', extension_loaded('pdo_sqlite')],
    ['Zlib verfügbar', function_exists('gzdecode')],
    ['App-Ordner beschreibbar', is_writable(__DIR__)],
];
$requirementsOkay = !in_array(false, array_column($requirements, 1), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_file($lockFile)) {
        $errors[] = 'Dieser Updater wurde bereits ausgeführt.';
    }
    if (!hash_equals(a12CsrfToken(), (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.';
    }
    if (!$requirementsOkay) {
        $errors[] = 'Nicht alle Voraussetzungen sind erfüllt.';
    }
    if (!$errors) {
        try {
            migrateDatabase(a12Db());
            installUpdateFiles();
            a12Db()->exec("UPDATE settings SET value = '' WHERE key = 'update_cache'");
            a12Db()->exec("UPDATE settings SET value = '0' WHERE key = 'update_checked_at'");
            @file_put_contents($lockFile, 'Updated at ' . gmdate('c') . PHP_EOL, LOCK_EX);
            $success = true;
            if (isset($_POST['delete_updater'])) {
                $selfDeleted = @unlink(__FILE__);
            }
        } catch (Throwable $exception) {
            error_log('A12 updater error: ' . $exception->getMessage());
            $errors[] = 'Das Update konnte nicht abgeschlossen werden: ' . $exception->getMessage();
        }
    }
}

$isLocked = is_file($lockFile) && !$success;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>A12-Teilchenbeschleuniger · Update</title>
  <style>
    :root{color-scheme:light dark;--blue:#00acd3;--ink:#162329;--muted:#6d7c82;--paper:#eef5f7;--panel:#fff;--line:#d5e2e6;--good:#25825c;--bad:#c34848}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:radial-gradient(circle at 85% 5%,#00acd325,transparent 32%),var(--paper);color:var(--ink);font:15px/1.5 Inter,system-ui,sans-serif}.card{width:min(650px,100%);background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:30px;box-shadow:0 20px 60px #004a5d20}.eyebrow{margin:0;color:#007f9e;font-size:10px;font-weight:850;letter-spacing:.14em}h1{margin:4px 0;font-size:29px}.intro{color:var(--muted);margin:5px 0 22px}.checks{list-style:none;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:8px}.checks li{padding:9px 11px;border:1px solid var(--line);border-radius:8px}.ok{color:var(--good)}.no,.error{color:var(--bad)}.notice,.error,.success{padding:13px;border-radius:9px;margin:16px 0}.notice{background:#00acd311;border:1px solid #00acd344}.error{background:#c3484810;border:1px solid #c3484844}.success{background:#25825c11;border:1px solid #25825c44}.option{display:flex;gap:8px;align-items:flex-start;margin:18px 0;color:var(--muted)}.option input{margin-top:4px}.actions{display:flex;justify-content:flex-end;margin-top:20px}.button{border:0;border-radius:9px;background:var(--blue);color:#05222a;padding:12px 18px;font:inherit;font-weight:850;text-decoration:none;cursor:pointer}.button[disabled]{opacity:.45}@media(prefers-color-scheme:dark){:root{--ink:#edf7f9;--muted:#93a7ad;--paper:#10181c;--panel:#182328;--line:#2d3b41}.button{color:#05222a}}@media(max-width:520px){.card{padding:22px}.checks{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <main class="card">
    <p class="eyebrow">A12-TEILCHENBESCHLEUNIGER</p><h1>Update <?= updaterH(A12_UPDATER_VERSION) ?></h1>
    <p class="intro">Aktualisiert Anwendung und Datenbank. Bauteile, Bestände, Benutzer und Passwörter bleiben erhalten.</p>
    <?php if ($success): ?>
      <div class="success"><strong>Update abgeschlossen.</strong><br><?= $selfDeleted ? 'Der Updater wurde gelöscht.' : 'Der Updater wurde gesperrt und sollte manuell gelöscht werden.' ?></div>
      <div class="actions"><a class="button" href="./">A12 öffnen →</a></div>
    <?php elseif ($isLocked): ?>
      <div class="notice">Dieser Updater wurde bereits ausgeführt. Öffne die Anwendung über <a href="./">A12</a>.</div>
    <?php else: ?>
      <?php foreach ($errors as $error): ?><div class="error"><?= updaterH($error) ?></div><?php endforeach; ?>
      <ul class="checks"><?php foreach ($requirements as [$label,$okay]): ?><li class="<?= $okay ? 'ok' : 'no' ?>"><?= $okay ? '✓' : '✕' ?> <?= updaterH($label) ?></li><?php endforeach; ?></ul>
      <div class="notice"><strong>Bestehende Daten bleiben erhalten.</strong><br>Dieses Update ergänzt bei Bedarf Rollen, Bewegungszuordnung und den öffentlichen GitHub-Release-Kanal.</div>
      <form method="post"><input type="hidden" name="csrf" value="<?= updaterH(a12CsrfToken()) ?>"><label class="option"><input type="checkbox" name="delete_updater" value="1" checked><span>Updater nach erfolgreichem Abschluss löschen</span></label><div class="actions"><button class="button" type="submit" <?= $requirementsOkay ? '' : 'disabled' ?>>Update installieren</button></div></form>
    <?php endif; ?>
  </main>
</body>
</html>
