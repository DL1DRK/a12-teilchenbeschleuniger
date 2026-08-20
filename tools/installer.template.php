<?php
declare(strict_types=1);

const A12_INSTALLER_VERSION = '2.3.3';
const A12_PAYLOAD_BASE64 = '__A12_PAYLOAD_BASE64__';

session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if (!isset($_SESSION['a12_installer_csrf'])) {
    $_SESSION['a12_installer_csrf'] = bin2hex(random_bytes(24));
}

$lockFile = __DIR__ . DIRECTORY_SEPARATOR . '.a12-installer.lock';
$errors = [];
$success = false;
$installedUrl = '';
$installedPath = '';
$storagePath = '';
$storageOutsideWebroot = false;
$adminUsername = '';
$selfDeleted = false;

/** @return array<string,array{sha256:string,size:int,data:string}> */
function a12Payload(): array
{
    $json = base64_decode(A12_PAYLOAD_BASE64, true);
    if ($json === false) {
        throw new RuntimeException('Das eingebettete Installationspaket ist beschädigt.');
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Das eingebettete Installationspaket ist ungültig.');
    }
    return $payload;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeTarget(string $target): string
{
    $target = trim(str_replace('\\', '/', $target), '/');
    if ($target === '') {
        return '';
    }
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*$~', $target)) {
        throw new InvalidArgumentException('Der Zielordner darf nur Buchstaben, Zahlen, Punkt, Minus, Unterstrich und Schrägstriche enthalten.');
    }
    foreach (explode('/', $target) as $segment) {
        if ($segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('Relative Pfadsegmente sind nicht erlaubt.');
        }
    }
    return $target;
}

function directoryIsWritable(string $directory): bool
{
    if (is_dir($directory)) {
        return is_writable($directory);
    }
    $parent = dirname($directory);
    while (!is_dir($parent) && $parent !== dirname($parent)) {
        $parent = dirname($parent);
    }
    return is_dir($parent) && is_writable($parent);
}

function relativeInstallUrl(string $target): string
{
    if ($target === '') {
        return './';
    }
    return './' . implode('/', array_map('rawurlencode', explode('/', $target))) . '/';
}

/** @return array{path:string,outside:bool} */
function chooseStoragePath(string $installedPath): array
{
    $suffix = substr(bin2hex(random_bytes(12)), 0, 16);
    $documentRoot = @realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $candidates = [];
    if ($documentRoot !== false) {
        $candidates[] = dirname($documentRoot);
    }
    $candidates[] = dirname(__DIR__);
    foreach (array_unique($candidates) as $outsideParent) {
        if ($outsideParent === __DIR__ || !@is_dir($outsideParent) || !@is_writable($outsideParent)) {
            continue;
        }
        $parentReal = @realpath($outsideParent);
        $isOutside = $documentRoot === false || ($parentReal !== false && !str_starts_with(strtolower($parentReal . DIRECTORY_SEPARATOR), strtolower($documentRoot . DIRECTORY_SEPARATOR)));
        if ($isOutside) {
            return ['path' => $outsideParent . DIRECTORY_SEPARATOR . '.a12-data-' . $suffix, 'outside' => true];
        }
    }

    $server = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    $supportsDirectoryRules = str_contains($server, 'apache') || str_contains($server, 'litespeed') || str_contains($server, 'microsoft-iis');
    if (!$supportsDirectoryRules) {
        throw new RuntimeException('Es wurde kein sicher beschreibbarer Ordner außerhalb des Webroots gefunden. Auf diesem Webserver muss vor der Installation ein privater Datenpfad eingerichtet werden.');
    }
    return ['path' => $installedPath . DIRECTORY_SEPARATOR . 'private-' . $suffix, 'outside' => false];
}

