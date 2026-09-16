# Installation

Auf einem normalen Webhosting-Paket, ohne Kommandozeile, in drei Schritten.

## Was das Hosting können muss

- PHP ab 8.2 mit `pdo_mysql`, `mbstring`, `openssl` und Sitzungen.
- MySQL ab 5.7 oder MariaDB ab 10.4, InnoDB, `utf8mb4`.
- Apache oder LiteSpeed mit `.htaccess`, oder Nginx (Beispiel in `docs/nginx.conf.example`).
- HTTPS. Bei den meisten Anbietern ist ein Let's-Encrypt-Zertifikat im Panel enthalten.

Die Einrichtungsseite prüft jeden dieser Punkte und sagt, was fehlt. Wenn PHP
zu alt ist, lässt sich die Version im Hosting-Panel meist selbst umstellen.

Ein Cronjob wird **nicht** benötigt. Das Portal erledigt Versand und
Aufräumarbeiten selbst; siehe [Cronjob statt Seitenaufruf](#optional-cronjob-statt-seitenaufruf).

## 1. Datenbank anlegen

Im Hosting-Panel unter **MySQL-Verwaltung** (bei manchen Anbietern
„Datenbanken“) eine neue, leere Datenbank anlegen und ihr einen Benutzer mit
einem eigenen Passwort zuordnen. Die Tabellen legt die Einrichtung selbst an.

Vier Angaben aus dieser Maske werden gleich gebraucht:

| Angabe | Üblicher Wert |
|---|---|
| Datenbankserver | `localhost` |
| Name der Datenbank | vom Panel vergeben, oft mit Präfix |
| Benutzername | vom Panel vergeben |
| Passwort | selbst gewählt |

## 2. Dateien einspielen

Es gibt zwei Wege. Welcher passt, hängt davon ab, ob der Server eine
Kommandozeile hat.

### Mit Shell-Zugang: git clone

```bash
git clone https://github.com/mangoman16/crmb.git /pfad/zum/webverzeichnis
cd /pfad/zum/webverzeichnis
composer install --no-dev --prefer-dist --optimize-autoloader
```

`bin/update.sh --clone /pfad/zum/webverzeichnis` erledigt beides in einem
Schritt. Ohne `composer install` läuft das Portal zwar, kann aber keine E-Mails
verschicken und keinen Zahlungs-QR-Code zeichnen.

### Ohne Shell-Zugang: ZIP-Datei

> **Woher kommt die ZIP-Datei?** Sie liegt nirgends zum Herunterladen bereit –
> für dieses Repository gibt es noch kein veröffentlichtes Release. Die Datei
> wird aus einer Arbeitskopie mit `bin/release.sh` gebaut; sie enthält dann auch
> die Abhängigkeiten, damit der Upload für sich allein funktioniert. Bis ein
> Release veröffentlicht ist, muss jemand mit Shell-Zugang sie einmal bauen und
> weitergeben.

Die ZIP-Datei im **Dateimanager** in das Verzeichnis der Domain hochladen
(meist `public_html` oder `domains/<domain>/public_html`) und dort entpacken.
Der Inhalt des entpackten Ordners kommt direkt in dieses Verzeichnis, nicht in
einen Unterordner – es sei denn, das Portal soll bewusst unter
`https://deine-domain.at/verein/` laufen, dann eben in `verein/`.

Danach muss es so aussehen:

```
public_html/
├── .htaccess
├── index.php
├── public/
├── app/
├── config/
├── storage/
└── …
```

Die `.htaccess` ganz oben leitet jede Anfrage nach `public/` weiter, und jeder
andere Ordner sperrt sich selbst. `app/`, `config/` und `storage/` sind dadurch
von außen nicht erreichbar, obwohl sie im Webverzeichnis liegen.

`config/` und `storage/` müssen beschreibbar sein (Rechte `755`). Bei den
meisten Anbietern sind sie das nach dem Entpacken bereits. Falls nicht, sagt es
die Einrichtungsseite.

## 3. Die Adresse im Browser öffnen

`https://deine-domain.at` aufrufen. Es erscheint die Einrichtungsseite.

Dort werden abgefragt:

- die vier Datenbank-Angaben aus Schritt 1,
- Name, E-Mail-Adresse und Passwort für das erste Konto (mindestens 12 Zeichen),
- Adresse und Zeitzone, beide bereits ausgefüllt.

**Installieren** drücken. Das war die Installation. Danach führt ein Link direkt
zur Anmeldung.

Die Einrichtungsseite lässt sich anschließend nicht noch einmal starten: sobald
ein Administrator existiert, antwortet sie nur noch mit einem Hinweis. Die Datei
muss also nicht gelöscht werden – schaden kann es aber auch nicht.

> Zwischen dem Hochladen und dem Drücken von **Installieren** ist die
> Einrichtungsseite öffentlich erreichbar. Wer sie in diesem Zeitfenster findet,
> könnte das Portal auf eine eigene Datenbank installieren. Deshalb: hochladen
> und gleich einrichten, nicht über Nacht liegen lassen.

## Zum Ausprobieren: Beispieldaten

Unter **Einstellungen → System → Beispieldaten anlegen** füllt sich das Portal
mit erfundenen Kindern, Kursen, Beiträgen und Nachrichten. Damit lässt sich
alles durchklicken, bevor echte Familien darin stehen – SMTP und
Datenschutzerklärung sind dafür nicht nötig, weil die Beispielkonten fertig
angelegt werden und keine Einladung per E-Mail brauchen. Das Passwort wird
einmalig auf derselben Seite angezeigt.

Beispieldaten sind in der Datenbank gekennzeichnet und lassen sich mit einem
Klick vollständig wieder entfernen. Echte Daten bleiben dabei unberührt.

## Danach: zwei Dinge in den Einstellungen

Einladungen bleiben gesperrt, bis beides erledigt ist.

1. **Einstellungen → SMTP**: Server, Port, Verschlüsselung, Benutzer, Passwort
   und Absenderadresse eintragen, dann **Testmail vormerken**. Die Mail geht an
   die eigene Adresse und wird innerhalb einer Minute verschickt; der Stand steht
   im **Postausgang**.
2. **Einstellungen → Datenschutz**: beide Entwürfe an den tatsächlichen
   Betreiber, das Hosting und den E-Mail-Anbieter anpassen und freigeben. Die
   Einordnung von Krankmeldungen und Minderjährigen ist im Entwurf ausdrücklich
   als offener Punkt markiert.

Beim E-Mail-Anbieter noch SPF und DKIM für die Absenderadresse einrichten,
sonst landen die Einladungen im Spam.

## Updates

Die neuen Dateien über die alten hochladen und das Portal öffnen. Die Datenbank
passt sich beim ersten Aufruf selbst an. Kein weiterer Schritt.

Mit Shell-Zugang macht `bin/update.sh` dasselbe in einem Befehl: es holt die
neuen Dateien per Git, installiert die Abhängigkeiten, schaltet den
Wartungsmodus ein, sichert, migriert und schaltet ihn wieder aus.

```bash
bin/update.sh            # aktualisieren
bin/update.sh --check    # nur anzeigen, was passieren würde
bin/update.sh --ref v0.6.0   # auf eine bestimmte Version festlegen
```

Die Version steht an zwei Stellen: in der Datei `VERSION` und in der Datenbank.
`php bin/console.php status` – oder **Einstellungen → System** – vergleicht
beide. Stimmen sie nicht überein, ist der Upload nicht vollständig angekommen.

Vorher prüft das Portal selbst vier Dinge, und bei jedem einzelnen bricht es ab,
**bevor** es die Datenbank anfasst:

| Prüfung | Wenn sie nicht stimmt |
|---|---|
| Sind die Dateien neuer als die Datenbank? | Bei älteren Dateien (falsches Paket) bleibt das Portal geschlossen. Sonst würde alter Code die vorhandenen Daten falsch lesen. |
| Ist der Upload vollständig? | Bei abgebrochenem Entpacken oder FTP im Textmodus bleibt das Portal geschlossen und nennt die betroffenen Dateien. |
| Lässt sich eine Sicherung anlegen? | Ohne Sicherung wird nichts geändert. |
| Sind danach noch alle Datensätze da? | Fehlen Schüler, Beiträge, Zahlungen oder Nachrichten, bleibt das Portal geschlossen. |

Die Sicherung ist eine vollständige SQL-Kopie der Datenbank und landet in
`storage/backups`. Die letzten fünf werden behalten, ältere selbst gelöscht. Der
Ordner ist über das Internet nicht erreichbar. Unter **Einstellungen → System**
stehen alle vorhandenen Sicherungen mit Datum.

`config/config.php` wird nicht überschrieben, weil sie in der ZIP-Datei nicht
enthalten ist. Sie enthält den Schlüssel, mit dem das gespeicherte SMTP-Passwort
entschlüsselt wird – **niemals löschen oder ersetzen**.

Geht trotzdem eine Datenbankänderung schief, bleibt das Portal geschlossen und
zeigt, welche Migration wo stehen geblieben ist, statt halb aktualisiert zu
öffnen. Details und der Weg zurück: [UPDATING.md](UPDATING.md).

### Wiederherstellen

Im Hosting-Panel **phpMyAdmin** öffnen, die Datenbank auswählen, unter
**Exportieren** zur Sicherheit den aktuellen Stand herunterladen, dann alle
Tabellen löschen und unter **Importieren** die gewünschte Datei aus
`storage/backups` einspielen. Die Datei bringt ihre eigenen Tabellen mit und
lässt sich auch zweimal einspielen.

Danach die passenden Programmdateien wiederherstellen: eine Sicherung von vor
dem Update gehört zur vorherigen Version, also auch deren ZIP wieder hochladen.

## Optional: Cronjob statt Seitenaufruf

Ohne Cronjob erledigt das Portal wartende Aufgaben selbst, kurz nachdem eine
Seite ausgeliefert wurde: E-Mails verschicken, abgelaufene Links entfernen und –
wenn eingeschaltet – am 1. die Monatsbeiträge anlegen. Höchstens einmal pro
Minute, und nach dem Absenden der Seite, sodass niemand darauf wartet. Der Stand
steht unter **Einstellungen → System**.

Wer viele wartende E-Mails hat oder lieber einen echten Cronjob möchte, legt im
Panel diese Zeile an und schaltet unter **Einstellungen → System** die Option
„Wartende Aufgaben beim Seitenaufruf erledigen“ aus:

```cron
* * * * * /usr/bin/php /pfad/zum/portal/bin/console.php mail:work 25
```

Beides gleichzeitig ist nicht schädlich – ein Datenbankschloss verhindert, dass
zwei Läufe dieselbe E-Mail verschicken.

## Mit Shell-Zugang

Wer eine Kommandozeile hat, braucht die Einrichtungsseite nicht:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader   # nur nach git clone
cp config/config.example.php config/config.php
php bin/console.php key                    # Ergebnis als app_key eintragen
php bin/console.php migrate
php bin/console.php create-admin
php bin/console.php check
```

Der Webserver kann dabei wie gewohnt direkt auf `public/` zeigen; die
Weiterleitung aus dem Projektverzeichnis ist dann unbenutzt. Für getrennte
Release-Ordner und eine gemeinsame Konfiguration siehe [UPDATING.md](UPDATING.md).

## Wenn etwas nicht klappt

| Was zu sehen ist | Was zu tun ist |
|---|---|
| „Diese Datenbank gibt es nicht, oder dieser Benutzer ist ihr nicht zugeordnet“ | Im Panel prüfen, ob der Benutzer der Datenbank zugeordnet ist. Viele Panels stellen dem Namen ein Präfix voran. |
| „Benutzername oder Passwort der Datenbank stimmt nicht“ | Im Panel ein neues Datenbankpasswort setzen und hier eintragen. |
| „Der Ordner config/ ist nicht beschreibbar“ | Rechte auf `755` setzen, oder die angezeigte Datei im Dateimanager als `config/config.php` anlegen und erneut auf **Installieren** tippen. |
| Beim Aufruf erscheint eine Dateiliste statt des Portals | Die `.htaccess`-Dateien wurden nicht mit entpackt. Der Dateimanager zeigt versteckte Dateien oft erst auf Wunsch an. |
| Alles wirkt unformatiert | `mod_rewrite` fehlt. `https://deine-domain.at/public/` aufrufen – das funktioniert auch. |
| „Das Portal wird gerade aktualisiert“ bleibt stehen | `storage/maintenance.flag` im Dateimanager löschen. |
| „Vor der Aktualisierung konnte keine Sicherung angelegt werden“ | Rechte für `storage` auf `755` setzen. Oder im Panel selbst eine Sicherung anlegen und danach im Ordner `storage` eine leere Datei `skip-backup` erstellen; sie gilt für genau ein Update. |
| „Die hochgeladenen Dateien sind älter als die Datenbank“ | Das falsche Paket hochgeladen. Die neueste Version holen und noch einmal entpacken. |
| „Die hochgeladenen Dateien sind unvollständig“ | Das Entpacken ist abgebrochen, oder der Upload lief über FTP im Textmodus. Noch einmal hochladen, FTP auf Binärmodus stellen. |
| E-Mails gehen nicht raus | **Einstellungen → System**: steht dort ein letzter Hintergrundlauf? Sonst **Postausgang**, dort steht der Fehler der letzten Zustellung. |

## Was geprüft wurde

Die Einrichtung über den Browser, das Anwenden neuer Migrationen beim
Seitenaufruf und jede der vier Prüfungen vor einem Update sind gegen
**MariaDB 10.11.14** mit echten HTTP-Anfragen durchgespielt worden – samt einer
Sicherung, die anschließend in eine zweite Datenbank zurückgespielt wurde und
dort vollständig ankam. Die gesamte Testsuite läuft dort ebenfalls durch. **MySQL 8.0 selbst ist nicht geprüft**, und
auf einem konkreten Hosting-Paket ist das Ganze noch nicht gelaufen. Stand und
offene Punkte: [VALIDATION.md](VALIDATION.md).
