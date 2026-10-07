<?php
// Renders whatever app/defaults.php declares for one group. Adding a setting
// there makes it appear here with no change to this file.
//
// $registryGroup names the group when it differs from the tab showing it, which
// is how the same declarations serve both Einstellungen and Verwaltung without a
// second copy of this form. A second group on one tab (the „Aussehen" card on
// Portal) also sets $registryCard: the card's id, and what to draw above the
// form - its heading and whatever explains it.
$registryGroup=$registryGroup??$tab;
$registryCard=$registryCard??null;
$specs=settings_in_group($registryGroup);
// Most portals never touch these, so they wait in one „Erweitert" block rather
// than standing among the questions a new portal has to answer (ADR 0011).
$advanced=array_filter($specs,fn($spec)=>!empty($spec['advanced']));
$everyday=array_diff_key($specs,$advanced);
$registryField=function(string $key,array $spec): void {
    $value=setting($key); $label=setting_label($spec); $hint=setting_hint($spec); $name='set_'.$key;
    switch($spec['kind']):
        case 'bool': echo '<div class="field">';check_field($name,$label,(bool)$value);if($hint)echo '<small>'.e($hint).'</small>';echo '</div>';break;
        case 'int': input($name,$label,(string)(int)$value,'number',false,$hint);break;
        case 'longtext': echo '<div class="full">';input($name,$label,(string)$value,'textarea',false,$hint);echo '</div>';break;
        case 'list': input($name,$label,implode("\n",(array)$value),'textarea',false,$hint?:t('Ein Eintrag pro Zeile.','One item per line.'));break;
        case 'choice':
            select_field($name,$label,(array)setting($spec['options']),(string)$value,true);
            if($hint)echo '<small>'.e($hint).'</small>';
            break;
        case 'reference':
            // „keiner" is a real answer, so it is an option of its own rather
            // than the empty "Auswählen". A stored id that is no longer offered
            // (archived since) shows as none, which is what it now resolves to.
            $options=setting_reference_options($spec);
            $current=isset($options[(int)$value])?(string)(int)$value:'0';
            $current=(string)held_input($name,$current);
            $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name);
            echo '<div class="field"><label for="'.e($id).'">'.e($label).'</label><select id="'.e($id).'" name="'.e($name).'">'
                .select_options(['0'=>t('keiner','none')]+$options,$current).'</select>';
            if($hint)echo '<small>'.e($hint).'</small>';
            echo '</div>';
            break;
        case 'map': ?>
            <div class="full">
                <h3><?=e($label)?></h3>
                <div class="option-editor" data-option-editor="<?=e($key)?>">
                <?php foreach((array)$value as $code=>$text): ?>
                    <div class="option-row">
                        <input type="hidden" name="<?=e($key)?>_keys[]" value="<?=e($code)?>">
                        <input name="<?=e($key)?>_labels[]" value="<?=e($text)?>" aria-label="<?=e($label)?>">
                    </div>
                <?php endforeach ?>
                    <div class="option-row">
                        <input type="hidden" name="<?=e($key)?>_keys[]" value="">
                        <input name="<?=e($key)?>_labels[]" placeholder="<?=e(t('Neue Bezeichnung','New label'))?>" aria-label="<?=e(t('Neue Bezeichnung','New label'))?>">
                    </div>
                </div>
                <button type="button" class="button secondary" data-add-option="<?=e($key)?>"><?=e(t('+ Weiterer Eintrag','+ Another entry'))?></button>
                <?php if($hint)echo '<small>'.e($hint).'</small>';?>
                <small><?=e(t('Bezeichnung leeren, um einen nicht verwendeten Eintrag zu entfernen.','Clear a label to remove an unused entry.'))?></small>
            </div>
        <?php break;
        case 'colour':
            /* Empty is the built-in colour, so the box shows that as its
               placeholder and „Standard übernehmen" empties it. Under it, the
               colour the portal really uses - which readability may have
               moved away from hers - on a swatch coloured by class, because a
               style attribute is refused (ADR 0013). */
            $palette=brand_palette(); $used=$palette['used'][$key]; $fallback=$palette['placeholder'][$key];
            echo '<div class="colour-field" data-colour="'.e($used).'" data-picker-label="'.e(t('Farbe auswählen: ','Pick a colour: ').$label).'">';
            default_field($name,$label,(string)$value,$fallback,$fallback,'text',$hint);
            $moved=$palette['adjusted'][$key]??null;
            echo '<p class="colour-used"><span class="brand-swatch '.e(brand_swatch_class($key)).'"></span><span>'
                .e($moved?t('Für gute Lesbarkeit verwendet: ','Used for readability: '):t('Verwendet: ','In use: ')).'<strong>'.e($used).'</strong>';
            if($moved) echo e(t(' – deine Wahl: ',' – your choice: ')).'<span class="brand-swatch '.e(brand_swatch_class($key,true)).'"></span><strong>'.e($moved['chosen']).'</strong>';
            echo '</span></p></div>';
            break;
        default: input($name,$label,(string)$value,'text',!empty($spec['required']),$hint);
    endswitch;
};
if(!$specs):?><p class="muted"><?=e(t('Für diesen Bereich gibt es keine Vorgaben.','No defaults in this group.'))?></p><?php else:?>
<section class="card"<?=$registryCard?' id="'.e($registryCard['id']).'"':''?>>
    <?php if($registryCard)($registryCard['head'])(); ?>
    <?php start_form('defaults_registry_save',['group'=>$registryGroup,'to_page'=>current_page(),'to_tab'=>$tab]); ?>
    <?php if($everyday): ?><div class="grid two"><?php foreach($everyday as $key=>$spec)$registryField($key,$spec); ?></div><?php endif ?>
    <?php if($advanced):
    /* Opened by the hub's „Erweitert" link (open=advanced), so the way there
       does not end at a closed box; a fragment alone cannot open it without
       JavaScript. */ ?>
    <details class="advanced-settings" id="<?=e($registryCard?'advanced-'.$registryCard['id']:'advanced')?>" <?=($_GET['open']??'')==='advanced'?'open':''?>><summary><?=e(t('Erweitert','Advanced'))?></summary>
        <p class="muted"><?=e(t('Selten gebraucht. Die Vorgaben passen für die meisten Portale.','Rarely needed. The defaults suit most portals.'))?></p>
        <div class="grid two"><?php foreach($advanced as $key=>$spec)$registryField($key,$spec); ?></div>
    </details>
    <?php endif ?>
    <?php submit_button(); ?></form>
</section>
<?php endif ?>
