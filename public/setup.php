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
 * afterwards. There is no CSRF token, deliberately: before the first
 * administrator exists there is no privilege to borrow, and anyone who could
 * make the operator submit this form could just as easily open it themselves.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/../app/install.php';

install_locale((string)($_GET['lang'] ?? ($_POST['lang'] ?? 'de')));

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$state = install_state();
$configPath = config_path();
$stored = install_read_config($configPath);
$blockers = install_blockers();
$errors = [];
$warnings = [];
$manual = '';      // the configuration to create by hand when config/ is read-only
$done = false;

$form = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'app_url' => install_base_url($_SERVER), 'timezone' => 'Europe/Vienna',
    'admin_name' => '', 'admin_email' => '',
];
$withDemo = false;
$demo = null;     // what demo_fill() created, to be shown on the finish page
if ($state === 'configured' && $stored) {
    $form['db_host'] = (string)($stored['db']['host'] ?? '');
    $form['db_port'] = (string)($stored['db']['port'] ?? '3306');
    $form['db_name'] = (string)($stored['db']['database'] ?? '');
    $form['db_user'] = (string)($stored['db']['username'] ?? '');
    $form['app_url'] = (string)($stored['app_url'] ?? $form['app_url']);
    $form['timezone'] = (string)($stored['timezone'] ?? $form['timezone']);
}

if ($post && $state !== 'installed' && !$blockers) {
    foreach (array_keys($form) as $field)
        if (isset($_POST[$field]) && is_string($_POST[$field])) $form[$field] = trim($_POST[$field]);
    $withDemo = isset($_POST['demo_fill']);
    $password = is_string($_POST['admin_password'] ?? null) ? (string)$_POST['admin_password'] : '';
    $repeat   = is_string($_POST['admin_password2'] ?? null) ? (string)$_POST['admin_password2'] : '';
    $dbPassword = is_string($_POST['db_password'] ?? null) ? (string)$_POST['db_password'] : '';

    $url = rtrim($form['app_url'], '/');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array((string)parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
        $errors[] = install_t('Die Adresse des Portals muss mit http:// oder https:// beginnen.',
                              'The portal address must start with http:// or https://.');
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
                $source = install_config_source([
                    'app_url' => $url, 'app_key' => install_app_key($configPath), 'db' => $db,
                    'timezone' => $form['timezone'],
                    // A portal reached over plain HTTP cannot set a secure cookie;
                    // insisting would sign the operator straight back out.
                    'secure_cookies' => str_starts_with($url, 'https://'),
                    'session_idle_minutes' => 120,
                    'maintenance_file' => ROOT . '/storage/maintenance.flag',
                ]);
                if (!install_writable($configPath)) {
                    $manual = $source;
                    throw new RuntimeException(install_t(
                        'Der Ordner config/ ist nicht beschreibbar. Die Datei unten bitte im Dateimanager als config/config.php anlegen und dann erneut auf „Installieren“ tippen.',
                        'The config/ folder is not writable. Create the file below as config/config.php in the file manager, then press “Install” again.'));
                }
                install_write_config($configPath, $source);
            }
            // The application can only be loaded once there is a configuration,
            // which is why this is here rather than at the top of the file.
            $_SESSION = ['locale' => install_locale()];   // read by t(); no session is started here
            require __DIR__ . '/../app/bootstrap.php';
            // No safeguards on a first install: there is no earlier release to be
            // older than, and an empty database has nothing worth copying.
            schema_apply(null, safeguards: false);
            create_admin_account($form['admin_name'], $form['admin_email'], $password);
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

$portal = $done ? rtrim((string)config('app_url'), '/') . '/index.php' : '';
$other = install_locale() === 'en' ? 'de' : 'en';
// Set before a single byte of the page goes out; after that it is too late.
if ($state === 'installed' && !$done) http_response_code(403);
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
<link rel="stylesheet" href="assets/app.css">
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

<?php elseif ($done): ?>
<div class="card setup-step">
    <p class="eyebrow"><?=install_e(install_t('Fertig', 'Done'))?></p>
    <h1><?=install_e(install_t('Das Portal ist eingerichtet', 'The portal is ready'))?></h1>
    <p class="muted"><?=install_e(install_t(
        'Melde dich jetzt mit der eben angelegten E-Mail-Adresse und dem Passwort an.',
        'Sign in now with the email address and password you just chose.'))?></p>
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
        <li>trainerin@beispiel.test <?=install_e(install_t('(Trainerin)', '(trainer)'))?></li>
        <li>familie.hofer@beispiel.test <?=install_e(install_t('(Familie)', '(family)'))?></li>
        <li>familie.berger@beispiel.test <?=install_e(install_t('(Familie)', '(family)'))?></li>
    </ul>
    <p><?=install_e(install_t('Passwort für alle drei: ', 'The password for all three: '))?>
       <strong class="mono"><?=install_e((string)$demo['password'])?></strong></p>
    <p class="muted"><?=install_e(install_t(
        'Jetzt aufschreiben – es wird nicht noch einmal angezeigt. Entfernen lässt sich alles unter Einstellungen → System → „Beispieldaten entfernen“.',
        'Write it down now – it is not shown again. Remove all of it under Einstellungen → System → “Remove example data”.'))?></p>
</div>
<?php endif ?>
<div class="card setup-step">
    <h2><?=install_e(install_t('Die nächsten zwei Schritte', 'The next two steps'))?></h2>
    <p class="muted"><?=install_e(install_t(
        'Einladungen bleiben gesperrt, bis beides erledigt ist: unter Einstellungen → SMTP den E-Mail-Versand eintragen, und unter Einstellungen → Datenschutz beide Entwürfe vervollständigen und freigeben.',
        'Invitations stay disabled until both are done: enter the email details under Einstellungen → SMTP, and complete and approve both drafts under Einstellungen → Datenschutz.'))?></p>
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
    <dl class="facts">
        <div><dt><?=install_e(install_t('Datenbank', 'Database'))?></dt><dd><?=install_e($form['db_name'])?></dd></div>
        <div><dt><?=install_e(install_t('Server', 'Server'))?></dt><dd><?=install_e($form['db_host'])?></dd></div>
    </dl>
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
