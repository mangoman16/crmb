<?php
/**
 * Messages, in the shape people already know one.
 *
 * Conversations down one side, bubbles down the other, one box at the bottom
 * with a paper clip and a microphone beside it. Nothing here needs explaining to
 * somebody who has used a phone, which is the whole point: the trainer and the
 * children both arrive already knowing how it works.
 *
 * The bulk tool - filters, templates, placeholders, a review step - is a
 * different job and lives on its own page, reached from the button at the top.
 * Mixing the two was what made writing one message feel like operating
 * machinery.
 */
$staff=is_staff($user);
$id=(int)($_GET['id']??0);
$new=!empty($_GET['new']);
$showContacts=!empty($_GET['contacts']);
$unread=array_flip(unread_thread_ids($user));
$threads=threads_for($user);
$waiting=pending_contact_count((int)$user['id']);

page_head(t('Nachrichten','Messages'),
    t('Nur die Beteiligten lesen mit.','Only the people in a conversation can read it.'),
    ($staff?link_button(t('An eine Gruppe schreiben','Write to a group'),'compose',[],'secondary'):'')
    .link_button(t('Neue Nachricht','New message'),'messages',['contacts'=>1]));
?>
<div class="messages-grid">
<aside class="card thread-list">
    <div class="section-heading">
        <h2><?=e(t('Unterhaltungen','Conversations'))?></h2>
        <a href="<?=e(url('messages',['contacts'=>1]))?>"><?=e(t('Neu','New'))?><?php if($waiting):?> <span class="count"><?=e($waiting)?></span><?php endif ?></a>
    </div>
    <?php if(!$threads):?><p class="muted"><?=e(t('Noch keine Nachrichten.','No messages yet.'))?></p><?php endif ?>
    <?php foreach($threads as $thread): $isNew=isset($unread[(int)$thread['id']]); ?>
    <a class="thread-item <?=$id===(int)$thread['id']?'selected':''?> <?=$isNew?'unread':''?>" href="<?=e(url('messages',['id'=>$thread['id']]))?>">
        <div><strong><?=e(thread_title($thread,$user))?><?php if($isNew):?> <span class="dot" aria-hidden="true"></span><span class="visually-hidden"><?=e(t('ungelesen','unread'))?></span><?php endif ?></strong><small><?=e(fmt_date($thread['updated_at']))?></small></div>
        <h3><?=e($thread['subject'])?><?php if($thread['kind']==='direct')badge(t('Privat','Private'));?></h3>
        <p><?=e(mb_substr((string)($thread['last_message']??''),0,90) ?: ((int)$thread['file_count']?t('Anhang','Attachment'):''))?></p>
    </a>
    <?php endforeach ?>
</aside>

<section class="card conversation">
<?php
// ---------------------------------------------------------------------------
if($showContacts): $contacts=contacts_for($user); $requests=contact_requests_for((int)$user['id']); ?>
    <h2><?=e(t('Neue Nachricht','New message'))?></h2>
    <?php if($requests): ?>
    <h3><?=e(t('Möchte dir schreiben','Would like to write to you'))?></h3>
    <?php foreach($requests as $r): ?>
    <div class="record-row">
        <div class="account-identity"><?=avatar($r+['name'=>$r['from_name']])?>
            <div><strong><?=e($r['from_name'])?></strong><?php if($r['message']):?><p><?=e($r['message'])?></p><?php endif ?></div></div>
        <div class="row-actions">
            <?php start_form('contact_decide',['id'=>$r['id'],'decision'=>'accept'],'inline-form');submit_button(t('Zustimmen','Agree'),'secondary');?></form>
            <?php start_form('contact_decide',['id'=>$r['id'],'decision'=>'decline'],'inline-form');submit_button(t('Ablehnen','Decline'),'subtle danger-text');?></form>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>

    <h3><?=e(t('An wen?','Who to?'))?></h3>
    <?php foreach($contacts as $c): ?>
    <div class="record-row">
        <div class="account-identity"><?=avatar($c)?>
            <div><strong><?=e($c['name'])?></strong><small><?=e(role_label((string)$c['role']))?></small></div></div>
        <div class="row-actions">
            <?php start_form('message_send',['to'=>$c['id']],'inline-form');
            input('body',t('Nachricht','Message'),'','text',true,'',t('Schreiben …','Write …'));
            submit_button(t('Senden','Send'),'secondary');?></form>
        </div>
    </div>
    <?php endforeach ?>

    <?php if(!$staff): $others=rows("SELECT a.id,a.name,a.avatar_name,a.role FROM accounts a WHERE a.role='student' AND a.state='active' AND a.id<>?"
        ." AND NOT EXISTS (SELECT 1 FROM contact_requests r WHERE (r.from_account_id=a.id AND r.to_account_id=?) OR (r.from_account_id=? AND r.to_account_id=a.id))"
        .' ORDER BY a.name LIMIT 100',[(int)$user['id'],(int)$user['id'],(int)$user['id']]); if($others): ?>
    <h3><?=e(t('Jemand anderen fragen','Ask somebody else'))?></h3>
    <p class="muted"><?=e(t('Andere Familien bekommen erst eine Nachricht von dir, wenn sie zugestimmt haben. Der Trainerin kannst du immer schreiben.','Other families only get a message from you once they have agreed. You can always write to the trainer.'))?></p>
    <?php foreach($others as $c): ?>
    <div class="record-row">
        <div class="account-identity"><?=avatar($c)?><div><strong><?=e($c['name'])?></strong></div></div>
        <div class="row-actions">
            <?php start_form('contact_request',['to'=>$c['id']],'inline-form');
            input('message',t('Kurz dazu','A word about it'),'','text',false,'',t('Wer bist du?','Who are you?'));
            submit_button(t('Anfragen','Ask'),'subtle');?></form>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif; endif ?>

