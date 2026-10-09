<?php
declare(strict_types=1);

/**
 * The marker that tells a stored payload apart from a plain one.
 *
 * A payload used to be the message text and nothing else. Attachments need more
 * than that, and a job queued by the previous version has to keep sending, so
 * the structured form announces itself rather than being guessed at.
 */
const MAIL_STRUCTURED = "\x01crm-mail\n";

/**
 * Queue one email.
 *
 * $attach describes files rather than carrying them: ['kind'=>'invoice','id'=>7].
 * The file is built when the message is sent, which keeps a queue of invoices
 * from being a queue of PDFs and means what goes out is the current document.
 *
 * The recipient is asked only whether mail can go there (email_deliverable()),
 * never whether it could be written today (email_value()). Every stored address
 * passed the first; a legacy one may fail the second, and a throw here rolls
 * back the whole action - a newsletter to every other family with it (ADR 0019,
 * R2).
 *
 * A login without an address has $recipient null: then nothing is queued and
 * nothing thrown, for the same reason - one login without a mailbox must not
 * roll back a newsletter to everybody else. The portal makes none that signs in
 * (ADR 0030), and a placeholder takes no mail (account_takes_mail()), so what
 * reaches this is a login edited by hand in the database - an active one
 * without an address.
 *
 * $notice marks a security mail that carries no link: a notice that how
 * somebody signs in has changed (notify_sign_in_changed()). The sender lets
 * only such a one go without a link; any other security mail goes only with a
 * live link in it (security_mail_links_live()).
 */
