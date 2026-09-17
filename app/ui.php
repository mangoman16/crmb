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
        'bell'=>'<path d="M18 9a6 6 0 1 0-12 0c0 6-3 7-3 7h18s-3-1-3-7"/><path d="M13.7 20a2 2 0 0 1-3.4 0"/>',
        'camera'=>'<path d="M3 8a2 2 0 0 1 2-2h2l1.4-2h7.2L17 6h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><circle cx="12" cy="13" r="3.5"/>',
        'eye'=>'<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'mic'=>'<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
        'help'=>'<circle cx="12" cy="12" r="9"/><path d="M9.2 9.3a2.9 2.9 0 0 1 5.6 1c0 1.9-2.8 2.2-2.8 4"/><path d="M12 17.3v.01"/>',
    ];
    return '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['arrow']).'</svg>';
}
function start_form(string $action,array $hidden=[],string $class='form',bool $multipart=false): void {
    form_open($action,$hidden+['return_page'=>current_page(),'return_id'=>(int)($_GET['id']??0),'return_tab'=>(string)($_GET['tab']??'')],$class,$multipart);
}

/**
 * A file picker, with the real limit written under it.
 *
 * The limit shown is upload_limit(), which is the smaller of what the operator
 * asked for and what this server will actually accept - because a form that
 * promises more than PHP allows fails in a way that looks like a broken portal.
 */
function file_field(string $name,string $label,string $kind='proof',string $hint=''): void {
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    echo '<div class="field"><label for="'.e($id).'">'.e($label).'</label>';
    echo '<input id="'.e($id).'" name="'.e($name).'" type="file" accept="'.e(implode(',',array_keys(upload_types($kind)))).'">';
    echo '<small>'.e(($hint?$hint.' ':'').t('Höchstens ','At most ').upload_limit_label().'.').'</small></div>';
}
/**
 * A saved filter, in the words that made it.
 *
 * A chip called "Montag" tells her nothing a month later. This says what the
 * view actually selects, which is also how she notices that two of them are the
 * same view under different names.
 */
function filter_summary(array $f): string {
    $parts=[];
    if(!empty($f['q']))        $parts[]=t('Suche: ','Search: ').$f['q'];
    if(!empty($f['course']))   $parts[]=(string)(scalar('SELECT name FROM classes WHERE id=?',[(int)$f['course']])?:t('Kurs','Course'));
    if(!empty($f['level']))    $parts[]=level_name((int)$f['level']);
    if(!empty($f['age_group']))$parts[]=(string)(scalar('SELECT name FROM age_groups WHERE id=?',[(int)$f['age_group']])?:'');
    if(!empty($f['status']))   $parts[]=status_label((string)$f['status']);
    if(!empty($f['absence']))  $parts[]=t('abwesend: ','away: ').reason_label((string)$f['absence']);
    if(!empty($f['tariff']))   $parts[]=(string)(scalar('SELECT name FROM tariffs WHERE id=?',[(int)$f['tariff']])?:'');
    if(!empty($f['overdue']))  $parts[]=t('überfällig','overdue');
    return $parts?implode(' · ',array_filter($parts)):t('alle Schüler','all students');
}

/**
 * A labelled form field.
 *
 * $placeholder is for compact rows where a visible label would crowd the
 * layout; the label is still rendered for screen readers rather than dropped,
 * because a bare box tells a sighted user nothing either.
 */