<?php
// ---------------------------------------------------------------------------
elseif($id): $thread=thread_record($id); mark_thread_read($id,(int)$user['id']);
    $messages=rows('SELECT m.*,a.name AS sender_name,a.avatar_name FROM messages m LEFT JOIN accounts a ON a.id=m.sender_id'
        .' WHERE m.thread_id=? ORDER BY m.id',[$id]);
    $files=files_by_message(array_column($messages,'id'));
?>
    <div class="section-heading">
        <div><h2><?=e(thread_title($thread,$user))?></h2><small><?=e($thread['subject'])?></small></div>
        <?php if($thread['kind']==='direct'){echo icon('lock');}else{echo icon('users');} ?>
    </div>
    <?php if($thread['kind']==='direct'): ?>
    <p class="muted"><?=e(t('Diese Unterhaltung ist privat. Auch die Trainerin und der Administrator lesen sie nicht mit.','This conversation is private. Neither the trainer nor the administrator can read it.'))?></p>
    <?php endif ?>
    <div class="message-history">
    <?php foreach($messages as $m): $mine=(int)$m['sender_id']===(int)$user['id']; ?>
        <article class="message-bubble <?=$mine?'mine':''?>">
            <div class="message-meta"><strong><?=e($m['sender_name']??t('Gelöschtes Konto','Deleted account'))?></strong><time><?=e(fmt_datetime($m['created_at']))?></time></div>
            <?php if(($m['body']??'')!==''): ?><div class="prewrap"><?=e($m['body'])?></div><?php endif ?>
            <?php foreach($files[(int)$m['id']]??[] as $file): $href=url('download',['what'=>'attachment','id'=>$file['id']]); ?>
                <?php if($file['kind']==='image'): ?>
                <a class="bubble-image" href="<?=e($href)?>"><img src="<?=e($href)?>" alt="<?=e($file['original_name'])?>" loading="lazy"></a>
                <?php elseif($file['kind']==='voice'): ?>
                <div class="bubble-voice"><audio controls preload="none" src="<?=e($href)?>"></audio>
                    <small><?=e(t('Sprachnachricht','Voice message').((int)$file['seconds']?' · '.duration_label((int)$file['seconds']):''))?></small></div>
                <?php else: ?>
                <a class="bubble-file" href="<?=e($href)?>"><?=icon('news')?><span><?=e($file['original_name']?:t('Datei','File'))?><small><?=e(round(((int)$file['bytes'])/1024).' kB')?></small></span></a>
                <?php endif ?>
            <?php endforeach ?>
        </article>
    <?php endforeach ?>
    </div>

    <?php /* One box, a paper clip and a microphone. The clip is an ordinary file
             input and works with no JavaScript at all; the microphone appears
             only when the browser can record, and puts what it records into that
             same input, so there is one way in and not two. */ ?>
    <?php start_form('message_send',['thread_id'=>$id],'composer',true); ?>
    <div class="composer-row">
        <label class="composer-clip" title="<?=e(t('Datei anhängen','Attach a file'))?>">
            <?=icon('plus')?><span class="visually-hidden"><?=e(t('Datei anhängen','Attach a file'))?></span>
            <input type="file" name="attachment" accept="<?=e(implode(',',array_keys(upload_types('message'))))?>">
        </label>
        <label class="visually-hidden" for="composer-body"><?=e(t('Nachricht','Message'))?></label>
        <textarea id="composer-body" name="body" rows="1" placeholder="<?=e(t('Nachricht schreiben …','Write a message …'))?>"></textarea>
        <button type="button" class="composer-mic" data-record hidden>
            <?=icon('mic')?><span class="visually-hidden"><?=e(t('Sprachnachricht aufnehmen','Record a voice message'))?></span>
        </button>
        <button class="button primary composer-send" type="submit"><?=icon('arrow')?><span class="visually-hidden"><?=e(t('Senden','Send'))?></span></button>
    </div>
    <input type="hidden" name="attachment_seconds" value="0" data-record-seconds>
    <p class="composer-note" data-record-status hidden></p>
    <small class="muted"><?=e(t('Anhänge bis ','Attachments up to ').upload_limit_label().t('. Bilder, PDF und Sprachnachrichten.','. Pictures, PDFs and voice messages.'))?></small>
    </form>

<?php
// ---------------------------------------------------------------------------
elseif($new && !$staff): ?>
    <h2><?=e(t('Nachricht an die Trainerin','Message your coach'))?></h2>
    <?php start_form('message_send',[],'form',true);
    input('subject',t('Worum geht es?','What is it about?'),'','text',true);
    input('body',t('Nachricht','Message'),'','textarea');
    file_field('attachment',t('Anhang (optional)','Attachment (optional)'),'message');
    submit_button(t('Nachricht senden','Send message'));?></form>
<?php else:
    empty_state(t('Keine Unterhaltung geöffnet','No conversation open'),
        // Not "on the left": on a phone the list is above this, and a portal
        // that tells somebody to look somewhere they cannot look reads as
        // broken. The words have to fit both layouts.
        t('Wähle eine Unterhaltung aus, oder schreibe eine neue Nachricht.','Choose a conversation, or write a new message.'),
        link_button(t('Neue Nachricht','New message'),'messages',['contacts'=>1]));
endif ?>
</section>
</div>
