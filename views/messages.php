<?php
/**
 * Messages, in the shape people already know one (ADR 0022).
 *
 * The chats down one side and the open one beside them - on a phone one at a
 * time. Each course's group first, then the chats with one person. Nothing here
 * needs explaining to somebody who has used a messenger, which is the whole
 * point: the trainer and the children arrive already knowing how it works.
 *
 * Who sees which chat is decided in app/messaging.php, never here. Writing to
 * many at once - filters, placeholders, a review step - is a different job and
 * has its own page, „An mehrere schreiben".
 */
$staff=is_staff($user);
$me=(int)$user['id'];
$id=(int)($_GET['id']??0);
$with=(int)($_GET['with']??0);
// contacts=1 is what links in older notifications say.
$picking=!empty($_GET['new']) || !empty($_GET['contacts']);
/* While staff look through somebody's eyes nothing can be written (ADR 0022
   §9), so nothing here offers to. „Neue Nachricht" and a chat asked for by who
   it is with both show the one page that says so, whoever is asked for: it lists
   nobody, and offers nothing to ask or agree to. A chat in the list still opens
   by its id, to read.
   Before, a chat opened by who it was with said „nicht gefunden" where the child
   had one this view did not show and opened empty where they had none, and the
   picker listed the child's requests, their agreed contacts and every other
   family - so typing addresses told the trainer whom the child writes to. */
$viewing=impersonator()!==null;
if($viewing && $with && !$id) { $picking=true; $with=0; }
$allDirect=is_admin($user) && !empty($_GET['all']);
$before=max(0,(int)($_GET['before']??0));
// A chat with one person is the one the two already have, or an empty one that
// the first message makes.
if($with && !$id) $id=pair_thread($me,$with);
$showMembers=$id && !empty($_GET['members']);
$open=$id || $with || $picking;
// Whether this page draws a writing box: only an open chat that may be written
// in does. views/layout.php reads it after this page, in the scope the two share,
// to keep its help button off the box's Send button.
$writable=false;
$chats=chat_list($user,$allDirect);
$waiting=($staff || $viewing)?0:pending_contact_count($me);

/** What one row of the list says under the name. */
$preview=function(array $c) use ($me): string {
    if($c['last_id']===null)
        return $c['kind']==='course'
            ? plural((int)$c['members'],'Person im Kurs','Personen im Kurs','person in the course','people in the course').' · '.t('noch keine Nachrichten','no messages yet')
            : t('Noch keine Nachrichten','No messages yet');
    if($c['last_removed_at']!==null) return t('Nachricht entfernt','Message removed');
    $text=trim((string)$c['last_body']);
    if($text==='' && $c['last_file']!==null) {
        [$kind,$seconds,$name]=explode('|',(string)$c['last_file'],3)+['','',''];
        $text=match($kind){
            'image'=>t('Foto','Photo'),
            'voice'=>trim(t('Sprachnachricht','Voice message').' '.duration_label((int)$seconds)),
            default=>t('Datei: ','File: ').$name,
        };
    }
    $text=mb_substr(preg_replace('/\s+/u',' ',$text),0,90);
    if((int)$c['last_sender_id']===$me) return t('Du: ','You: ').$text;
    if($c['kind']==='course') return strtok((string)($c['last_sender_name']??t('Gelöscht','Deleted')),' ').': '.$text;
    return $text;
};
/** When, the way a messenger says it: the time today, the day otherwise. */
$when=function(?string $utc): string {
    $at=$utc!==null?local_time($utc):null;
    if(!$at) return '';
    return $at->format('Y-m-d')===today() ? $at->format('H:i') : day_label($at->format('Y-m-d'));
};
$row=function(array $c) use ($user,$id,$preview,$when): void {
    $unread=(int)$c['unread']; $group=$c['kind']==='course'; $other=chat_person_in($c,'other_'); ?>
    <a class="thread-item<?=$id===(int)$c['id']?' selected':''?><?=$unread?' unread':''?>" href="<?=e(url('messages',['id'=>$c['id'],'#'=>'chat-end']))?>">
        <?php if($group): ?><span class="hue hue-<?=e(chat_hue((int)$c['class_id']))?>"><?=avatar(['name'=>$c['class_name']])?></span>
        <?php else: ?><span class="avatar-presence"><?=avatar($other)?><?=$c['other_id']!==null && (int)$c['me_in']?presence_dot($user,$other):''?></span><?php endif ?>
        <span class="thread-item-text">
            <span class="thread-item-top"><strong><span class="thread-item-name"><?=e(thread_title($c,$user))?></span><?=!$group && (int)$c['me_in']?status_emoji_mark($other):''?></strong><time><?=e($when($c['last_at']??$c['updated_at']))?></time></span>
            <span class="thread-item-bottom"><span class="thread-item-preview"><?=e($preview($c))?></span><?php if($unread):?><span class="count"><?=e($unread)?><span class="visually-hidden"><?=e(' '.t('ungelesen','unread'))?></span></span><?php endif ?></span>
        </span>
    </a>
<?php };
?>
<div class="messages-page<?=$open?' is-open':''?>">
<?php page_head(t('Nachrichten','Messages'),'',$viewing?'':link_button(t('Neue Nachricht','New message'),'messages',['new'=>1]));
/* The pages that belong to Nachrichten without a menu entry of their own
   (nav_owner()): writing to many at once, the news, and what went out by email. */
