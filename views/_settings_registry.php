<?php
// Renders whatever app/defaults.php declares for this group. Adding a setting
// there makes it appear here with no change to this file.
$specs=settings_in_group($tab);
if(!$specs):?><p class="muted"><?=e(t('Für diesen Bereich gibt es keine Vorgaben.','No defaults in this group.'))?></p><?php else:?>
<section class="card">
    <p class="muted"><?=e(t('Leere Felder fallen auf die eingebaute Vorgabe zurück, damit nie ein undefinierter Wert entsteht.','An empty field falls back to the built-in default, so no value is ever undefined.'))?></p>
    <?php start_form('defaults_registry_save',['group'=>$tab]); ?>
    <div class="grid two">
    <?php foreach($specs as $key=>$spec):
        $value=setting($key); $label=setting_label($spec); $hint=setting_hint($spec); $name='set_'.$key;
        switch($spec['kind']):
            case 'bool': echo '<div class="field">';check_field($name,$label,(bool)$value);if($hint)echo '<small>'.e($hint).'</small>';echo '</div>';break;
            case 'int': input($name,$label,(string)(int)$value,'number',false,$hint);break;
            case 'longtext': echo '<div class="full">';input($name,$label,(string)$value,'textarea',false,$hint);echo '</div>';break;
            case 'list': input($name,$label,implode("\n",(array)$value),'textarea',false,$hint?:t('Ein Eintrag pro Zeile.','One item per line.'));break;
            case 'choice':
                $options=(array)setting($spec['options']);
                select_field($name,$label,$options,(string)$value,true);
                if($hint)echo '<small>'.e($hint).'</small>';
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
            default: input($name,$label,(string)$value,'text',!empty($spec['required']),$hint);
        endswitch;
    endforeach ?>
    </div>
    <?php submit_button(); ?></form>
</section>
<?php endif ?>
