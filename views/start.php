<?php
/* Dein Portal einrichten (ADR 0011): the nine things a new portal needs before
   families come, as a checklist rather than a stepper - she does the bank
   details on the evening the letter arrives, not in step order. This page only
   reads. Every tick comes from setup_steps(), which asks the same functions the
   linked pages ask, so it cannot call something done that the page itself
   still complains about; there is nothing here to tick by hand. */
$steps=setup_steps();$progress=setup_progress();$hidden=(bool)setting('setup_hidden');
$next=$progress['next']['key']??null;
// Each step's number and whether it is done, so "Geht, sobald Schritt 7 und 8
// erledigt sind" is worked out from the list rather than written into it.
$numbers=[];foreach($steps as $i=>$step)$numbers[$step['key']]=['n'=>$i+1,'done'=>$step['done']];
foreach($steps as &$step)$step['next']=$step['key']===$next;unset($step);
page_head(t('Dein Portal einrichten','Set up your portal'),
    t('Einmal erledigen, bevor die Familien kommen. Das Häkchen setzt das Portal selbst, sobald etwas eingetragen ist.',
      'Do this once, before the families arrive. The portal ticks each step itself as soon as something is entered.'));
?>
<div class="setup-progress">
    <p><strong><?=e($progress['done'].' '.t('von','of').' '.$progress['total'].' '.t('erledigt','done'))?></strong></p>
    <progress max="<?=(int)$progress['total']?>" value="<?=(int)$progress['done']?>" aria-hidden="true"></progress>
</div>
<?php /* Example data is made to be looked at, not counted: a portal holding only
         the pretend children has nothing set up, and families must not be
         invited into it. */
if(demo_present()): ?>
<div class="notice warn setup-demo">
    <p><?=e(t('Beispieldaten sind noch im Portal. Sie zählen hier nicht mit. Entferne sie, bevor du Familien einlädst.','Example data is still in the portal. It does not count here. Remove it before you invite families.'))?></p>
    <?php start_form('demo_data',['mode'=>'clear','from'=>'start'],'inline-form');submit_button(t('Beispieldaten entfernen','Remove the example data'),'secondary');?></form>
</div>
<?php /* Filling from „Wenn du magst" (via the System tab) returns here, and its
         message says the password is shown below. */
demo_password_notice(); ?>
<?php endif ?>
<?php if($progress['next']===null): ?>
<div class="notice setup-done">
    <p><strong><?=e(t('Alles eingerichtet. Die Familien können kommen.','Everything is set up. The families can come.'))?></strong></p>
    <?php if(!$hidden){start_form('setup_visibility',['hidden'=>'1'],'inline-form');submit_button(t('Ausblenden','Hide'),'secondary');echo '</form>';} ?>
</div>
<?php endif ?>
<?php if($hidden): ?>
<div class="notice">
    <p><?=e(t('Die Einrichtung ist ausgeblendet: nicht im Menü und nicht auf der Übersicht.','The setup is hidden: not in the menu and not on the overview.'))?></p>
    <?php start_form('setup_visibility',['hidden'=>'0'],'inline-form');submit_button(t('Wieder anzeigen','Show again'),'secondary');?></form>
</div>
<?php endif ?>
<?php
$byKey=array_column($steps,null,'key');
$groups=[[t('Über dich','About you'),['organisation','bank']],
         [t('Dein Training','Your training'),['first_course','course_prices','students','billing']],
         [t('Familien ins Portal holen','Bring the families in'),['mail','privacy','invite']]];
foreach($groups as [$heading,$keys]):
    $group=array_values(array_filter(array_map(fn($k)=>$byKey[$k]??null,$keys)));
    if($group)next_steps_card($group,$heading,$numbers[$group[0]['key']]['n'],$numbers);
endforeach;
/* Not needed to start, and each already has its own page: listed so she knows
   they exist, and carrying from=start so the way back is there afterwards. */
$optional=[];
if(!demo_present())$optional[]=[t('Mit Beispieldaten ausprobieren','Try it out with example data'),'settings',['tab'=>'system'],'demo'];
$optional[]=[t('Name des Portals ändern (jetzt: „','Change the portal’s name (now: “').setting('club_name','Badminton').t('“)','”)'),'settings',['tab'=>'portal'],''];
$optional[]=[t('Symbol und Farbe','Icon and colour'),'settings',['tab'=>'portal'],'icon'];
$optional[]=[t('Eine zweite Trainerin einladen','Invite a second trainer'),'accounts',[],''];
?>
<section class="card setup-optional">
    <h2><?=e(t('Wenn du magst','If you like'))?></h2>
    <ul class="link-list">
    <?php foreach($optional as [$label,$target,$params,$anchor]): ?>
        <li><a href="<?=e(url($target,$params+['from'=>'start']).($anchor!==''?'#'.$anchor:''))?>"><?=e($label)?><?=icon('arrow')?></a></li>
    <?php endforeach ?>
    </ul>
</section>