if($staff): ?>
<nav class="page-links" aria-label="<?=e(t('Mehr zu Nachrichten','More about messages'))?>">
    <a class="chip" href="<?=e(url('compose'))?>"><?=e(t('An mehrere schreiben','Write to several'))?></a>
    <a class="chip" href="<?=e(url('news'))?>"><?=e(t('Neuigkeiten','News'))?></a>
    <a class="chip" href="<?=e(url('outbox'))?>"><?=e(t('Postausgang','Outbox'))?></a>
    <?php if(is_admin($user)): ?><a class="chip" href="<?=e(url('messages',$allDirect?[]:['all'=>1]))?>"><?=e($allDirect?t('Meine Chats','My chats'):t('Alle Direktchats','All direct chats'))?></a><?php endif ?>
</nav>
<?php elseif($waiting): ?>
<nav class="page-links" aria-label="<?=e(t('Anfragen','Requests'))?>"><a class="chip" href="<?=e(url('messages',['new'=>1]))?>"><?=e(plural($waiting,'neue Anfrage','neue Anfragen','new request','new requests'))?></a></nav>
<?php endif ?>
<div class="messages-grid">
<aside class="card thread-list">
<?php if($allDirect): ?>
    <h2 class="chat-section"><?=e(t('Chats zwischen Schülern und Team','Chats between students and the team'))?></h2>
    <p class="chat-empty"><?=e(t('Du liest hier mit; schreiben können nur die beiden.','You can read these; only the two of them write.'))?></p>
    <?php foreach($chats as $c) $row($c); ?>
