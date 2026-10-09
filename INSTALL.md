# Installation

Auf einem normalen Webhosting-Paket, ohne Kommandozeile, in drei Schritten.

## Was das Hosting können muss

- PHP ab 8.2 mit `pdo_mysql`, `mbstring`, `openssl`, `session`, `fileinfo`,
  `iconv`, `ctype` und `filter`. Fehlt eine, installiert die Einrichtungsseite
  nicht; fehlt eine später, etwa nach einem Wechsel der PHP-Version im Panel,
  steht es unter **Einstellungen → System**. Ohne `fileinfo` lehnt das Portal
  jedes Foto und jeden Beleg ab; ohne `iconv` öffnet sich keine Rechnung als PDF
  und keine Seite mit einem QR-Code; ohne `ctype` zeigen die Schülerliste, die
  Rechnungen, der Postausgang und jede Seite mit einem QR-Code einen Fehler, und
  keine IBAN lässt sich speichern; ohne `filter` öffnet sich keine Seite.
- Für Profilbilder außerdem `gd`. Fehlt sie, installiert die Einrichtungsseite
  trotzdem und zeigt sie als „eingeschränkt“: Profilbilder lassen sich dann
  nicht speichern, alles andere geht, und **Einstellungen → System** nennt sie.
- MariaDB mit InnoDB und `utf8mb4`. Geprüft ist das Portal nur mit MariaDB 10.11;
  MySQL 8.0 ist vorgesehen, aber noch nie ausprobiert worden.
- Apache oder LiteSpeed mit `.htaccess`, oder Nginx (Beispiel in `docs/nginx.conf.example`).
- HTTPS. Bei den meisten Anbietern ist ein Let's-Encrypt-Zertifikat im Panel enthalten.

