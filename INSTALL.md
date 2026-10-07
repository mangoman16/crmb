# Installation

Auf einem normalen Webhosting-Paket, ohne Kommandozeile, in drei Schritten.

## Was das Hosting können muss

- PHP ab 8.2 mit `pdo_mysql`, `mbstring`, `openssl` und Sitzungen.
- MariaDB mit InnoDB und `utf8mb4`. Geprüft ist das Portal nur mit MariaDB 10.11;
  MySQL 8.0 ist vorgesehen, aber noch nie ausprobiert worden.
- Apache oder LiteSpeed mit `.htaccess`, oder Nginx (Beispiel in `docs/nginx.conf.example`).
- HTTPS. Bei den meisten Anbietern ist ein Let's-Encrypt-Zertifikat im Panel enthalten.

Die Einrichtungsseite prüft HTTPS, die PHP-Version, die Erweiterungen und ob
`config/` und `storage/` beschreibbar sind, und sagt, was fehlt. Die Version der
Datenbank und den Webserver prüft sie nicht. Wenn PHP zu alt ist, lässt sich die
Version im Hosting-Panel meist selbst umstellen.

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
von außen nicht erreichbar, obwohl sie im Webverzeichnis liegen. Versteckte
Ordner wie `.git` beantwortet sie mit „nicht gefunden“.

`config/` und `storage/` müssen beschreibbar sein (Rechte `755`). Bei den
meisten Anbietern sind sie das nach dem Entpacken bereits. Falls nicht, sagt es
die Einrichtungsseite.

## 3. Die Adresse im Browser öffnen

`https://deine-domain.at` aufrufen. Es erscheint die Einrichtungsseite.

