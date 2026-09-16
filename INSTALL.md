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

## 2. Dateien hochladen

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

`config/config.php` wird dabei nicht überschrieben, weil sie in der ZIP-Datei
nicht enthalten ist. Sie enthält den Schlüssel, mit dem das gespeicherte
SMTP-Passwort entschlüsselt wird – **niemals löschen oder ersetzen**.

Geht eine Datenbankänderung schief, bleibt das Portal geschlossen und zeigt,
welche Migration wo stehen geblieben ist, statt halb aktualisiert zu öffnen.
Details in [UPDATING.md](UPDATING.md).

Sicherungen macht dieses Programm nicht. Vor einem Update die Datenbanksicherung
des Hostings prüfen.

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
| E-Mails gehen nicht raus | **Einstellungen → System**: steht dort ein letzter Hintergrundlauf? Sonst **Postausgang**, dort steht der Fehler der letzten Zustellung. |

## Was geprüft wurde

Die Einrichtung über den Browser, das Anwenden neuer Migrationen beim
Seitenaufruf und das Verhalten bei einer fehlerhaften Migration sind gegen
**MariaDB 10.11.14** mit echten HTTP-Anfragen durchgespielt worden. Die gesamte
Testsuite läuft dort ebenfalls durch. **MySQL 8.0 selbst ist nicht geprüft**, und
auf einem konkreten Hosting-Paket ist das Ganze noch nicht gelaufen. Stand und
offene Punkte: [VALIDATION.md](VALIDATION.md).
