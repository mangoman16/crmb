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
    $staff=is_staff($user);
    return array_column(rows(
        'SELECT t.id FROM threads t JOIN messages m ON m.id=(SELECT MAX(id) FROM messages WHERE thread_id=t.id)'
        .' LEFT JOIN thread_reads r ON r.thread_id=t.id AND r.account_id=?'
        .' WHERE m.sender_id<>? AND m.id>COALESCE(r.last_read_message_id,0)'
        .($staff?'':' AND t.account_id=?'),
        $staff?[$user['id'],$user['id']]:[$user['id'],$user['id'],$user['id']]
    ),'id');
}
function unread_count(array $user): int { return count(unread_thread_ids($user)); }
function dispatch_messages(string $action): array {
    switch($action) {
    case 'message_send':
        $u=require_user();throttle('message',(string)$u['id'],40,300);$id=(int)post('thread_id');
        if($id) $thread=thread_record($id);
        else {
            $accountId=is_staff($u)?(int)post('account_id'):(int)$u['id'];
            $a=one("SELECT * FROM accounts WHERE id=? AND role='student' AND state='active'",[$accountId]);if(!$a)throw new UserError(t('Kein aktives Schülerkonto.','No active student account.'));
            run('INSERT INTO threads (account_id,subject,updated_at) VALUES (?,?,?)',[$accountId,required_text('subject',180),now()]);$id=(int)db()->lastInsertId();$thread=thread_record($id);
        }
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',[$id,$u['id'],required_text('body',20000),now()]);run('UPDATE threads SET updated_at=? WHERE id=?',[now(),$id]);
        // Whoever did not write it gets told, by email if they want one and in the
        // portal either way. The staff list said role IN ('admin','manager'):
        // 'manager' is the name trainers had before 0.2, so a message to the
        // trainer reached the administrator and nobody else.
        if(is_staff($u)) {
            $recipient=one('SELECT * FROM accounts WHERE id=?',[$thread['account_id']]);
            notify_thread($recipient,$id,$thread['subject']);
            notify((int)$recipient['id'],'message',t('Neue Nachricht','New message'),$thread['subject'],'messages',['id'=>$id]);
        } else {
            foreach(rows("SELECT * FROM accounts WHERE role IN ('admin','trainer','manager') AND state='active'") as $a) {
                notify_thread($a,$id,$thread['subject']);
                notify((int)$a['id'],'message',t('Neue Nachricht von ','New message from ').$u['name'],$thread['subject'],'messages',['id'=>$id]);
            }
        }
        mark_thread_read($id,(int)$u['id']);
        audit('message.sent','thread',$id);return ['messages',['id'=>$id]];
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
            run('INSERT INTO threads (account_id,subject,updated_at) VALUES (?,?,?)',[$accountId,$subject,now()]);$threadId=(int)db()->lastInsertId();
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
