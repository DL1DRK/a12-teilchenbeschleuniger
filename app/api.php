<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
a12RequireAuthentication();

$action = (string)($_GET['action'] ?? 'snapshot');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

function a12Setting(string $key, string $default = ''): string
{
    $statement = a12Db()->prepare('SELECT value FROM settings WHERE key = ?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    $statement->closeCursor();
    return $value === false ? $default : (string)$value;
}

function a12SetSetting(string $key, string $value): void
{
    $statement = a12Db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $statement->execute([$key, $value]);
}

/** @return array{status:int,body:string} */
function a12GithubRequest(string $repository): array
{
    if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository)) {
        throw new RuntimeException('Das konfigurierte GitHub-Repository ist ungültig.');
    }
    $url = 'https://api.github.com/repos/' . $repository . '/releases/latest';
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: A12-Teilchenbeschleuniger/' . A12_APP_VERSION,
    ];

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) {
            throw new RuntimeException('GitHub konnte nicht erreicht werden: ' . $error);
        }
        return ['status' => $status, 'body' => (string)$body];
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('Für die Updateprüfung wird die PHP-Erweiterung cURL oder allow_url_fopen benötigt.');
    }
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('~\s(\d{3})\s~', $responseHeaders[0], $match)) {
        $status = (int)$match[1];
    }
    if ($body === false) {
        throw new RuntimeException('GitHub konnte nicht erreicht werden.');
    }
    return ['status' => $status, 'body' => (string)$body];
}

function a12ValidateUpdaterUrl(string $url, string $repository): void
{
    $parts = parse_url($url);
    $path = (string)($parts['path'] ?? '');
    $pattern = '~^/' . preg_quote($repository, '~') . '/releases/download/[^/]+/updater\.php$~i';
    if (($parts['scheme'] ?? '') !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'github.com'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match($pattern, $path)) {
        throw new DomainException('Das Release enthält keine zulässige Updater-Adresse.');
    }
}

function a12ValidateGithubDownloadHost(string $url): void
{
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowedHosts = ['github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, $allowedHosts, true)) {
        throw new DomainException('GitHub hat auf eine unerwartete Download-Adresse weitergeleitet.');
    }
}

function a12DownloadUpdater(string $url): string
{
    $maximumBytes = 5 * 1024 * 1024;
    if (function_exists('curl_init')) {
        $body = '';
        $tooLarge = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
            CURLOPT_USERAGENT => 'A12-Teilchenbeschleuniger/' . A12_APP_VERSION,
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body, &$tooLarge, $maximumBytes): int {
                if (strlen($body) + strlen($chunk) > $maximumBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $okay = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($handle);
        curl_close($handle);
        if ($tooLarge) {
            throw new DomainException('Der Updater überschreitet die zulässige Größe von 5 MB.');
        }
        if ($okay === false || $status !== 200) {
            throw new DomainException('Der Updater konnte nicht von GitHub geladen werden' . ($error !== '' ? ': ' . $error : '.'));
        }
        a12ValidateGithubDownloadHost($finalUrl);
        return $body;
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new DomainException('Für den direkten Download wird cURL oder allow_url_fopen benötigt.');
    }
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Accept: application/octet-stream\r\nUser-Agent: A12-Teilchenbeschleuniger/" . A12_APP_VERSION,
        'timeout' => 30,
        'follow_location' => 1,
        'max_redirects' => 5,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context, 0, $maximumBytes + 1);
    $headers = $http_response_header ?? [];
    $lastStatus = 0;
    foreach ($headers as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $match)) {
            $lastStatus = (int)$match[1];
        }
    }
    if ($body === false || $lastStatus !== 200) {
        throw new DomainException('Der Updater konnte nicht von GitHub geladen werden.');
    }
    if (strlen($body) > $maximumBytes) {
        throw new DomainException('Der Updater überschreitet die zulässige Größe von 5 MB.');
    }
    return $body;
}

