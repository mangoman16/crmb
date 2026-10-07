<?php
declare(strict_types=1);

/**
 * The browser installer.
 *
 * Everything needed to get from "the files are uploaded" to "I can sign in", on
 * hosting with a panel and no shell: check what the server offers, take the
 * database details the panel handed out, write the configuration, create the
 * schema, and create the first administrator. One page, one button.
 *
 * It refuses to run again once an administrator exists, which is what closes it
 * afterwards, and it refuses while the database it was given does not answer.
 * Once a configuration exists, whoever finishes the installation must show she
 * can open the portal's files: the setup code in storage/setup-code.txt, or the
 * cookie this page handed the browser that wrote the configuration. Without
 * that, an installation left unfinished - a password refused after the
 * configuration was written - made the first stranger to open this page its
 * administrator.
 *
 * There is no CSRF token, deliberately: before the configuration exists there is
 * no privilege to borrow, and afterwards the code is the proof, which another
 * site cannot know and the cookie that carries it is not sent along with.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/../app/install.php';

// Read by the installer's one rule for a submitted value: an address or a form
// that sends a list, ?lang[]=, is no language and leaves the page in German.
// This page does not go through public/index.php, which drops every list from
// the address before anything reads it, so it guards its own reads.
install_locale(form_text($_GET['lang'] ?? $_POST['lang'] ?? 'de'));

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$configPath = config_path();
$state = install_state($configPath);
$stored = install_read_config($configPath);
$blockers = install_blockers();
$errors = [];
$warnings = [];
$manual = '';      // the configuration to create by hand when config/ is read-only
$done = false;
$handed = false;   // this response hands the browser the setup code as a cookie

$form = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'app_url' => install_base_url($_SERVER), 'timezone' => 'Europe/Vienna',
    'admin_name' => '', 'admin_email' => '',
];
$withDemo = false;
$demo = null;     // what demo_fill() created, to be shown on the finish page
// Not the database's name, server or user: whoever opens this page has not
// shown yet that she is the one installing it.
if ($state === 'configured' && $stored) {
    $form['app_url'] = (string)($stored['app_url'] ?? $form['app_url']);
    $form['timezone'] = (string)($stored['timezone'] ?? $form['timezone']);
}

$open = in_array($state, ['fresh', 'configured'], true) && !$blockers;
$needsCode = $open && is_file($configPath);
if ($needsCode) install_code($stored);
$typedCode = form_text($_POST['setup_code'] ?? '') ?? '';
$byCookie = fn(?array $config): bool => install_code_matches($config, $_COOKIE[INSTALL_CODE_COOKIE] ?? null);
$proven = !$needsCode || install_code_matches($stored, $typedCode) || $byCookie($stored);

// The folder this page is in, as the browser sees it: /setup.php and
// /verein/setup.php each keep the cookie to their own portal.
$cookie = ['path' => rtrim(dirname((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/')), '/') . '/',
           'secure' => install_is_https($_SERVER), 'httponly' => true, 'samesite' => 'Strict'];
/** Give the browser that is installing the code, so the same person never needs the file manager for it. */
$handCode = function (?array $config) use (&$handed, $cookie): void {
    if (($code = install_code($config)) === null) return;
    setcookie(INSTALL_CODE_COOKIE, $code, ['expires' => 0] + $cookie);
    $handed = true;
};