function queue_mail(?int $accountId,?string $recipient,string $subject,string $body,string $category,array $attach=[],bool $notice=false): void {
    if($recipient===null) return;
    if(!email_deliverable(email_normalised($recipient))) throw new UserError(t('Ungültige E-Mail-Adresse.','Invalid email address.'));
    if(preg_match('/[\r\n]/',$subject) || mb_strlen($subject)>255) throw new UserError(t('Ungültiger Betreff.','Invalid subject.'));
    $payload=$attach || $notice
        ? MAIL_STRUCTURED.json_encode(['body'=>$body,'attach'=>$attach]+($notice?['notice'=>true]:[]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
        : $body;
    run('INSERT INTO mail_jobs (account_id,recipient,subject,payload,category,created_at) VALUES (?,?,?,?,?,?)',[$accountId,$recipient,$subject,seal($payload),$category,now()]);
}

/** A stored payload, as message text, the files to build and whether it is a notice (queue_mail()). */
function mail_payload(string $stored): array {
    if(!str_starts_with($stored,MAIL_STRUCTURED)) return ['body'=>$stored,'attach'=>[],'notice'=>false];
    $decoded=json_decode(substr($stored,strlen(MAIL_STRUCTURED)),true);
    if(!is_array($decoded)) return ['body'=>$stored,'attach'=>[],'notice'=>false];
    return ['body'=>(string)($decoded['body']??''),'attach'=>is_array($decoded['attach']??null)?$decoded['attach']:[],
            'notice'=>($decoded['notice']??false)===true];
}

/**
 * Build one described attachment, or null when it can no longer be built.
 *
 * A null means the file is gone - an invoice deleted, say - and the message goes
 * without it rather than failing for ever in the queue.
 */
function mail_attachment(array $described): ?array {
    if(($described['kind']??'')!=='invoice') return null;
    $invoice=one('SELECT * FROM invoices WHERE id=?',[(int)($described['id']??0)]);
    if(!$invoice) return null;
    return ['name'=>invoice_filename($invoice),'mime'=>'application/pdf','body'=>invoice_pdf($invoice)];
}
/**
 * Whether two addresses are one mailbox, compared the way they are stored.
 *
 * Not byte for byte: an address saved with capitals, before email_value()
 * lower-cased everything, would otherwise cancel a real reset mail to it
 * (ADR 0019, R7).
 */
function same_address(string $a, string $b): bool { return email_normalised($a)===email_normalised($b); }

/**
 * An address as a mail to another mailbox may show it: the first letter of the
 * name and of the domain, and its ending - „l***@b***.test". Enough for its
 * holder to know it, too little for whoever reads the other mailbox to write to.
 */
function masked_address(string $email): string {
    [$local,$domain]=explode('@',$email,2)+['',''];
    $dot=strrpos($domain,'.');
    return mb_substr($local,0,1).'***@'.mb_substr($domain,0,1).'***'.($dot===false?'':substr($domain,$dot));
}

/**
 * Whether every sign-in link in a security mail may still go to $recipient.
 *
 * Asked by the sender at the moment of sending, because a lot can happen while a
 * mail waits: a link replaced, a login suspended, an address moved. Every mail
 * send_account_token() writes carries one link today; every link found is still
 * checked, not the first, so a body that ever carries more cannot let a stale
 * one through [S1]. A mail with no link in it fails here, and is not sent unless
 * it was queued as a notice (queue_mail()'s $notice).
 * Each link must still open what it was made for - link_usable(), the rule the
 * page and the action ask, so a mail never carries a link that would only say
 * „Link nicht mehr gültig" - and belong to this recipient: the login's own
 * address, or for a changed address the new one it is confirming.
 */
function security_mail_links_live(string $body, string $recipient): bool {
    if(!preg_match_all('/[?&]token=([a-f0-9]{64})\b/',$body,$found)) return false;
    foreach($found[1] as $token) {
        $record=token_record(hash('sha256',$token));
        if(!link_usable($record)) return false;
        $address=$record['purpose']==='email' ? (string)$record['target_email'] : (string)$record['email'];
        if(!same_address($address,$recipient)) return false;
    }
    return true;
}
/** Whether a login is in use: its holder has set it up, and it is not suspended. */
function account_in_use(array $account): bool { return ($account['state']??'')==='active' && !empty($account['verified_at']); }
/**
 * Whether an account takes mail of one category: set up, not suspended, and not
 * switched off by its holder (unsubscribe_categories() names each switch; mail
 * that has none, a security mail, cannot be switched off).
 *
 * One rule for the queue, which drops what this refuses, and for everything
 * that writes to a family: notify_invoice() asked the first two and never the
 * third, so an invoice was queued, dropped, and marked as e-mailed.
 */
function account_takes_mail(array $account, string $category): bool {
    // A login in use without an address - only a row edited by hand, since
    // the portal makes none (ADR 0030) - has nowhere to take it.
    if(!account_in_use($account) || (string)($account['email']??'')==='') return false;
    $switch=unsubscribe_categories()[$category]??null;
    return $switch===null || (bool)($account[$switch]??1);
}
function cancel_account_mail(int $id): void { run("UPDATE mail_jobs SET status='cancelled',payload='',error=NULL WHERE account_id=? AND status IN ('queued','failed')",[$id]); }
function notify_thread(array $account,int $threadId,string $subject): void {
    if($account['state']!=='active' || !$account['verified_at'] || !$account['notifications']) return;
    $en=$account['locale']==='en';
    queue_mail((int)$account['id'],$account['email'],$en?'New message in your badminton portal':'Neue Nachricht im Badminton-Portal',($en?'A new message is waiting for you. Open your conversation:':'Du hast eine neue Nachricht. Öffne deine Unterhaltung:')."\n".url('messages',['id'=>$threadId]),'notifications');
}
/**
 * Tell a login's holder that how they sign in has changed, so a change made by
 * somebody else - in a session left open, or with the password - does not go
 * unnoticed (security review, 2026-10-08): a new address to the old one, with
 * the new one masked (masked_address()), a new password to the address the
 * login has. $account is the login as it was before the change.
 *
 * Only for a login that was set up: nobody signs in yet with an invitation's
 * address, which staff correct when it was mistyped - a stranger's mailbox.
 * A security mail, so no switch stops it, the queue never tries it again by
 * itself and its body is cleared once it is sent; it is a notice, with no link
 * (queue_mail()). Nothing without a deliverable address: a notice is never the
 * reason a change fails.
 */
function notify_sign_in_changed(array $account, string $to, ?string $newAddress = null): void {
    if(empty($account['verified_at']) || !email_deliverable(email_normalised($to))) return;
    if($newAddress!==null && same_address($to,$newAddress)) return;
    $en=($account['locale']??'')==='en';
    $what=$newAddress!==null
        ? strtr($en?'the address you sign in with has been changed to {address}.':'die Adresse, mit der du dich anmeldest, wurde auf {address} geändert.',
                ['{address}'=>masked_address($newAddress)])
        : ($en?'the password you sign in with has been changed.':'das Passwort, mit dem du dich anmeldest, wurde geändert.');
    queue_mail((int)$account['id'],$to,
        $newAddress!==null ? ($en?'Your sign-in address was changed':'Deine Anmeldeadresse wurde geändert')
                           : ($en?'Your password was changed':'Dein Passwort wurde geändert'),
        mail_greeting($account).$what."\n\n".($en?'Wasn’t that you? Get in touch with the club.':'Warst du das nicht? Melde dich beim Verein.'),
        'security',notice:true);
}
const MAIL_MAX_ATTEMPTS = 5;
// Backoff per attempt number, in seconds: ~1min, 5min, 15min, 1h.
const MAIL_BACKOFF = [60, 300, 900, 3600];
/**
 * The name a mail opens with, after "Hallo".
 *
 * A student's login greets the student: it is their own access (ADR 0010), and
 * the name on the account is whatever was typed when it was made - "Familie
 * Hofer" on an old one. The first name, because every mail here says "du".
 * Staff, and a student login nobody points to any more, are greeted by the
 * account's own name. One function, so no mail greets differently.
 */
function greeting_name(array $account): string {
    if(($account['role']??'')==='student' && isset($account['id'])) {
        $first=scalar('SELECT first_name FROM students WHERE account_id=?',[(int)$account['id']]);
        if(is_string($first) && trim($first)!=='') return trim($first);
    }
    return (string)($account['name']??'');
}
/**
 * The line every mail opens with, and the empty line after it: „Hallo Lena,“,
 * or „Hallo,“ for a login nobody has named yet - an invitation by address
 * (ADR 0021, §3) - never „Hallo ,“.
 */
function mail_greeting(array $account): string {
    $name=trim(greeting_name($account));
    return (($account['locale']??'')==='en'?'Hello':'Hallo').($name!==''?' '.$name:'').",\n\n";
}
/**
 * Who „Jetzt schicken" on Geld reminds of overdue charges, and who it cannot
 * (design N5): one reminder per login with an overdue charge, listing all of
 * them, oldest first. One login is one child (ADR 0030), so brothers and
 * sisters get one each. None for a child with no login or one whose login takes
 * no payments mail, and none for a login reminded already today, the club's
 * date, so that a second tap or a page left open sends nobody a second mail.
 * The sheet on Geld counts with this and payment_remind sends to it, so the
 * number on the button is the number that goes out. $studentId narrows it to
 * one child.
 *
 * 'send' holds, per login, the account, the child and its overdue charges with
 * 'paid'; 'none' and 'today' count the children left out, for each reason.
 */
function payment_reminders(?int $studentId = null): array {
    $byChild = [];
    foreach (rows('SELECT c.*, s.first_name, s.last_name, s.account_id, '.charge_paid_sql().' AS paid'
        .' FROM charges c JOIN students s ON s.id=c.student_id'
        .' WHERE '.charge_is_overdue_sql().($studentId ? ' AND s.id=?' : '')
        .' ORDER BY s.id, c.due_on, c.id', $studentId ? [today(), $studentId] : [today()]) as $charge)
        $byChild[(int)$charge['student_id']][] = $charge;
    $accountIds = array_values(array_filter(array_map(fn(array $charges) => (int)$charges[0]['account_id'], $byChild)));
    $accounts = $accountIds
        ? array_column(rows('SELECT * FROM accounts WHERE id IN ('.implode(',', array_fill(0, count($accountIds), '?')).')', $accountIds), null, 'id')
        : [];
    $remindedToday = array_flip(logins_reminded_today());
    $reminders = ['send' => [], 'none' => 0, 'today' => 0];
    foreach ($byChild as $childId => $charges) {
        $account = $accounts[(int)$charges[0]['account_id']] ?? null;
        if (!$account || !account_takes_mail($account, 'payments')) { $reminders['none']++; continue; }
        if (isset($remindedToday[(int)$account['id']])) { $reminders['today']++; continue; }
        $reminders['send'][] = ['account' => $account, 'charges' => $charges,
            'student' => ['id' => $childId, 'first_name' => $charges[0]['first_name'], 'last_name' => $charges[0]['last_name']]];
    }
    return $reminders;
}

/**
 * The logins a payment reminder went to today, the club's calendar date, as the
 * outbox has them. An invoice goes under the same category, and what a mail
 * says is sealed, so a reminder is told from it by how its subject begins
 * (payment_reminder_subject_start()), in either language. One taken back from
 * the outbox does not count.
 */
function logins_reminded_today(): array {
    $since = (new DateTimeImmutable(today().' 00:00:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $begins = fn(string $locale): string => addcslashes(in_locale($locale, payment_reminder_subject_start(...)), '%_\\').'%';
    return array_map('intval', array_column(rows("SELECT DISTINCT account_id FROM mail_jobs WHERE category='payments' AND status<>'cancelled'"
        .' AND account_id IS NOT NULL AND created_at>=? AND (subject LIKE ? OR subject LIKE ?)', [$since, $begins('de'), $begins('en')]), 'account_id'));
}

/** How a payment reminder's subject begins: the one place it is worded, and how the outbox finds it again. */
function payment_reminder_subject_start(): string { return t('Noch offen: ', 'Still to pay: '); }

/**
 * Who a reminder run leaves out, and why, as said on the sheet before „Jetzt
 * schicken" and in the banner after it: '' when it leaves out nobody.
 */
function payment_reminders_left_out(array $reminders): string {
    return trim(($reminders['today'] ? t('Heute schon erinnert: ', 'Already reminded today: ').plural($reminders['today'], 'Kind', 'Kinder', 'child', 'children').'.' : '')
        .($reminders['none'] ? ' '.plural($reminders['none'], 'Kind bekommt keine', 'Kinder bekommen keine', 'child gets none', 'children get none')
            .t(': ohne Anmeldung oder abbestellt.', ': no sign-in, or unsubscribed.') : ''));
}

/**
 * Why the children overdue cannot be reminded at all: they have no login, or
 * have unsubscribed from these mails. One reason for the sheet on Geld before
 * the tap („Keine Erinnerung möglich: …“) and the banner after it („Keine
 * Erinnerung verschickt: …“), each with its own opening.
 */
function payment_reminders_unreachable(): string {
    return t('Diese Kinder melden sich nicht an oder haben Erinnerungen abbestellt.', 'these children don’t sign in, or have unsubscribed from reminders.');
}

/**
 * Queue one payment reminder for a login: every overdue charge of its child,
 * oldest first, in the family's language - amounts and dates too - and signed
 * by the club, not by whoever sent it. No bank details: „Beiträge" has each
 * charge's own account and QR code, and where money goes can change (ADR
 * 0025), which an IBAN in an old mail never does.
 *
 * Returns false when nothing was queued, so the caller can report how many
 * families will actually hear about it.
 */
function notify_payment(array $account, array $student, array $charges): bool {
    if (!$charges || !account_takes_mail($account, 'payments')) return false;
    [$subject, $body] = in_locale((string)$account['locale'], function () use ($account, $student, $charges): array {
        $several = count($charges) > 1;
        $lines = []; $labels = []; $total = 0;
        // The mail promises bank details only when „Beiträge" shows them for
        // every charge it lists (charge_bank_details()).
        $details = true;
        foreach ($charges as $charge) {
            $amount = (int)$charge['amount_cents'];
            $open = $amount - (int)$charge['paid'];
            $total += $open;
            if (!charge_bank_details($charge)) $details = false;
            // A label is one line in a form, but nothing stops a line break
            // sent by hand, and a subject must not carry one (queue_mail()).
            $labels[] = $label = preg_replace('/\s+/u', ' ', (string)$charge['label']);
            $lines[] = ($several ? '• ' : '').$label.': '
                .($open < $amount ? strtr(t('noch {open} von {amount}', '{open} of {amount} still to pay'), ['{open}' => money($open), '{amount}' => money($amount)]) : money($open))
                .strtr(t(', fällig seit {due}', ', due {due}'), ['{due}' => fmt_date((string)$charge['due_on'])]);
        }
        $subject = payment_reminder_subject_start()
            .($several ? plural(count($charges), 'Beitrag', 'Beiträge', 'charge', 'charges') : mb_substr($labels[0], 0, 200));
        $paying = !$details ? t('Alles Weitere findest du unter „Beiträge“:', 'You’ll find the details under “Payments”:')
            : ($several ? t('Bankverbindung und für jeden Beitrag einen QR-Code findest du unter „Beiträge“:', 'You’ll find the bank details, and a QR code for each charge, under “Payments”:')
                        : t('Bankverbindung und QR-Code für die Bank-App findest du unter „Beiträge“:', 'You’ll find the bank details and a QR code for your banking app under “Payments”:'));
        $body = mail_greeting($account)
            .($several ? plural(count($charges), 'Beitrag ist', 'Beiträge sind', 'charge is', 'charges are').t(' noch offen:', ' still outstanding:')
                       : t('ein Beitrag ist noch offen:', 'One charge is still outstanding:'))."\n\n"
            .implode("\n", $lines)."\n\n"
            .($several ? t('Zusammen: ', 'Total: ').money($total)."\n\n" : '')
            .$paying."\n".url('student', ['id' => $student['id'], 'tab' => 'payments'])."\n\n"
            .t('Schon überwiesen? Dann passt alles – danke!', 'Already paid? Then all is well – thank you!')."\n\n"
            .t('Viele Grüße', 'Best wishes,')."\n".setting('club_name');
        return [$subject, $body];
    });
    queue_mail((int)$account['id'], $account['email'], $subject, $body, 'payments');
    return true;
}
/**
 * Tell the families in a course that one date has changed.
 *
 * Sent to the account behind each current member, once, with the change spelled
 * out rather than a link saying something changed. A family reading this on a
 * phone at eight in the morning should not have to open anything.
 *
 * Deliberately a separate step from saving: correcting a typo in a note should
 * not send fifteen emails.
 */
function notify_class_change(array $class, string $date, ?array $entry, string $note): int {
    $sent=0;
    foreach(rows('SELECT DISTINCT a.* FROM class_students cs'
        .' JOIN students s ON s.id=cs.student_id JOIN accounts a ON a.id=s.account_id'
        .' WHERE cs.class_id=? AND '.current_enrolment_sql(), [(int)$class['id']]) as $account) {
        if(!account_takes_mail($account,'notifications')) continue;
        $en=$account['locale']==='en';
        $what=match($entry['status']??'planned') {
            'cancelled' => $en?'is cancelled':'entfällt',
            'extra'     => $en?'is an extra session':'ist ein Zusatztermin',
            'changed'   => $en?'has changed':'hat sich geändert',
            default     => $en?'is going ahead':'findet statt',
        };
        $body=mail_greeting($account)
            .$class['name'].' '.($en?'on ':'am ').fmt_date($date).' '.$what.".\n"
            .($entry?session_label($entry)."\n":'')
            .($note!==''?"\n".$note."\n":'')
            // The overview, where a family's dates are: the course's own page
            // is staff's, and a family is refused it.
            ."\n".($en?'All dates are in the portal:':'Alle Termine stehen im Portal:')."\n".url('dashboard');
        queue_mail((int)$account['id'],$account['email'],
            ($en?'Change to ':'Änderung: ').$class['name'].' – '.fmt_date($date),$body,'notifications');
        $sent++;
    }
    return $sent;
}

/** Tell one family what the trainer decided about their request. */
function notify_enrolment_decision(array $request, bool $approved, string $note): bool {
    $account=one('SELECT a.* FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.id=?', [(int)$request['student_id']]);
    if(!$account || !account_takes_mail($account,'notifications')) return false;
    $student=one('SELECT first_name,last_name FROM students WHERE id=?',[(int)$request['student_id']]);
    $class=one('SELECT name FROM classes WHERE id=?',[(int)$request['class_id']]);
    $en=$account['locale']==='en';
    $body=mail_greeting($account)
        .request_kind_label((string)$request['kind']).' – '.$student['first_name'].' '.$student['last_name']
        .' · '.$class['name'].":\n"
        .($approved?($en?'Approved.':'Angenommen.'):($en?'Not approved.':'Leider nicht angenommen.'))."\n"
        .($note!==''?"\n".$note."\n":'')
        ."\n".($en?'Details are in the portal:':'Die Einzelheiten stehen im Portal:')."\n".url('student',['id'=>$request['student_id'],'tab'=>'classes']);
    queue_mail((int)$account['id'],$account['email'],
        ($en?'Your request: ':'Deine Anfrage: ').$class['name'],$body,'notifications');
    return true;
}

/**
 * Why an invoice cannot be e-mailed to its family, in a sentence, or null when
 * it can. Asked before anything is queued, so she hears it on the page rather
 * than finding the mail cancelled in Postausgang - and so the invoice never says
 * „per E-Mail geschickt“ about a mail the queue was always going to drop.
 */
function invoice_mail_refusal(array $invoice): ?string {
    $account=$invoice['account_id']?one('SELECT * FROM accounts WHERE id=?',[(int)$invoice['account_id']]):null;
    if(!$account)
        return t('Für dieses Kind ist kein Konto hinterlegt, an das die Rechnung gehen könnte.','This child has no account for the invoice to go to.');
    if(!account_in_use($account))
        return t('Der Zugang dieses Kindes ist noch nicht eingerichtet oder gesperrt. Lade die Rechnung herunter und gib sie anders weiter.',
                 'This child’s login is not set up yet, or is suspended. Download the invoice and pass it on another way.');
    if((string)($account['email']??'')==='')
        return t('Dieses Kind meldet sich ohne E-Mail-Adresse an, also gibt es keine, an die die Rechnung gehen könnte. Lade sie herunter und gib sie anders weiter.',
                 'This child signs in without an email address, so there is none for the invoice to go to. Download it and pass it on another way.');
    if(!account_takes_mail($account,'payments'))
        return t('Diese Familie hat E-Mails zu Beiträgen abbestellt („Erinnerung, wenn ein Beitrag offen ist“ unter „Mein Konto“). Lade die Rechnung herunter und gib sie anders weiter.',
                 'This family has switched off emails about payments (“Remind me when a payment is outstanding” under “My account”). Download the invoice and pass it on another way.');
    return null;
}

/**
 * Send one invoice to the family, with the PDF attached. False, and nothing
 * queued, when invoice_mail_refusal() has a reason.
 *
 * The amount, the number and the due date are in the body as well, because an
 * attachment on a phone is a tap away and a parent reading this on the bus
 * should already know what it says.
 */
function notify_invoice(array $invoice): bool {
    if(invoice_mail_refusal($invoice)!==null) return false;
    $account=one('SELECT * FROM accounts WHERE id=?',[(int)$invoice['account_id']]);
    $en=$account['locale']==='en';
    $body=mail_greeting($account)
        .($en?'Invoice ':'Rechnung ').$invoice['number'].' '.($en?'over':'über').' '.money((int)$invoice['gross_cents'])
        .', '.($en?'payable by ':'zahlbar bis ').fmt_date((string)$invoice['due_on']).".\n\n"
        .($en?'The invoice is attached as a PDF and is also in the portal:':'Die Rechnung hängt als PDF an und steht auch im Portal:')."\n"
        .url('student',['id'=>$invoice['student_id'],'tab'=>'invoices']);
    queue_mail((int)$account['id'],$account['email'],
        ($en?'Invoice ':'Rechnung ').$invoice['number'],$body,'payments',
        [['kind'=>'invoice','id'=>(int)$invoice['id']]]);
    run('UPDATE invoices SET sent_at=? WHERE id=?',[now(),(int)$invoice['id']]);
    return true;
}

/**
 * One PHPMailer, configured from the stored settings.
 *
 * The queue and the connection test have to talk to the server the same way,
 * or a green test proves nothing about what the queue will do. One copy, so
 * they cannot drift apart.
 *
 * $debug, when given, receives the SMTP conversation line by line.
 */
function smtp_mailer(array $s, ?callable $debug=null): \PHPMailer\PHPMailer\PHPMailer {
    $m=new \PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP(); $m->Host=(string)($s['host']??''); $m->Port=(int)($s['port']??0);
    $m->SMTPAuth=($s['username']??'')!==''; $m->Username=(string)($s['username']??'');
    // unseal() rather than smtp_password(): a password this installation can no
    // longer decrypt must stop the send loudly, not quietly try an empty one.
    $m->Password=empty($s['password'])?'':unseal($s['password']);
    $m->SMTPSecure=($s['encryption']??'tls')==='tls'
        ?\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
        :\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $m->Timeout=15; $m->CharSet='UTF-8';
    // And fifteen seconds for each answer, which PHPMailer otherwise waits 300
    // for - twice that after the message itself: a server that took the
    // connection and then said nothing held one send, the background run it
    // was part of and the account row it locks, for five minutes and more.
    // Timeout above is the connection's. A send that runs out is a failed
    // attempt like any other: process_mail() writes it down, and tries again
    // later all but a security mail, whose link may have lapsed by then.
    // The cost, accepted because the bound matters more: RFC 5321 gives a
    // server ten minutes to answer the end of the message, so a slow provider
    // may have taken an ordinary mail that is then sent a second time (a
    // security mail never is); without the bound, one background run - which
    // no visitor waits for, since the session is let go first - holds the
    // queue and that row lock for many minutes a mail.
    $m->getSMTPInstance()->Timelimit=15;
    $m->SMTPDebug=$debug?\PHPMailer\PHPMailer\SMTP::DEBUG_SERVER:\PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
    if($debug) $m->Debugoutput=static function(string $line,int $level) use ($debug): void { $debug($line); };
    return $m;
}

/**
 * Take the credentials back out of a transcript.
 *
 * AUTH LOGIN sends the user name and the password as two lines of base64, which
 * is not encryption, it is spelling; AUTH PLAIN sends both on the command line.
 * The transcript is shown on a screen, stored in the settings table and copied
 * into support emails, so neither may survive in it. Only the lines of the AUTH
 * exchange are touched, so what is left - the greeting, the capabilities, the
 * refusal and its code - still says what went wrong.
 */
function smtp_redact(string $transcript, array $s): string {
    $out=[]; $inAuth=false;
    foreach(preg_split('/\R/',$transcript) ?: [] as $line) {
        $text=strip_smtp_prefix($line);
        if(preg_match('/^AUTH\s+(\S+)(\s.*)?$/i',$text,$m)) {
            $inAuth=true;
            if(($m[2]??'')!=='') $line=str_replace($m[2],' [entfernt]',$line);
        } elseif($inAuth) {
            if(preg_match('#^[A-Za-z0-9+/]{4,}={0,2}$#D',$text)) $line=str_replace($text,'[entfernt]',$line);
            elseif(!preg_match('/^334\b/',$text)) $inAuth=false;
        }
        // A backstop for a mechanism the rule above does not know. The password
        // only: the user name is worth reading back - it is half of what she is
        // checking - and the AUTH rule has already taken it out of the exchange
        // itself, which is the only place it is sent.
        $secret=smtp_password($s);
        if($secret!=='') $line=str_replace([$secret,base64_encode($secret)],'[entfernt]',$line);
        $out[]=$line;
    }
    return implode("\n",$out);
}

/**
 * The stored password, for redaction only.
 *
 * Never throws: a transcript that cannot be cleaned because the key changed
 * must still be a transcript that is safe to show, and the AUTH rule above has
 * already removed the exchange.
 */
function smtp_password(array $s): string {
    if(empty($s['password'])) return '';
    try { return unseal($s['password']); } catch(Throwable) { return ''; }
}

/** A debug line without PHPMailer's "SERVER -> CLIENT:" prefix. */
function strip_smtp_prefix(string $line): string {
    return trim((string)preg_replace('/^(SERVER -> CLIENT|CLIENT -> SERVER|SMTP[^:]*)\s*:\s*/i','',trim($line)));
}

/**
 * What a mail library's failure means, in a sentence the operator can act on.
 *
 * PHPMailer's own wording is English, and accurate about the protocol rather
 * than about what to change: "Could not authenticate" is a password, "SSL
 * operation failed" is usually the wrong choice between STARTTLS and TLS/SSL.
 * The original text stays in the transcript, so nothing is hidden from whoever
 * she forwards it to.
 */
function smtp_explain(string $raw): string {
    $seen=static fn(string ...$needles)=>array_filter($needles,fn($n)=>stripos($raw,$n)!==false)!==[];
    if($seen('could not authenticate','535','authentication failed','auth failed'))
        return t('Benutzername oder Passwort hat der Server nicht angenommen.','The server did not accept the user name or the password.');
    if($seen('certificate verify failed','ssl operation failed','certificate has expired','self-signed','self signed'))
        return t('Die verschlüsselte Verbindung kam nicht zustande. Meist passt die Einstellung „Verschlüsselung“ nicht zum Port – STARTTLS gehört zu 587, TLS/SSL zu 465.','The encrypted connection could not be set up. Usually the “Encryption” setting does not match the port – STARTTLS goes with 587, TLS/SSL with 465.');
    if($seen('could not connect to smtp host','smtp connect() failed'))
        return t('Der Server hat nicht geantwortet. Servername, Port und Verschlüsselung prüfen.','The server did not answer. Check the server name, the port and the encryption.');
    if($seen('relay','not permitted','sender address rejected','550','553'))
        return t('Der Server hat die Absender- oder Empfängeradresse abgelehnt. Die Absenderadresse muss zu diesem Postfach gehören.','The server refused the sender or the recipient address. The sender address has to belong to this mailbox.');
    if($seen('data not accepted'))
        return t('Der Server hat die Nachricht selbst abgelehnt.','The server refused the message itself.');
    return $raw;
}

/**
 * Try the SMTP server now and say what happened, step by step.
 *
 * Queueing a message and telling the operator to go and look in the outbox was
 * not a test: a wrong port, a blocked outgoing connection and a rejected
 * password all looked the same from there - nothing arrived. This opens the
 * connection while she waits and writes down each step, because "which step
 * failed" is the whole answer.
 *
 * $recipient, when given, also sends one real message to that address.
 *
 * Returns ['ok'=>bool,'summary'=>string,'transcript'=>string,'sent_to'=>string,
 *          'at'=>string], with the credentials taken back out of the transcript.
 */
function smtp_check(?string $recipient=null): array {
    $s=setting('smtp',[]);
    $lines=[]; $ok=false; $summary='';
    $note=static function(string $step,string $detail='') use (&$lines): void {
        $lines[]=$detail===''?$step:$step.': '.$detail;
    };
    $host=(string)($s['host']??''); $port=(int)($s['port']??0);
    $encryption=($s['encryption']??'tls')==='tls'?'STARTTLS':'TLS/SSL';
    $note(t('Einstellungen','Settings'),$host===''?t('kein Server eingetragen','no server configured'):$host.':'.$port.' ('.$encryption.')');
    $note(t('Absender','Sender'),(string)($s['from_email']??'')?:t('keine Absenderadresse eingetragen','no sender address configured'));
    $note(t('Anmeldung','Authentication'),($s['username']??'')!==''
        ?t('als ','as ').$s['username'].(empty($s['password'])?' '.t('(ohne gespeichertes Passwort)','(no password saved)'):'')
        :t('ohne Benutzernamen','without a user name'));
    try {
        if($missing=mail_library_missing()) throw new UserError($missing);
        if($host===''||$port<1||empty($s['from_email']))
            throw new UserError(t('Server, Port und Absenderadresse müssen zuerst gespeichert werden.','Save the server, the port and the sender address first.'));
        if(!extension_loaded('openssl'))
            throw new UserError(t('Die PHP-Erweiterung openssl fehlt, ohne sie ist keine verschlüsselte Verbindung möglich.','The PHP extension openssl is missing; without it there is no encrypted connection.'));
        // Tried before PHPMailer, because a hosting package with outgoing mail
        // ports closed is the commonest reason for this to fail, and a plain
        // "connection refused" says so where a mail library says "SMTP connect()
        // failed" and sends the operator looking at her password.
        $started=microtime(true);
        $socket=@stream_socket_client(($encryption==='TLS/SSL'?'ssl://':'tcp://').$host.':'.$port,$errno,$errstr,10);
        if(!$socket) throw new UserError(t('Keine Verbindung zu ','Could not reach ').$host.':'.$port.' – '.($errstr?:t('Zeitüberschreitung','timed out'))
            .'. '.t('Meist ist der Port beim Hoster gesperrt oder falsch eingetragen.','Usually the port is blocked by the host or entered wrongly.'));
        fclose($socket);
        $note(t('Verbindung','Connection'),t('offen nach ','open after ').round((microtime(true)-$started)*1000).' ms');

        $m=smtp_mailer($s,static function(string $line) use (&$lines): void { $lines[]=rtrim($line); });
        if(!$m->smtpConnect()) throw new UserError(t('Der Server hat die Verbindung nicht angenommen.','The server did not accept the connection.'));
        $note(t('SMTP','SMTP'),t('Verbindung und Anmeldung erfolgreich','connected and authenticated'));
        $m->smtpClose();
        $ok=true;
        $summary=t('Verbindung und Anmeldung haben funktioniert.','The connection and the sign-in worked.');

        if($recipient!==null) {
            $note('');
            $note(t('Testmail an ','Test email to ').$recipient);
            $m=smtp_mailer($s,static function(string $line) use (&$lines): void { $lines[]=rtrim($line); });
            $m->setFrom($s['from_email'],(string)($s['from_name']??''));
            $m->addAddress($recipient);
            $m->Subject=t('Test aus dem Badminton-Portal','Test from the badminton portal');
            $m->isHTML(false);
            $m->Body=t("Wenn du das liest, verschickt das Portal E-Mails.\n\nGesendet am ","If you are reading this, the portal can send email.\n\nSent on ")
                .fmt_datetime(now())." \u{2013} ".(string)setting('club_name','Badminton');
            $m->send();
            $note(t('Testmail','Test email'),t('angenommen für ','accepted for ').$recipient);
            $summary=t('Testmail an ','Test email sent to ').$recipient.t(' verschickt.','.');
        }
    } catch(Throwable $ex) {
        $ok=false;
        $raw=mb_substr($ex->getMessage(),0,500);
        $summary=$ex instanceof UserError?$raw:smtp_explain($raw);
        $note(t('Fehlgeschlagen','Failed'),$summary);
        if($summary!==$raw) $note(t('Meldung des Mailservers','What the mail library said'),$raw);
    }
    return ['ok'=>$ok,'summary'=>$summary,'transcript'=>smtp_redact(implode("\n",$lines),$s),
            'sent_to'=>$ok&&$recipient!==null?$recipient:'','at'=>now()];
}

/**
 * Whether mail has been shown to work: a server is saved and the last
 * connection test against it succeeded.
 *
 * The one answer to "are emails going out?" - the start checklist ticks
 * „E-Mails verschicken“ by it and the SMTP tab shows it as green (ADR 0011).
 * A test is only about the settings it ran with, which is why smtp_save
 * forgets it when they change: a green result for yesterday's server says
 * nothing about today's.
 */
function smtp_tested_ok(): bool {
    return (bool)setting('smtp', []) && !empty(setting('smtp_last_test', [])['ok']);
}

/**
 * Whether two saved SMTP configurations would talk to a server differently.
 *
 * The password is compared as she typed it, not as stored: sealing it again
 * gives new bytes for the same password, and re-saving the form without
 * touching it must not throw away a test that still holds.
 */
function smtp_settings_changed(array $old, array $new): bool {
    $plain = static fn(array $s): array => ['password' => smtp_password($s)] + $s;
    return $plain($old) != $plain($new);
}

/**
 * Why mail cannot be sent from this copy of the portal at all, or null. The
 * library comes in the release ZIP's vendor/ folder; she has no shell, so the
 * answer is to upload it, never a command to run.
 */
function mail_library_missing(): ?string {
    if(class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) return null;
    return t('Das Programm zum Versenden (PHPMailer) fehlt: Der Ordner vendor/ ist nicht auf dem Server. Er ist im Release-ZIP enthalten – lade den ganzen Inhalt des ZIP noch einmal hoch.',
             'The program that sends email (PHPMailer) is missing: the vendor/ folder is not on the server. It is in the release ZIP – upload the whole content of the ZIP again.');
}

function process_mail(int $limit=25, float $budget=0.0): array {
    if(is_file(maintenance_file()))throw new UserError(t('Im Wartungsmodus werden keine E-Mails verschickt. Sobald er aus ist, geht der Versand weiter.','No email is sent while maintenance mode is on. Sending carries on once it is off.'));
    if($missing=mail_library_missing()) throw new UserError($missing);
    // An advisory database lock works across cron processes and hosts.
    if((int)scalar("SELECT GET_LOCK('badminton_crm_mail',0)")!==1) return ['sent'=>0,'failed'=>0,'skipped'=>0,'deferred'=>0];
    $count=['sent'=>0,'failed'=>0,'skipped'=>0,'deferred'=>0];
    try {
        $s=setting('smtp',[]);
        if(empty($s['host']) || empty($s['from_email'])) throw new UserError(t('SMTP ist noch nicht eingerichtet.','SMTP is not configured yet.'));
        // Security mail is deliberately excluded from automatic retries: the token in the
        // body may already have expired, so the user requests a fresh link instead.
        run("UPDATE mail_jobs SET status='queued' WHERE status='failed' AND category<>'security' AND attempts<".MAIL_MAX_ATTEMPTS." AND retry_after IS NOT NULL AND retry_after<=?",[now()]);
        $deadline=$budget>0?microtime(true)+$budget:0.0;
        foreach(rows("SELECT id FROM mail_jobs WHERE status='queued' ORDER BY id LIMIT ".max(1,min(100,$limit))) as $r) {
            // One SMTP conversation can take the full 15s timeout. Without a budget a
            // web-triggered run of 25 messages outlives max_execution_time and is killed
            // mid-loop; each job commits on its own, so stopping early is safe.
            if($deadline>0.0 && microtime(true)>=$deadline) {$count['deferred']++;continue;}
            db()->beginTransaction();
            try {
                $job=one('SELECT * FROM mail_jobs WHERE id=? FOR UPDATE',[$r['id']]);
                if(!$job || $job['status']!=='queued') {db()->commit();continue;}
                // Hold the account lock during send: suspension/deletion cannot race this check.
                $a=$job['account_id']?one('SELECT * FROM accounts WHERE id=? FOR UPDATE',[$job['account_id']]):null;
                $eligible=$a && $a['state']!=='suspended';
                $stored=$job['payload']!==''?mail_payload(unseal($job['payload'])):['body'=>'','attach'=>[],'notice'=>false];
                $plainBody=$stored['body'];
                // A notice has no link to check, and goes to the address it
                // names, which for a moved login is the old one (notify_sign_in_changed()).
                if($eligible && $job['category']==='security') $eligible=$stored['notice'] || security_mail_links_live($plainBody,(string)$job['recipient']);
                if($eligible && $job['category']!=='security') $eligible=account_takes_mail($a,(string)$job['category']) && same_address((string)$a['email'],(string)$job['recipient']);
                if(!$eligible) {run("UPDATE mail_jobs SET status='cancelled',payload='',retry_after=NULL WHERE id=?",[$job['id']]);db()->commit();$count['skipped']++;continue;}
                $m=smtp_mailer($s);
                $m->setFrom($s['from_email'],$s['from_name']); $m->addAddress($job['recipient']);
                $m->Subject=$job['subject'];
                $body=$plainBody; $en=$a['locale']==='en';
                $body.="\n\n".($en?'This mailbox is not monitored. Please reply inside the app.':'Dieses Postfach wird nicht gelesen. Bitte antworte in der App.');
                if(in_array($job['category'],['newsletter','notifications','payments'],true)) {
                    $link=url('unsubscribe',['account'=>$a['id'],'category'=>$job['category'],'signature'=>unsubscribe_signature((int)$a['id'],$job['category'])]);
                    $body.="\n\n".($en?'Unsubscribe: ':'Abmelden: ').$link;
                    $m->addCustomHeader('List-Unsubscribe','<'.$link.'>');
                }
                foreach($stored['attach'] as $described) {
                    $file=mail_attachment(is_array($described)?$described:[]);
                    if($file) $m->addStringAttachment($file['body'],$file['name'],'base64',$file['mime']);
                }
                $m->Body=$body; $m->isHTML(false); $m->send();
                run("UPDATE mail_jobs SET status='sent',sent_at=?,attempts=attempts+1,error=NULL,retry_after=NULL,payload=IF(category='security','',payload) WHERE id=?",[now(),$job['id']]);
                db()->commit(); $count['sent']++;
            } catch(Throwable $ex) {
                if(db()->inTransaction()) db()->rollBack();
                $message=mb_substr($ex->getMessage(),0,1000);
                if(!empty($s['password'])) { try { $message=str_replace(unseal($s['password']),'[redacted]',$message); } catch(Throwable) { $message='[redacted]'; } }
                // The transaction is already rolled back here, so this runs in autocommit.
                $attempts=(int)scalar('SELECT attempts FROM mail_jobs WHERE id=?',[$r['id']])+1;
                $retry=$attempts<MAIL_MAX_ATTEMPTS?gmdate('Y-m-d H:i:s',time()+MAIL_BACKOFF[min($attempts,count(MAIL_BACKOFF))-1]):null;
                run("UPDATE mail_jobs SET status='failed',attempts=?,error=?,retry_after=? WHERE id=?",[$attempts,$message,$retry,$r['id']]);
                $count['failed']++;
            }
        }
        set_setting('mail_last_run',now());
    } finally { run("SELECT RELEASE_LOCK('badminton_crm_mail')"); }
    return $count;
}