function input(string $name,string $label,mixed $value='',string $type='text',bool $required=false,string $hint='',string $placeholder=''): void {
    // What she typed wins over what the record holds, so a form rejected for one
    // bad character comes back filled in rather than blank.
    $held=held_input($name,$value); if(!is_array($held)) $value=$held;
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    $labelClass=$label===''?' class="visually-hidden"':'';
    echo '<div class="field"><label'.$labelClass.' for="'.e($id).'">'.e($label!==''?$label:($placeholder!==''?$placeholder:$name)).($required?' <span aria-hidden="true">*</span>':'').'</label>';
    $ph=$placeholder!==''?' placeholder="'.e($placeholder).'"':'';
    if($type==='textarea')echo '<textarea id="'.e($id).'" name="'.e($name).'" rows="5"'.$ph.' '.($required?'required':'').'>'.e($value).'</textarea>';
    else echo '<input id="'.e($id).'" name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"'.$ph.' '.($required?'required ':'').($type==='password'?'autocomplete="new-password" minlength="12" maxlength="72"':'').($type==='number'?' step="any"':'').'>';
    if($hint)echo '<small>'.e($hint).'</small>';echo '</div>';
}
function select_field(string $name,string $label,array $options,mixed $value='',bool $required=false,bool $multiple=false): void {
    $value=held_input($name,$value);
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    echo '<div class="field"><label for="'.e($id).'">'.e($label).($required?' *':'').'</label><select id="'.e($id).'" name="'.e($name).($multiple?'[]':'').'" '.($required?'required ':'').($multiple?'multiple size="4"':'').'>';
    if(!$multiple) echo select_options(['' => t('Auswählen','Select')]+$options,$value);
    else foreach($options as $k=>$v)
        echo '<option value="'.e($k).'" '.(in_array((string)$k,array_map('strval',is_array($value)?$value:[]),true)?'selected':'').'>'.e($v).'</option>';
    echo '</select></div>';
}
function check_field(string $name,string $label,bool $value=false): void {
    // An unticked box sends nothing at all, so "held, and absent" means unticked
    // rather than "no opinion" - checking holding_input() first is what tells the
    // two apart.
    if(holding_input()) $value=held_input($name,null)!==null;
    echo '<label class="check"><input type="checkbox" name="'.e($name).'" value="1" '.($value?'checked':'').'><span>'.e($label).'</span></label>';
}

/**
 * A field that falls back to a default when it is left empty.
 *
 * "Leer lassen, um den Standardpreis des Tarifs zu übernehmen" told her there was
 * a default without ever telling her what it was, so the only way to find out was
 * to save and look. The default is now printed next to the field, a value that
 * differs from it is marked as her own, and one button puts it back.
 *
 * $defaultValue is what goes into the box when she presses that button;
 * $defaultLabel is how the default reads to a person - money, a date, a name.
 */
function default_field(string $name,string $label,mixed $value,string $defaultValue,string $defaultLabel,string $type='text',string $hint=''): void {
    $shown=(string)held_input($name,$value);
    $isDefault=$shown==='';
    echo '<div class="with-default'.($isDefault?' is-default':'').'" data-default="'.e($defaultValue).'">';
    input($name,$label,$shown,$type,false,$hint,$defaultLabel);
    echo '<p class="default-note">'
        .'<span>'.e($isDefault?t('Standard wird übernommen: ','Using the default: '):t('Standard: ','Default: ')).'<strong>'.e($defaultLabel!==''?$defaultLabel:t('keiner','none')).'</strong></span>';
    // Without JavaScript the default is still readable, which is the part that
    // was missing; the button is the convenience on top of it.
    if($defaultValue!=='') echo '<button type="button" class="chip default-reset" hidden>'.e(t('Standard übernehmen','Use the default')).'</button>';
    echo '</p></div>';
}
/**
 * The button that sends a form.
 *
 * $name and $value are for the rare form that does two things - "test the
 * connection" and "send a test email" ask the same questions and differ in one
 * word - so that it stays one form with one set of fields rather than two forms
 * whose fields have to be kept in step.
 */
