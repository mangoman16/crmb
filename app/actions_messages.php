<?php
declare(strict_types=1);

function dispatch_messages(string $action): array {
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

    case 'contact_decide':
        $u=require_user();
        $accept=post('decision')==='accept';
        $r=decide_contact((int)post('id'),$accept);
        notify((int)$r['from_account_id'],'message',
            $accept?t('Deine Anfrage wurde angenommen','Your request was accepted'):t('Deine Anfrage wurde abgelehnt','Your request was declined'),
            $u['name'],'messages',[]);
        flash($accept?t('Ihr könnt euch jetzt schreiben.','You can write to each other now.'):t('Abgelehnt.','Declined.'));
        return ['messages',['contacts'=>1]];

    case 'news_save':
        require_staff();$id=(int)post('id');$title=required_text('title',180);$body=required_text('body',20000);$published=post('published')?1:0;
        if($id){if(!one('SELECT id FROM news WHERE id=?',[$id]))throw new NotFound(t('Diese Neuigkeit gibt es nicht mehr.','That news item no longer exists.'));run('UPDATE news SET title=?,body=?,published=?,updated_at=? WHERE id=?',[$title,$body,$published,now(),$id]);}
        else {run('INSERT INTO news (title,body,published,created_at,updated_at) VALUES (?,?,?,?,?)',[$title,$body,$published,now(),now()]);$id=(int)db()->lastInsertId();}
        if(post('send_email')) {
            if(!$published)throw new UserError(t('Bitte die Nachricht zuerst veröffentlichen.','Publish the news before emailing it.'));
            foreach(rows("SELECT * FROM accounts WHERE state='active' AND verified_at IS NOT NULL AND newsletter=1 AND role='student'") as $a)queue_mail((int)$a['id'],$a['email'],$title,$body."\n\n".url('news',['id'=>$id]),'newsletter');
        }
        audit('news.saved','news',$id);flash(t('Neuigkeit gespeichert.','News saved.'));return ['news',[]];
    default: throw new UserError(t('Unbekannte Aktion.','Unknown action.'));
    }
}