Die Einrichtung läuft nur verschlüsselt. Über `http://` geöffnet, steht bei
**Verschlüsselte Verbindung (HTTPS)** ein rotes „fehlt“, und sie richtet nichts
ein; der Knopf **Über https:// öffnen** führt zur selben Seite mit `https://`.
Zeigt der Browser dort eine Fehlermeldung („Verbindung ist nicht sicher“ oder
„Server nicht gefunden“), fehlt noch das Zertifikat: im Hosting-Panel das
SSL-Zertifikat für die Domain einschalten (meist „Let's Encrypt“, kostenlos),
ein paar Minuten warten, neu laden. Nur ein Test auf dem eigenen Rechner
(`localhost`) geht ohne. Ist das Portal eingerichtet, schickt es jeden, der eine
Seite über `http://` aufruft, selbst zur selben Seite mit `https://`.

Dort werden abgefragt:

- die vier Datenbank-Angaben aus Schritt 1 (der Port ist schon ausgefüllt),
- Name, E-Mail-Adresse und Passwort für das erste Konto (mindestens 12 Zeichen),
- Adresse und Zeitzone, beide bereits ausgefüllt.

Darunter steht **Beispieldaten anlegen**. Nur ankreuzen, wenn das Portal erst
ausprobiert werden soll – für ein Portal, das gleich echte Familien bekommt,
frei lassen.

**Installieren** drücken. Das war die Installation. Danach führt ein Link direkt
zur Anmeldung.

Geht dabei etwas schief, nachdem die Einstellungsdatei schon geschrieben ist –
zum Beispiel ein zu leicht zu erratendes Passwort –, sagt die Seite, was nicht
stimmt, und der nächste Versuch **im selben Browser** braucht nichts weiter. In
einem anderen Browser oder auf einem anderen Gerät erscheint stattdessen die
Karte **Einrichtungscode**: im Dateimanager den Ordner `storage` öffnen, darin
die Datei `setup-code.txt`, und ihre erste Zeile (etwa `ABCD-EFGH-JKLM`) in das
Feld eintragen. Groß- und Kleinschreibung und Bindestriche sind egal. So kann
niemand sonst die Einrichtung abschließen, der die Seite zufällig findet. Nach
der Einrichtung wird die Datei von selbst gelöscht.

Die Einrichtungsseite lässt sich anschließend nicht noch einmal starten: sobald
ein Administrator existiert, antwortet sie nur noch mit einem Hinweis. Die Datei
muss also nicht gelöscht werden – schaden kann es aber auch nicht.

> Zwischen dem Hochladen und dem Drücken von **Installieren** ist die
> Einrichtungsseite öffentlich erreichbar. Wer sie in diesem Zeitfenster findet,
> könnte das Portal auf eine eigene Datenbank installieren. Deshalb: hochladen
> und gleich einrichten, nicht über Nacht liegen lassen. Sobald die
> Einstellungsdatei geschrieben ist, braucht der letzte Schritt den
> Einrichtungscode, und die Angaben zur Datenbank zeigt die Seite niemandem mehr.

## Zum Ausprobieren: Beispieldaten

Unter **Einstellungen → System → Beispieldaten anlegen** füllt sich das Portal
mit erfundenen Kindern, Kursen, Beiträgen und Nachrichten. Damit lässt sich
alles durchklicken, bevor echte Familien darin stehen – SMTP und
Datenschutzerklärung sind dafür nicht nötig, weil die Beispielkonten fertig
angelegt werden und keine Einladung per E-Mail brauchen. Das Passwort wird
einmalig auf derselben Seite angezeigt.

Beispieldaten sind in der Datenbank gekennzeichnet und lassen sich mit einem
Klick vollständig wieder entfernen. Echte Daten bleiben dabei unberührt. Auf
der Liste „Dein Portal einrichten“ (nächster Abschnitt) haken Beispieldaten nie
einen Schritt ab.

## Danach: „Dein Portal einrichten“

Nach der ersten Anmeldung öffnet sich die Seite **„Dein Portal einrichten“**.
Sie zählt die neun Dinge auf, die ein Portal braucht, bevor Familien kommen,
und führt mit einem Tippen jeweils dorthin, wo es erledigt wird:

1. **Name und Anschrift** – stehen auf jeder Rechnung und in der
   Datenschutzerklärung.
2. **Bankkonto** – damit Rechnungen und der QR-Code sagen, wohin das Geld geht.
3. **Ersten Kurs anlegen**
4. **Preis für jeden Kurs** – jeder Kurs braucht einen Trainingstag und einen
   Tarif.
5. **Kinder eintragen** – jedes Kind in einem Kurs mit Preis.
6. **Beiträge** – automatisch anlegen lassen oder einmal selbst anlegen.
7. **E-Mails verschicken** – unter **Einstellungen → SMTP** Server, Port,
   Verschlüsselung, Benutzer, Passwort und Absenderadresse eintragen und
   speichern, dann **„Nur Verbindung prüfen“** oder **„Verbindung prüfen und
   Testmail senden“** drücken. Das Portal fragt den Mailserver sofort und zeigt
   das Ergebnis; erst ein grünes **Erfolgreich** hakt diesen Schritt ab. Wer die
   SMTP-Angaben später ändert, muss noch einmal prüfen.
8. **Datenschutzerklärung** – unter **Einstellungen → Datenschutz** den
   deutschen Entwurf an den tatsächlichen Betreiber, das Hosting und den
   E-Mail-Anbieter anpassen und freigeben. Die Einordnung von Krankmeldungen und
   Minderjährigen ist im Entwurf ausdrücklich als offener Punkt markiert. Eine
   englische Fassung ist freiwillig; wer das Portal auf Englisch nutzt, sieht
   sonst die deutsche mit einem Hinweis darauf.
9. **Familien einladen** – geht erst, wenn 7 und 8 erledigt sind. Jedes Kind
   bekommt seinen Zugang auf seiner eigenen Seite: eine Einladung an seine
   eigene E-Mail-Adresse – Geschwister brauchen jeweils eine eigene –, oder,
   für ein Kind ohne eigene Adresse, einen Benutzernamen und einen
   Anmeldelink, den die Trainerin als QR-Code zeigt oder weiterschickt.

Jeder Schritt wird aus den Daten abgehakt, nicht von Hand. Wer von der Liste
aus einen Schritt öffnet, findet nach dem Speichern oben auf der Seite
**„← Zurück zur Einrichtung“**. Bis alles erledigt ist, führt jede Anmeldung
als Administratorin auf diese Seite. Danach lässt sich die Liste
**ausblenden** und unter **Einstellungen → Einrichtung ansehen** wieder
hervorholen.

Beim E-Mail-Anbieter noch SPF und DKIM für die Absenderadresse einrichten,
sonst landen die Einladungen im Spam.

## Updates

Die neuen Dateien über die alten hochladen und das Portal öffnen. Die Datenbank
passt sich beim ersten Aufruf selbst an. Kein weiterer Schritt.

Ist schon ein Portal mit Familien in Betrieb, vorher in
[UPDATING.md](UPDATING.md#updating-an-existing-portal-to-060) den Abschnitt zu
dieser Version lesen: ein paar Dinge sind **vor** dem Hochladen zu erledigen.

Mit Shell-Zugang macht `bin/update.sh` dasselbe in einem Befehl: es holt die
neuen Dateien per Git, installiert die Abhängigkeiten, schaltet den
Wartungsmodus ein, sichert, migriert und schaltet ihn wieder aus.

```bash
bin/update.sh            # aktualisieren
bin/update.sh --check    # nur anzeigen, was passieren würde
bin/update.sh --help     # alle Möglichkeiten
```

Mit `--ref` lässt sich eine bestimmte Version festlegen, sobald es eine gibt:
bisher ist keine Version als Release markiert.

Die Version steht an zwei Stellen: in der Datei `VERSION` und in der Datenbank.
`php bin/console.php status` – oder **Einstellungen → System** – vergleicht
beide. Stimmen sie nicht überein, ist der Upload nicht vollständig angekommen.

Dabei prüft das Portal selbst vier Dinge: drei, **bevor** es die Datenbank
anfasst, und eines danach.

| Prüfung | Wenn sie nicht stimmt |
|---|---|
| Sind die Dateien neuer als die Datenbank? | Bei älteren Dateien (falsches Paket) bleibt das Portal geschlossen. Sonst würde alter Code die vorhandenen Daten falsch lesen. |
| Ist der Upload vollständig? | Bei abgebrochenem Entpacken oder FTP im Textmodus bleibt das Portal geschlossen und nennt die betroffenen Dateien. |
| Lässt sich eine Sicherung anlegen? | Ohne Sicherung wird nichts geändert. |
| Sind danach noch alle Datensätze da? | Fehlen Schüler, Beiträge, Zahlungen oder Nachrichten, bleibt das Portal geschlossen – bei jedem Aufruf, bis sie wieder da sind (siehe „Wiederherstellen“). |

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

Zuerst die Programmdateien hochladen, die zur Sicherung gehören: eine Sicherung
von vor einer Aktualisierung gehört zur vorherigen Version, also deren ZIP. Das
Portal bleibt dabei geschlossen. Erst die Dateien, dann die Datenbank:
andersherum würden die neueren Dateien ihre Datenbankänderungen beim nächsten
Aufruf noch einmal anwenden.

Dann im Hosting-Panel **phpMyAdmin** öffnen, die Datenbank auswählen, unter
**Exportieren** zur Sicherheit den aktuellen Stand herunterladen, dann alle
Tabellen löschen und unter **Importieren** die gewünschte Datei aus
`storage/backups` einspielen. Die Datei bringt ihre eigenen Tabellen mit und
lässt sich auch zweimal einspielen.

Danach das Portal öffnen. Wurde eine Aktualisierung abgelehnt, weil Datensätze
fehlten, zählt das Portal nach: sind alle wieder da, öffnet es sich von selbst;
sonst nennt die Seite, was noch fehlt.

## Optional: Cronjob statt Seitenaufruf

Ohne Cronjob erledigt das Portal wartende Aufgaben selbst, kurz nachdem eine
Seite ausgeliefert wurde: E-Mails verschicken, abgelaufene Links entfernen und –
wenn eingeschaltet – einmal im Monat die Monatsbeiträge anlegen. Höchstens einmal pro
Minute, und nach dem Absenden der Seite, sodass niemand darauf wartet. Der Stand
steht unter **Einstellungen → System**.

Wer lieber echte Cronjobs möchte, schaltet unter **Einstellungen → System →
Erweitert** die Option „Wartende Aufgaben beim Seitenaufruf erledigen“ aus. Dann
erledigt das Portal **alle drei** Aufgaben nicht mehr selbst, und es braucht im
Panel eine Zeile für jede:

```cron
* * * * * /usr/bin/php /pfad/zum/portal/bin/console.php mail:work 25
30 3 * * * /usr/bin/php /pfad/zum/portal/bin/console.php maintenance
0 6 1 * * /usr/bin/php /pfad/zum/portal/bin/console.php billing:run
```

Die erste verschickt die E-Mails. Die zweite räumt einmal in der Nacht auf; ohne
sie bleiben unter anderem die Zeiten, wann jemand online war, länger als die 30
Tage gespeichert, die die Datenschutzerklärung zusagt. Die dritte legt am
Monatsersten die Beiträge an – nur eintragen, wenn das Portal die Monatsbeiträge
automatisch anlegen soll, denn sie tut es auch, wenn das auf der Seite
**Beiträge** ausgeschaltet ist.

Cronjob und Seitenaufruf gleichzeitig sind nicht schädlich – ein
Datenbankschloss verhindert, dass zwei Läufe dieselbe E-Mail verschicken.

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
| Der Browser meldet „Verbindung ist nicht sicher“ oder findet den Server nicht | Das SSL-Zertifikat fehlt. Im Hosting-Panel für die Domain einschalten (meist „Let's Encrypt“), ein paar Minuten warten, neu laden. |
| „Dieses Portal nimmt Eingaben nur verschlüsselt an“ | Die Seite wurde über `http://` geöffnet, etwa aus einem alten Lesezeichen. Mit `https://` vorne neu öffnen und noch einmal senden. |
| Die Einrichtungsseite fragt nach dem „Einrichtungscode“ | Im Dateimanager `storage/setup-code.txt` öffnen und die erste Zeile eintragen. |
| Die Einrichtungsseite sagt „Die Datenbank antwortet gerade nicht“ | Ein paar Minuten warten. Bleibt es so: im Panel unter **MySQL-Verwaltung** prüfen, ob die Datenbank läuft und ihr Passwort noch das in `config/config.php` ist. |
| „Der Ordner config/ ist nicht beschreibbar“ | Rechte auf `755` setzen, oder die angezeigte Datei im Dateimanager als `config/config.php` anlegen und erneut auf **Installieren** tippen. |
| Beim Aufruf erscheint eine Dateiliste statt des Portals | Die `.htaccess`-Dateien wurden nicht mit entpackt. Der Dateimanager zeigt versteckte Dateien oft erst auf Wunsch an. |
| Alles wirkt unformatiert | `mod_rewrite` fehlt. `https://deine-domain.at/public/` aufrufen – das funktioniert auch. |
| „Das Portal wird gerade aktualisiert“ bleibt stehen | `storage/maintenance.flag` im Dateimanager löschen. |
| „Das Portal ist vorübergehend geschlossen.“ mit „… fehlen Datensätze: …“ | Die Aktualisierung hat Datensätze verloren, und das Portal bleibt zu, bis sie zurück sind. Zuerst die Dateien der Version hochladen, die die Seite nennt, dann die Sicherung einspielen, die sie nennt – siehe [Wiederherstellen](#wiederherstellen) und in [UPDATING.md](UPDATING.md#a-refused-update) „A refused update“. |
| „Im Ordner storage liegt eine Datei skip-backup, die das Portal nicht löschen kann“ | Dem Ordner `storage` Schreibrechte geben (`755`) und neu laden. Die Datei gilt nur für eine Aktualisierung; eine, die liegen bliebe, würde jede spätere Sicherung auslassen. |
| „Vor der Aktualisierung konnte keine Sicherung angelegt werden“ | Rechte für `storage` auf `755` setzen. Oder im Panel selbst eine Sicherung anlegen und danach im Ordner `storage` eine leere Datei `skip-backup` erstellen; sie gilt für genau ein Update. |
| „Vor der Aktualisierung konnte das Portal im Ordner storage nicht schreiben“ | Dem Ordner `storage` Schreibrechte geben (`755`) und neu laden. Das Portal hat dabei nichts geändert und keine Sicherung angelegt. |
| „Die hochgeladenen Dateien sind älter als die Datenbank“ | Das falsche Paket hochgeladen. Die neueste Version holen und noch einmal entpacken. |
| „Die hochgeladenen Dateien sind unvollständig“ | Das Entpacken ist abgebrochen, oder der Upload lief über FTP im Textmodus. Noch einmal hochladen, FTP auf Binärmodus stellen. |
| E-Mails gehen nicht raus | **Einstellungen → System**: steht dort ein letzter Hintergrundlauf? Sonst **Postausgang** (unter **Nachrichten**), dort steht der Fehler der letzten Zustellung. |
| „Eine Einladung lässt sich noch nicht verschicken …“ | Die Meldung sagt, was fehlt: unter **Einstellungen → SMTP** **„Nur Verbindung prüfen“**, bis dort **Erfolgreich** steht – nach jeder Änderung der SMTP-Angaben noch einmal –, und unter **Einstellungen → Datenschutz** die deutsche Datenschutzerklärung freigeben. |
| „Ein Anmeldelink geht erst, wenn die Datenschutzerklärung freigegeben ist …“ | Unter **Einstellungen → Datenschutz** die deutsche Datenschutzerklärung freigeben; sie wird bei der ersten Anmeldung bestätigt. |
| „Diese E-Mail-Adresse gehört schon zu einem anderen Zugang …“ | Die Adresse ist schon die Anmeldung einer anderen Person, oft eines Geschwisters. Für dieses Kind eine andere Adresse eintragen – oder einen Benutzernamen. |
| Jemand sieht „Die Anwendung ist vorübergehend nicht verfügbar“ oder „Speichern fehlgeschlagen. Bitte erneut versuchen.“ | Das Portal hat den Fehler selbst festgehalten: **Einstellungen → Rückmeldungen**, Eintrag „Automatisch erfasst“, mit der Zahl, wie oft er vorkam. Unter **„Für den Support kopieren“** steht ein Text ohne Namen, E-Mail-Adressen und Eingaben, der an die Person gehen kann, die hilft. |

## Was geprüft wurde

Die Einrichtung über den Browser, das Anwenden neuer Migrationen beim
Seitenaufruf und jede der vier Prüfungen vor einem Update sind gegen
**MariaDB 10.11.14** mit echten HTTP-Anfragen durchgespielt worden – samt einer
Sicherung, die anschließend in eine zweite Datenbank zurückgespielt wurde und
dort vollständig ankam. Die gesamte Testsuite läuft dort ebenfalls durch, mit
allen 33 Datenbankänderungen, zuletzt am 7. Oktober 2026.

Für Version 0.6.0 wurde außerdem der erste Abend von Anfang bis Ende in einem
echten Browser in Telefonbreite durchgespielt, gegen MariaDB 10.11.14: die
Einrichtungsseite, alle neun Schritte von „Dein Portal einrichten“ über ihre
eigenen Knöpfe, eine Einladung über einen Mailserver auf demselben Rechner, die
Anmeldung als Familie, ein Zahlungsbeleg, eine Problemmeldung, eine Rechnung
und ein absichtlich ausgelöster Fehler. Das war Chromium, kein iPhone, und kein
echter E-Mail-Anbieter.

**MySQL 8.0 selbst ist nicht geprüft**, und auf einem konkreten Hosting-Paket
ist das Ganze noch nicht gelaufen – auch die neue Sperre für versteckte Ordner
in der `.htaccess` nicht, denn der Testserver liest keine `.htaccess`. Stand und
offene Punkte: [VALIDATION.md](VALIDATION.md).