function submit_button(string $label='',string $class='primary',string $name='',string $value=''): void {
    echo '<button class="button '.e($class).'" type="submit"'
        .($name!==''?' name="'.e($name).'" value="'.e($value).'"':'').'>'
        .e($label?:t('Speichern','Save')).'</button>';
}
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
    echo '<a class="student-card" href="'.e(url('student',['id'=>$s['id']])).'">'.avatar($s,'','student').'<div class="student-card-name"><h3>'.e($s['first_name'].' '.$s['last_name']).'</h3><p>'.e(implode(' · ',array_filter([$s['level_name']??'',age_group_name($s),$s['tariff_name']?:t('Kein Tarif','No tariff')]))).'</p></div><div class="student-card-status">';
    badge(status_label($s['status']),$s['status']==='active'?'green':'');
    if($due)echo '<span class="due">'.e(money($due)).' '.e(t('überfällig','overdue')).'</span>';
    echo '</div>'.icon('arrow').'</a>';
}
function render_filters(array $f,string $target='students'): void {
    // A GET form is never rejected, so it has no held submission to offer back.
    // Saying so explicitly keeps the fields below from picking up the context of
    // whichever form was written out before them.
    form_context('');
    echo '<form method="get" class="filters"><input type="hidden" name="page" value="'.e($target).'">';
    input('q',t('Suche','Search'),$f['q']??'','search');
    select_field('status',t('Mitgliedschaft','Membership'),array_combine(array_keys(statuses()),array_map('status_label',array_keys(statuses()))),$f['status']??'');
    select_field('absence',t('Aktuell abwesend','Currently absent'),array_combine(array_keys(reasons()),array_map('reason_label',array_keys(reasons()))),$f['absence']??'');
    select_field('course',t('Kurs','Course'),array_column(training_classes(),'name','id'),$f['course']??'');
    select_field('level',t('Leistungsgruppe','Level'),array_column(levels(),'name','id'),$f['level']??'');
    select_field('age_group',t('Altersgruppe','Age group'),array_column(age_groups(),'name','id'),$f['age_group']??'');
    echo '<details class="filter-more" '.(!empty($f['tariff'])?'open':'').'><summary>'.e(t('Tarif und eigene Felder','Tariff and custom fields')).'</summary><div class="grid two">';
    select_field('tariff',t('Tarif','Tariff'),array_column(rows('SELECT id,name FROM tariffs ORDER BY name'),'name','id'),$f['tariff']??'');
    select_field('field',t('Eigenes Feld','Custom field'),array_column(field_definitions(),'label','id'),$f['field']??'');
    input('value',t('Wert entspricht','Value equals'),$f['value']??'');echo '</div></details>';
    check_field('overdue',t('Nur überfällige Beiträge','Overdue charges only'),!empty($f['overdue']));
    submit_button(t('Filtern','Filter'),'secondary');echo '</form>';
}

/**
 * The main menu, as sections rather than one long list.
 *
 * An administrator has thirteen destinations. In a row they made the panel
 * taller than a laptop window at 110% zoom, and a menu you have to scroll is a
 * menu whose last three entries nobody finds. So the six that clearly belong to
 * a subject sit inside it, and only the section you are working in is open.
 *
 * What stays at the top level is what she reaches for without thinking:
 * Übersicht, Schüler, Nachrichten, Neuigkeiten, and the lists under Verwaltung.
 *
 * Returns an ordered list of entries, each either
 *   ['route'=>…, 'icon'=>…, 'label'=>…, 'count'=>int]   a destination, or
 *   ['section'=>…, 'icon'=>…, 'label'=>…, 'items'=>[…]] a section of them.
 */
