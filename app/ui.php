<?php
declare(strict_types=1);

function icon(string $name): string {
    $paths=[
        'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/><path d="M9 21v-8h6v8"/>',
        'users'=>'<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M17 4a4 4 0 0 1 0 8m2 3a6 6 0 0 1 3 6"/>',
        'mail'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'news'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h4M8 18h8"/>',
        'settings'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
        'wallet'=>'<path d="M20 8V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v12H5a3 3 0 0 1-3-3V6"/><path d="M20 12h-5v5h5"/>',
        'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'logout'=>'<path d="M9 3H4v18h5m5-14 5 5-5 5M8 12h11"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'more'=>'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    ];
    return '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['arrow']).'</svg>';
}
function start_form(string $action,array $hidden=[],string $class='form'): void {
    global $page;
    form_open($action,$hidden+['return_page'=>$page??'dashboard','return_id'=>(int)($_GET['id']??0),'return_tab'=>(string)($_GET['tab']??'')],$class);
}
/**
 * A labelled form field.
 *
 * $placeholder is for compact rows where a visible label would crowd the
 * layout; the label is still rendered for screen readers rather than dropped,
 * because a bare box tells a sighted user nothing either.
 */
function input(string $name,string $label,mixed $value='',string $type='text',bool $required=false,string $hint='',string $placeholder=''): void {
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    $labelClass=$label===''?' class="visually-hidden"':'';
    echo '<div class="field"><label'.$labelClass.' for="'.e($id).'">'.e($label!==''?$label:($placeholder!==''?$placeholder:$name)).($required?' <span aria-hidden="true">*</span>':'').'</label>';
    $ph=$placeholder!==''?' placeholder="'.e($placeholder).'"':'';
    if($type==='textarea')echo '<textarea id="'.e($id).'" name="'.e($name).'" rows="5"'.$ph.' '.($required?'required':'').'>'.e($value).'</textarea>';
    else echo '<input id="'.e($id).'" name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"'.$ph.' '.($required?'required ':'').($type==='password'?'autocomplete="new-password" minlength="12" maxlength="72"':'').($type==='number'?' step="any"':'').'>';
    if($hint)echo '<small>'.e($hint).'</small>';echo '</div>';
}
function select_field(string $name,string $label,array $options,mixed $value='',bool $required=false,bool $multiple=false): void {
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    echo '<div class="field"><label for="'.e($id).'">'.e($label).($required?' *':'').'</label><select id="'.e($id).'" name="'.e($name).($multiple?'[]':'').'" '.($required?'required ':'').($multiple?'multiple size="4"':'').'>';
    if(!$multiple)echo '<option value="">'.e(t('Auswählen','Select')).'</option>';
    foreach($options as $k=>$v)echo '<option value="'.e($k).'" '.(($multiple?in_array((string)$k,array_map('strval',is_array($value)?$value:[]),true):(string)$k===(string)$value)?'selected':'').'>'.e($v).'</option>';
    echo '</select></div>';
}
function check_field(string $name,string $label,bool $value=false): void { echo '<label class="check"><input type="checkbox" name="'.e($name).'" value="1" '.($value?'checked':'').'><span>'.e($label).'</span></label>'; }
function submit_button(string $label='',string $class='primary'): void { echo '<button class="button '.e($class).'" type="submit">'.e($label?:t('Speichern','Save')).'</button>'; }
function page_head(string $title,string $description='',string $action=''): void { echo '<div class="page-heading"><div><h1>'.e($title).'</h1>'.($description?'<p class="muted">'.e($description).'</p>':'').'</div>'.$action.'</div>'; }
function link_button(string $label,string $page,array $params=[],string $class='primary'): string { return '<a class="button '.e($class).'" href="'.e(url($page,$params)).'">'.e($label).'</a>'; }
function empty_state(string $title,string $body='',string $action=''): void { echo '<div class="empty"><div class="empty-icon">'.icon('users').'</div><h2>'.e($title).'</h2>'.($body?'<p>'.e($body).'</p>':'').$action.'</div>'; }
function tabs(array $items,string $active,string $page,array $params=[]): void { echo '<nav class="tabs" aria-label="'.e(t('Bereiche','Sections')).'">';foreach($items as $key=>$label)echo '<a '.($key===$active?'aria-current="page"':'').' href="'.e(url($page,['tab'=>$key]+$params)).'">'.e($label).'</a>';echo '</nav>'; }
function badge(string $text,string $style=''): void {echo '<span class="badge '.e($style).'">'.e($text).'</span>';}
/**
 * One student in a list.
 *
 * $s['due_cents'] may be supplied by a caller that resolved every balance in one
 * query; without it the card falls back to looking up its own, which is correct
 * but costs a query per card.
 */
function student_card(array $s): void {
    $due=array_key_exists('due_cents',$s)?(int)$s['due_cents']:balance((int)$s['id'],true);
    echo '<a class="student-card" href="'.e(url('student',['id'=>$s['id']])).'"><span class="avatar">'.e(mb_substr($s['first_name'],0,1).mb_substr($s['last_name'],0,1)).'</span><div class="student-card-name"><h3>'.e($s['first_name'].' '.$s['last_name']).'</h3><p>'.e($s['tariff_name']?:t('Kein Tarif','No tariff')).($s['price_cents']!==null?' · '.e(money((int)$s['price_cents'])):'').'</p></div><div class="student-card-status">';
    badge(status_label($s['status']),$s['status']==='active'?'green':'');
    if($due)echo '<span class="due">'.e(money($due)).' '.e(t('überfällig','overdue')).'</span>';
    echo '</div>'.icon('arrow').'</a>';
}
function render_filters(array $f,string $target='students'): void {
    echo '<form method="get" class="filters"><input type="hidden" name="page" value="'.e($target).'">';
    input('q',t('Suche','Search'),$f['q']??'','search');
    select_field('status',t('Mitgliedschaft','Membership'),array_combine(array_keys(statuses()),array_map('status_label',array_keys(statuses()))),$f['status']??'');
    select_field('absence',t('Aktuell abwesend','Currently absent'),array_combine(array_keys(reasons()),array_map('reason_label',array_keys(reasons()))),$f['absence']??'');
    select_field('tariff',t('Tarif','Tariff'),array_column(rows('SELECT id,name FROM tariffs ORDER BY name'),'name','id'),$f['tariff']??'');
    echo '<details class="filter-more" '.(!empty($f['field'])?'open':'').'><summary>'.e(t('Weitere Filter','More filters')).'</summary><div class="grid two">';
    select_field('field',t('Eigenes Feld','Custom field'),array_column(field_definitions(),'label','id'),$f['field']??'');input('value',t('Wert entspricht','Value equals'),$f['value']??'');echo '</div></details>';
    check_field('overdue',t('Nur überfällige Beiträge','Overdue charges only'),!empty($f['overdue']));
    submit_button(t('Filtern','Filter'),'secondary');echo '</form>';
}