Die Einrichtungsseite prüft HTTPS, die PHP-Version, die Erweiterungen, ob
`config/` und `storage/` beschreibbar sind und ob die E-Mail- und
QR-Bibliotheken im Ordner `vendor/` da sind, und sagt, was fehlt. Fehlen nur
`gd` oder diese Bibliotheken, installiert sie trotzdem und zeigt die Zeile als
„eingeschränkt“. Die Version der Datenbank und den Webserver prüft sie nicht.
Wenn PHP zu alt ist, lässt sich die Version im Hosting-Panel meist selbst
umstellen.

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
composer install --no-dev --prefer-dist --optimize-autoloader --ignore-platform-req=ext-gd
```

`bin/update.sh --clone /pfad/zum/webverzeichnis` erledigt beides in einem
Schritt, mit demselben Schalter. Ohne `composer install` läuft das Portal zwar,
kann aber keine E-Mails verschicken und keinen Zahlungs-QR-Code zeichnen.
`--ignore-platform-req=ext-gd` lässt Composer auch dort installieren, wo PHPs
`gd` fehlt: Nur Profilbilder brauchen sie, alles andere läuft ohne. Ausgenommen
ist damit nur `gd`; der Schalter braucht Composer 2.0 oder neuer.

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
mit einem erfundenen Kurs, vier Kindern, Beiträgen und Nachrichten. Damit lässt
sich alles durchklicken, bevor echte Familien darin stehen – SMTP und
Datenschutzerklärung sind dafür nicht nötig, weil die Beispielkonten fertig
angelegt werden und keine Einladung per E-Mail brauchen. Das Passwort – vier
erfundene Wörter in einem, etwa `KemoTapiRunaSofe` – wird einmalig auf derselben
Seite angezeigt. Die Beispielkonten melden sich 14 Tage lang an; danach geben
„Beispieldaten entfernen“ und erneutes Anlegen neue.

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
   Minderjährigen ist im Entwurf ausdrücklich als offener Punkt markiert. Unter
   „6.“ stehen die Fristen, nach denen das Portal Daten löscht, so wie sie unter
   **Einstellungen → System → Erweitert** eingestellt sind; wer dort eine Frist
   ändert, ändert sie in der Erklärung mit. Eine englische Fassung ist
   freiwillig; wer das Portal auf Englisch nutzt, sieht sonst die deutsche mit
   einem Hinweis darauf.
9. **Familien einladen** – geht erst, wenn 7 und 8 erledigt sind. Jedes Kind
   bekommt seinen Zugang auf seiner eigenen Seite: eine Einladung an seine
   eigene E-Mail-Adresse – Geschwister brauchen jeweils eine eigene; die
   Adresse der Eltern gehört zu den Notfallkontakten. Ein Kind, dessen Adresse
   noch fehlt, bleibt „Ohne Anmeldung“, bis die Adresse auf seiner Seite
   eingetragen und die Einladung geschickt ist.

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

Zuerst den **Wartungsmodus einschalten**: unter **Einstellungen → System**, oder
im Dateimanager eine leere Datei `storage/maintenance.flag` anlegen. Solange er
an ist, sehen Familien und Trainer „Das Portal wird gerade aktualisiert“, und
das Portal verschickt nichts, legt nichts an und räumt nichts auf – auch dann
nicht, wenn die Tabellen gerade fehlen oder erst halb wieder da sind. Als
Administrator bleibst du angemeldet; öffne trotzdem keine Seite, bis die
Sicherung ganz drin ist.

Dann die Programmdateien hochladen, die zur Sicherung gehören: eine Sicherung
von vor einer Aktualisierung gehört zur vorherigen Version, also deren ZIP.
Erst die Dateien, dann die Datenbank: andersherum würden die neueren Dateien
ihre Datenbankänderungen beim nächsten Aufruf noch einmal anwenden.

Dann im Hosting-Panel **phpMyAdmin** öffnen, die Datenbank auswählen, unter
**Exportieren** zur Sicherheit den aktuellen Stand herunterladen, dann alle
Tabellen löschen und unter **Importieren** die gewünschte Datei aus
`storage/backups` einspielen. Die Datei bringt ihre eigenen Tabellen mit und
lässt sich auch zweimal einspielen. Jede Sicherung, die das Portal ab dieser
Version schreibt, legt als Erstes eine Tabelle `import_unfinished` an und
löscht sie mit ihrer letzten Zeile wieder: solange sie da ist, weiß das Portal,
dass gerade eingespielt wird, bleibt geschlossen und legt weder etwas an noch
räumt es auf – wer die Seite aufruft, ob mit oder ohne Wartungsmodus; auch
Versand, Beiträge und Aufräumen im Hintergrund warten von selbst. Bricht das
Einspielen ab, dieselbe Datei noch einmal einspielen; jede Tabelle wird darin
vor dem Anlegen gelöscht, nichts wird doppelt. Eine Sicherung von vor dieser
Version, oder eine im Hosting-Panel exportierte, hat diese Tabelle nicht; bei
ihr erkennt das Portal nur am Fehlen seiner Liste der Datenbankänderungen, dass
noch eingespielt wird, und sobald die Liste drin ist, hält es die Datenbank für
fertig. Eine solche Sicherung deshalb mit den Dateien ihrer eigenen Version
einspielen und mit eingeschaltetem Wartungsmodus, der bis zum Ende an bleibt.

Zuletzt den **Wartungsmodus ausschalten** – unter Einstellungen → System, oder
die Datei löschen – und das Portal öffnen. Wurde eine Aktualisierung
abgelehnt, weil Datensätze fehlten, zählt das Portal nach: sind alle wieder
da, öffnet es sich von selbst; sonst nennt die Seite, was noch fehlt. Ohne
Wartungsmodus zeigt das Portal, solange die Tabellen fehlen oder das Einspielen
läuft, „Das Portal ist vorübergehend geschlossen.“ mit einem der drei Sätze
unten in [Wenn etwas nicht klappt](#wenn-etwas-nicht-klappt); diese Seite lädt
sich alle fünf Minuten neu und öffnet das Portal von selbst, sobald die
Sicherung ganz eingespielt ist. Ein Ordner `storage` gehört zu genau einem
Portal: zwei Portale, die ihn teilen, löschen einander Belege, Fotos und
Sicherungen.

## Optional: Cronjob statt Seitenaufruf

Ohne Cronjob erledigt das Portal wartende Aufgaben selbst, kurz nachdem eine
Seite ausgeliefert wurde: E-Mails verschicken, einmal am Tag aufräumen –
abgelaufene Links und alles, dessen Aufbewahrungsfrist vorbei ist – und, wenn
eingeschaltet, einmal im Monat die Monatsbeiträge anlegen. Höchstens einmal pro
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
sie bleibt gespeichert, was die Datenschutzerklärung unter „6.“ zu löschen
zusagt – Nachrichten, Abwesenheiten und erledigte Problemmeldungen samt
Bildschirmfoto unter anderem. Sie schreibt danach in einer Zeile, was sie
gelöscht hat. Läuft gerade eine Aktualisierung, oder sind die Dateien neuer als
die Datenbank, löscht sie nichts, schreibt das und versucht es beim nächsten
Lauf wieder; solange eine Aktualisierung nicht abgeschlossen ist, eine Sicherung
eingespielt wird oder der Wartungsmodus an ist, bricht sie mit einem Satz ab,
wie in UPDATING.md beschrieben. Die dritte legt am Monatsersten die Beiträge an
– nur eintragen, wenn das Portal die Monatsbeiträge automatisch anlegen soll,
denn sie tut es auch, wenn das auf der Seite **Geld** ausgeschaltet ist.

Cronjob und Seitenaufruf gleichzeitig sind nicht schädlich – ein
Datenbankschloss verhindert, dass zwei Läufe dieselbe E-Mail verschicken.

## Mit Shell-Zugang

Wer eine Kommandozeile hat, braucht die Einrichtungsseite nicht:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader \
  --ignore-platform-req=ext-gd   # nur nach git clone; gd brauchen nur Profilbilder
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
| „Vor der Aktualisierung konnte keine Sicherung angelegt werden“ | Den Grund nennt das Fehlerprotokoll des Hostings, nicht die Seite. Rechte für `storage` auf `755` setzen. Oder im Panel selbst eine Sicherung anlegen und danach im Ordner `storage` eine leere Datei `skip-backup` erstellen; sie gilt für genau ein Update. |
| „Vor der Aktualisierung konnte das Portal im Ordner storage nicht schreiben“ | Dem Ordner `storage` Schreibrechte geben (`755`) und neu laden. Das Portal hat dabei nichts geändert und keine Sicherung angelegt. |
| Jedes Foto und jeder Beleg wird abgelehnt, auch eine Datei, die in Ordnung ist | Unter **Einstellungen → System** nachsehen: Steht dort „Es fehlt: Fotos und Belege hochladen (fileinfo)“, im Hosting-Panel die PHP-Erweiterung `fileinfo` aktivieren. |
| Statt „Foto hinzufügen“ steht „Fotos gehen auf diesem Server noch nicht.“ | Unter **Einstellungen → System** steht dann „Es fehlt: Profilbilder verkleinern (gd)“: im Hosting-Panel die PHP-Erweiterung `gd` aktivieren. Alles andere geht auch ohne sie. |
| Über „Die Datei ist zu groß. Höchstens …“ steht eine englische PHP-Warnung „POST Content-Length of … bytes exceeds the limit …“ | Das Hosting zeigt PHP-Fehler beim Start einer Anfrage auf der Seite an (`display_errors` und `display_startup_errors` an); in PHPs Vorgabe für Server, `php.ini-production`, sind beide aus. Im Hosting-Panel ausschalten. Die Datei war größer, als der Server annimmt; das Portal sagt es im Satz darunter. |
| „Gerade wird eine Sicherung eingespielt, oder das Einspielen ist abgebrochen.“ | Warten, bis phpMyAdmin fertig meldet, dann die Seite neu laden; sie lädt sich alle fünf Minuten von selbst neu. Ist das Einspielen abgebrochen: dieselbe Datei in phpMyAdmin noch einmal einspielen, nichts wird doppelt. Kommt das Ende der Datei nie an, weil sie beschädigt ist: in phpMyAdmin die Tabelle `import_unfinished` löschen – das Portal nimmt die Datenbank dann so, wie sie ist ([UPDATING.md](UPDATING.md#a-refused-update), „A refused update“). |
| „In der Datenbank fehlt die Tabelle schema_migrations, die jedes Portal hat.“ | Wird gerade eine Sicherung von vor dieser Version oder eine aus dem Panel eingespielt: warten, bis phpMyAdmin fertig meldet, dann neu laden; abgebrochen: dieselbe Datei noch einmal einspielen. Wird nichts eingespielt, nennt `config/config.php` eine fremde Datenbank: die richtige eintragen. Das Portal hat nichts verändert. |
| „Die Datenbank ist leer, aber in diesem Ordner lief schon ein Portal.“ | Beim Wiederherstellen: die Sicherung in phpMyAdmin einspielen, dann neu laden. Soll hier wirklich ein neues, leeres Portal entstehen: im Dateimanager `storage/schema.stamp` löschen und neu laden – Belege und Fotos des alten Portals werden danach gelöscht, seine Sicherungen in `storage/backups` bleiben. Die Einrichtungsseite sagt denselben Satz, wenn sie auf eine neue Datenbank zeigt, aber im Ordner eines alten Portals liegt. |
| „Die hochgeladenen Dateien sind älter als die Datenbank“ | Das falsche Paket hochgeladen. Die neueste Version holen und noch einmal entpacken. |
| „Die hochgeladenen Dateien sind unvollständig“ | Das Entpacken ist abgebrochen, oder der Upload lief über FTP im Textmodus. Noch einmal hochladen, FTP auf Binärmodus stellen. |
| E-Mails gehen nicht raus | **Einstellungen → System**: steht dort ein letzter Hintergrundlauf? Sonst **Postausgang** (unter **Chats**), dort steht der Fehler der letzten Zustellung. |
| „Eine Einladung lässt sich noch nicht verschicken …“ | Die Meldung sagt, was fehlt: unter **Einstellungen → SMTP** **„Nur Verbindung prüfen“**, bis dort **Erfolgreich** steht – nach jeder Änderung der SMTP-Angaben noch einmal –, und unter **Einstellungen → Datenschutz** die deutsche Datenschutzerklärung freigeben. |
| „Diese E-Mail-Adresse gehört schon zu einem anderen Zugang …“ | Die Adresse ist schon die Anmeldung einer anderen Person, oft eines Geschwisters; jede Person braucht ihre eigene. Für dieses Kind seine eigene Adresse eintragen – bis dahin bleibt es „Ohne Anmeldung“. |
| Jemand sieht „Die Anwendung ist vorübergehend nicht verfügbar“ oder „Speichern fehlgeschlagen. Bitte erneut versuchen.“ | Das Portal hat den Fehler selbst festgehalten: **Einstellungen → Rückmeldungen**, Eintrag „Automatisch erfasst“, mit der Zahl, wie oft er vorkam. Unter **„Für den Support kopieren“** steht ein Text ohne Namen, E-Mail-Adressen und Eingaben, der an die Person gehen kann, die hilft. |

## Was geprüft wurde

Die gesamte Testsuite läuft gegen **MariaDB 10.11.14** mit PHP 8.4.26 durch, mit
allen 41 Datenbankänderungen, zuletzt am 9. Oktober 2026; sie prüft auch die
Einrichtung, das Anwenden neuer Migrationen beim Seitenaufruf und jede der vier
Prüfungen vor einem Update. Am selben Tag ist das Paket 0.6.0-beta.2 so
durchgespielt worden, wie es hier steht: neu eingerichtet über die
Einrichtungsseite, mit allen 14 Prüfungen und allen 41 Datenbankänderungen, auch
auf einem PHP ohne `gd`; und als Aktualisierung über das erste Beta-Paket, samt
der Sicherung, die das Portal davor schreibt und die anschließend wieder
eingespielt wurde und Tabelle für Tabelle vollständig ankam.

Auf dem Paket wurde außerdem der erste Abend von Anfang bis Ende in einem echten
Browser in Telefonbreite durchgespielt, gegen MariaDB 10.11.14: die
Einrichtungsseite, alle neun Schritte von „Dein Portal einrichten“ über ihre
eigenen Knöpfe, eine Einladung über einen Mailserver auf demselben Rechner, die
Anmeldung als Familie, ein Zahlungsbeleg, eine Problemmeldung, eine Rechnung und
ein absichtlich ausgelöster Fehler. Das war Chromium, kein iPhone, und kein
echter E-Mail-Anbieter.

**MySQL 8.0 selbst ist nicht geprüft**, und auf einem konkreten Hosting-Paket
ist das Ganze noch nicht gelaufen – auch die neue Sperre für versteckte Ordner
in der `.htaccess` nicht, denn der Testserver liest keine `.htaccess`. Stand und
offene Punkte: [VALIDATION.md](VALIDATION.md).