<?php else:
    $groups=array_filter($chats,fn($c)=>$c['kind']==='course');
    $direct=array_filter($chats,fn($c)=>in_array($c['kind'],['direct','staff_direct'],true));
    $desk=array_filter($chats,fn($c)=>$c['kind']==='staff'); ?>
    <h2 class="chat-section"><?=e(t('Kursgruppen','Course groups'))?></h2>
    <?php if(!$groups): ?>
    <p class="chat-empty"><?=e($staff?t('Noch keine Kurse. Jeder Kurs bekommt hier automatisch seine Gruppe.','No courses yet. Every course gets its group here by itself.')
                                     :t('Du bist gerade in keinem Kurs. Sobald du in einem bist, erscheint hier seine Gruppe.','You are not in a course right now. As soon as you are, its group appears here.'))?>
        <?php if($staff) echo '<br>'.link_button(t('Kurs anlegen','Add a course'),'classes',[],'secondary'); ?></p>
    <?php endif; foreach($groups as $c) $row($c); ?>
    <h2 class="chat-section"><?=e(t('Einzelchats','Direct chats'))?></h2>
    <?php if(!$direct): ?>
    <p class="chat-empty"><?=e($staff?t('Noch keine Einzelchats.','No direct chats yet.'):t('Noch keine Nachrichten. Schreib deiner Trainerin – sie antwortet hier.','No messages yet. Write to your coach – she answers here.'))?></p>
    <?php endif; foreach($direct as $c) $row($c); ?>
    <?php if($desk): ?>
    <h2 class="chat-section"><?=e(t('Frühere Unterhaltungen','Earlier conversations'))?></h2>
    <?php foreach($desk as $c) $row($c); endif ?>
<?php endif ?>
</aside>

