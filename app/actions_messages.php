<?php
declare(strict_types=1);

function dispatch_messages(string $action): array {
    switch($action) {
    case 'message_send':
        $u=require_user();throttle('message',(string)$u['id'],40,300);
        // A photo is a file on the disk for as long as its chat lasts: twenty an
        // hour per family's login, as for a receipt (proof_upload), so one login
        // cannot fill the disk (security review, 2026-10-08). Staff are not
        // counted: a trainer posting a tournament's photos is not told to wait
        // (the owner: „no need to be over sensitive"). Counted before anything
        // is written, like every throttle.
        $hasFile=isset($_FILES['attachment']) && (int)($_FILES['attachment']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
        if($hasFile && !is_staff($u)) throttle('message-photo',(string)$u['id'],20,3600);
        $id=(int)post('thread_id');
        // Into a conversation they may write in, or the first message to
        // somebody, which makes the chat with them. There is no third way: the
        // shared desk thread is closed (ADR 0022).
        if($id) $thread=thread_record($id);
        elseif(post('to')!=='') $thread=thread_record($id=direct_thread($u,(int)post('to')));
        else throw new UserError(t('An wen geht die Nachricht?','Who is the message for?'));
        if(!may_write_thread($u,$thread))
            throw new UserError(match(true){
                $thread['kind']==='course'=>t('Dieser Kurs ist archiviert. In seiner Gruppe wird nicht mehr geschrieben.','This course is archived. Its group takes no more messages.'),
                // An administrator reading a chat between two others (ADR 0022 §11.1).
                two_person_chat_open($thread)=>only_the_two_write(),
                default=>t('In dieser Unterhaltung wird nicht mehr geschrieben.','This conversation takes no more messages.'),
            });
        // A photo is a message on its own; only a bubble with neither text nor
        // a photo is nothing to send.
        $body=text_limit('body',20000);
        if($body==='' && !$hasFile) throw new UserError(t('Bitte etwas schreiben oder ein Foto anhängen.','Write something, or attach a photo.'));
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',[$id,$u['id'],$body,now()]);
        $messageId=(int)db()->lastInsertId();
        attach_to_message($messageId);
        run('UPDATE threads SET updated_at=? WHERE id=?',[now(),$id]);
        // A chat tells the other person, by email if they want one and in the
        // bell either way. A group tells nobody: fifteen children would each
        // get a mail for every message (ADR 0022); the unread count says it.
        // The mail goes once until they look: somebody with a message here
        // they have not read was told then, and forty messages were forty
        // mails (security review, 2026-10-08).
        if($thread['kind']!=='course') {
            $summary=$body!==''?mb_substr($body,0,120):t('Ein Foto','A photo');
            foreach(thread_people($id) as $person) {
                if((int)$person['id']===(int)$u['id']) continue;
                $account=one('SELECT * FROM accounts WHERE id=?',[(int)$person['id']]);
                if(has_read_before($id,(int)$person['id'],$messageId)) notify_thread($account,$id,thread_title($thread,$account));
                notify((int)$person['id'],'message',t('Neue Nachricht von ','New message from ').$u['name'],$summary,'messages',['id'=>$id]);
            }
        }
        mark_thread_read($id,$u);
        audit('message.sent','thread',$id);return ['messages',['id'=>$id,'#'=>'chat-end']];

    case 'message_remove':
        // Staff take a group message down, or put it back from the same place.
        $restore=post('restore')!=='';
        $messageId=(int)post('id');
        $threadId=moderate_message($messageId,!$restore);
        // For as long as removed_messages_days, after which the daily cleanup
        // deletes it (ADR 0032): the way back has a deadline, so it is said.
        flash($restore?t('Nachricht wiederhergestellt.','Message restored.')
                      :strtr(t('Nachricht entfernt. {days} lang kannst du sie an derselben Stelle wiederherstellen.','Message removed. For {days} you can restore it in the same place.'),
                             ['{days}'=>plural((int)setting('removed_messages_days'),'Tag','Tage','day','days')]));
        return ['messages',['id'=>$threadId,'#'=>'m'.$messageId]];

    case 'news_save':
        require_staff();$id=(int)post('id');$title=required_text('title',180);$body=required_text('body',20000);$published=post('published')?1:0;
        // Locked, so that two saves at once cannot both find it unpublished and both tell the families.
        $wasPublished=0;
        if($id){$old=one('SELECT published FROM news WHERE id=? FOR UPDATE',[$id]);if(!$old)throw new NotFound(t('Diese Neuigkeit gibt es nicht mehr.','That news item no longer exists.'));$wasPublished=(int)$old['published'];run('UPDATE news SET title=?,body=?,published=?,updated_at=? WHERE id=?',[$title,$body,$published,now(),$id]);}
        else {run('INSERT INTO news (title,body,published,created_at,updated_at) VALUES (?,?,?,?,?)',[$title,$body,$published,now(),now()]);$id=(int)db()->lastInsertId();}
        // The bell carries the news, since the family's bar gave up „Neues"
        // (design, Part 7). Published, every active family login hears of it
        // once, in the trainer's words; an edit tells nobody again. Unpublished,
        // it leaves every bell: the link would open „Neuigkeit nicht gefunden".
        if($published && !$wasPublished) {
            foreach(rows("SELECT id FROM accounts WHERE role='student' AND state='active'") as $a)
                notify((int)$a['id'],'news',$title,mb_substr($body,0,120),'news',['id'=>$id]);
        } elseif(!$published && $wasPublished) withdraw_notices('news','news',['id'=>$id]);
        if(post('send_email')) {
            if(!$published)throw new UserError(t('Bitte die Nachricht zuerst veröffentlichen.','Publish the news before emailing it.'));
            foreach(rows("SELECT * FROM accounts WHERE state='active' AND verified_at IS NOT NULL AND newsletter=1 AND role='student'") as $a)queue_mail((int)$a['id'],$a['email'],$title,$body."\n\n".url('news',['id'=>$id]),'newsletter');
        }
        audit('news.saved','news',$id);flash(t('Neuigkeit gespeichert.','News saved.'));return ['news',[]];
    default: throw new UserError(t('Unbekannte Aktion.','Unknown action.'));
    }
}