/** @return array<string,mixed> */
function a12CheckForUpdates(bool $force): array
{
    $repository = a12Setting('github_repository', 'DL1DRK/a12-teilchenbeschleuniger');
    $lastChecked = (int)a12Setting('update_checked_at', '0');
    $cachedJson = a12Setting('update_cache', '');
    $cached = $cachedJson === '' ? null : json_decode($cachedJson, true);
    $age = time() - $lastChecked;
    $minimumAge = $force ? 60 : 21600;
    if (is_array($cached) && $age >= 0 && $age < $minimumAge) {
        $cached['cached'] = true;
        return $cached;
    }

    try {
        $response = a12GithubRequest($repository);
        if ($response['status'] === 404) {
            $result = [
                'currentVersion' => A12_APP_VERSION,
                'latestVersion' => null,
                'updateAvailable' => false,
                'repository' => $repository,
                'releaseUrl' => 'https://github.com/' . $repository . '/releases',
                'releaseName' => null,
                'releaseNotes' => 'Noch kein veröffentlichtes GitHub Release gefunden.',
                'publishedAt' => null,
                'updaterUrl' => null,
                'updaterDigest' => null,
                'updaterSize' => null,
                'checkedAt' => gmdate('c'),
                'cached' => false,
            ];
        } elseif ($response['status'] === 200) {
            $release = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $tag = ltrim((string)($release['tag_name'] ?? ''), "vV \t\n\r\0\x0B");
            if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $tag)) {
                throw new RuntimeException('Das aktuelle GitHub Release besitzt keine gültige Versionsnummer.');
            }
            $updater = null;
            foreach (($release['assets'] ?? []) as $asset) {
                if (($asset['name'] ?? '') === 'updater.php') {
                    $updater = $asset;
                    break;
                }
            }
            $result = [
                'currentVersion' => A12_APP_VERSION,
                'latestVersion' => $tag,
                'updateAvailable' => version_compare($tag, A12_APP_VERSION, '>'),
                'repository' => $repository,
                'releaseUrl' => (string)($release['html_url'] ?? 'https://github.com/' . $repository . '/releases'),
                'releaseName' => (string)($release['name'] ?? $release['tag_name'] ?? $tag),
                'releaseNotes' => substr((string)($release['body'] ?? ''), 0, 6000),
                'publishedAt' => $release['published_at'] ?? null,
                'updaterUrl' => $updater['browser_download_url'] ?? null,
                'updaterDigest' => $updater['digest'] ?? null,
                'updaterSize' => isset($updater['size']) ? (int)$updater['size'] : null,
                'checkedAt' => gmdate('c'),
                'cached' => false,
            ];
        } else {
            throw new RuntimeException('GitHub antwortete mit HTTP ' . $response['status'] . '.');
        }
        a12SetSetting('update_cache', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        a12SetSetting('update_checked_at', (string)time());
        return $result;
    } catch (Throwable $exception) {
        if (is_array($cached)) {
            $cached['cached'] = true;
            $cached['stale'] = true;
            $cached['error'] = $exception->getMessage();
            return $cached;
        }
        return [
            'currentVersion' => A12_APP_VERSION,
            'latestVersion' => null,
            'updateAvailable' => false,
            'repository' => $repository,
            'checkedAt' => gmdate('c'),
            'error' => $exception->getMessage(),
        ];
    }
}

/** @return array{url:string,version:string,sha256:string} */
function a12PrepareUpdate(): array
{
    $status = a12CheckForUpdates(true);
    if (!empty($status['error']) && empty($status['latestVersion'])) {
        throw new DomainException('Die aktuelle Release-Information konnte nicht geladen werden.');
    }
    if (empty($status['updateAvailable'])) {
        throw new DomainException('Diese Installation verwendet bereits die aktuelle Version.');
    }

    $repository = (string)($status['repository'] ?? '');
    $url = (string)($status['updaterUrl'] ?? '');
    $version = (string)($status['latestVersion'] ?? '');
    $digest = strtolower((string)($status['updaterDigest'] ?? ''));
    a12ValidateUpdaterUrl($url, $repository);
    if (!preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $digestMatch)) {
        throw new DomainException('GitHub liefert für diesen Updater keine gültige SHA-256-Prüfsumme.');
    }
    if (!is_writable(__DIR__)) {
        throw new DomainException('Der A12-Webordner ist für PHP nicht beschreibbar. Bitte verwenden Sie den manuellen Download.');
    }

    $contents = a12DownloadUpdater($url);
    $actualDigest = hash('sha256', $contents);
    if (!hash_equals($digestMatch[1], $actualDigest)) {
        throw new DomainException('Die SHA-256-Prüfung des heruntergeladenen Updaters ist fehlgeschlagen.');
    }
    if (!preg_match("/const A12_UPDATER_VERSION = '([^']+)';/", $contents, $versionMatch)
        || !hash_equals($version, (string)$versionMatch[1])) {
        throw new DomainException('Die Versionsnummer im Updater stimmt nicht mit dem GitHub-Release überein.');
    }

    $destination = __DIR__ . DIRECTORY_SEPARATOR . 'updater.php';
    $temporary = __DIR__ . DIRECTORY_SEPARATOR . '.a12-updater-download-' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new DomainException('Der geprüfte Updater konnte nicht im Webordner gespeichert werden.');
    }
    @chmod($temporary, 0644);
    $backup = null;
    if (is_file($destination)) {
        $backup = __DIR__ . DIRECTORY_SEPARATOR . '.a12-updater-backup-' . bin2hex(random_bytes(5)) . '.php';
        if (!@rename($destination, $backup)) {
            @unlink($temporary);
            throw new DomainException('Eine vorhandene updater.php konnte nicht ersetzt werden.');
        }
    }
    if (!@rename($temporary, $destination)) {
        @unlink($temporary);
        if ($backup !== null) {
            @rename($backup, $destination);
        }
        throw new DomainException('Der geprüfte Updater konnte nicht aktiviert werden.');
    }
    if ($backup !== null) {
        @unlink($backup);
    }
    return ['url' => 'updater.php', 'version' => $version, 'sha256' => $actualDigest];
}