<section class="card conversation">
<?php
// ---------------------------------------------------------------------------
// „Neue Nachricht": who to write to.
if($picking): ?>
    <header class="chat-head"><a class="chat-back" href="<?=e(url('messages'))?>"><?=icon('arrow')?><span class="visually-hidden"><?=e(t('Zurück zu allen Nachrichten','Back to all messages'))?></span></a><div class="chat-head-who"><span class="chat-head-text"><h2><?=e(t('Neue Nachricht','New message'))?></h2><?php if(!$viewing): ?><small><?=e(t('An wen?','Who to?'))?></small><?php endif ?></span></div></header>
    <?php /* The one answer while looking through somebody's eyes (above), in the
             words the actions refuse with: nobody listed, nothing to ask or agree to. */
    if($viewing): ?>
    <p class="chat-empty"><?=e(viewing_refusal())?></p>
    <?php else:
    $q=trim((string)($_GET['q']??''));
    $match=fn(array $p): bool => $q==='' || mb_stripos((string)$p['name'],$q)!==false;
    $person=function(array $p, string $small='') use ($user): void { ?>
    <a class="member-row" href="<?=e(url('messages',['with'=>$p['id'],'#'=>'chat-end']))?>">
        <span class="avatar-presence"><?=avatar($p)?><?=presence_dot($user,$p)?></span>
        <span class="member-row-text"><strong><?=chat_name($p)?></strong><?php if($small!==''):?><small><?=e($small)?></small><?php endif ?></span><?=icon('arrow')?>
    </a>
<?php };
    $contacts=array_filter(contacts_for($user),$match);
    $team=array_filter($contacts,fn($p)=>is_staff($p));
    $others=array_filter($contacts,fn($p)=>!is_staff($p));
    if($staff): ?>
    <form class="filter-search" method="get" action="<?=e(url('messages'))?>">
        <input type="hidden" name="page" value="messages"><input type="hidden" name="new" value="1">
        <?php input('q',t('Name suchen','Search by name'),$q,'search'); submit_button(t('Suchen','Search'),'secondary'); ?>
    </form>
    <?php
    $groups=array_filter($chats,fn($c)=>$c['kind']==='course' && ($q==='' || mb_stripos((string)$c['class_name'],$q)!==false));
    if($groups): ?><h2 class="chat-section"><?=e(t('Kursgruppen','Course groups'))?></h2><?php foreach($groups as $c) $row($c); endif;
    if($team): ?><h2 class="chat-section"><?=e(t('Team','Team'))?></h2><?php foreach($team as $p) $person($p,role_label((string)$p['role'])); endif;
    if($others):
        $shown=array_slice($others,0,50); $courses=student_courses_by_account(array_column($shown,'id')); ?>
    <h2 class="chat-section"><?=e(t('Schülerinnen und Schüler','Students'))?></h2>
    <?php foreach($shown as $p) $person($p,$courses[(int)$p['id']]??'');
        if(count($others)>50): ?><p class="chat-empty"><?=e(t('Weitere über die Suche.','Find more with the search.'))?></p><?php endif;
    endif;
    if(!$groups && !$team && !$others): ?><p class="chat-empty"><?=e(t('Niemand mit diesem Namen.','Nobody by that name.'))?></p><?php endif ?>
    <?php else:
    $requests=contact_requests_for($me);
    if($requests): ?>
    <h2 class="chat-section"><?=e(t('Möchte dir schreiben','Would like to write to you'))?></h2>
    <?php foreach($requests as $r): ?>
    <div class="record-row">
        <?php /* The row is the request, so its id is the request's, not the sender's:
                 the picture has to be asked for by the sender's account id, and
                 whether it may be shown at all depends on the sender's role. */ ?>
        <div class="account-identity"><?=avatar(['id'=>(int)$r['from_account_id'],'role'=>$r['from_role'],'name'=>$r['from_name'],'avatar_name'=>$r['avatar_name']])?>
            <div><strong><?=e($r['from_name'])?></strong><?php if($r['message']):?><p><?=e($r['message'])?></p><?php endif ?></div></div>
        <div class="row-actions">
            <?php start_form('contact_decide',['id'=>$r['id'],'decision'=>'accept'],'inline-form');submit_button(t('Zustimmen','Agree'),'secondary');?></form>
            <?php start_form('contact_decide',['id'=>$r['id'],'decision'=>'decline'],'inline-form');submit_button(t('Ablehnen','Decline'),'subtle danger-text');?></form>
        </div>
    </div>
    <?php endforeach; endif ?>
    <h2 class="chat-section"><?=e(t('Trainerteam','Coaching team'))?></h2>
    <?php foreach($team as $p) $person($p,role_label((string)$p['role']));
    if($others): ?><h2 class="chat-section"><?=e(t('Kinder','Children'))?></h2><?php foreach($others as $p) $person($p); endif;
    $strangers=rows("SELECT a.id,a.name,a.avatar_name,a.role FROM accounts a WHERE a.role='student' AND a.state='active' AND a.id<>?"
        ." AND NOT EXISTS (SELECT 1 FROM contact_requests r WHERE (r.from_account_id=a.id AND r.to_account_id=?) OR (r.from_account_id=? AND r.to_account_id=a.id))"
        .' ORDER BY a.name LIMIT 100',[$me,$me,$me]);
    if($strangers): ?>
    <details class="ask-others"><summary><?=e(t('Jemand anderen fragen','Ask somebody else'))?></summary>
    <p class="muted"><?=e(t('Andere Familien bekommen erst eine Nachricht von dir, wenn sie zugestimmt haben. Der Trainerin kannst du immer schreiben.','Other families only get a message from you once they have agreed. You can always write to the trainer.'))?></p>
    <?php foreach($strangers as $c): ?>
    <div class="record-row with-form">
        <div class="account-identity"><?=avatar($c)?><div><strong><?=e($c['name'])?></strong></div></div>
        <?php start_form('contact_request',['to'=>$c['id']],'row-form');
        input('message',t('Kurz dazu','A word about it'),'','text',false,'',t('Wer bist du?','Who are you?'));
        submit_button(t('Anfragen','Ask'),'subtle');?></form>
    </div>
    <?php endforeach ?></details>
    <?php endif; endif; endif ?>

