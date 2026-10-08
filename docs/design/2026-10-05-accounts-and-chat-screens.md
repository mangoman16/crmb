# UI spec: student wizard, sign-in links, accounts page, basic chat (ui-ux-designer, 2026-10-05)

> **Addendum, 2026-10-08 (ADR 0030).** Usernames and one-time sign-in links go; the addendum at the end replaces §2–§6 where they differ. Read it first.

Status 2026-10-07: what §0 assumes of the server is built as ADR 0023 settled it (commits e9aff6e and d095ca4), with a first, minimal form of the wizard (§2), the link card (§3), the page the link opens (§4) and the sign-in box (§5). The start page (§1), the accounts page (§6) and the chat (§7, ADR 0022 §11) are not built. Building this specification is ROADMAP.md item 5. Where this text and ADR 0023 disagree, ADR 0023 wins.

Project manager's decisions on the designer's open issues (binding unless the owner overrules):
- Chats between two students: CLOSED (no new ones, no contact requests); what exists stays readable. Admins read every chat.
- Sign-in link validity: 48 hours (same as an invitation), works once.
- Step 1: first and last name required. The invitation by address alone stays as the small link at the foot of step 1.
- Voice notes and files already in chats keep showing; nothing new of that kind can be sent.
- Password minimum stays 12 (owner asked for the highest security standard; the page tells them to let the phone save it).
- No sign-in links for staff logins (staff keep the e-mail reset link; impersonation covers viewing).
- „Anwesenheit" on today's training row stays (it belongs to the training row, not a quick action).
- Old students without a login get placeholder logins in a migration (ADR 0023 decides the mechanics).
- There is NO undo in the portal any more (app/history.php: tracked() records history only). Every way back is explicit.

## Overruled or narrowed by ADR 0023 §12 and ADR 0022 §11 (these win over the text below)
- Usernames: a–z, 0–9, `.` and `-` only (no `_`), 3–30, start with a letter, end with a letter or digit, no two separators in a row; column VARCHAR(30).
- No „Ansehen" (view as) on a placeholder login or a login not yet signed in (A6 overruled).
- The link page ALWAYS asks for a new password in the POST that signs in — also when a password exists (A8 overruled). The „(b) password already set → Jetzt anmelden → profile, change without the old one" flow in §4 is dropped.
- No sign-in link for a login invited by e-mail (invoices would go to an unconfirmed address). The e-mail done page's line „…oder einen Anmeldelink zum Teilen erstellen" changes to: check the address, send again, or switch to a username.
- Sign-in links for a username login that is already in use: administrators only. Trainers may make one only for a login not yet signed in (the owner may widen this).
- Students send JPEG only (camera); staff JPEG, PNG, WebP. No GIF.
- The access card and „Mein Konto" say who made the last sign-in link, and when.
- Drafts of the wizard are dropped after two hours, at most ten per session.

## 0. Server rules assumed (ADR 0023 confirms or overrules)
A1 A student login may have a username instead of an e-mail address: unique case-insensitive, 3–30 chars of a–z 0–9 . - _ , never '@'. Staff keep a required e-mail address.
A2 Placeholder login: role student, no address, no username, no password, cannot sign in. Badge „Ohne Anmeldung".
A3 Wizard writes nothing until step 2. Step 1 posts student_draft (validates, keeps the details in the session under a random key, &draft=KEY). Step 2 posts student_create with the key and method=email|username|none: student + login + enrolment in one transaction, then mail or link. „Schüler anlegen" without draft always shows an empty step 1.
A4 Sign-in link = token purpose 'signin' at ?page=activate&token=… . GET only shows the page; only the POST consumes it (link previews cannot). 48 h, once; a new link removes the older (make_token() does); can be withdrawn.
A5 The readable link is kept in the staff session after creation so it can be shown again until used/replaced/withdrawn/expired (iOS may reload the tab when switching to WhatsApp). Only the hash in the DB. (security-reviewer to check)
A6 may_impersonate() also allows placeholder logins and username logins not yet signed in.
A7 Sign-in takes address or username in one box, one refusal text; throttle counts the typed value.
A8 Signed in by link with a password already set: may set a new password without the old one, once, in that session.
A9 Chat: upload_types('message') = JPEG, PNG, WebP only; the server refuses voice notes and other files; student–student chats closed (no writing, no new ones, contact requests go); admins read every direct and staff_direct chat.
A10 Deleting a student whose login was never set up (open invitation, placeholder, username never signed in) deletes that login too (extends login_goes_with_student()).
A11 New route student_new: staff only, nav_owner() → students. ?page=student without an id goes there.

## 1. Start page — one quick action (views/dashboard.php)
- Keep the heading button, now to the wizard: link_button(t('+ Schüler anlegen','+ Add student'),'student_new',['from'=>'dashboard']); hidden until a real course exists, as now.
- Remove the .quick-actions row in the news panel („Neuigkeit schreiben"; „An mehrere schreiben" already went with ADR 0026).
- Remove „Termin ändern" from the Termine heading (Kurse has it).
- Keep „Anwesenheit" on today's training row, „Alle ansehen", the admin's setup card.
- Empty state: link_button(t('Ersten Schüler anlegen','Add first student'),'student_new'); body t('Leg deinen ersten Schüler an – Schritt für Schritt, mit der Anmeldung gleich dabei.','Add your first student – step by step, with the sign-in included.').
- app/start.php checklist „students" step links to student_new (backend).
- views/students.php: drop „Per E-Mail einladen" from the heading; one button + Schüler anlegen → student_new&from=students.

