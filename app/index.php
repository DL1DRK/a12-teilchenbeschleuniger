<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (hash_equals(a12CsrfToken(), (string)($_POST['csrf'] ?? ''))) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
        }
        session_destroy();
    }
    header('Location: ./');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!hash_equals(a12CsrfToken(), (string)($_POST['csrf'] ?? ''))) {
        $loginError = 'Die Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $statement = a12Db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? COLLATE NOCASE AND active = 1');
        $statement->execute([$username]);
        $user = $statement->fetch();
        if ($user && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = (string)$user['username'];
            $_SESSION['role'] = (string)$user['role'];
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            $update = a12Db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
            $update->execute([(int)$user['id']]);
            header('Location: ./');
            exit;
        }
        usleep(500000);
        $loginError = 'Benutzername oder Passwort ist nicht korrekt.';
    }
}

if (!a12IsAuthenticated()):
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>A12-Teilchenbeschleuniger · Anmeldung</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="theme-dark-blue.css">
</head>
<body class="loginPage">
  <main class="loginShell">
    <section class="loginCard">
      <div class="loginBrand"><img src="logo.png" alt="A12-Teilchenbeschleuniger"><div><p class="eyebrow">CLUB-LAGER</p><h1>A12-Teilchenbeschleuniger</h1></div></div>
      <p class="loginIntro">Melde dich an, um auf den gemeinsamen Bauteilbestand zuzugreifen.</p>
      <?php if ($loginError !== ''): ?><div class="loginError"><?= htmlspecialchars($loginError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
      <form method="post" class="loginForm">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(a12CsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <label>Benutzername<input name="username" autocomplete="username" required autofocus></label>
        <label>Passwort<input name="password" type="password" autocomplete="current-password" required></label>
        <button class="primary" type="submit" name="login" value="1">Anmelden</button>
      </form>
      <small class="loginFoot">Gemeinsame, lokale SQLite-Datenbank</small>
    </section>
  </main>
</body>
</html>
<?php
exit;
endif;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="a12-csrf" content="<?= htmlspecialchars(a12CsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
  <meta name="a12-role" content="<?= htmlspecialchars(a12CurrentRole(), ENT_QUOTES, 'UTF-8') ?>">
  <meta name="a12-user-id" content="<?= (int)$_SESSION['user_id'] ?>">
  <title>A12-Teilchenbeschleuniger · Bauteile</title>
  <link rel="icon" type="image/png" sizes="128x128" href="favicon.png">
  <link rel="apple-touch-icon" href="favicon.png">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="theme-dark-blue.css">
</head>
<body>
  <aside class="sidebar">
    <div class="brand"><img src="logo.png" alt="A12-Teilchenbeschleuniger"><div><b>A12</b><span>TEILCHEN<br>BESCHLEUNIGER</span></div></div>
    <nav>
      <button class="nav active" data-view="inventory">▦ <span>Bauteile</span></button>
      <button class="nav" data-view="drawers">▤ <span>Schubladen</span></button>
      <button class="nav" data-view="movements">⇄ <span>Bewegungen</span></button>
      <?php if (a12CurrentRole() === 'admin'): ?><button class="nav" data-view="users">♙ <span>Benutzer</span></button><?php endif; ?>
      <?php if (a12CurrentRole() === 'admin'): ?><button class="nav" data-view="system">⚙ <span>System <i class="updateDot" id="updateDot" hidden></i></span></button><?php endif; ?>
    </nav>
    <?php $roleLabel = ['admin' => 'Administrator', 'storekeeper' => 'Lagerist', 'member' => 'Mitglied'][a12CurrentRole()] ?? 'Benutzer'; ?>
    <div class="club"><span class="status"></span><div><b><?= htmlspecialchars((string)$_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></b><small><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?> · SQLite</small></div></div>
  </aside>

  <main>
    <header><div><p class="eyebrow">A12-TEILCHENBESCHLEUNIGER</p><h1 id="viewTitle">Bauteile</h1></div><div class="headerActions"><button class="themeToggle" id="themeToggle" type="button" aria-label="Dark-Mode einschalten" title="Darstellung wechseln"><span id="themeIcon">☾</span><span id="themeLabel">Dark-Mode</span></button><button class="logoutButton" type="submit" form="logoutForm" title="Abmelden">↪ <span>Abmelden</span></button><button class="primary" id="addBtn" <?= a12HasAnyRole(['admin', 'storekeeper']) ? '' : 'hidden' ?>>＋ Bauteil einlagern</button><button class="primary" id="addUserBtn" hidden>＋ Benutzer anlegen</button></div></header>
    <form method="post" id="logoutForm"><input type="hidden" name="csrf" value="<?= htmlspecialchars(a12CsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="logout" value="1"></form>
    <?php if (a12CurrentRole() === 'admin'): ?><div class="updateBanner" id="updateBanner" hidden><div><strong>Eine neue A12-Version ist verfügbar.</strong><span id="updateBannerText"></span></div><button type="button" data-open-system>Details ansehen</button></div><?php endif; ?>

    <section id="inventory" class="view active">
      <div class="stats">
        <article><span>BAUTEILE</span><strong id="statParts">–</strong><small>verschiedene Positionen</small></article>
        <article><span>GESAMTBESTAND</span><strong id="statUnits">–</strong><small>Einzelteile auf Lager</small></article>
        <article class="warn"><span>KNAPPER BESTAND</span><strong id="statLow">–</strong><small>unter Mindestbestand</small></article>
        <article><span>SCHUBLADEN</span><strong id="statDrawers">–</strong><small>belegte Lagerorte</small></article>
      </div>
      <div class="toolbar"><label class="search">⌕<input id="search" placeholder="Bauteil, Wert, Hersteller oder Schublade suchen …"></label><select id="category"><option value="">Alle Kategorien</option></select><button class="iconbtn" id="exportBtn" title="Daten exportieren" <?= a12CurrentRole() === 'admin' ? '' : 'hidden' ?>>⇩</button></div>
      <div class="panel"><table><thead><tr><th>BAUTEIL</th><th>KATEGORIE</th><th>LAGERORT</th><th>BESTAND</th><th>DATENBLATT</th><th></th></tr></thead><tbody id="partsBody"></tbody></table><div class="empty" id="empty">Keine passenden Bauteile gefunden.</div></div>
    </section>

    <section id="drawers" class="view"><div class="sectionIntro"><p>Alle belegten Fächer des Apothekerschranks auf einen Blick.</p></div><div id="drawerGrid" class="drawerGrid"></div></section>
    <section id="movements" class="view"><div class="panel"><table><thead><tr><th>ZEITPUNKT</th><th>BAUTEIL</th><th>VORGANG</th><th>MENGE</th><th>NEUER BESTAND</th><th>PERSON</th></tr></thead><tbody id="movementBody"></tbody></table></div></section>
    <?php if (a12CurrentRole() === 'admin'): ?><section id="users" class="view"><div class="sectionIntro"><p>Konten, Rollen und Zugriffsstatus der Clubmitglieder verwalten.</p></div><div class="panel"><table><thead><tr><th>BENUTZER</th><th>ROLLE</th><th>STATUS</th><th>LETZTE ANMELDUNG</th><th></th></tr></thead><tbody id="usersBody"></tbody></table></div></section><?php endif; ?>
    <?php if (a12CurrentRole() === 'admin'): ?><section id="system" class="view"><div class="sectionIntro"><p>Versionsstand und GitHub-Release-Kanal der Installation.</p></div><div class="systemGrid"><article class="systemCard"><span class="systemLabel">INSTALLIERTE VERSION</span><strong class="systemVersion">v<?= htmlspecialchars(A12_APP_VERSION, ENT_QUOTES, 'UTF-8') ?></strong><small>A12-Teilchenbeschleuniger</small></article><article class="systemCard updateCard"><div class="systemCardHead"><div><span class="systemLabel">GITHUB UPDATES</span><h2 id="updateHeadline">Update wird geprüft …</h2></div><button class="iconbtn" type="button" id="checkUpdateBtn" title="Jetzt prüfen">↻</button></div><p id="updateMessage">Verbindung zum Release-Kanal wird hergestellt.</p><div class="releaseNotes" id="releaseNotes" hidden></div><div class="updateMeta"><span id="updateChecked"></span><a href="https://github.com/DL1DRK/a12-teilchenbeschleuniger" target="_blank" rel="noopener">GitHub Repository ↗</a></div><div class="updateActions" id="updateActions" hidden><a class="secondaryButton" id="releaseLink" target="_blank" rel="noopener">Release-Details</a><a class="secondaryButton" id="updaterLink" target="_blank" rel="noopener">Manuell herunterladen</a><button class="primary" type="button" id="prepareUpdateBtn" hidden>Update vorbereiten</button></div><small class="updateHint">„Update vorbereiten“ lädt und prüft den Updater. Installiert wird erst nach Ihrer Bestätigung auf der nächsten Seite.</small></article></div></section><?php endif; ?>
  </main>

  <dialog id="partDialog"><form method="dialog" id="partForm"><div class="dialogHead"><div><p class="eyebrow">NEUER LAGEREINTRAG</p><h2>Bauteil einlagern</h2></div><button class="close" type="button" data-close-dialog="partDialog">×</button></div>
    <div class="formgrid"><label class="wide">Bezeichnung *<input name="name" required maxlength="120" placeholder="z. B. NE555P"></label><label>Hersteller<input name="manufacturer" maxlength="120" placeholder="z. B. Texas Instruments"></label><label>Kategorie<select name="category"><option>IC / Halbleiter</option><option>Widerstand</option><option>Kondensator</option><option>Diode</option><option>Transistor</option><option>Steckverbinder</option><option>Mechanik</option><option>Sonstiges</option></select></label><label>Wert / Typ<input name="value" maxlength="160" placeholder="z. B. Timer, DIP-8"></label><label>Schublade *<input name="drawer" required maxlength="40" placeholder="z. B. B-14"></label><label>Anfangsbestand<input name="quantity" type="number" min="0" max="100000000" value="1"></label><label>Mindestbestand<input name="minimum" type="number" min="0" max="100000000" value="5"></label></div>
    <label class="check"><input type="checkbox" name="autoDatasheet" checked> Datenblatt nach dem Speichern automatisch suchen</label>
    <div class="dialogActions"><button type="button" data-close-dialog="partDialog">Abbrechen</button><button class="primary" id="savePartBtn" type="submit" value="default">Bauteil speichern</button></div>
  </form></dialog>

  <dialog id="detailDialog"><div id="detailContent"></div></dialog>
  <?php if (a12CurrentRole() === 'admin'): ?><dialog id="userDialog"><form method="dialog" id="userForm"><div class="dialogHead"><div><p class="eyebrow">BENUTZERVERWALTUNG</p><h2 id="userDialogTitle">Benutzer anlegen</h2></div><button class="close" type="button" data-close-dialog="userDialog">×</button></div><input type="hidden" name="id" value=""><div class="formgrid"><label class="wide">Benutzername *<input name="username" required minlength="3" maxlength="64" autocomplete="off" placeholder="z. B. dl1abc"></label><label>Rolle<select name="role"><option value="member">Mitglied</option><option value="storekeeper">Lagerist</option><option value="admin">Administrator</option></select></label><label class="activeField">Status<select name="active"><option value="1">Aktiv</option><option value="0">Deaktiviert</option></select></label><label class="wide">Passwort <span id="passwordHint">*</span><input name="password" type="password" minlength="10" autocomplete="new-password" placeholder="mindestens 10 Zeichen"></label></div><div class="roleInfo" id="roleInfo"></div><div class="dialogActions"><button type="button" data-close-dialog="userDialog">Abbrechen</button><button class="primary" id="saveUserBtn" type="submit" value="default">Benutzer speichern</button></div></form></dialog><?php endif; ?>
  <div id="toast" role="status"></div>
  <script src="app.js"></script>
</body>
</html>