<?php
// ---------------------------------------------------------------------------
// Who is in a group.
elseif($showMembers): $thread=thread_record($id);
    if($thread['kind']!=='course') throw new NotFound(t('Unterhaltung nicht gefunden.','Conversation not found.'));
    $people=course_group_people((int)$thread['class_id']); $class=training_class((int)$thread['class_id']); ?>
    <header class="chat-head">
        <a class="chat-back" href="<?=e(url('messages',['id'=>$id,'#'=>'chat-end']))?>"><?=icon('arrow')?><span class="visually-hidden"><?=e(t('Zurück zur Gruppe','Back to the group'))?></span></a>
        <div class="chat-head-who"><span class="hue hue-<?=e(chat_hue((int)$thread['class_id']))?>"><?=avatar(['name'=>$thread['class_name']],'small')?></span>
            <span class="chat-head-text"><h2><?=e($thread['class_name'])?></h2><small><?=e(class_schedule($class))?></small></span></div>
    </header>
    <?php $member=function(array $p, string $small) use ($user,$staff,$me,$viewing): void {
        // A row is a link only where a chat is allowed: staff with anybody, a
        // child with staff - and none while looking through somebody's eyes,
        // where nothing is written.
        $canWrite=!$viewing && $p['id']!==null && (int)$p['id']!==$me && ($staff || is_staff($p));
        $tag=$canWrite?'a':'div'; ?>
    <<?=e($tag)?> class="member-row"<?php if($canWrite):?> href="<?=e(url('messages',['with'=>$p['id'],'#'=>'chat-end']))?>"<?php endif ?>>
        <span class="avatar-presence"><?=avatar($p)?><?=$p['id']!==null?presence_dot($user,$p):''?></span>
        <span class="member-row-text"><strong><?=chat_name($p)?></strong><?php if($small!==''):?><small><?=e($small)?></small><?php endif ?></span><?=$canWrite?icon('arrow'):''?>
    </<?=e($tag)?>>
    <?php }; ?>
    <h2 class="chat-section"><?=e(t('Trainerteam','Coaching team').' ('.count($people['staff']).')')?></h2>
    <?php foreach($people['staff'] as $p) $member($p,(int)$p['id']===$me?t('Du','You'):role_label((string)$p['role'])); ?>
    <?php /* Staff see every child enrolled, with who has no login yet; a child sees
             the people who read the group, and only a number for the others -
             a classmate whose family is not in the portal is not named to them. */
    $members=$staff?$people['members']:array_values(array_filter($people['members'],fn($p)=>$p['id']!==null));
    $outside=count($people['members'])-count($members); ?>
    <h2 class="chat-section"><?=e(t('Im Kurs','In the course').' ('.count($people['members']).')')?></h2>
    <?php foreach($members as $p) $member(['name'=>$p['name']??$p['first_name'].' '.$p['last_name']]+$p,
        $p['id']===null?t('Noch kein Zugang','No login yet'):((int)$p['id']===$me?t('Du','You'):''));
    if($outside): ?><p class="chat-empty"><?=e(t('Dazu ','And ').plural($outside,'Person ohne Zugang zum Portal','Personen ohne Zugang zum Portal','person without a login','people without a login').'.')?></p><?php endif ?>
    <p><?=link_button(t('Zurück zur Gruppe','Back to the group'),'messages',['id'=>$id,'#'=>'chat-end'],'secondary')?></p>