function nav_entries(array $user): array {
    $staff=is_staff($user); $admin=is_admin($user);
    $entry=fn(string $route,string $symbol,string $label,int $count=0)=>
        ['route'=>$route,'icon'=>$symbol,'label'=>$label,'count'=>$count];
    $out=[$entry('dashboard','home',t('Übersicht','Overview')),
          $entry('students','users',t('Schüler','Students'))];
    if($staff) {
        $out[]=['section'=>'training','icon'=>'calendar','label'=>t('Training','Training'),'items'=>[
            $entry('classes','calendar',t('Kurse','Courses'),pending_request_count()),
            $entry('attendance','check',t('Anwesenheit','Attendance'))]];
        $out[]=['section'=>'money','icon'=>'wallet','label'=>t('Geld','Money'),'items'=>[
            $entry('payments','wallet',t('Beiträge','Payments')),
            $entry('invoices','news',t('Rechnungen','Invoices'))]];
    }
    $out[]=$entry('messages','mail',t('Nachrichten','Messages'),unread_count($user));
    $out[]=$entry('news','news',t('Neuigkeiten','News'));
    if($staff) {
        $out[]=$entry('manage','settings',t('Verwaltung','Management'));
        $system=[$entry('accounts','lock',t('Konten','Accounts')),
                 $entry('outbox','mail',t('Postausgang','Outbox'))];
        if($admin) {
            $system[]=$entry('history','calendar',t('Änderungen','Changes'));
            $system[]=$entry('settings','settings',t('Einstellungen','Settings'));
        }
        $out[]=['section'=>'system','icon'=>'lock','label'=>t('System','System'),'items'=>$system];
    }
    return $out;
}

/**
 * Whether a menu entry is the page being looked at.
 *
 * Three pages have no entry of their own because they are opened from one:
 * a single student, a new message, and the page that is not there.
 */
function nav_is_current(string $route,string $page): bool {
    return $route===$page
        || ($route==='students' && $page==='student')
        || ($route==='messages' && $page==='compose');
}

/** One menu row: the link, its label, and the number waiting behind it. */
function nav_link(array $item,string $page): string {
    $count=(int)($item['count']??0);
    return '<a href="'.e(url($item['route'])).'" '.(nav_is_current($item['route'],$page)?'aria-current="page"':'').'>'
        .icon($item['icon']).'<span>'.e($item['label']).'</span>'
        .($count?'<span class="count" aria-label="'.e($count.' '.t('wartet','waiting')).'">'.e((string)$count).'</span>':'')
        .'</a>';
}

/**
 * The main menu as markup.
 *
 * The open section is decided here rather than in the browser, so the menu is
 * already showing where you are on the first paint and without JavaScript. The
 * name attribute makes the browser close the other sections when one is opened,
 * which is what keeps the panel one section tall; a browser too old for it
 * simply lets two stand open.
 */
function sidebar_nav(array $user,string $page): string {
    $html='<nav aria-label="'.e(t('Hauptmenü','Main menu')).'">';
    foreach(nav_entries($user) as $entry) {
        if(isset($entry['route'])) {$html.=nav_link($entry,$page);continue;}
        $open=false; $waiting=0; $inner='';
        foreach($entry['items'] as $item) {
            $open=$open || nav_is_current($item['route'],$page);
            $waiting+=(int)($item['count']??0);
            $inner.=nav_link($item,$page);
        }
        $html.='<details class="nav-section" name="nav-section"'.($open?' open':'').'>'
            .'<summary>'.icon($entry['icon']).'<span>'.e($entry['label']).'</span>'
            // Shown by the stylesheet only while the section is closed: the count
            // is on the entry itself once you can see the entry.
            .($waiting?'<span class="count section-count" aria-label="'.e($waiting.' '.t('wartet','waiting')).'">'.e((string)$waiting).'</span>':'')
            .'<span class="chevron" aria-hidden="true">'.icon('arrow').'</span></summary>'
            .'<div class="nav-sub">'.$inner.'</div></details>';
    }
    return $html.'</nav>';
}

/**
 * The fields of one contact person.
 *
 * One copy for adding and for editing. They were written out twice and drifted:
 * the same box was "Name" on one form and "Wem gehört der Kontakt?" on the
 * other, which reads as a question about ownership rather than a request for
 * the person's name - and "Beziehung, z. B. Mutter" put the example inside the
 * label, where it stays on screen after the box has been filled in.
 *
 * $standard says whether this contact is, or would become, the one invoices and
 * reminders are sent to, which is the only reason the email address is required.
 */