function createProtectedStorage(string $storagePath): void
{
    if (!is_dir($storagePath) && !@mkdir($storagePath, 0700, true) && !is_dir($storagePath)) {
        throw new RuntimeException('Der private Datenordner konnte nicht angelegt werden.');
    }
    @chmod($storagePath, 0700);
    $htaccess = "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    $webConfig = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n";
    $protectionFiles = ['.htaccess' => $htaccess, 'web.config' => $webConfig, 'index.php' => "<?php\nhttp_response_code(404);\nexit;\n"];
    foreach ($protectionFiles as $name => $contents) {
        if (@file_put_contents($storagePath . DIRECTORY_SEPARATOR . $name, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Der private Datenordner konnte nicht vollständig abgesichert werden.');
        }
    }
}

function initializeDatabase(string $databasePath, string $username, string $password, bool $examples): void
{
    $database = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('PRAGMA busy_timeout = 5000');
    try {
        $database->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable) {
        $database->exec('PRAGMA journal_mode = DELETE');
    }
    $database->beginTransaction();
    try {
        $database->exec(<<<'SQL'
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL COLLATE NOCASE UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin','storekeeper','member')),
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TEXT
);
CREATE TABLE parts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  manufacturer TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL,
  value TEXT NOT NULL DEFAULT '',
  drawer TEXT NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 0 CHECK (quantity >= 0),
  minimum INTEGER NOT NULL DEFAULT 0 CHECK (minimum >= 0),
  datasheet TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_parts_name ON parts(name COLLATE NOCASE);
CREATE INDEX idx_parts_drawer ON parts(drawer COLLATE NOCASE);
CREATE TABLE movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  part_id INTEGER,
  part_name TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('Einlagerung','Entnahme','Korrektur')),
  delta INTEGER NOT NULL,
  stock INTEGER NOT NULL CHECK (stock >= 0),
  actor_user_id INTEGER,
  actor_name TEXT NOT NULL DEFAULT 'System',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE SET NULL,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_movements_part ON movements(part_id);
CREATE INDEX idx_movements_created ON movements(created_at DESC);
CREATE TABLE settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
INSERT INTO settings (key, value) VALUES ('schema_version', '3');
INSERT INTO settings (key, value) VALUES ('application_name', 'A12-Teilchenbeschleuniger');
INSERT INTO settings (key, value) VALUES ('github_repository', 'DL1DRK/a12-teilchenbeschleuniger');
INSERT INTO settings (key, value) VALUES ('update_cache', '');
INSERT INTO settings (key, value) VALUES ('update_checked_at', '0');
SQL);

        $statement = $database->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin']);
        $adminId = (int)$database->lastInsertId();
        if ($examples) {
            $exampleRows = [
                ['NE555P', 'Texas Instruments', 'IC / Halbleiter', 'Präzisions-Timer · DIP-8', 'B-14', 24, 5, 'https://www.ti.com/lit/ds/symlink/ne555.pdf'],
                ['BC547B', 'onsemi', 'Transistor', 'NPN · TO-92', 'C-07', 86, 20, 'https://www.onsemi.com/pdf/datasheet/bc550-d.pdf'],
                ['1N4148', 'Vishay', 'Diode', '100 V · DO-35', 'C-12', 8, 15, 'https://www.vishay.com/docs/81857/1n4148.pdf'],
            ];
            $part = $database->prepare('INSERT INTO parts (name, manufacturer, category, value, drawer, quantity, minimum, datasheet) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $movement = $database->prepare('INSERT INTO movements (part_id, part_name, type, delta, stock, actor_user_id, actor_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($exampleRows as $row) {
                $part->execute($row);
                $id = (int)$database->lastInsertId();
                $movement->execute([$id, $row[0], 'Einlagerung', $row[5], $row[5], $adminId, $username]);
            }
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    @chmod($databasePath, 0600);
}

function writeConfiguration(string $installedPath, string $databasePath): void
{
    $configuration = [
        'database' => $databasePath,
        'session_name' => 'A12_' . substr(bin2hex(random_bytes(12)), 0, 16),
        'app_key' => bin2hex(random_bytes(32)),
    ];
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
    $destination = $installedPath . DIRECTORY_SEPARATOR . 'config.php';
    if (file_put_contents($destination, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Die Anwendungskonfiguration konnte nicht geschrieben werden.');
    }
    @chmod($destination, 0640);
}

$requirements = [
    ['PHP 8.1 oder neuer', version_compare(PHP_VERSION, '8.1.0', '>=')],
    ['Zlib zum Entpacken', function_exists('gzdecode')],
    ['PDO-SQLite-Datenbank', extension_loaded('pdo_sqlite')],
    ['Sichere Passwortfunktionen', function_exists('password_hash')],
    ['Schreibzugriff im aktuellen Ordner', is_writable(__DIR__)],
    ['JSON-Unterstützung', function_exists('json_decode')],
];
$requirementsOkay = !in_array(false, array_column($requirements, 1), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_file($lockFile)) {
        $errors[] = 'Dieser Installer wurde bereits ausgeführt und ist gesperrt.';
    }
    if (!hash_equals($_SESSION['a12_installer_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.';
    }
    if (!$requirementsOkay) {
        $errors[] = 'Nicht alle Servervoraussetzungen sind erfüllt.';
    }

    try {
        $target = normalizeTarget((string)($_POST['target'] ?? ''));
        $installedPath = __DIR__ . ($target === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target));
        $installedUrl = relativeInstallUrl($target);
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }

    $adminUsername = trim((string)($_POST['admin_username'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $adminUsername)) {
        $errors[] = 'Der Administratorname muss 3 bis 64 Zeichen lang sein und darf Buchstaben, Zahlen, Punkt, Minus, Unterstrich und @ enthalten.';
    }
    if (strlen($adminPassword) < 10) {
        $errors[] = 'Das Administratorpasswort muss mindestens 10 Zeichen lang sein.';
    }
    if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
        $errors[] = 'Die beiden Passwörter stimmen nicht überein.';
    }

    if (!$errors && !directoryIsWritable($installedPath)) {
        $errors[] = 'Der Zielordner kann nicht angelegt oder beschrieben werden.';
    }

    if (!$errors) {
        try {
            $payload = a12Payload();
            $conflicts = [];
            foreach (array_keys($payload) as $name) {
                if (is_file($installedPath . DIRECTORY_SEPARATOR . $name)) {
                    $conflicts[] = $name;
                }
            }
            if (is_file($installedPath . DIRECTORY_SEPARATOR . 'index.html')) {
                $conflicts[] = 'index.html (alte Browser-Version)';
            }
            if (is_file($installedPath . DIRECTORY_SEPARATOR . 'config.php')) {
                throw new RuntimeException('Im Zielordner befindet sich bereits eine konfigurierte Installation von A12-Teilchenbeschleuniger. Dieser Installer führt bewusst kein Datenbank-Upgrade durch.');
            }
            $overwrite = isset($_POST['overwrite']);
            if ($conflicts && !$overwrite) {
                throw new RuntimeException('Diese Dateien existieren bereits: ' . implode(', ', $conflicts) . '. Zum Ersetzen muss die entsprechende Option aktiviert werden.');
            }

            $storage = chooseStoragePath($installedPath);
            $storagePath = $storage['path'];
            $storageOutsideWebroot = $storage['outside'];

            if (!is_dir($installedPath) && !mkdir($installedPath, 0755, true) && !is_dir($installedPath)) {
                throw new RuntimeException('Der Zielordner konnte nicht angelegt werden.');
            }

            foreach ($payload as $name => $asset) {
                if (!preg_match('~^[A-Za-z0-9._-]+$~', $name)) {
                    throw new RuntimeException('Das Paket enthält einen unzulässigen Dateinamen.');
                }
                $compressed = base64_decode((string)$asset['data'], true);
                $contents = $compressed === false ? false : gzdecode($compressed);
                if ($contents === false || !hash_equals((string)$asset['sha256'], hash('sha256', $contents))) {
                    throw new RuntimeException('Integritätsprüfung für ' . $name . ' fehlgeschlagen.');
                }
                $destination = $installedPath . DIRECTORY_SEPARATOR . $name;
                $temporary = $destination . '.a12-' . bin2hex(random_bytes(5)) . '.tmp';
                if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $destination)) {
                    @unlink($temporary);
                    throw new RuntimeException($name . ' konnte nicht geschrieben werden.');
                }
                @chmod($destination, 0644);
            }

            $legacyIndex = $installedPath . DIRECTORY_SEPARATOR . 'index.html';
            if (is_file($legacyIndex)) {
                $legacyBackup = $installedPath . DIRECTORY_SEPARATOR . 'index.html.a12-v1-backup-' . gmdate('YmdHis');
                if (!rename($legacyIndex, $legacyBackup)) {
                    throw new RuntimeException('Die alte index.html konnte nicht sicher umbenannt werden.');
                }
            }

            createProtectedStorage($storagePath);
            $databasePath = $storagePath . DIRECTORY_SEPARATOR . 'a12.sqlite';
            initializeDatabase($databasePath, $adminUsername, $adminPassword, isset($_POST['examples']));
            writeConfiguration($installedPath, $databasePath);

            $marker = json_encode([
                'application' => 'A12-Teilchenbeschleuniger',
                'installerVersion' => A12_INSTALLER_VERSION,
                'installedAt' => gmdate('c'),
                'storageOutsideWebroot' => $storageOutsideWebroot,
                'files' => array_map(static fn(array $asset): string => $asset['sha256'], $payload),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($storagePath . DIRECTORY_SEPARATOR . 'installation.json', $marker . PHP_EOL, LOCK_EX);
            @chmod($storagePath . DIRECTORY_SEPARATOR . 'installation.json', 0600);

            file_put_contents($lockFile, 'Installed at ' . gmdate('c') . PHP_EOL, LOCK_EX);
            $success = true;

            if (isset($_POST['delete_installer'])) {
                $selfDeleted = @unlink(__FILE__);
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
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
  <title>A12-Teilchenbeschleuniger · Installation</title>
  <style>
    :root{color-scheme:light dark;--blue:#00acd3;--blue-dark:#007f9e;--ink:#162329;--muted:#6d7c82;--paper:#eef5f7;--panel:#fff;--line:#d5e2e6;--good:#25825c;--bad:#c34848;--shadow:0 22px 65px #004a5d20}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 85% 5%,#00acd31c,transparent 30%),var(--paper);color:var(--ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{width:min(760px,calc(100% - 28px));margin:6vh auto}.brand{display:flex;align-items:center;margin-bottom:22px}.brand b{display:block;font-size:19px}.brand span{color:var(--muted);font-size:12px}.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);overflow:hidden}.head{padding:30px 32px 23px;border-bottom:1px solid var(--line)}.eyebrow{margin:0;color:var(--blue-dark);font-size:10px;font-weight:850;letter-spacing:.14em}.head h1{font-size:30px;letter-spacing:-.035em;margin:4px 0}.head p:last-child{color:var(--muted);margin:6px 0 0}.content{padding:25px 32px 32px}.checks{list-style:none;padding:0;margin:0 0 24px;display:grid;grid-template-columns:1fr 1fr;gap:9px}.checks li{border:1px solid var(--line);padding:10px 12px;border-radius:9px}.ok{color:var(--good)}.no{color:var(--bad)}label{display:block;font-weight:750;font-size:13px;margin:18px 0 6px}input[type=text],input[type=password]{width:100%;padding:12px;border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:9px;font:inherit;outline:none}input[type=text]:focus,input[type=password]:focus{border-color:var(--blue);box-shadow:0 0 0 3px #00acd321}.fieldgrid{display:grid;grid-template-columns:1fr 1fr;gap:0 13px}.fieldgrid .wide{grid-column:1/-1}.hint{display:block;margin-top:5px;color:var(--muted);font-size:12px}.option{display:flex;align-items:flex-start;gap:9px;font-weight:500;margin:13px 0}.option input{margin-top:4px}.notice,.error,.success{padding:13px 15px;border-radius:9px;margin:0 0 18px}.notice{background:#00acd311;border:1px solid #00acd344}.error{background:#c3484810;border:1px solid #c3484844;color:var(--bad)}.success{background:#25825c11;border:1px solid #25825c44}.action{display:flex;justify-content:flex-end;margin-top:22px}.button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;background:var(--blue);color:#05222a;padding:12px 19px;font:inherit;font-weight:850;cursor:pointer;text-decoration:none}.button:hover{filter:brightness(1.07)}.button[disabled]{opacity:.45;cursor:not-allowed}.success h2{margin:0 0 5px}.success p{margin:5px 0}.foot{text-align:center;color:var(--muted);font-size:11px;margin-top:14px}@media(prefers-color-scheme:dark){:root{--ink:#ecf6f8;--muted:#93a8ad;--paper:#10191d;--panel:#182429;--line:#304047;--blue-dark:#54d7f5;--shadow:0 22px 65px #0008}.button{color:#06232a}}@media(max-width:560px){.shell{margin:18px auto}.head,.content{padding:22px}.checks,.fieldgrid{grid-template-columns:1fr}.fieldgrid .wide{grid-column:auto}.head h1{font-size:25px}}
  </style>
</head>
<body>
<main class="shell">
  <div class="brand"><div><b>A12-Teilchenbeschleuniger</b><span>Ein-Datei-Installer · Version <?= h(A12_INSTALLER_VERSION) ?></span></div></div>
  <section class="card">
    <div class="head"><p class="eyebrow">INSTALLATION</p><h1>Bereit zum Beschleunigen?</h1><p>Dieser Assistent entpackt die Bauteil-Verwaltung direkt auf diesem Webserver.</p></div>
    <div class="content">
      <?php if ($success): ?>
        <div class="success"><h2>Installation abgeschlossen</h2><p>Die Anwendung wurde in <code><?= h($installedPath) ?></code> installiert.</p><p>Die gemeinsame SQLite-Datenbank liegt in <code><?= h($storagePath) ?></code><?= $storageOutsideWebroot ? ' außerhalb des öffentlichen App-Ordners.' : ' in einem zusätzlich geschützten privaten Ordner.' ?></p><p>Administrator: <strong><?= h($adminUsername) ?></strong></p><p><?= $selfDeleted ? 'Der Installer wurde anschließend gelöscht.' : 'Der Installer wurde gesperrt. Löschen Sie die Datei bei Gelegenheit manuell.' ?></p></div>
        <div class="action"><a class="button" href="<?= h($installedUrl) ?>">A12-Teilchenbeschleuniger öffnen →</a></div>
      <?php elseif ($isLocked): ?>
        <div class="notice"><strong>Installer gesperrt.</strong><br>Diese Datei wurde bereits verwendet. Löschen Sie <code>.a12-installer.lock</code> nur, wenn Sie bewusst erneut installieren möchten.</div>
      <?php else: ?>
        <?php foreach ($errors as $error): ?><div class="error"><?= h($error) ?></div><?php endforeach; ?>
        <ul class="checks">
          <?php foreach ($requirements as [$label, $okay]): ?><li class="<?= $okay ? 'ok' : 'no' ?>"><?= $okay ? '✓' : '✕' ?> <?= h($label) ?></li><?php endforeach; ?>
        </ul>
        <div class="notice"><strong>Gemeinsame Club-Datenbank:</strong> Der Installer legt eine lokale SQLite-Datei und ein Administratorkonto an. Alle angemeldeten Benutzer arbeiten anschließend mit demselben Bestand – ohne externen Datenbankserver.</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['a12_installer_csrf']) ?>">
          <label for="target">Installationsordner (optional)</label>
          <input id="target" name="target" type="text" placeholder="leer = aktueller Ordner, z. B. a12" value="<?= h((string)($_POST['target'] ?? 'a12')) ?>">
          <small class="hint">Der Pfad ist relativ zum Speicherort dieser Installer-Datei.</small>
          <div class="fieldgrid">
            <div class="wide"><label for="admin_username">Administratorname</label><input id="admin_username" name="admin_username" type="text" minlength="3" maxlength="64" autocomplete="username" required value="<?= h((string)($_POST['admin_username'] ?? 'admin')) ?>"></div>
            <div><label for="admin_password">Administratorpasswort</label><input id="admin_password" name="admin_password" type="password" minlength="10" autocomplete="new-password" required></div>
            <div><label for="admin_password_confirm">Passwort wiederholen</label><input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="10" autocomplete="new-password" required></div>
          </div>
          <label class="option"><input type="checkbox" name="examples" value="1" <?= isset($_POST['examples']) ? 'checked' : '' ?>><span>Drei Beispielbauteile zum Ausprobieren anlegen</span></label>
          <label class="option"><input type="checkbox" name="overwrite" value="1" <?= isset($_POST['overwrite']) ? 'checked' : '' ?>><span>Vorhandene gleichnamige App-Dateien ersetzen</span></label>
          <label class="option"><input type="checkbox" name="delete_installer" value="1" checked><span>Installer nach erfolgreicher Installation löschen</span></label>
          <div class="action"><button class="button" type="submit" <?= $requirementsOkay ? '' : 'disabled' ?>>A12-Teilchenbeschleuniger installieren</button></div>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <p class="foot">A12-Teilchenbeschleuniger · Keine externen Downloads während der Installation</p>
</main>
</body>
</html>