<?php
// ---------------------------------------------------------------------------
// One conversation, or the empty one a first message to somebody makes.
elseif($id || $with):
    if($id) {
        // Read for them only by them: while staff look through their eyes, nothing is marked.
        $thread=thread_record($id); if(!$viewing) mark_thread_read($id,$me);
        $history=thread_messages($id,$before);
        $people=$thread['kind']==='course'?[]:thread_people($id);
    } else {
        // The person drawn from the facts the header of a chat already made is
        // drawn from, and the kind the first message will make it - so the line
        // at the top says who will read it before anything is written.
        $to=one('SELECT '.chat_person_columns('a')." FROM accounts a WHERE a.id=? AND a.state='active'",[$with]);
        if(!$to || !may_message($user,$with)) throw new NotFound(t('Diese Person kannst du hier nicht anschreiben.','You cannot write to this person here.'));
        $thread=['id'=>0,'kind'=>pair_kind($user,$to)];
        $history=['messages'=>[],'more'=>false];
        $people=[$user,$to];
    }
    $group=$thread['kind']==='course';
    $others=array_values(array_filter($people,fn($p)=>(int)$p['id']!==$me));
    $other=count($others)===1?$others[0]:null;
    $writable=$id?may_write_thread($user,$thread):!$viewing; ?>
    <header class="chat-head">
        <a class="chat-back" href="<?=e(url('messages'))?>"><?=icon('arrow')?><span class="visually-hidden"><?=e(t('Zurück zu allen Nachrichten','Back to all messages'))?></span></a>
        <?php if($group): $count=count(course_group_people((int)$thread['class_id'])['members']); ?>
        <a class="chat-head-who" href="<?=e(url('messages',['id'=>$id,'members'=>1]))?>">
            <span class="hue hue-<?=e(chat_hue((int)$thread['class_id']))?>"><?=avatar(['name'=>$thread['class_name']],'small')?></span>
            <span class="chat-head-text"><h2><?=e($thread['class_name'])?></h2><small><?=e(plural($count,'Person im Kurs','Personen im Kurs','person in the course','people in the course').' · '.t('Trainerteam','Coaching team'))?></small></span></a>
        <a class="chat-members" href="<?=e(url('messages',['id'=>$id,'members'=>1]))?>"><?=icon('users')?><span class="visually-hidden"><?=e(t('Wer ist in der Gruppe?','Who is in the group?'))?></span></a>
        <?php else: ?>
        <div class="chat-head-who">
            <?php if($other): ?><span class="avatar-presence"><?=avatar($other,'small')?><?=presence_dot($user,$other)?></span><?php endif ?>
            <span class="chat-head-text"><h2><?=$other?chat_name($other):e(thread_title($thread,$user))?></h2>
                <?php if($other): ?><small><?=e(($staff || is_staff($other)?role_label((string)$other['role']).' · ':'').presence_state_label(presence_state($other)))?></small><?php endif ?></span>
        </div>
        <?php endif ?>
    </header>
    <div class="message-history">
    <?php
    // Who reads along, said once at the top, in the words the chat is for.
    $note=match($thread['kind']){
        'course'=>t('Alle aus diesem Kurs und das Trainerteam lesen hier mit.','Everyone in this course and the coaching team can read this.'),
        'staff_direct'=>t('Die Administratoren des Vereins können diesen Chat lesen.','The club’s administrators can read this chat.'),
        'direct'=>t('Diese Unterhaltung ist privat. Auch die Trainerin und der Administrator lesen sie nicht mit.','This conversation is private. Neither the trainer nor the administrator can read it.'),
        default=>t('Diese frühere Unterhaltung ist geschlossen. Schreib in den Chat mit der Person.','This earlier conversation is closed. Write in the chat with the person.'),
    }; ?>
    <p class="chat-note"><?=e($note)?></p>
    <?php if($history['more']): ?><p class="chat-older"><?=link_button(t('Ältere Nachrichten','Earlier messages'),'messages',['id'=>$id,'before'=>$history['messages'][0]['id']],'secondary')?></p><?php endif ?>
    <?php if(!$history['messages']): ?><p class="chat-note"><?=e(t('Noch keine Nachrichten. Schreib die erste!','No messages yet. Write the first one!'))?></p><?php endif;
    $lastDay=''; $lastSender=null;
    foreach($history['messages'] as $m):
        $mine=(int)$m['sender_id']===$me; $day=local_time((string)$m['created_at'])?->format('Y-m-d') ?? '';
        if($day!==$lastDay): $lastSender=null; ?><span class="day-separator"><?=e(day_label($day))?></span><?php endif;
        // A run: the same sender on the same day. Only its first bubble names them.
        $runStart=$m['sender_id']!==$lastSender; $lastDay=$day; $lastSender=$m['sender_id'];
        $sender=chat_person_in($m,'author_'); ?>
    <div class="message-row<?=$mine?' mine':''?><?=$runStart?' run-start':''?>" id="m<?=e($m['id'])?>">
        <?php if($group && !$mine) echo $runStart?avatar($sender,'tiny'):'<span class="avatar-slot"></span>'; ?>
        <article class="message-bubble<?=$mine?' mine':''?><?=$m['removed']?' removed':''?>">
            <?php if($group && !$mine && $runStart): ?><span class="bubble-sender hue-<?=e(chat_hue((int)$m['sender_id']))?>"><?=chat_name($sender)?></span><?php endif ?>
            <?php if($m['removed']): ?><div class="prewrap"><?=e(t('Nachricht entfernt','Message removed'))?></div>
            <?php elseif(($m['body']??'')!==''): ?><div class="prewrap"><?=e($m['body'])?></div><?php endif ?>
            <?php foreach($m['files'] as $file): $href=url('download',['what'=>'attachment','id'=>$file['id']]); ?>
                <?php if($file['kind']==='image'): ?>
                <a class="bubble-image" href="<?=e($href)?>"><img src="<?=e($href)?>" alt="<?=e($file['original_name'])?>" loading="lazy"></a>
                <?php elseif($file['kind']==='voice'): ?>
                <div class="bubble-voice"><audio controls preload="none" src="<?=e($href)?>"></audio>
                    <small><?=e(t('Sprachnachricht','Voice message').((int)$file['seconds']?' · '.duration_label((int)$file['seconds']):''))?></small></div>
                <?php else: ?>
                <a class="bubble-file" href="<?=e($href)?>"><?=icon('news')?><span><?=e($file['original_name']?:t('Datei','File'))?><small><?=e(round(((int)$file['bytes'])/1024).' kB')?></small></span></a>
                <?php endif ?>
            <?php endforeach ?>
            <footer class="bubble-foot"><time datetime="<?=e($m['created_at'])?>Z"><?=e(local_time((string)$m['created_at'])?->format('H:i') ?? '')?></time>
            <?php /* Staff take a group message down, or put it back, from the same
                     place (ADR 0022) - no confirmation box, because restoring is
                     the way back. A family's chat is theirs. Not while looking
                     through somebody's eyes, where it could only be refused. */
            if($staff && $group && !$viewing): ?>
                <details class="bubble-menu"><summary aria-label="<?=e(t('Mehr zu dieser Nachricht','More about this message'))?>"><?=icon('more')?></summary>
                <?php start_form('message_remove',['id'=>$m['id']]+($m['removed']?['restore'=>1]:[]),'inline-form');
                submit_button($m['removed']?t('Wiederherstellen','Restore'):t('Nachricht entfernen','Remove message'),$m['removed']?'secondary':'subtle danger-text'); ?></form>
                </details>
            <?php endif ?></footer>
        </article>
    </div>
    <?php endforeach ?>
    <span id="chat-end"></span>
    </div>

    <?php if($writable):
    /* One box, a paper clip and a microphone. The clip is an ordinary file
       input and works with no JavaScript at all; the microphone appears only
       when the browser can record, and puts what it records into that same
       input, so there is one way in and not two. */
    start_form('message_send',$id?['thread_id'=>$id]:['to'=>$with],'composer',true); ?>
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
    <?php endif ?>

<?php else:
    empty_state(t('Keine Unterhaltung geöffnet','No conversation open'),
        // Not "on the left": on a phone the list is above this, and a portal
        // that tells somebody to look somewhere they cannot look reads as
        // broken. The words have to fit both layouts - and, while looking
        // through somebody's eyes, a view that writes nothing.
        $viewing?t('Wähle eine Unterhaltung aus.','Choose a conversation.')
                :t('Wähle eine Unterhaltung aus, oder schreibe eine neue Nachricht.','Choose a conversation, or write a new message.'),
        $viewing?'':link_button(t('Neue Nachricht','New message'),'messages',['new'=>1]));
endif ?>
</section>
</div>
</div>
