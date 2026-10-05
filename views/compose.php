<?php
$review=!empty($_GET['review']);$p=$_SESSION['bulk_preview']??null;
page_head($review?t('Nachricht prüfen','Review message'):t('An mehrere schreiben','Write to several'),'',link_button(t('Nachrichten','Messages'),'messages',[],'secondary'));
if($review && $p): ?>
<?php /* One login is one student (ADR 0010), so every recipient is a student, and
         each gets the message in their own chat with the sender (ADR 0022), with
         their own name and figures in it - which is what each preview shows. */
$recipients=array_map(fn($sid)=>student((int)$sid),$p['student_ids']); ?>
<section class="card"><span class="eyebrow"><?=e(count($recipients).' '.t('Empfänger','recipients'))?></span><h2><?=e($p['subject'])?></h2>
<p><?=e(implode(', ',array_map(fn($r)=>$r['first_name'].' '.$r['last_name'],$recipients)))?></p>
<div class="notice"><?=e($p['email']?t('Jede Person bekommt die Nachricht in ihren Chat mit dir; eine E-Mail dazu nur, wenn sie Hinweise eingeschaltet hat.','Everybody gets the message in their chat with you; an email about it only if they have notifications switched on.'):t('Jede Person bekommt die Nachricht in ihren Chat mit dir, ohne E-Mail dazu.','Everybody gets the message in their chat with you, without an email about it.'))?></div>
<?php foreach($recipients as $i=>$r): ?><details <?=$i===0?'open':''?>><summary><?=e($r['first_name'].' '.$r['last_name'])?></summary><h3><?=e(template_text($p['subject'],$r))?></h3><div class="prewrap"><?=e(template_text($p['body'],$r))?></div></details><?php endforeach ?>
<?php start_form('bulk_send');submit_button(t('Jetzt senden','Send now'));?></form><?=link_button(t('Zurück zum Entwurf','Back to draft'),'compose',['draft'=>1],'secondary')?></section>
<?php else:
$f=filters_from($_GET);$all=filtered_students($f);$tpl=!empty($_GET['template'])?one('SELECT * FROM message_templates WHERE id=?',[(int)$_GET['template']]):null;
$draft=!empty($_GET['draft'])&&$p?$p:null;
?>
<section class="card filter-card"><h2><?=e(t('Empfänger finden','Find recipients'))?></h2><?php render_filters($f,'compose');?><div class="saved-filters"><?php foreach(rows('SELECT * FROM saved_filters ORDER BY name') as $filter):?><a class="chip" href="<?=e(url('compose',json_decode($filter['criteria_json'],true)))?>"><?=e($filter['name'])?></a><?php endforeach ?></div></section>
<section class="card"><div class="saved-filters"><span><?=e(t('Vorlage:','Template:'))?></span><?php foreach(rows('SELECT * FROM message_templates ORDER BY name') as $template):?><a class="chip" href="<?=e(url('compose',$f+['template'=>$template['id']]))?>"><?=e($template['name'])?></a><?php endforeach ?></div>
<?php start_form('bulk_preview');?><div class="section-heading"><h2><?=e(t('Schüler auswählen','Select students'))?></h2><label class="check"><input type="checkbox" data-select-all="student_ids[]"><span><?=e(t('Alle auswählen','Select all'))?></span></label></div><div class="recipient-list">
<?php // Every listed login in one query, not one per row.
$ids=array_values(array_filter(array_map(fn($s)=>(int)$s['account_id'],$all)));
$logins=$ids?array_column(rows('SELECT id,state,verified_at FROM accounts WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids),null,'id'):[];
foreach($all as $s):$a=$logins[(int)$s['account_id']]??null;$eligible=$a&&$a['state']==='active'&&$a['verified_at'];?><label class="recipient <?=$eligible?'':'disabled'?>"><input type="checkbox" name="student_ids[]" value="<?=(int)$s['id']?>" <?=$eligible?'':'disabled'?> <?=in_array((int)$s['id'],$draft['student_ids']??[],true)?'checked':''?>><span><strong><?=e($s['first_name'].' '.$s['last_name'])?></strong><?php if(!$eligible):?><small><?=e(t('Kein aktiver Zugang','No active login'))?></small><?php endif ?></span></label><?php endforeach;if(!$all)echo '<p>'.e(t('Keine Schüler in dieser Auswahl.','No students in this selection.')).'</p>';?></div>
<?php input('subject',t('Betreff','Subject'),$draft['subject']??$tpl['subject']??'','text',true);input('body',t('Nachricht','Message'),$draft['body']??$tpl['body']??'','textarea',true);check_field('send_email',t('Zusätzlich per E-Mail auf die neue Nachricht hinweisen','Also send an email notification'),$draft['email']??true);submit_button(t('Empfänger und Nachricht prüfen','Review recipients and message'));?></form></section>
<?php endif ?>
