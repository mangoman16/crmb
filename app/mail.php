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
 */
function queue_mail(?int $accountId,string $recipient,string $subject,string $body,string $category,array $attach=[]): void {
    email_value($recipient);
    if(preg_match('/[\r\n]/',$subject) || mb_strlen($subject)>255) throw new UserError(t('Ungültiger Betreff.','Invalid subject.'));
    $payload=$attach
        ? MAIL_STRUCTURED.json_encode(['body'=>$body,'attach'=>$attach],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
        : $body;
    run('INSERT INTO mail_jobs (account_id,recipient,subject,payload,category,created_at) VALUES (?,?,?,?,?,?)',[$accountId,$recipient,$subject,seal($payload),$category,now()]);
}

/** A stored payload, as message text and the files to build. */
function mail_payload(string $stored): array {
    if(!str_starts_with($stored,MAIL_STRUCTURED)) return ['body'=>$stored,'attach'=>[]];
    $decoded=json_decode(substr($stored,strlen(MAIL_STRUCTURED)),true);
    if(!is_array($decoded)) return ['body'=>$stored,'attach'=>[]];
    return ['body'=>(string)($decoded['body']??''),'attach'=>is_array($decoded['attach']??null)?$decoded['attach']:[]];
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
function cancel_account_mail(int $id): void { run("UPDATE mail_jobs SET status='cancelled',payload='',error=NULL WHERE account_id=? AND status IN ('queued','failed')",[$id]); }
function notify_thread(array $account,int $threadId,string $subject): void {
    if($account['state']!=='active' || !$account['verified_at'] || !$account['notifications']) return;
    $en=$account['locale']==='en';
    queue_mail((int)$account['id'],$account['email'],$en?'New message in your badminton portal':'Neue Nachricht im Badminton-Portal',($en?'A new message is waiting for you. Open your conversation:':'Du hast eine neue Nachricht. Öffne deine Unterhaltung:')."\n".url('messages',['id'=>$threadId]),'notifications');
}
const MAIL_MAX_ATTEMPTS = 5;
// Backoff per attempt number, in seconds: ~1min, 5min, 15min, 1h.
const MAIL_BACKOFF = [60, 300, 900, 3600];
/**
 * Queue a payment reminder for the account that manages a student.
 *
 * Returns false when nothing was queued, so the caller can report how many
 * parents will actually hear about it rather than implying every selected
 * student produced an email.
 */
function notify_payment(array $account, array $student, int $amountCents, string $dueOn): bool {
    if($account['state']!=='active' || !$account['verified_at'] || empty($account['payment_notices'])) return false;
    $en=$account['locale']==='en';
    $name=$student['first_name'].' '.$student['last_name'];
    $body=($en?'Hello ':'Hallo ').$account['name'].",\n\n"
        .($en?'There is an outstanding amount for ':'Für ').$name
        .($en?' of ':' ist noch ein Betrag von ').money($amountCents)
        .($en?', due ':' offen, fällig am ').fmt_date($dueOn).".\n\n"
        .($en?'You can see the amount and the transfer details, including a QR code for your banking app, in the portal:'
             :'Betrag und Bankverbindung samt QR-Code für die Bank-App findest du im Portal:')."\n"
        .url('student',['id'=>$student['id'],'tab'=>'payments']);
    queue_mail((int)$account['id'],$account['email'],
        $en?'Outstanding badminton payment':'Offener Badminton-Beitrag',$body,'payments');
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
        .' WHERE cs.class_id=? AND cs.left_on IS NULL', [(int)$class['id']]) as $account) {
        if($account['state']!=='active' || !$account['verified_at'] || empty($account['notifications'])) continue;
        $en=$account['locale']==='en';
        $what=match($entry['status']??'planned') {
            'cancelled' => $en?'is cancelled':'entfällt',
            'extra'     => $en?'is an extra session':'ist ein Zusatztermin',
            'changed'   => $en?'has changed':'hat sich geändert',
            default     => $en?'is going ahead':'findet statt',
        };
        $body=($en?'Hello ':'Hallo ').$account['name'].",\n\n"
            .$class['name'].' '.($en?'on ':'am ').fmt_date($date).' '.$what.".\n"
            .($entry?session_label($entry)."\n":'')
            .($note!==''?"\n".$note."\n":'')
            ."\n".($en?'All dates are in the portal:':'Alle Termine stehen im Portal:')."\n".url('classes',['id'=>$class['id']]);
        queue_mail((int)$account['id'],$account['email'],
            ($en?'Change to ':'Änderung: ').$class['name'].' – '.fmt_date($date),$body,'notifications');
        $sent++;
    }
    return $sent;
}

/** Tell one family what the trainer decided about their request. */
function notify_enrolment_decision(array $request, bool $approved, string $note): bool {
    $account=one('SELECT a.* FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.id=?', [(int)$request['student_id']]);
    if(!$account || $account['state']!=='active' || !$account['verified_at'] || empty($account['notifications'])) return false;
    $student=one('SELECT first_name,last_name FROM students WHERE id=?',[(int)$request['student_id']]);
    $class=one('SELECT name FROM classes WHERE id=?',[(int)$request['class_id']]);
    $en=$account['locale']==='en';
    $body=($en?'Hello ':'Hallo ').$account['name'].",\n\n"
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
 * Send one invoice to the family, with the PDF attached.
 *
 * The amount, the number and the due date are in the body as well, because an
 * attachment on a phone is a tap away and a parent reading this on the bus
 * should already know what it says.
 */
function notify_invoice(array $invoice): bool {
    $account=$invoice['account_id']?one('SELECT * FROM accounts WHERE id=?',[(int)$invoice['account_id']]):null;
    if(!$account || $account['state']!=='active' || !$account['verified_at']) return false;
    $en=$account['locale']==='en';
    $body=($en?'Hello ':'Hallo ').$account['name'].",\n\n"
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
        if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class))
            throw new UserError(t('PHPMailer fehlt. Der Ordner vendor/ wurde nicht mit hochgeladen.','PHPMailer is missing. The vendor/ folder was not uploaded.'));
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

function process_mail(int $limit=25, float $budget=0.0): array {
    if(is_file(maintenance_file()))throw new UserError('Maintenance mode is active.');
    if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new UserError('PHPMailer fehlt. composer install ausführen.');
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
                $stored=$job['payload']!==''?mail_payload(unseal($job['payload'])):['body'=>'','attach'=>[]];
                $plainBody=$stored['body'];
                if($eligible && $job['category']==='security') {
                    preg_match('/[?&]token=([a-f0-9]{64})\b/',$plainBody,$match);
                    $token=isset($match[1])?token_record(hash('sha256',$match[1])):null;
                    $eligible=$token && (int)$token['account_id']===(int)$a['id'] && ($token['target_email']??$a['email'])===$job['recipient'];
                }
                if($eligible && $job['category']!=='security') $eligible=$a['state']==='active' && $a['verified_at'] && $a['email']===$job['recipient'];
                $switch=['newsletter'=>'newsletter','notifications'=>'notifications','payments'=>'payment_notices'][$job['category']]??null;
                if($eligible && $switch!==null) $eligible=(bool)($a[$switch]??1);
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