## 2. Wizard — new views/student_new.php
Progress in page_head()'s description („Schritt 1 von 2 …"). The create branches of views/student.php go (the „Mitgliedschaft" card for a new student, „Gleich einladen", „Anlegen und weiter", the „Leeres Formular drucken" heading button → moves to step 1).

Step 1 — page_head(t('Neuer Schüler','New student'), t('Schritt 1 von 2: Wer kommt dazu?','Step 1 of 2: Who is joining?')). One card, start_form('student_draft'):
1. input first_name t('Vorname','First name') required, autocomplete=off, autocapitalize=words, maxlength=100
2. input last_name t('Nachname','Last name') required, same
3. input birth_date t('Geburtsdatum','Date of birth') type date optional, max=today, hint t('Bestimmt die Altersgruppe. Kann auch später ergänzt werden.','Decides the age group. Can be added later too.')
4. select_field course t('Kurs','Course') required, default '' („Auswählen"). Options: 'none' => t('Noch keinen Kurs','No course yet'); per course+tariff "{class}:{tariff}" => "Kinder Anfänger · Monatlich 35,00 €"; course without tariff "{class}:0" => "Kinder Anfänger · " + t('noch ohne Tarif','no tariff yet'); full courses left out. Hint t('Volle Kurse stehen nicht in der Liste. Ohne Kurs entstehen keine Beiträge.','Full courses are not listed. Without a course there are no charges.')
5. select_field status t('Mitgliedschaft','Membership') statuses, default_status, required
.form-footer (sticky at 320) submit_button(t('Weiter','Next')).
Under the card, stacked .text-link (44pt): t('Abbrechen','Cancel') → back to from (only dashboard|students); t('Nur die E-Mail-Adresse bekannt? Ohne Namen einladen','Only know the email address? Invite without a name') → students&invite=1#invite. (The „Leeres Formular drucken" link that stood between them went with the printed form, ADR 0026 §8.)
Errors: existing .flash.error, fields refill. Draft gone at step 2 → step 1 with t('Die Angaben waren nicht mehr da. Bitte noch einmal eintragen.','The details were no longer there. Please enter them again.').

Step 2 — page_head(t('Neuer Schüler','New student'), strtr(t('Schritt 2 von 2: Wie meldet sich {name} an?','Step 2 of 2: How does {name} sign in?'),…)) ({name} = first name).
Top: <p class="muted"> summary „Lena Hofer · 12.05.2015 · Kinder Anfänger" + text-link t('Ändern','Change') → step 1 with the draft filled in.
Three cards, each its own form with one submit (no radios, no JS):
- Card A #by-email: <h2> „Per E-Mail einladen" + badge(t('Empfohlen','Recommended'),'green'); text t('{name} bekommt einen Link per E-Mail, legt ein Passwort fest und kann alle Angaben selbst ergänzen oder ändern.','{name} gets a link by email, chooses a password and can add or change all the details themselves.'); mail not ready → mail_not_ready_notice($user) instead of the form; fields input email t('E-Mail-Adresse','Email address') type email required ['autocomplete'=>'off'] + sign_in_address_attributes(), hint t('Die eigene Adresse der Schülerin oder des Schülers – die der Eltern gehört zu den Kontakten.','The student’s own address – a parent’s belongs with the contacts.'); select_field locale t('Sprache der Einladung','Invitation language') de/en 'de'; submit_button(t('Anlegen und Einladung senden','Create and send the invitation')) primary.
- Card B #by-username: heading „Ohne E-Mail, mit Benutzername" / „Without email, with a username" + badge(t('Nur wenn nötig','Only if needed'),'amber'); text t('Für Kinder ohne eigene E-Mail-Adresse. Du bekommst einen QR-Code und einen Link für die erste Anmeldung.','For children without an email address of their own. You get a QR code and a link for the first sign-in.'); .notice.warn <strong> t('Ohne E-Mail-Adresse fehlt einiges','Without an email address, some things are missing') <p> t('Kein „Passwort vergessen“ – ein neues Passwort gibt es nur über einen neuen Link von dir. Keine Rechnungen, Erinnerungen und Hinweise per E-Mail.','No “forgot your password” – a new password only comes through a new link from you. No invoices, reminders or notices by email.'); privacy not released → same notice component (mail_not_ready_notice() gets optional $missing so it can name only the privacy step); input username t('Benutzername','Username') suggestion (lowercase first.last, ä→ae ö→oe ü→ue ß→ss, append 2 if taken) required, sign_in_address_attributes() + maxlength=30, hint t('Kleinbuchstaben, Ziffern, Punkt und Bindestrich. Damit meldet sich {name} an.','Lower-case letters, digits, dot and hyphen. {name} signs in with it.'); submit_button(t('Anlegen und Anmeldelink zeigen','Create and show the sign-in link'),'secondary').
- Card C #later: heading „Ich schließe es selbst ab" / „I will finish it myself"; text t('{name} kann sich vorerst nicht anmelden; du trägst alles selbst ein. Eine Einladung oder einen Anmeldelink erstellst du später auf der Seite von {name}.','{name} cannot sign in for now; you enter everything yourself. You create an invitation or a sign-in link later on {name}’s page.'); submit_button(t('Ohne Anmeldung anlegen','Create without sign-in'),'secondary').
Under the cards: text-link t('Abbrechen – es wird nichts gespeichert','Cancel – nothing is saved') → from.

Done page — student_new&step=done&id=…: page_head(strtr(t('{name} ist angelegt','{name} has been added')), <path line>); the path's card; next_steps_card(student_next_steps($id)); .row-actions link_button(t('Zur Seite von {name}','To {name}’s page'),'student',[id]) and link_button(t('Noch einen Schüler anlegen','Add another student'),'student_new',…,'secondary'); muted line t('Versehentlich angelegt? Ganz unten auf der Seite von {name} löschen – das geht, solange es keine Beiträge gibt.','Added by mistake? Delete it at the very bottom of {name}’s page – possible while there are no charges.').
- E-mail: line t('Die Einladung ist unterwegs.','The invitation is on its way.'); card <p> t('Die Einladung geht an {email}. Der Link darin gilt bis {until}.','The invitation goes to {email}. Its link is valid until {until}.') <p class="muted"> t('Kommt keine E-Mail an? Auf der Seite von {name} kannst du sie erneut senden oder einen Anmeldelink zum Teilen erstellen.','No email arriving? On {name}’s page you can send it again or create a sign-in link to share.')
- Username: line t('Mit diesem Link meldet sich {name} zum ersten Mal an.','{name} signs in for the first time with this link.'); card = link card (§3).
- Placeholder: line t('Ohne Anmeldung – du trägst alles selbst ein.','Without sign-in – you enter everything yourself.'); card <p> t('{name} kann sich noch nicht anmelden. Soll {name} oder die Familie das Portal nutzen, erstell eine Einladung oder einen Anmeldelink.','{name} cannot sign in yet. If {name} or the family should use the portal, create an invitation or a sign-in link.') + link_button(t('Anmeldung einrichten','Set up sign-in'),'student',[id,'#'=>'access'],'secondary').

## 3. „Anmeldelink erstellen"
Only on the student's access card #access (views/student.php); accounts-page Schüler rows lead there. For every student login except a suspended one; never for staff.
Access card by state:
- Ohne Anmeldung: t('{name} meldet sich noch nicht an. Du trägst alles selbst ein.','{name} does not sign in yet. You enter everything yourself.'); actions: existing „Einladung senden" (or the existing "enter an address first" line), the fold with a username field, „Portal als {name} ansehen".
- Noch nicht angemeldet, link valid: t('Anmeldelink erstellt am {sent}; er gilt einmal, bis {until}.','Sign-in link created on {sent}; it works once, until {until}.'); link card if still in this session; actions: fold as „Neuen Link erstellen", POST t('Link zurückziehen','Withdraw the link') (subtle danger-text, no confirmation), Ansehen, Löschen.
- Noch nicht angemeldet, link expired: t('Der Anmeldelink vom {sent} ist abgelaufen. Erstell einen neuen – er gilt wieder 48 Stunden.','The sign-in link of {sent} has expired. Create a new one – it is valid for another 48 hours.'); fold's button primary.
- Aktiv with username: login_facts() shows t('Benutzername','Username') instead of address; actions Ansehen, the fold (forgotten-password path), Sperren, Löschen (login_delete_details() asks for the username typed); no „Link zum Zurücksetzen senden".
- Aktiv/Eingeladen with address: today's actions plus the fold.
The fold: new helper signin_link_details(array $account, string $firstName, bool $askUsername) in app/ui.php beside invitation_withdraw_details(); plain <details>. <summary> t('Anmeldelink erstellen','Create a sign-in link') or t('Neuen Link erstellen','Create a new link'); <p> t('Mit dem Link meldet sich {name} einmal ohne Passwort an und legt dann ein neues fest. Er gilt 48 Stunden.','With the link {name} signs in once without a password and then chooses a new one. It is valid for 48 hours.'); if one exists <p> t('Der Link, den du vorher erstellt hast, gilt dann nicht mehr.','The link you created before then stops working.'); if $askUsername the username field; submit_button(t('Link erstellen','Create the link')); POSTs signin_link, returns to student&id#access.
The link card: new partial views/_signin_link.php (done page + access card). Reuses .pay-box/.pay-qr (suggest renaming to .qr-box/.qr-plate in both places). qr_svg($link,200) drawn at 170px on the white plate (light and dark); <dl class="facts"> t('Benutzername','Username') <dd class="mono">lena.hofer</dd>, t('Gilt einmal, bis','Works once, until') fmt_datetime; <p> t('{name} scannt den Code mit der Handykamera – oder du schickst den Link an {name} oder die Eltern.','{name} scans the code with a phone camera – or you send the link to {name} or the parents.'); .support-copy: label t('Der Link','The link'), read-only <textarea class="support-text" rows="3"> (no-JS fallback), .row-actions <button data-share hidden> t('Teilen','Share'), <button data-copy-from hidden> t('Link kopieren','Copy link') (existing copy code), <span class="copy-status" data-copy-status hidden> t('Kopiert','Copied'); .notice.warn <strong> t('Wer diesen Link hat, kann sich einmal als {name} anmelden.','Whoever has this link can sign in once as {name}.') <p> t('Schick ihn nur an {name} oder die Eltern, nicht in eine Gruppe. Später lässt er sich nicht mehr anzeigen – dann erstellst du einen neuen.','Send it only to {name} or the parents, not to a group. It cannot be shown again later – then you create a new one.') + the „Link zurückziehen" POST.
Web Share: new app.js block (~10 lines), shows [data-share] only where navigator.share exists. Title: club name. Text: t('Hallo {name}, hier ist dein Link für das Portal von {club}. Er gilt einmal, bis {until}.','Hi {name}, here is your link for {club}’s portal. It works once, until {until}.'). URL: the link.

## 4. The page the link opens (views/activate.php, purpose signin)
(a) No password yet — password set in the same POST that signs in. Eyebrow club name; <h1> t('Hallo {name}!','Hello {name}!') (first name); <p> t('Mit diesem Link meldest du dich zum ersten Mal an. Leg dafür ein Passwort fest.','This link signs you in for the first time. Choose a password for it.'); <p class="muted"> t('Du bist nicht {name}? Dann trag hier nichts ein und schließ die Seite.','You are not {name}? Then enter nothing here and close the page.'); if somebody else is signed in on this device: .notice t('Auf diesem Gerät ist gerade {other} angemeldet. Mit diesem Link meldest du {other} ab.','{other} is signed in on this device right now. This link signs {other} out.'); form: input username t('Dein Benutzername','Your username') readonly + sign_in_address_attributes(), hint t('Damit meldest du dich ab jetzt an.','You sign in with this from now on.') (or today's read-only address); input password t('Passwort','Password') hint t('Mindestens 12 Zeichen. Am besten lässt du es dein Handy speichern.','At least 12 characters. Best let your phone save it.'); input password_confirm t('Passwort wiederholen','Repeat password'); today's privacy link + privacy_seen tick (newsletter/notification ticks only with an address); submit_button(t('Speichern und anmelden','Save and sign in')).
(b) Password already set: same <h1>, <p> t('Mit diesem Link meldest du dich einmal ohne Passwort an.','This link signs you in once, without a password.'), the "not you" line and the device notice; one POST t('Jetzt anmelden','Sign in now') + the .signin-consent sentence; lands on profile#sign-in with notice t('Du bist mit einem Link angemeldet. Passwort vergessen? Leg hier gleich ein neues fest – ohne das alte.','You are signed in with a link. Forgotten your password? Set a new one right here – without the old one.'); „Passwort ändern" fold open without „Aktuelles Passwort".
Used/expired: existing „Link nicht mehr gültig" block + t('Einen Anmeldelink von deiner Trainerin kannst du nur einmal benutzen. Frag sie nach einem neuen.','A sign-in link from your coach works only once. Ask her for a new one.').
After first sign-in (and after an accepted e-mail invitation): their own student page, Profil, flash t('Willkommen, {name}! Schau kurz, ob alles stimmt, und ergänze, was fehlt. Frag deine Eltern, wenn du etwas nicht weißt.','Welcome, {name}! Check that everything is right and fill in what is missing. Ask your parents if you are not sure.'); existing „Noch zu ergänzen" card and form below.

## 5. Sign-in (views/login.php)
input('login', t('E-Mail oder Benutzername','Email or username'), …, 'text', true, …, sign_in_address_attributes() + ['inputmode'=>'email']) — type text (email refuses a username), inputmode=email keeps @ and . on the first layer; update sign_in_address_attributes()'s comment. Field name email→login is backend's call (e2e uses the name).
Refusal: t('Anmeldung nicht möglich. Bitte E-Mail bzw. Benutzernamen und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link öffnen, den du bekommen hast.','Could not sign in. Please check the email or username and the password. Not set up yet? Then first open the link you were given.')
views/forgot.php: <p class="muted"> t('Ohne E-Mail-Adresse angemeldet? Dann bekommst du einen neuen Anmeldelink von deiner Trainerin.','Signed up without an email address? Then your coach gives you a new sign-in link.')

## 6. Accounts page (views/accounts.php)
page_head(t('Zugänge','Logins'), t('Wer sich im Portal anmelden kann: Trainer, Administratoren und Schüler.','Who can sign in to the portal: trainers, administrators and students.')). Order: „+ Teammitglied einladen" (unchanged); card „Trainer"; card „Administratoren"; card #students „Schüler"; „Zugänge ohne Schüler" (unchanged, only when any). Headings badge-line h2 + count badge. Trainer/admin rows: today's identity rows and actions. Empty Trainer card: t('Noch niemand. Lade oben eine Trainerin oder einen Trainer ein.','Nobody yet. Invite a trainer above.').
Schüler card: intro <p class="muted"> t('Ändern, sperren und Anmeldelinks machst du auf der Seite der Schülerin oder des Schülers.','Changes, suspending and sign-in links are done on the student’s own page.'); GET filter chips (.saved-filters .chip): „Alle (n)", „Noch nicht angemeldet (n)", „Ohne Anmeldung (n)", „Gesperrt (n)", zero-count chips hidden; each row <a class="member-row"> → student&id#access: avatar, name, <small> address / t('Benutzername: ','Username: ')+username / t('Ohne Anmeldung','No sign-in'); presence_line for active; while not signed in t('Link gilt bis ','Link valid until ')+date or t('Link abgelaufen','Link expired'); badge + arrow. Badges via login_state_badge() with two new states: Aktiv (green), Eingeladen (amber, e-mail invite open), Noch nicht angemeldet (amber, username login), Ohne Anmeldung (grey), Gesperrt (red), Kein Zugang (grey, any old student still without a login). Open invitations by address alone: address as name → students&invitations=1#invitations. Sorted by last name, 50 per page (.pagination). Empty t('Noch keine Schüler. Leg den ersten über „Schüler anlegen“ an.','No students yet. Add the first with “Add student”.'); filtered empty t('Niemand in dieser Auswahl.','Nobody in this selection.').

## 7. Chat (views/messages.php)
Composer: remove the microphone, attachment_seconds and the recorder block in app.js. The clip label becomes the photo button with icon('camera'): student label t('Foto aufnehmen','Take a photo'), <input type="file" name="attachment" accept="{images}" capture="environment">; staff t('Foto senden','Send a photo'), no capture. Placeholder t('Nachricht','Message'). Help line under the box, visible on phones, 13px: student t('Text und Fotos. Fotos direkt mit der Kamera.','Text and photos, straight from the camera.'); staff t('Text und Fotos bis ','Text and photos up to ').upload_limit_label().'.'. CSS: delete the max-width:379px rule wrapping .composer-row; delete .composer>small{display:none}; small text .8125rem. app.js: when a photo is picked, .composer-note shows t('Foto bereit – zum Senden auf den Pfeil tippen.','Photo ready – tap the arrow to send.') (text from a data attribute printed by PHP). Server refusal for an empty message: t('Bitte etwas schreiben oder ein Foto anhängen.','Write something, or attach a photo.').
Chat notes (.chat-note): course unchanged; staff_direct and direct between two staff: t('Hier schreibt ihr zu zweit. Die Administratoren des Vereins können mitlesen.','Just the two of you write here. The club’s administrators can read along.'); direct between two students (closed): t('Chats zwischen Schülern gibt es nicht mehr. Was hier steht, kannst du weiter lesen. Schreib deiner Trainerin oder in deiner Kursgruppe.','Chats between students have closed. You can still read what is here. Write to your coach or in your course group.'); staff desk unchanged; admin in a chat she is not part of: .chat-note where the composer would be t('Du liest hier mit. Schreiben können nur die beiden.','You are reading along. Only the two of them can write.').
A student's „Neue Nachricht": only „Trainerteam". Gone: „Kinder", „Möchte dir schreiben", „Jemand anderen fragen", the „neue Anfrage" chip.
„Alle Einzelchats" (admins): chip renamed from „Alle Direktchats" to t('Alle Einzelchats','All direct chats') (open: „Meine Chats"); sections t('Schüler und Team','Students and team'), t('Im Team','Within the team'), t('Zwischen Schülern (geschlossen)','Between students (closed)'); rows show both names (people_names), no unread count; empty t('Noch keine Einzelchats.','No direct chats yet.').

## TESTING.md by hand (iPhone)
Student's photo button opens the camera directly; a photo arrives as an upright JPEG; „Teilen" opens the share sheet; a second phone scans the QR off a 320px screen; a WhatsApp preview of the link does not use it up; iCloud Keychain saves the password under the username.

---

## Addendum, 2026-10-08: ADR 0030 — one person, one address (ui-ux-designer)

ADR 0030 (accepted 2026-10-08): usernames and one-time sign-in links go, every login signs in by its own e-mail address, a student without an address is a placeholder, „Ohne Anmeldung", until invited by address. This addendum replaces §2–§6 where they differ from frontend-dev's batch for ADR 0023 (`scratchpad/review/frontend-0023.diff` at the time of writing) as ADR 0030 §8 trims it; what the batch has and this text does not name stands as built. §3 and §4 go whole. Written in Part 0's language (`docs/design/2026-10-07-ios-design-language-and-goal-screens.md`).

*Decided by the project manager, 2026-10-08:* the fourth chip „Eingeladen" on Zugänge (open issue 1) is built.

**Who, where, in their words.**
- The trainer in the hall, one hand on the phone: „Ich leg das Kind schnell an – die Mail-Adresse hab ich noch nicht." Weeks later, with a parent's message open: „Jetzt hab ich die Adresse, die Einladung soll raus."
- A parent on the sofa: „Womit melde ich mich an?" — the address, and nothing else, on every page that asks.

### §2 The wizard

**Step 1** is unchanged.

**Step 2 stays a step with two cards, not an optional address box on step 1** (ADR 0030 §6 left this to the designer). Sending a family an e-mail is a decision of its own, taken by a tap, not a side effect of a box left empty; each card says its consequence before the tap; and ADR 0023 §5's rules (every refusal before the first write, one transaction, the draft in the session) do not move. One question per step: „Schritt 2 von 2: Wie meldet sich {name} an?"

The screen at 320: large title, `wizard_progress(2,2)`, the summary line with „Ändern", then:

- **Card (a) `#by-email`**, as built, tightened so that card (b) reaches the first screen (measured):
  - h2 „Per E-Mail einladen" + `badge(t('Empfohlen','Recommended'),'green')`;
  - one sentence: `strtr(t('{name} bekommt einen Link per E-Mail und richtet sich selbst ein.','{name} gets a link by email and sets themselves up.'),…)` (was three lines);
  - while `!account_mail_ready()`: `mail_not_ready_notice($user)` instead of the form;
  - the form: the address box as built, hint `strtr(t('Die eigene Adresse von {name} – die der Eltern gehört zu den Kontakten.','{name}’s own address – a parent’s belongs with the contacts.'),…)`; the language select; the filled button `t('Anlegen und einladen','Create and invite')` (was „Anlegen und Einladung senden", two lines at 320).
- **Card (b) `#later`** (replaces the batch's „Ich schließe es selbst ab" and the username card):
  - h2 `t('Ohne Anmeldung','Without sign-in')`, no badge — „Empfohlen" on (a) ranks them;
  - one sentence: `strtr(t('Noch keine Adresse? Dann kann sich {name} vorerst nicht anmelden, und du trägst alles selbst ein. Die Einladung schickst du später von der Seite von {name}.','No address yet? Then {name} cannot sign in for now, and you enter everything yourself. You send the invitation later from {name}’s page.'),…)`;
  - the tinted button `t('Ohne Anmeldung anlegen','Create without sign-in')`, posting `student_create` with `method=none`.
- Under the cards, as built: „Abbrechen – es wird nichts gespeichert".

**If the owner says no child without an address:** card (b) goes and nothing else moves; the step's description becomes `strtr(t('Schritt 2 von 2: Die E-Mail-Adresse von {name}','Step 2 of 2: {name}’s email address'),…)`; and while `!account_mail_ready()` step 1 shows `mail_not_ready_notice($user)` in place of its form, because the wizard could not finish — a form that can only be refused is not offered.

**The done page** (`step=done` and `made`): `$path` has two values.
- (a) `email`: description „Die Einladung ist unterwegs."; the card „Die Einladung geht an {email}. Der Link darin gilt bis {until}." and the muted line `strtr(t('Kommt keine E-Mail an? Auf der Seite von {name} prüfst du die Adresse und sendest die Einladung noch einmal.','No email arriving? On {name}’s page you check the address and send the invitation again.'),…)` — the „– oder ziehst sie zurück und vergibst einen Benutzernamen" goes.
- (b) `none`: description „Ohne Anmeldung – du trägst alles selbst ein."; the card `strtr(t('{name} kann sich noch nicht anmelden. Sobald du die E-Mail-Adresse hast, trägst du sie auf der Seite von {name} ein und schickst die Einladung.','{name} cannot sign in yet. Once you have the email address, enter it on {name}’s page and send the invitation.'),…)` and the built tinted `link_button(t('Anmeldung einrichten','Set up sign-in'),'student',['id'=>…,'#'=>'access'],'secondary')`.
- The `username` path, the link card and „Neuen Link erstellen" go. `next_steps_card()`, the two buttons and „Versehentlich angelegt? …" stay.

### §3 and §4 go
No link card (`views/_signin_link.php`), no „Teilen" / „Link kopieren", no QR on the done page or the access card, no page a sign-in link opens. Part 3's screen B (the design-language document) reads "the address, read only" where it said "the address or username".

### §3 (new) The access card `#access` on the child's page
Four states, one card, every action its own form; folds that remove something open as sheets (C11); buttons stack full width at 320 as built (`.access-card`).

| State | The card |
| --- | --- |
| **Ohne Anmeldung** | the sentence „{name} meldet sich noch nicht an. Du trägst alles selbst ein."; then **one form**, `student_invite`: the address box (stacked, C9) pre-filled with `students.email`, the wizard's hint; the select row `t('Sprache der Einladung','Invitation language')`; the filled button `t('Einladung senden','Send the invitation')`. While `!account_mail_ready()`: `mail_not_ready_notice($user)` instead of the form. While the record's address is another login's: the built warning, no form. The built „Die Einladung geht an …" and „Trag oben zuerst eine E-Mail-Adresse ein und speichere." go: the box says it. |
| **Eingeladen** | `login_facts()` with the address; „Eingeladen am {sent}; der Link gilt bis {until}." or the expired variants, as built; „Einladung erneut senden" (tinted; filled once the link has run out); „Einladung zurückziehen" as a sheet with nothing to type (`invitation_withdraw_details()`), or `login_delete_details()` when a password was ever set — as built. |
| **Aktiv** | `login_facts()`; the muted „Passwort zuletzt per E-Mail-Link neu gesetzt: {when}" within 14 days; „Portal als {name} ansehen" (tinted, `may_impersonate()`); „Link zum Zurücksetzen senden" (tinted, `reset_link_possible()`); „Zugang sperren" (tinted); „Anmeldung löschen" (sheet, red, the address typed). |
| **Gesperrt** | „Gesperrt – {name} kann sich nicht anmelden. Daten und Nachrichten bleiben."; `login_facts()`; „Zugang entsperren" (tinted); „Anmeldung löschen" (sheet). |

- The `waiting` state, both sign-in-link folds, „Link zurückziehen", „Benutzernamen zurückziehen" and the „Anmeldelink vom … ({who})" lines go.
- **Why the address box sits on the card.** The trainer gets the address weeks after adding the child, from a parent's message, with the child's page open at the card: typing it under Persönliche Daten, saving, scrolling back and tapping again is two posts for one thought. One form, one tap — ADR 0030 §6's "the address and „Einladung senden"". Persönliche Daten keeps its address box for a placeholder: anchor `#email`, where the list's „Kinder ohne E-Mail-Adresse" and the next steps lead, and the only place to keep an address while mail is not set up. Both boxes show `students.email`; the card's post writes it through `invite_student()`, byte for byte, as today.
- **The way back.** An invitation to a wrong address: „Einladung zurückziehen" — nothing to type, nothing lost, invite again (as built). A login deleted: the child keeps a new, empty login and can be invited again; the sheet says so and offers „sperren" as the lighter choice (as built).
- The danger zone „Schüler löschen": the `waiting` sentence goes; the placeholder, invited and active sentences stay.
- Persönliche Daten's `#email` block keeps its three states (read-only in use, editable with the re-send hint while invited, editable for a placeholder).

### §4 (new) Mein Konto (`views/profile.php`)
The card „Anmeldung", then „Name und Darstellung", the privacy group and „Abmelden", as built. In the card:
- `login_facts($user, t('bestätigt','verified'))`: the address, one row;
- the one sentence `t('Mit dieser Adresse meldest du dich an.','You sign in with this address.')`;
- **the resets of the last 14 days** (`password_resets_for()` only): `.notice.warn` with `<strong>` `t('Dein Passwort wurde per E-Mail-Link neu festgelegt','Your password was set anew through an email link')`, one line per reset with `fmt_datetime()`, then the built „Warst du das nicht? …" sentence (family / staff);
- the fold „E-Mail-Adresse ändern", always this label, with the one hint (the current-address one); „Passwort ändern";
- in „Name und Darstellung": the three switches (`check_field(…, switch: true)`) **always**; the hidden-field branch and „E-Mails vom Verein … sobald du oben eine E-Mail-Adresse hinzufügst" go.

### §5 Sign-in, „vergessen", the link page
- **`views/login.php`**: `input('email', t('E-Mail-Adresse','Email address'), '', 'email', true, '', '', sign_in_address_attributes())`. `type="email"` again (the „@" keyboard, the format checked before posting); no `inputmode`. The box's `name` is backend-dev's (`email` as at 0021, or `login` as the batch posts it; `tests/e2e.mjs` fills it by name). `autocomplete="username"` stays (ADR 0030 §7).
- **The refusal** (`sign_in_refusal()`, backend-dev): `t('Anmeldung nicht möglich. Bitte E-Mail-Adresse und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link aus der Einladung öffnen.','Could not sign in. Please check the email address and the password. Not set up yet? Then first open the link in your invitation.')`.
- **`views/forgot.php`**: `t('Gib deine E-Mail-Adresse ein. Du bekommst dann einen Link, mit dem du ein Passwort festlegst.','Enter your email address. You will then get a link to set a password.')`; the same box as the sign-in page; „Ohne E-Mail-Adresse angemeldet? …" goes; „Keine E-Mail bekommen? …" stays.
- **`views/activate.php`**: „Link nicht mehr gültig": `t('Schon eingerichtet? Dann melde dich einfach mit deiner E-Mail-Adresse an.','Already set up? Then just sign in with your email address.')`; the lifetime sentence and „Neuen Link anfordern" stay; the coach's-link sentence goes. The form: no `$signin` branch; the eyebrow is the login's name for an invitation that has one, else the club's; h1 „Konto einrichten" / „E-Mail bestätigen" / „Neues Passwort"; the address read-only with „Damit meldest du dich an."; the username box goes; the password hint `t('Mindestens 12 Zeichen. Am besten lässt du es dein Handy speichern.','At least 12 characters. Best let your phone save it.')` wherever a password is set (the PM's decision of 2026-10-05); the two mail switches on every invitation, since every login here has an address; „Konto aktivieren" stays until Part 3 G1b makes it „Weiter".

### §6 Zugänge (`views/accounts.php`)
As built — the groups, the team rows, the student rows leading to the access card, the paging — with:
- **The chips** on the Schüler group: „Alle (n)", „Eingeladen (n)", „Ohne Anmeldung (n)", „Gesperrt (n)"; zero-count chips hidden. „Eingeladen" is the batch's `waiting` chip (`a.state='invited'`) under the name its badge already has: one name per state. ADR 0030 §8 lists three chips; the fourth is this spec's recommendation, decided (above): „Wer hat die Einladung noch nicht angenommen?" is the question she opens this page for, and the badge alone means scrolling fifty rows.
- **A student's row**: the name; `<small>` the address (no `.mono`, no „Benutzername: "); while invited „Link gilt bis {date}" or „Link abgelaufen" (`link_expires_at` from `invite` alone); `login_state_badge()` without its `waiting` branch; the chevron. A placeholder's row has no `<small>`.
- **The footer**: `t('Einladen, sperren und löschen machst du auf der Seite der Schülerin oder des Schülers.','Inviting, suspending and deleting are done on the student’s own page.')`.
- Badges: Aktiv green, Eingeladen amber, Ohne Anmeldung grey, Gesperrt red, Kein Zugang grey (never expected).

### Without JavaScript
Forms and links throughout, as built; the sheets open in place. Nothing new needs it.

### Empty and error states
- Wizard (a): an address invalid, in use, on a student without sign-in, or mail not ready → refused before the first write, back to step 2 with the typed address and the draft (as built, `held_for('student_create')`). Draft gone → step 1 with „Die Angaben waren nicht mehr da…" (as built).
- The access card's form: the same refusals, back to the card (`#access`) with the typed address.
- Zugänge empty, Mein Konto without a reset in 14 days (no notice): as built.

### What a family sees
The sign-in page: one box „E-Mail-Adresse" and the password. The link page: their address read-only, a password, the privacy acknowledgement, two switches. Mein Konto: their address, two folds, three switches. Nowhere a family reads does „Benutzername" or „Anmeldelink" appear.

### Reuses
`input()`, `select_field()`, `check_field(…, switch)`, `badge()`, `wizard_progress()`, `next_steps_card()`, `mail_not_ready_notice($user)` (its `$missing` and `$title` go, ADR 0030 §8), `login_facts()`, `login_state_badge()`, `login_delete_details()`, `invitation_withdraw_details()`, `sign_in_address_attributes()`, `token_lifetime_words()`, `invitation_dates()`, `invitation_link_live()`, `password_resets_for()`, `.access-card`, `.account-group`, `.member-row`, `.saved-filters .chip`, `.text-links`.

### Takes away
The username card and its privacy variant (`#by-username`); the link card, „Teilen", „Link kopieren", the QR on the done page and the access card; „Anmeldelink erstellen", „Neuen Link erstellen", „Link zurückziehen", „Benutzernamen zurückziehen"; the `waiting` state and „Noch nicht angemeldet" everywhere; „E-Mail oder Benutzername"; Mein Konto's three sign-in sentences, „E-Mail-Adresse hinzufügen" with its hint, and the hidden mail fields; `activate.php`'s `$signin` branch, username box and coach's-link sentence; `forgot.php`'s coach's-link sentence; the done page's username path and link lines; the access card's „Die Einladung geht an …" and „Trag oben zuerst …" lines (the box replaces them).

### For backend-dev (beyond ADR 0030 §1–§4 and §6)
- `student_invite` takes the posted `email` through `invitation_address()`, as the wizard does, and `locale` through `choose(…,['de','en'])`; falls back to the record's address when none is posted (an old page); `invite_student($s,$email,$locale)` writes both. The flash stays „Die Einladung an {email} ist unterwegs."
- `student_login_filters()`: the key `waiting` becomes `invited` (chip address `logins=invited`); `student_logins()`' subquery reads `purpose='invite'`.
- `sign_in_refusal()`'s text above.
- Optional: while `account_mail_ready()`, `student_next_steps()`' „E-Mail-Adresse eintragen" may lead to `#access`, where the box now is.

### Measured
A copy of the working tree (HEAD `2f633c4` plus frontend-dev's uncommitted batch, `php -l` clean), on a throwaway portal with the example data and mail marked ready; MariaDB 10.11.14, PHP 8.4.26, Chromium 141; 320 and 390, light and dark; `tests/mobile.mjs`'s rules loaded from the file. Prototypes are the built pages edited in the browser; DejaVu Sans, wider than San Francisco.
- **Wizard step 2.** Built (three cards): 2,022 px at 320; „Anlegen und Einladung senden" two lines (63 px); the third card's button at y 1,708. Revised two cards, tightened: 1,253 px; (a)'s „Anlegen und einladen" one line at y 632–676; card (b) begins at y 716, on the first 780 px screen; its button at 939–1,001 (two lines in the test font inside the card's 256 px; one line expected in San Francisco). 390: (b) at 647, button 846–890, one line. (b) as a plain block under the card instead of a card: button at 878–922, page 1,157 — rejected: a muted sentence under a card is what people here skip; the heading „Ohne Anmeldung" is what they scan for. 0 problems in every variant, both widths, both themes.
- **Access card, placeholder.** Built: 305 px at 320 (with the sign-in-link fold). Revised (address box, language row, button, no fold): 459 px at 320, 436 at 390; the button 44 px, one line. 0 problems.
- **Zugänge chips.** Four chips: three rows at 320 (148 px), two at 390 (96 px); built two chips: two rows / one. 0 problems.
- Not measured: Mein Konto, sign-in, forgot, activate — text changes on built layouts.
- Open: card (b)'s button may wrap to two lines at 320 in a wide font (44 pt either way; mobile-tester checks on the build); `tests/mobile.mjs` cannot reach step 2 (it needs a draft): a TESTING.md walk, or the views suite renders it; two address boxes on a placeholder's page (Persönliche Daten and the card), by design and stated; both read `students.email`.
