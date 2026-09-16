# Installation – v0.1.0

## Voraussetzungen

- PHP ab 8.2 mit `pdo_mysql`, `mbstring`, `openssl` und Sitzungsunterstützung. Für einen neuen Server eine aktuell unterstützte PHP-Version verwenden; PHP 8.4 oder 8.5 bietet mehr verbleibende Laufzeit als 8.2.
- MySQL 8.0+ oder MariaDB 10.11+, InnoDB, `utf8mb4`.
- Apache/LiteSpeed mit PHP oder Nginx mit PHP-FPM; HTTPS.
- PHP-CLI für Einrichtung und Cronjobs.
- Ein SMTP-Zugang mit STARTTLS oder TLS sowie eine dazu passende Absenderadresse.

Getestete Versionen stehen in `VALIDATION.md`. Die offiziellen [PHP-Supportzeiträume](https://www.php.net/supported-versions.php) helfen bei der Versionswahl.

## 1. Dateien ablegen

Das Paket beispielsweise nach `/srv/badminton/releases/0.1.0/` entpacken. Der **DocumentRoot muss auf dessen `public/` zeigen**. Niemals den gesamten Projektordner veröffentlichen. `app/`, `config/`, `database/`, `tests/` und `vendor/` liegen außerhalb des DocumentRoot.

Bei dieser ersten Installation den aktiven Versionspfad anlegen:

```bash
ln -s /srv/badminton/releases/0.1.0 /srv/badminton/current
cd /srv/badminton/current
```

Die folgenden Befehle werden in diesem Projektverzeichnis ausgeführt. Einen bereits vorhandenen `current`-Pfad nicht als Teil einer Neuinstallation überschreiben.

Das ZIP enthält die PHP-Abhängigkeit bereits. Nach einem Git-Clone:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Kein Node.js-Build ist erforderlich.

## 2. Datenbank erstellen

Im Hosting-Panel eine leere Datenbank und einen eigenen Benutzer erstellen. Alternativ als Datenbankadministrator:

```sql
CREATE DATABASE badminton_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'badminton_crm'@'127.0.0.1' IDENTIFIED BY 'HIER_EIN_EIGENES_STARKES_PASSWORT';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
ON badminton_crm.* TO 'badminton_crm'@'127.0.0.1';
```

Hostnamen und Benutzerformat an das Hosting anpassen. Für getrennte Migrationen können `CREATE`, `ALTER`, `INDEX` und `REFERENCES` einem eigenen Migrationsbenutzer vorbehalten bleiben. Die erste Migration benötigt `CREATE`.

## 3. Konfiguration außerhalb der Releases speichern

Für spätere Updates ist eine gemeinsame Konfiguration sinnvoll:

```bash
mkdir -p /srv/badminton/shared
cp config/config.example.php /srv/badminton/shared/config.php
php bin/console.php key
```

Die erzeugte Zeichenfolge als `app_key` in der Konfiguration eintragen. Außerdem `app_url`, Datenbankzugang und folgenden gemeinsamen Wartungspfad eintragen:

```php
'maintenance_file' => '/srv/badminton/shared/maintenance.flag',
```

Die vorhandene `app_key` bei Updates beibehalten. Sie wird zum Entschlüsseln des SMTP-Passworts und der wartenden E-Mails verwendet. Den Schlüssel niemals ins Repository aufnehmen.

Zwei Möglichkeiten, die Konfiguration einzubinden:

```bash
ln -s /srv/badminton/shared/config.php config/config.php
```

Oder die Umgebungsvariable `CRM_CONFIG=/srv/badminton/shared/config.php` sowohl für PHP-FPM als auch für die CLI setzen. Keine echte Konfiguration in Git speichern. Der PHP-Benutzer benötigt Leserechte auf die Konfiguration; nur der Betreiber sollte sie ändern können. Das Verzeichnis des Wartungsflags muss für den ausführenden CLI-Benutzer beschreibbar sein. Die normale PHP-Sitzungsablage muss funktionieren.

## 4. Schema und erstes Administratorkonto

```bash
php bin/console.php migrate
php bin/console.php create-admin
php bin/console.php check
```

`create-admin` fragt Name, E-Mail-Adresse und Passwort interaktiv ab. Es funktioniert nur, solange kein Administrator existiert. Dieses erste Konto wird vom Serverbetreiber eingerichtet und gilt als vertrauenswürdig; alle Einladungen über die Webseite müssen anschließend per E-Mail bestätigt werden.

Es gibt keine vorgegebenen Produktivzugänge. Weitere Administratoren oder Manager können über **Konten** eingeladen werden. Manager verwalten Schüler und Nachrichten; SMTP, Tarife und Formulare bearbeitet der Administrator.

## 5. Domain und HTTPS

`app_url` muss exakt zur aufgerufenen Adresse passen, beispielsweise `https://badminton.example.at`, ohne abschließenden Schrägstrich. Unterverzeichnisse sind möglich. `secure_cookies` bleibt für den öffentlichen Betrieb `true`.

Apache/LiteSpeed: DocumentRoot auf `public/`, `DirectoryIndex index.php`, keine Verzeichnisauflistung. Die `.htaccess` im Projektwurzelverzeichnis ist eine zusätzliche Sperre. Keine statische Ganzseiten-Zwischenspeicherung durch LiteSpeed, CDN oder Hosting-Panel für das Portal einschalten.

Ein Nginx-Beispiel befindet sich in `docs/nginx.conf.example`. TLS-Zertifikat, PHP-Socket und Servernamen anpassen. Ohne URL-Rewrite funktionieren die Routen über `index.php?page=...`.

## 6. SMTP und Datenschutzerklärung

1. Anmelden → **Einstellungen → SMTP**.
2. Server, Port, STARTTLS/TLS, Benutzer, Passwort und Absender speichern.
3. **Testmail vormerken** → **Postausgang → Warteschlange senden**. Die Mail geht an die Adresse des angemeldeten Administrators.
4. Unter **Datenschutz** beide Entwürfe vervollständigen. Betreiber, Hosting, SMTP-Anbieter, Zwecke und Aufbewahrungsfristen an die tatsächliche Nutzung anpassen. Die Einordnung von Krankmeldungen und Minderjährigen ist im Entwurf ausdrücklich als offener Punkt markiert.
5. Nach Fertigstellung die beiden Fassungen in den Einstellungen freigeben.

SPF/DKIM und die zulässige Absenderadresse beim E-Mail-Anbieter einrichten. PHPMailer prüft TLS-Zertifikate; unsichere Verbindungen werden nicht als Ausweichlösung verwendet. E-Mails tragen einen Hinweis, dass Antworten im Portal erfolgen.

## 7. Cronjobs

Mit dem passenden PHP-CLI-Pfad, unter dem Anwendungsbenutzer:

```cron
* * * * * /usr/bin/php /srv/badminton/current/bin/console.php mail:work 25 >> /srv/badminton/shared/mail-worker.log 2>&1
15 3 * * * /usr/bin/php /srv/badminton/current/bin/console.php maintenance >> /srv/badminton/shared/maintenance.log 2>&1
30 4 1 * * /usr/bin/php /srv/badminton/current/bin/console.php billing:run >> /srv/badminton/shared/billing.log 2>&1
```

Die dritte Zeile legt am 1. jedes Monats die Monatsbeiträge an. Sie ist bewusst
wiederholbar: ein zweiter Lauf im selben Monat erzeugt nichts. Wer lieber selbst
kontrolliert, lässt diese Zeile weg und verwendet **Beiträge → Monatsbeiträge**,
wo die Liste vor dem Anlegen angezeigt wird. `billing:plan` zeigt dasselbe auf
der Kommandozeile, ohne etwas zu ändern.

`current` zeigt auf den aktiven Releaseordner, siehe `UPDATING.md`. Alternativ zunächst den tatsächlichen Installationspfad verwenden. Cron muss dieselbe Konfiguration laden wie die Webseite. Pro Versandlauf werden höchstens 25 wartende Mails verarbeitet. Ein Datenbankschloss verhindert parallele Versandläufe. Fehlgeschlagene Mails werden im Postausgang angezeigt; normale Mails können dort erneut vorgemerkt werden. Für fehlgeschlagene Sicherheitsmails neue Links anfordern.

`maintenance` entfernt nur abgelaufene Zugangstokens, alte Ratenbegrenzungen und vorübergehende Formularkennungen. Es löscht keine Schüler, Zahlungen oder Nachrichten. Logrotation wird vom Hosting verwaltet.

## 8. Erste Einrichtung

Tarife und Felder in den Einstellungen anpassen. Schüler können vor dem Einladen eines Kontos angelegt werden. Anschließend das Konto einladen und bei einem oder mehreren Schülern zuordnen. Beiträge werden bewusst einzeln angelegt; der Zeitraum und die Fälligkeit sind frei wählbar.
