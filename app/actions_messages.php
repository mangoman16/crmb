<?php
declare(strict_types=1);

// Read state is per account: staff see every thread, so a shared marker on the thread
// itself would make one manager's reading hide a reply from another.
function mark_thread_read(int $threadId, int $accountId): void {
    run('INSERT INTO thread_reads (thread_id,account_id,last_read_message_id,updated_at) VALUES (?,?,COALESCE((SELECT MAX(id) FROM messages WHERE thread_id=?),0),?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id)),updated_at=VALUES(updated_at)',[$threadId,$accountId,$threadId,now()]);
}
// A thread counts as unread when its newest message was written by someone else and is
// newer than this account's marker. Own replies never mark a thread unread.
function unread_thread_ids(array $user): array {
    // The same rule as may_read_thread(), in SQL: staff see every staff thread
    // plus the direct ones they are in, and everybody else sees the threads they
    // are a participant of. Two rules for one question is how a private
    // conversation ends up in somebody's unread count.
    $staff=is_staff($user);
    $visible=$staff
        ? "(t.kind='staff' OR EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?))"
        : 'EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?)';
    return array_column(rows(
        'SELECT t.id FROM threads t JOIN messages m ON m.id=(SELECT MAX(id) FROM messages WHERE thread_id=t.id)'
        .' LEFT JOIN thread_reads r ON r.thread_id=t.id AND r.account_id=?'
        .' WHERE m.sender_id<>? AND m.id>COALESCE(r.last_read_message_id,0) AND '.$visible,
        [$user['id'],$user['id'],$user['id']]
    ),'id');
}
function unread_count(array $user): int { return count(unread_thread_ids($user)); }
function dispatch_messages(string $action): array {
    switch($action) {
    case 'message_send':
        $u=require_user();throttle('message',(string)$u['id'],40,300);$id=(int)post('thread_id');
        if($id) $thread=thread_record($id);
        elseif(post('to')!=='') {
            // A conversation with one other person, made once and reused, so a
            // messenger does not fill up with one-message threads.
            $id=direct_thread($u,(int)post('to'));
            $thread=thread_record($id);
        } else {
            // The staff conversation: a family writing in, or staff starting one
            // with a family. The family owns it and every member of staff reads it.
            $accountId=is_staff($u)?(int)post('account_id'):(int)$u['id'];
            $a=one("SELECT * FROM accounts WHERE id=? AND role='student' AND state='active'",[$accountId]);
            if(!$a)throw new UserError(t('Kein aktives Schülerkonto.','No active student account.'));
            run("INSERT INTO threads (account_id,kind,subject,updated_at) VALUES (?,'staff',?,?)",[$accountId,required_text('subject',180),now()]);
            $id=(int)db()->lastInsertId();
            join_thread($id,$accountId);
            $thread=thread_record($id);
        }
        // A voice note or a photo is a message on its own; only a bubble with
        // neither text nor a file is nothing to send.
        $body=text_limit('body',20000);
        $hasFile=isset($_FILES['attachment']) && (int)($_FILES['attachment']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
        if($body==='' && !$hasFile) throw new UserError(t('Bitte etwas schreiben oder etwas anhängen.','Write something, or attach something.'));
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',[$id,$u['id'],$body,now()]);
        $messageId=(int)db()->lastInsertId();
        attach_to_message($messageId);
        run('UPDATE threads SET updated_at=? WHERE id=?',[now(),$id]);
        join_thread($id,(int)$u['id']);

        // Whoever did not write it gets told, by email if they want one and in
        // the portal either way. The staff list said role IN ('admin','manager'):
        // 'manager' is the name trainers had before 0.2, so a message to the
        // trainer reached the administrator and nobody else.
        $summary=$body!==''?mb_substr($body,0,120):t('Anhang','An attachment');
        if($thread['kind']==='direct') {
            foreach(thread_people($id) as $person) {
                if((int)$person['id']===(int)$u['id']) continue;
                $account=one('SELECT * FROM accounts WHERE id=?',[(int)$person['id']]);
                notify_thread($account,$id,thread_title($thread,$account));
                notify((int)$person['id'],'message',t('Neue Nachricht von ','New message from ').$u['name'],$summary,'messages',['id'=>$id]);
            }
        } elseif(is_staff($u)) {
            $recipient=one('SELECT * FROM accounts WHERE id=?',[$thread['account_id']]);
            notify_thread($recipient,$id,$thread['subject']);
            notify((int)$recipient['id'],'message',t('Neue Nachricht','New message'),$summary,'messages',['id'=>$id]);
        } else {
            foreach(rows("SELECT * FROM accounts WHERE role IN ('admin','trainer','manager') AND state='active'") as $a) {
                notify_thread($a,$id,$thread['subject']);
                notify((int)$a['id'],'message',t('Neue Nachricht von ','New message from ').$u['name'],$summary,'messages',['id'=>$id]);
            }
        }
        mark_thread_read($id,(int)$u['id']);
        audit('message.sent','thread',$id);return ['messages',['id'=>$id]];

    case 'contact_request':
        $u=require_user();throttle('contact',(string)$u['id'],20,3600);
        $to=(int)post('to');
        $state=request_contact($u,$to,text_limit('message',300));
        if($state==='accepted') {
            notify($to,'message',t('Ihr könnt euch jetzt schreiben','You can write to each other now'),$u['name'],'messages',[]);
            flash(t('Ihr könnt euch jetzt schreiben.','You can write to each other now.'));
        } else {
            notify($to,'message',t('Jemand möchte dir schreiben','Somebody would like to write to you'),$u['name'],'messages',['contacts'=>1]);
            flash(t('Anfrage geschickt. Sobald zugestimmt wird, könnt ihr schreiben.','Request sent. Once they agree, you can write to each other.'));
        }
        return ['messages',['contacts'=>1]];

    case 'contact_decide':
        $u=require_user();
        $accept=post('decision')==='accept';
        $r=decide_contact((int)post('id'),$accept);
        notify((int)$r['from_account_id'],'message',
            $accept?t('Deine Anfrage wurde angenommen','Your request was accepted'):t('Deine Anfrage wurde abgelehnt','Your request was declined'),
            $u['name'],'messages',[]);
        flash($accept?t('Ihr könnt euch jetzt schreiben.','You can write to each other now.'):t('Abgelehnt.','Declined.'));
        return ['messages',['contacts'=>1]];

    case 'bulk_preview':
        require_staff();$ids=$_POST['student_ids']??[];
        if(!is_array($ids) || !$ids || count($ids)>1000)throw new UserError(t('Bitte 1 bis 1.000 Schüler auswählen.','Select 1 to 1,000 students.'));
        $selected=[];$accounts=[];$subject=required_text('subject',180);$body=required_text('body',20000);
        foreach(array_unique(array_map('intval',$ids)) as $id) {
            $s=student($id);$a=$s['account_id']?one("SELECT * FROM accounts WHERE id=? AND state='active' AND verified_at IS NOT NULL",[$s['account_id']]):null;
            if(!$a)continue;
            $selected[]=$id;$accounts[(int)$a['id']]=$a['name'];
        }
        if(!$selected)throw new UserError(t('Die Auswahl hat keine aktiven, bestätigten Konten.','The selection has no active, verified accounts.'));
        $_SESSION['bulk_preview']=['student_ids'=>$selected,'subject'=>$subject,'body'=>$body,'email'=>(bool)post('send_email'),'accounts'=>$accounts,'created'=>time()];
        return ['compose',['review'=>1]];
    case 'bulk_send':
        $u=require_staff();$p=$_SESSION['bulk_preview']??null;
        if(!$p || time()-$p['created']>1800)throw new UserError(t('Die Vorschau ist abgelaufen. Bitte erneut erstellen.','The preview expired. Please create it again.'));
        $grouped=[];
        foreach($p['student_ids'] as $id) {
            $s=student($id);if($s['account_id'] && isset($p['accounts'][(int)$s['account_id']]))$grouped[(int)$s['account_id']][]=$s;
        }
        $count=0;
        foreach($grouped as $accountId=>$students) {
            $a=one("SELECT * FROM accounts WHERE id=? AND state='active' AND verified_at IS NOT NULL FOR UPDATE",[$accountId]);if(!$a)continue;
            $subject=mb_substr(template_text($p['subject'],$students[0]),0,180);
            $specific=preg_match('/\{\{(student_name|first_name|tariff|outstanding|paid_through)\}\}/',$p['body']);
            $body=$specific?implode("\n\n────────\n\n",array_map(fn($s)=>template_text($p['body'],$s),$students)):template_text($p['body'],$students[0]);
            if(strlen($body)>60000)throw new UserError(t('Nachricht für ein Konto zu lang. Bitte Auswahl verkleinern.','Message is too long for one account. Reduce the selection.'));
            run("INSERT INTO threads (account_id,kind,subject,updated_at) VALUES (?,'staff',?,?)",[$accountId,$subject,now()]);$threadId=(int)db()->lastInsertId();
            join_thread($threadId,$accountId);
            run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',[$threadId,$u['id'],$body,now()]);
            if($p['email'])notify_thread($a,$threadId,$subject);
            $count++;
        }
        unset($_SESSION['bulk_preview']);audit('message.bulk_sent','thread');flash($count.' '.t('Unterhaltungen erstellt. E-Mails berücksichtigen die Benachrichtigungseinstellungen.','conversations created. Emails respect notification preferences.'));return ['messages',[]];
    case 'news_save':
        require_staff();$id=(int)post('id');$title=required_text('title',180);$body=required_text('body',20000);$published=post('published')?1:0;
        if($id){if(!one('SELECT id FROM news WHERE id=?',[$id]))throw new UserError('Not found');run('UPDATE news SET title=?,body=?,published=?,updated_at=? WHERE id=?',[$title,$body,$published,now(),$id]);}
        else {run('INSERT INTO news (title,body,published,created_at,updated_at) VALUES (?,?,?,?,?)',[$title,$body,$published,now(),now()]);$id=(int)db()->lastInsertId();}
        if(post('send_email')) {
            if(!$published)throw new UserError(t('Bitte die Nachricht zuerst veröffentlichen.','Publish the news before emailing it.'));
            foreach(rows("SELECT * FROM accounts WHERE state='active' AND verified_at IS NOT NULL AND newsletter=1 AND role='student'") as $a)queue_mail((int)$a['id'],$a['email'],$title,$body."\n\n".url('news',['id'=>$id]),'newsletter');
        }
        audit('news.saved','news',$id);flash(t('Neuigkeit gespeichert.','News saved.'));return ['news',[]];
    default: throw new UserError(t('Unbekannte Aktion.','Unknown action.'));
    }
}