try {
    if ($action === 'snapshot' && $method === 'GET') {
        a12Json(a12SnapshotForCurrentUser());
    }

    if ($action === 'update-status' && $method === 'GET') {
        a12RequireAnyRole(['admin']);
        a12Json(a12CheckForUpdates(isset($_GET['force']) && $_GET['force'] === '1'));
    }

    if ($action === 'prepare-update' && $method === 'POST') {
        a12RequireAnyRole(['admin']);
        a12RequireCsrf();
        a12Json(a12PrepareUpdate());
    }

    if ($action === 'export' && $method === 'GET') {
        a12RequireAnyRole(['admin']);
        $parts = a12Db()->query('SELECT id, name, manufacturer, category, value, drawer, quantity, minimum, datasheet, created_at, updated_at FROM parts ORDER BY id')->fetchAll();
        $movements = a12Db()->query('SELECT id, part_id AS partId, part_name AS name, type, delta, stock, actor_name AS actor, created_at AS date FROM movements ORDER BY id')->fetchAll();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="a12-lager-' . gmdate('Y-m-d') . '.json"');
        echo json_encode(['exportedAt' => gmdate('c'), 'parts' => $parts, 'movements' => $movements], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'create-part' && $method === 'POST') {
        a12RequireAnyRole(['admin', 'storekeeper']);
        a12RequireCsrf();
        $input = a12ReadJson();
        $name = a12Text($input['name'] ?? '', 120, true);
        $manufacturer = a12Text($input['manufacturer'] ?? '', 120);
        $category = a12Text($input['category'] ?? 'Sonstiges', 80, true);
        $value = a12Text($input['value'] ?? '', 160);
        $drawer = strtoupper(a12Text($input['drawer'] ?? '', 40, true));
        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000000]]);
        $minimum = filter_var($input['minimum'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000000]]);
        if ($quantity === false || $minimum === false) {
            throw new InvalidArgumentException('Bestand und Mindestbestand müssen gültige positive Zahlen sein.');
        }

        $database = a12Db();
        $database->exec('BEGIN IMMEDIATE');
        try {
            $statement = $database->prepare('INSERT INTO parts (name, manufacturer, category, value, drawer, quantity, minimum, datasheet, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $statement->execute([$name, $manufacturer, $category, $value, $drawer, $quantity, $minimum]);
            $partId = (int)$database->lastInsertId();
            if ($quantity > 0) {
                $movement = $database->prepare('INSERT INTO movements (part_id, part_name, type, delta, stock, actor_user_id, actor_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
                $movement->execute([$partId, $name, 'Einlagerung', $quantity, $quantity, (int)$_SESSION['user_id'], (string)$_SESSION['username']]);
            }
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
        a12Json(a12SnapshotForCurrentUser(), 201);
    }

    if ($action === 'adjust-stock' && $method === 'POST') {
        a12RequireCsrf();
        $input = a12ReadJson();
        $partId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $delta = filter_var($input['delta'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => -100000000, 'max_range' => 100000000]]);
        if ($partId === false || $delta === false || $delta === 0) {
            throw new InvalidArgumentException('Ungültige Bestandsänderung.');
        }
        a12RequireAnyRole($delta > 0 ? ['admin', 'storekeeper'] : ['admin', 'storekeeper', 'member']);

        $database = a12Db();
        $database->exec('BEGIN IMMEDIATE');
        try {
            $statement = $database->prepare('SELECT name, quantity FROM parts WHERE id = ?');
            $statement->execute([$partId]);
            $part = $statement->fetch();
            if (!$part) {
                throw new A12NotFoundException('Das Bauteil wurde nicht gefunden.');
            }
            $newStock = (int)$part['quantity'] + $delta;
            if ($newStock < 0) {
                throw new DomainException('Nicht genügend Bestand vorhanden.');
            }
            $update = $database->prepare('UPDATE parts SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $update->execute([$newStock, $partId]);
            $movement = $database->prepare('INSERT INTO movements (part_id, part_name, type, delta, stock, actor_user_id, actor_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            $movement->execute([$partId, $part['name'], $delta > 0 ? 'Einlagerung' : 'Entnahme', $delta, $newStock, (int)$_SESSION['user_id'], (string)$_SESSION['username']]);
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
        a12Json(a12SnapshotForCurrentUser());
    }

    if ($action === 'delete-part' && $method === 'POST') {
        a12RequireAnyRole(['admin']);
        a12RequireCsrf();
        $input = a12ReadJson();
        $partId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($partId === false) {
            throw new InvalidArgumentException('Ungültiges Bauteil.');
        }
        $statement = a12Db()->prepare('DELETE FROM parts WHERE id = ?');
        $statement->execute([$partId]);
        if ($statement->rowCount() === 0) {
            throw new A12NotFoundException('Das Bauteil wurde nicht gefunden.');
        }
        a12Json(a12SnapshotForCurrentUser());
    }

    if ($action === 'create-user' && $method === 'POST') {
        a12RequireAnyRole(['admin']);
        a12RequireCsrf();
        $input = a12ReadJson();
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $role = (string)($input['role'] ?? 'member');
        if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
            throw new InvalidArgumentException('Der Benutzername muss 3 bis 64 gültige Zeichen enthalten.');
        }
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }
        if (!in_array($role, ['admin', 'storekeeper', 'member'], true)) {
            throw new InvalidArgumentException('Ungültige Benutzerrolle.');
        }
        try {
            $statement = a12Db()->prepare('INSERT INTO users (username, password_hash, role, active) VALUES (?, ?, ?, 1)');
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
        } catch (PDOException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new InvalidArgumentException('Dieser Benutzername ist bereits vergeben.');
            }
            throw $exception;
        }
        a12Json(a12SnapshotForCurrentUser(), 201);
    }

    if ($action === 'update-user' && $method === 'POST') {
        a12RequireAnyRole(['admin']);
        a12RequireCsrf();
        $input = a12ReadJson();
        $userId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $role = (string)($input['role'] ?? '');
        $active = filter_var($input['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $password = (string)($input['password'] ?? '');
        if ($userId === false || $active === null || !in_array($role, ['admin', 'storekeeper', 'member'], true)) {
            throw new InvalidArgumentException('Ungültige Benutzerdaten.');
        }
        if ($password !== '' && strlen($password) < 10) {
            throw new InvalidArgumentException('Ein neues Passwort muss mindestens 10 Zeichen lang sein.');
        }
        $statement = a12Db()->prepare('SELECT id, role, active FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $target = $statement->fetch();
        if (!$target) {
            throw new A12NotFoundException('Der Benutzer wurde nicht gefunden.');
        }
        if ($userId === (int)$_SESSION['user_id'] && ($role !== 'admin' || !$active)) {
            throw new DomainException('Das eigene Administratorkonto kann nicht deaktiviert oder herabgestuft werden.');
        }
        if ($target['role'] === 'admin' && (int)$target['active'] === 1 && ($role !== 'admin' || !$active)) {
            $adminCount = (int)a12Db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
            if ($adminCount <= 1) {
                throw new DomainException('Der letzte aktive Administrator kann nicht deaktiviert oder herabgestuft werden.');
            }
        }
        $update = a12Db()->prepare('UPDATE users SET role = ?, active = ? WHERE id = ?');
        $update->execute([$role, $active ? 1 : 0, $userId]);
        if ($password !== '') {
            $passwordUpdate = a12Db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $passwordUpdate->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        }
        a12Json(a12SnapshotForCurrentUser());
    }

    a12Json(['error' => 'Unbekannte API-Anfrage.'], 404);
} catch (InvalidArgumentException $exception) {
    a12Json(['error' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    a12Json(['error' => $exception->getMessage()], 409);
} catch (A12NotFoundException $exception) {
    a12Json(['error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('A12 API error: ' . $exception->getMessage());
    a12Json(['error' => 'Der Server konnte die Anfrage nicht verarbeiten.'], 500);
}