if ($post && $open && !$proven) {
    // Before anything else is read or tried: without the code this request
    // touches neither the database nor the configuration.
    $errors[] = install_t(
        'Der Einrichtungscode fehlt oder stimmt nicht. Er steht in der Datei ' . install_code_location($stored) . ' (im Dateimanager öffnen).',
        'The setup code is missing or wrong. It is in the file ' . install_code_location($stored) . ' (open it in the file manager).');
} elseif ($post && $open) {
    ['form' => $form, 'password' => $password, 'repeat' => $repeat, 'db_password' => $dbPassword]
        = install_submission($_POST, $form);
    $withDemo = isset($_POST['demo_fill']);

    $url = rtrim($form['app_url'], '/');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array((string)parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
        $errors[] = install_t('Die Adresse des Portals muss mit http:// oder https:// beginnen.',
                              'The portal address must start with http:// or https://.');
    // The address every link and every email will carry, and whether the
    // sign-in cookie is a secure one: decided here, once.
    elseif (parse_url($url, PHP_URL_SCHEME) !== 'https' && !install_host_is_local((string)parse_url($url, PHP_URL_HOST)))
        $errors[] = install_t('Die Adresse des Portals muss mit https:// beginnen. Ohne geht es nur für einen Test auf dem eigenen Rechner (localhost).',
                              'The portal address must start with https://. Only a test on your own computer (localhost) can do without.');
    if (!in_array($form['timezone'], timezone_identifiers_list(), true))
        $errors[] = install_t('Unbekannte Zeitzone.', 'Unknown time zone.');
    if ($form['admin_name'] === '' || mb_strlen($form['admin_name']) > 160)
        $errors[] = install_t('Bitte einen Namen eingeben.', 'Please enter a name.');
    if (!filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL))
        $errors[] = install_t('Bitte eine gültige E-Mail-Adresse eingeben.', 'Please enter a valid email address.');
    if (strlen($password) < 12 || strlen($password) > 72)
        $errors[] = install_t('Das Passwort muss 12 bis 72 Zeichen lang sein.', 'The password must be 12 to 72 characters long.');
    elseif ($password !== $repeat)
        $errors[] = install_t('Die beiden Passwörter stimmen nicht überein.', 'The two passwords do not match.');

    $db = ['host' => $form['db_host'], 'port' => (int)$form['db_port'], 'database' => $form['db_name'],
           'username' => $form['db_user'], 'password' => $dbPassword];
    if ($state === 'fresh') {
        if ($db['host'] === '' || $db['database'] === '' || $db['username'] === '')
            $errors[] = install_t('Datenbankserver, Datenbankname und Benutzername sind nötig. Sie stehen im Hosting-Panel.',
                                  'The database server, name and user are required. The hosting panel shows them.');
        elseif ($db['port'] < 1 || $db['port'] > 65535)
            $errors[] = install_t('Ungültiger Datenbank-Port. Üblich ist 3306.', 'Invalid database port. 3306 is the usual one.');
        elseif (!$errors) {
            $probe = install_probe($db);
            if (!$probe['ok']) $errors[] = $probe['message'];
            elseif ($note = install_server_note($probe['server'])) $warnings[] = $note;
        }
    } else {
        $db = $stored['db'];
    }

    if (!$errors) {
        try {
            if ($state === 'fresh') {
                // The same keys as config/config.example.php and the suite's own
                // tests/run-config.php; install_config_keys() names them, and the
                // install suite holds all three to it.
                $values = [
                    'app_url' => $url, 'app_key' => install_app_key($configPath), 'db' => $db,
                    'timezone' => $form['timezone'],
                    // A portal reached over plain HTTP cannot set a secure cookie;
                    // insisting would sign the operator straight back out.
                    'secure_cookies' => str_starts_with($url, 'https://'),
                    'session_idle_minutes' => 120,
                    'maintenance_file' => ROOT . '/storage/maintenance.flag',
                ];
                $source = install_config_source($values);
                if (!install_writable($configPath)) {
                    $manual = $source;
                    // She is about to create the file by hand; the code lets this
                    // browser finish afterwards without anybody else being able to.
                    $handCode($values);
                    throw new RuntimeException(install_t(
                        'Der Ordner config/ ist nicht beschreibbar. Die Datei unten bitte im Dateimanager als config/config.php anlegen und dann erneut auf „Installieren“ tippen.',
                        'The config/ folder is not writable. Create the file below as config/config.php in the file manager, then press “Install” again.'));
                }
                install_write_config($configPath, $source);
                $stored = install_read_config($configPath);
                // From here on the configuration exists, so if anything below
                // fails the next attempt needs the code. This browser has it.
                $handCode($stored);
            }
            // The application can only be loaded once there is a configuration,
            // which is why this is here rather than at the top of the file.
            $_SESSION = ['locale' => install_locale()];   // read by t(); no session is started here
            require __DIR__ . '/../app/bootstrap.php';
            // No safeguards on a first install: there is no earlier release to be
            // older than, and an empty database has nothing worth copying. The
            // runner honours that only for a database that has never been
            // migrated (schema_first_install()), whatever this page believes.
            schema_apply(null, safeguards: false);
            create_admin_account($form['admin_name'], $form['admin_email'], $password);
            // The code has nothing left to open.
            install_code_forget($stored);
            setcookie(INSTALL_CODE_COOKIE, '', ['expires' => 1] + $cookie);
            // Example data here rather than only afterwards, because the portal
            // is unrecognisable empty: no courses, no children, no charges, and
            // every page an empty state. Trying it out meant inventing a term's
            // worth of data first, which is the one thing somebody deciding
            // whether to use it will not do. Failing to fill it is not failing
            // to install: the portal is up either way, and the reason is shown.
            if ($withDemo) {
                try { $demo = demo_fill(); }
                catch (Throwable $e) { $warnings[] = install_t('Beispieldaten: ', 'Example data: ') . $e->getMessage(); }
            }
            $done = true;
        } catch (Throwable $e) {
            error_log('CRM setup: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

// What the page offers next follows from what this request did: a configuration
// written a moment ago makes the next attempt the last step, and that one needs
// the code - which this browser has just been handed, if anybody has.
if ($post && !$done) {
    $state = install_state($configPath);
    $stored = install_read_config($configPath);
    $open = in_array($state, ['fresh', 'configured'], true) && !$blockers;
    $needsCode = $open && is_file($configPath);
}
// Typed rather than carried by the cookie: asked again, with what she typed
// filled in when it was right, so a refused password does not cost her the code.
$askCode = $needsCode && !$handed && !$byCookie($stored);
$keptCode = install_code_matches($stored, $typedCode) ? $typedCode : '';

$portal = $done ? rtrim((string)config('app_url'), '/') . '/index.php' : '';
$other = install_locale() === 'en' ? 'de' : 'en';
// Set before a single byte of the page goes out; after that it is too late.
if ($state === 'installed' && !$done) http_response_code(403);
if ($state === 'unreachable' && !$done) { http_response_code(503); header('Retry-After: 300'); }
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?>
<!doctype html>
<html lang="<?=install_e(install_locale())?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?=install_e(install_t('Einrichtung', 'Setup'))?> – Badminton</title>
<link rel="stylesheet" href="<?=install_e(asset_path('app.css'))?>">
</head>
<body class="public-page">
<header class="public-header">
    <span class="brand"><span class="brand-mark">B<span></span></span>Badminton</span>
    <a class="language" href="?lang=<?=install_e($other)?>"><?=install_e(strtoupper($other))?></a>
</header>
<main class="public-main">

<?php if ($state === 'installed' && !$done): ?>
<div class="card setup-step">
    <h1><?=install_e(install_t('Schon eingerichtet', 'Already set up'))?></h1>
    <p class="muted"><?=install_e(install_t(
        'Dieses Portal ist fertig installiert. Die Einrichtung lässt sich nicht noch einmal starten.',
        'This portal is installed. Setup cannot be run a second time.'))?></p>
    <p><a class="button" href="index.php"><?=install_e(install_t('Zur Anmeldung', 'Go to sign-in'))?></a></p>
</div>

<?php elseif ($state === 'unreachable' && !$done): ?>
<?php /* Whoever reads this may be a parent, so it names no database, server or
         user - only what the operator can do about it. */ ?>
<div class="card setup-step">
    <h1><?=install_e(install_t('Die Datenbank antwortet gerade nicht', 'The database is not answering right now'))?></h1>
    <p class="muted"><?=install_e(install_t(
        'Für dieses Portal gibt es schon eine Einstellungsdatei, aber die Datenbank darin ist im Moment nicht erreichbar. Solange das so ist, lässt sich hier nichts einrichten. Bitte in ein paar Minuten noch einmal versuchen.',
        'This portal already has a settings file, but the database it names cannot be reached at the moment. Nothing can be set up here until it can. Please try again in a few minutes.'))?></p>
    <p class="muted"><?=install_e(install_t(
        'Bleibt es so: im Hosting-Panel unter „MySQL-Verwaltung“ nachsehen, ob die Datenbank läuft und ihr Passwort noch dasselbe ist wie in config/config.php.',
        'If it stays like this: check under “MySQL Management” in the hosting panel that the database is running and that its password is still the one in config/config.php.'))?></p>
</div>

<?php elseif ($done): ?>
<div class="card setup-step">
    <p class="eyebrow"><?=install_e(install_t('Fertig', 'Done'))?></p>
    <h1><?=install_e(install_t('Das Portal ist eingerichtet', 'The portal is ready'))?></h1>
    <p><?=install_e(install_t('Du meldest dich mit deiner E-Mail-Adresse an:', 'You sign in with your email address:'))?></p>
    <p class="mono"><?=install_e(email_normalised($form['admin_email']))?></p>
    <p class="muted"><?=install_e(install_t('Und mit dem Passwort, das du eben gewählt hast.', 'And with the password you have just chosen.'))?></p>
    <p><a class="button" href="<?=install_e($portal)?>"><?=install_e(install_t('Zur Anmeldung', 'Go to sign-in'))?></a></p>
</div>
<?php if ($demo): ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Die Beispieldaten', 'The example data'))?></h2>
    <p><?=install_e(install_t('Angelegt: ', 'Created: ')
        . (int)$demo['courses'] . install_t(' Kurse, ', ' courses, ') . (int)$demo['students']
        . install_t(' Kinder, ', ' children, ') . (int)$demo['charges'] . install_t(' Beiträge.', ' charges.'))?></p>
    <p><?=install_e(install_t('Diese Konten kannst du zum Anprobieren verwenden:', 'These accounts are there to try it with:'))?></p>
    <ul>
        <?php foreach ($demo['logins'] as $login): ?>
        <li><span class="mono"><?=install_e($login['email'])?></span> <?=install_e($login['role'] === 'student' ? install_t('(Familie)', '(family)') : install_t('(Trainerin)', '(trainer)'))?></li>
        <?php endforeach ?>
    </ul>
    <p><?=install_e(install_t('Passwort für alle: ', 'The password for all of them: '))?>
       <strong class="mono"><?=install_e((string)$demo['password'])?></strong></p>
    <p class="muted"><?=install_e(install_t(
        'Jetzt aufschreiben – es wird nicht noch einmal angezeigt. Entfernen lässt sich alles unter Einstellungen → System → „Beispieldaten entfernen“.',
        'Write it down now – it is not shown again. Remove all of it under Einstellungen → System → “Remove example data”.'))?></p>
</div>
<?php endif ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Wie es weitergeht', 'What comes next'))?></h2>
    <?php /* The checklist owns what is left and how many steps that is (ADR 0011).
             This page used to list them itself and was wrong within a release. */ ?>
    <p class="muted"><?=install_e(install_t(
        'Nach der Anmeldung zeigt dir die Liste „Dein Portal einrichten“ Schritt für Schritt, was noch fehlt.',
        'Once you are signed in, the “Set up your portal” checklist shows you what is left, step by step.'))?></p>
    <p class="muted"><?=install_e(install_t(
        'Updates brauchen keinen weiteren Schritt: die neuen Dateien hochladen genügt, die Datenbank passt sich beim nächsten Aufruf selbst an.',
        'Updates need no further step: uploading the new files is enough, and the database updates itself on the next page view.'))?></p>
</div>

<?php else: ?>
<div class="card setup-step">
    <p class="eyebrow"><?=install_e(install_t('Einrichtung', 'Setup'))?></p>
    <h1><?=install_e(install_t('Badminton-Portal installieren', 'Install the badminton portal'))?></h1>
    <p class="muted"><?=install_e(install_t(
        'Die Angaben zur Datenbank stehen im Hosting-Panel unter „MySQL-Verwaltung“. Alles andere ist schon ausgefüllt.',
        'The database details are in the hosting panel under “MySQL Management”. Everything else is filled in already.'))?></p>
</div>

<?php foreach ($errors as $message): ?>
<div class="flash error setup-note" role="alert"><?=install_e($message)?></div>
<?php endforeach ?>
<?php foreach ($warnings as $message): ?>
<div class="notice setup-note"><?=install_e($message)?></div>
<?php endforeach ?>

<?php if ($manual !== ''): ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Einstellungsdatei von Hand anlegen', 'Create the settings file by hand'))?></h2>
    <p class="muted"><?=install_e(install_t(
        'Im Dateimanager eine neue Datei config/config.php anlegen und genau diesen Inhalt hineinkopieren. Danach diese Seite neu laden.',
        'Create a new file config/config.php in the file manager and paste exactly this into it. Then reload this page.'))?></p>
    <div class="field"><label class="visually-hidden" for="manual">config/config.php</label>
    <textarea id="manual" rows="18" readonly spellcheck="false"><?=install_e($manual)?></textarea></div>
</div>
<?php endif ?>

<?php $requirements = install_requirements(); ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Was dieser Server bietet', 'What this server offers'))?></h2>
    <?php foreach ($requirements as $check):
        $tone = $check['ok'] ? 'green' : ($check['fatal'] ? 'red' : '');
        $verdict = $check['ok'] ? install_t('in Ordnung', 'fine')
            : ($check['fatal'] ? install_t('fehlt', 'missing') : install_t('eingeschränkt', 'limited')); ?>
    <div class="record-row">
        <div>
            <strong><?=install_e($check['label'])?></strong>
            <?php if ($check['fix'] !== ''): ?><p><?=install_e($check['fix'])?></p><?php endif ?>
        </div>
        <span class="badge <?=install_e($tone)?>"><?=install_e($verdict)?></span>
    </div>
    <?php endforeach ?>
</div>

<?php if ($blockers): ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Erst danach kann es weitergehen', 'This has to be fixed first'))?></h2>
    <p class="muted"><?=install_e(install_t(
        'Die rot markierten Punkte oben müssen im Hosting-Panel erledigt werden. Diese Seite danach neu laden.',
        'The points marked in red above have to be handled in the hosting panel. Reload this page afterwards.'))?></p>
    <?php if (!install_request_secure($_SERVER)): ?>
    <p><a class="button" href="<?=install_e(install_secure_setup_url($_SERVER))?>"><?=install_e(install_t('Über https:// öffnen', 'Open over https://'))?></a></p>
    <?php endif ?>
</div>
<?php else: ?>
<form method="post" class="form">
<input type="hidden" name="lang" value="<?=install_e(install_locale())?>">

<?php if ($state === 'fresh'): ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Datenbank', 'Database'))?></h2>
    <p class="muted"><?=install_e(install_t(
        'Die Datenbank muss im Hosting-Panel bereits angelegt sein und einem Benutzer gehören. Sie darf leer sein – die Tabellen legt die Einrichtung an.',
        'The database must already exist in the hosting panel and belong to a user. It may be empty; setup creates the tables.'))?></p>
    <div class="grid two">
        <div class="field"><label for="db_host"><?=install_e(install_t('Datenbankserver', 'Database server'))?></label>
            <input id="db_host" name="db_host" value="<?=install_e($form['db_host'])?>" required>
            <small><?=install_e(install_t('Bei den meisten Hostern: localhost', 'On most hosting: localhost'))?></small></div>
        <div class="field"><label for="db_port">Port</label>
            <input id="db_port" name="db_port" inputmode="numeric" value="<?=install_e($form['db_port'])?>" required></div>
        <div class="field"><label for="db_name"><?=install_e(install_t('Name der Datenbank', 'Database name'))?></label>
            <input id="db_name" name="db_name" value="<?=install_e($form['db_name'])?>" required autocapitalize="off" spellcheck="false"></div>
        <div class="field"><label for="db_user"><?=install_e(install_t('Benutzername', 'User name'))?></label>
            <input id="db_user" name="db_user" value="<?=install_e($form['db_user'])?>" required autocapitalize="off" spellcheck="false"></div>
        <div class="field full"><label for="db_password"><?=install_e(install_t('Passwort der Datenbank', 'Database password'))?></label>
            <input id="db_password" name="db_password" type="password" autocomplete="off"></div>
    </div>
</div>
<?php else: ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Datenbank', 'Database'))?></h2>
    <p class="muted"><?=install_e(install_t(
        'Die Zugangsdaten sind bereits gespeichert und werden nicht noch einmal abgefragt. Es fehlt nur das erste Konto.',
        'The credentials are already stored and are not asked for again. Only the first account is missing.'))?></p>
</div>
<?php endif ?>

<?php if ($askCode): ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Einrichtungscode', 'Setup code'))?></h2>
    <p class="muted"><?=install_e(install_t(
        'Für dieses Portal gibt es schon eine Einstellungsdatei. Damit nur du die Einrichtung abschließen kannst, liegt ein Code in einer Datei, die nur mit Zugang zu den Dateien zu öffnen ist:',
        'This portal already has a settings file. So that only you can finish setting it up, there is a code in a file that only somebody with access to the files can open:'))?></p>
    <p class="mono"><?=install_e(install_code_location($stored))?></p>
    <p class="muted"><?=install_e(install_t(
        'Im Dateimanager des Hosting-Panels öffnen und die erste Zeile hier eingeben, zum Beispiel ABCD-EFGH-JKLM. Groß- und Kleinschreibung und Bindestriche sind egal.',
        'Open it in the hosting panel’s file manager and enter its first line here, for example ABCD-EFGH-JKLM. Upper or lower case and the dashes do not matter.'))?></p>
    <div class="field"><label for="setup_code"><?=install_e(install_t('Einrichtungscode', 'Setup code'))?></label>
        <input id="setup_code" name="setup_code" value="<?=install_e($keptCode)?>" required autocomplete="off" autocapitalize="characters" spellcheck="false"></div>
</div>
<?php endif ?>

<div class="card setup-step">
    <h2><?=install_e(install_t('Erstes Konto', 'First account'))?></h2>
    <p class="muted"><?=install_e(install_t(
        'Damit meldest du dich anschließend an. Alle weiteren Konten werden später im Portal eingeladen.',
        'This is what you sign in with. Every further account is invited later inside the portal.'))?></p>
    <div class="grid two">
        <div class="field"><label for="admin_name"><?=install_e(install_t('Name', 'Name'))?></label>
            <input id="admin_name" name="admin_name" value="<?=install_e($form['admin_name'])?>" required></div>
        <div class="field"><label for="admin_email"><?=install_e(install_t('E-Mail-Adresse', 'Email address'))?></label>
            <input id="admin_email" name="admin_email" type="email" value="<?=install_e($form['admin_email'])?>" required autocapitalize="off" spellcheck="false"></div>
        <div class="field"><label for="admin_password"><?=install_e(install_t('Passwort', 'Password'))?></label>
            <input id="admin_password" name="admin_password" type="password" required minlength="12" maxlength="72" autocomplete="new-password">
            <small><?=install_e(install_t('Mindestens 12 Zeichen.', 'At least 12 characters.'))?></small></div>
        <div class="field"><label for="admin_password2"><?=install_e(install_t('Passwort wiederholen', 'Repeat password'))?></label>
            <input id="admin_password2" name="admin_password2" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div>
    </div>
</div>

<div class="card setup-step">
    <h2><?=install_e(install_t('Zum Ausprobieren', 'To try it out'))?></h2>
    <label class="check">
        <input type="checkbox" name="demo_fill" value="1"<?=$withDemo ? ' checked' : ''?>>
        <span><?=install_e(install_t('Beispieldaten anlegen', 'Create example data'))?>
        <small><?=install_e(install_t(
            'Zwei Kurse, ein paar erfundene Kinder mit Beiträgen, und Konten zum Anmelden – eine Trainerin und zwei Familien. Das Passwort dafür steht auf der nächsten Seite. Alles davon lässt sich später unter Einstellungen → System mit einem Tippen wieder entfernen. Für ein Portal, das gleich echte Daten bekommt: nicht ankreuzen.',
            'Two courses, a few invented children with charges, and accounts to sign in with – one trainer and two families. The password is on the next page. All of it can be removed again later under Einstellungen → System with one tap. For a portal that is about to hold real data: leave it unticked.'))?></small></span>
    </label>
</div>

<div class="card setup-step">
    <h2><?=install_e(install_t('Adresse und Zeitzone', 'Address and time zone'))?></h2>
    <div class="grid two">
        <div class="field"><label for="app_url"><?=install_e(install_t('Adresse des Portals', 'Portal address'))?></label>
            <input id="app_url" name="app_url" value="<?=install_e($form['app_url'])?>" required autocapitalize="off" spellcheck="false">
            <small><?=install_e(install_t('Ohne Schrägstrich am Ende. Nur ändern, wenn das Portal unter einer anderen Adresse erreichbar ist.',
                                          'No trailing slash. Change this only if the portal answers on a different address.'))?></small></div>
        <div class="field"><label for="timezone"><?=install_e(install_t('Zeitzone', 'Time zone'))?></label>
            <select id="timezone" name="timezone">
            <?php foreach (timezone_identifiers_list() as $zone): ?>
                <option value="<?=install_e($zone)?>"<?=$zone === $form['timezone'] ? ' selected' : ''?>><?=install_e($zone)?></option>
            <?php endforeach ?>
            </select></div>
    </div>
    <button class="button primary" type="submit"><?=install_e(install_t('Installieren', 'Install'))?></button>
</div>
</form>
<?php endif ?>
<?php endif ?>

</main>
</body>
</html>
