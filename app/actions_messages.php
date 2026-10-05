<?php
declare(strict_types=1);

function dispatch_messages(string $action): array {
    // Everything handled here speaks in the name of whoever is signed in: a
    // message, a request to write or its answer, the circular into each child's
    // chat, a group message taken down or put back, the news. None of it happens
    // while looking through somebody else's eyes - an administrator may view the
    // portal as a trainer, and would otherwise write into children's chats as
    // her. So the whole dispatcher refuses rather than a list of its actions,
    // which the next action added here would not be on (security review,
    // ADR 0022 §9).
    if(impersonator())
        throw new UserError(t('Schreiben kann nur die Person selbst. Beende zuerst die Ansicht.','Only the person themselves can write. Stop viewing first.'));
    switch($action) {
    case 'message_send':
        $u=require_user();throttle('message',(string)$u['id'],40,300);$id=(int)post('thread_id');
        // Into a conversation they may write in, or the first message to
        // somebody, which makes the chat with them. There is no third way: the
        // shared desk thread is closed (ADR 0022).
        if($id) $thread=thread_record($id);
        elseif(post('to')!=='') $thread=thread_record($id=direct_thread($u,(int)post('to')));
        else throw new UserError(t('An wen geht die Nachricht?','Who is the message for?'));
        if(!may_write_thread($u,$thread))
            throw new UserError($thread['kind']==='course'
                ?t('Dieser Kurs ist archiviert. In seiner Gruppe wird nicht mehr geschrieben.','This course is archived. Its group takes no more messages.')
                :t('In dieser Unterhaltung wird nicht mehr geschrieben.','This conversation takes no more messages.'));
        // A voice note or a photo is a message on its own; only a bubble with
        // neither text nor a file is nothing to send.
        $body=text_limit('body',20000);
        $hasFile=isset($_FILES['attachment']) && (int)($_FILES['attachment']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
        if($body==='' && !$hasFile) throw new UserError(t('Bitte etwas schreiben oder etwas anhängen.','Write something, or attach something.'));
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',[$id,$u['id'],$body,now()]);
        $messageId=(int)db()->lastInsertId();
        attach_to_message($messageId);
        run('UPDATE threads SET updated_at=? WHERE id=?',[now(),$id]);
        // A chat tells the other person, by email if they want one and in the
        // bell either way. A group tells nobody: fifteen children would each
        // get a mail for every message (ADR 0022); the unread count says it.
        if($thread['kind']!=='course') {
            $summary=$body!==''?mb_substr($body,0,120):t('Anhang','An attachment');
            foreach(thread_people($id) as $person) {
                if((int)$person['id']===(int)$u['id']) continue;
                $account=one('SELECT * FROM accounts WHERE id=?',[(int)$person['id']]);
                notify_thread($account,$id,thread_title($thread,$account));
                notify((int)$person['id'],'message',t('Neue Nachricht von ','New message from ').$u['name'],$summary,'messages',['id'=>$id]);
            }
        }
        mark_thread_read($id,(int)$u['id']);
        audit('message.sent','thread',$id);return ['messages',['id'=>$id,'#'=>'chat-end']];

    case 'message_remove':
        // Staff take a group message down, or put it back from the same place.
        $restore=post('restore')!=='';
        $messageId=(int)post('id');
        $threadId=moderate_message($messageId,!$restore);
        flash($restore?t('Nachricht wiederhergestellt.','Message restored.')
                      :t('Nachricht entfernt. Du kannst sie an derselben Stelle wiederherstellen.','Message removed. You can restore it in the same place.'));
        return ['messages',['id'=>$threadId,'#'=>'m'.$messageId]];

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
        // Into each student's own chat with whoever sends it (ADR 0022), with
        // their own name and figures filled in, and the subject as its first
        // line so a circular still says what it is about.
        $count=0;
        foreach($p['student_ids'] as $id) {
            $s=student($id);
            if(!$s['account_id'] || !isset($p['accounts'][(int)$s['account_id']])) continue;
            $a=one("SELECT * FROM accounts WHERE id=? AND state='active' AND verified_at IS NOT NULL FOR UPDATE",[(int)$s['account_id']]);if(!$a)continue;
            $threadId=direct_thread($u,(int)$a['id']);
            $subject=mb_substr(template_text($p['subject'],$s),0,180);
            run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',[$threadId,$u['id'],$subject."\n\n".template_text($p['body'],$s),now()]);
            run('UPDATE threads SET updated_at=? WHERE id=?',[now(),$threadId]);
            if($p['email'])notify_thread($a,$threadId,$subject);
            $count++;
        }
        unset($_SESSION['bulk_preview']);audit('message.bulk_sent','thread');
        flash($count.' '.t('Nachrichten verschickt, jede in den Chat mit der Person. E-Mails berücksichtigen die Benachrichtigungseinstellungen.','messages sent, each into the chat with that person. Emails respect notification preferences.'));
        return ['messages',[]];
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