function contact_fields(array $contact=[], bool $standard=false): void {
    echo '<div class="grid two">';
    input('owner_name',t('Name der Kontaktperson','Name of the contact'),$contact['owner_name']??'','text',true,
          '',t('z. B. Maria Hofer','e.g. Maria Hofer'));
    input('relation_label',t('Beziehung zum Kind','Relationship to the child'),$contact['relation_label']??'','text',true,
          '',t('z. B. Mutter','e.g. mother'));
    input('phone',t('Telefonnummer','Phone number'),$contact['phone']??'','tel');
    input('email',t('E-Mail-Adresse','Email address'),$contact['email']??'','email',$standard,
          $standard?t('Dorthin gehen Rechnungen und Erinnerungen.','Invoices and reminders go here.'):'');
    echo '</div>';
}

/**
 * The choices in the minute box: every five minutes, plus whatever is stored.
 *
 * Training starts at half past, not at 17:37, so twelve choices are a wheel you
 * can flick rather than one you have to aim at. A time already in the database
 * that is not on the grid is kept in the list, or saving an unrelated change
 * would quietly move it.
 */
function minute_options(string $current=''): array {
    $out=[];
    for($m=0;$m<60;$m+=5) $out[]=sprintf('%02d',$m);
    if($current!=='' && !in_array($current,$out,true)) { $out[]=$current; sort($out); }
    return array_combine($out,$out);
}

/**
 * A time of day, as an hour box and a minute box.
 *
 * Not <input type="time">: that one renders in the language of the phone or the
 * computer rather than of the page, so a device set to English shows "05:30 PM"
 * however the portal is set - and a trainer reading 17:30 off a hall timetable
 * should not have to translate it. Two boxes are the same 24-hour time on every
 * device, and on a phone they are the same native wheel the picker would have
 * been.
 *
 * Posts as <name>_h and <name>_m; posted_time() puts them back together.
 */
function time_field(string $name,string $label,string $value='',bool $required=false,string $hint=''): void {
    [$hour,$minute]=time_parts($value);
    $hour=(string)held_input($name.'_h',$hour); $minute=(string)held_input($name.'_m',$minute);
    $blank=$required?[]:['' => '–'];
    $hours=[]; for($h=0;$h<24;$h++) $hours[sprintf('%02d',$h)]=sprintf('%02d',$h);
    echo '<div class="field"><label for="'.e($name).'_h">'.e($label).($required?' <span aria-hidden="true">*</span>':'').'</label>'
        .'<div class="time-field">'
        .'<select name="'.e($name).'_h" id="'.e($name).'_h" aria-label="'.e($label.' – '.t('Stunde','hour')).'">'
        .select_options($blank+$hours,$hour).'</select>'
        .'<span class="time-colon" aria-hidden="true">:</span>'
        .'<select name="'.e($name).'_m" aria-label="'.e($label.' – '.t('Minute','minute')).'">'
        .select_options($blank+minute_options($minute),$minute).'</select>'
        .'</div>'.($hint?'<small>'.e($hint).'</small>':'').'</div>';
}

/** The two boxes of a repeating row, without the label a single field carries. */
function time_cells(string $name,string $label,string $value=''): string {
    [$hour,$minute]=time_parts($value);
    $hours=['' => '–']; for($h=0;$h<24;$h++) $hours[sprintf('%02d',$h)]=sprintf('%02d',$h);
    return '<span class="time-field">'
        .'<select name="'.e($name).'_h[]" aria-label="'.e($label.' – '.t('Stunde','hour')).'">'.select_options($hours,$hour).'</select>'
        .'<span class="time-colon" aria-hidden="true">:</span>'
        .'<select name="'.e($name).'_m[]" aria-label="'.e($label.' – '.t('Minute','minute')).'">'
        .select_options(['' => '–']+minute_options($minute),$minute).'</select></span>';
}

/** The <option> list of a select, escaped. */
function select_options(array $options,mixed $value): string {
    $out='';
    foreach($options as $key=>$label)
        $out.='<option value="'.e((string)$key).'" '.((string)$key===(string)$value?'selected':'').'>'.e((string)$label).'</option>';
    return $out;
}
