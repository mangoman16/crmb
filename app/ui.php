<?php
declare(strict_types=1);

/**
 * One of the portal's icons: a 24-unit grid, round caps and joins, drawn in the
 * text colour (Part 0, C16). The class names the icon drawn, so the stylesheet
 * can size and colour a kind of icon wherever it appears - the chevron that
 * ends a row. glyph-, not icon-: .icon-home is already the home screen drawn on
 * Einstellungen → Portal, and took the tab bar's „Übersicht" for itself. An
 * unknown name draws the arrow rather than nothing.
 */
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
        // The end of a row that leads somewhere, and turned round, „Zurück".
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        // Chats; 'mail' stays for e-mail.
        'chat'=>'<path d="M20.5 11.5c0 4.1-3.8 7.5-8.5 7.5-1.3 0-2.6-.3-3.7-.8L4 19.5l1.3-3.6A7 7 0 0 1 3.5 11.5C3.5 7.4 7.3 4 12 4s8.5 3.4 8.5 7.5Z"/>',
        'person'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        // A banner that says something went wrong; 'check' says it went right.
        'alert'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>',
    ];
    $drawn=isset($paths[$name])?$name:'arrow';
    return '<svg class="glyph-'.$drawn.'" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'.$paths[$drawn].'</svg>';
}
function start_form(string $action,array $hidden=[],string $class='form',bool $multipart=false): void {
    $draft=student_draft_key($_GET['draft']??'') ? ['return_draft'=>$_GET['draft']] : [];
    form_open($action,$hidden+['return_page'=>current_page(),'return_id'=>(int)($_GET['id']??0),'return_tab'=>$_GET['tab']??'']+$draft,$class,$multipart);
}

/**
 * A file picker, with the real limit written under it.
 *
 * The limit shown is upload_limit(), which is the smaller of what the operator
 * asked for and what this server will actually accept - because a form that
 * promises more than PHP allows fails in a way that looks like a broken portal.
 * $maxBytes is a smaller limit of the upload's own, such as the logo's, which
 * is then the one stated.
 */
function file_field(string $name,string $label,string $kind='proof',string $hint='',?int $maxBytes=null): void {
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    echo '<div class="field"><label for="'.e($id).'">'.e($label).'</label>';
    echo '<input id="'.e($id).'" name="'.e($name).'" type="file" accept="'.e(implode(',',array_keys(upload_types($kind)))).'">';
    echo '<small>'.e(($hint?$hint.' ':'').t('Höchstens ','At most ').upload_limit_label($maxBytes).'.').'</small></div>';
}
/**
 * A labelled form field.
 *
 * $placeholder is for compact rows where a visible label would crowd the
 * layout; the label is still rendered for screen readers rather than dropped,
 * because a bare box tells a sighted user nothing either.
 *
 * $attributes adds to the box, each value escaped: a key input() already sets
 * is replaced, and null removes it. A password box asks for a new password by
 * default (autocomplete="new-password", at least 12 characters), which is right
 * for choosing one and wrong for the box that asks for the existing one: an
 * iPhone then offers to invent a password where it should fill in the saved
 * one. Those boxes pass current_password_attributes().
 */
function input(string $name,string $label,mixed $value='',string $type='text',bool $required=false,string $hint='',string $placeholder='',array $attributes=[]): void {
    // What she typed wins over what the record holds, so a form rejected for one
    // bad character comes back filled in rather than blank.
    $held=held_input($name,$value); if(!is_array($held)) $value=$held;
    $defaults=match($type) {
        'password' => ['autocomplete'=>'new-password','minlength'=>'12','maxlength'=>'72'],
        'number'   => ['step'=>'any'],
        default    => [],
    };
    // Built before anything is printed, so a refused name leaves no half a
    // field on the page.
    $extra='';
    foreach(array_merge($defaults,$attributes) as $key=>$attribute) {
        // A name cannot be escaped into safety, only refused: it is always one
        // written in the code, and this makes sure it stays one.
        if(!is_string($key) || !preg_match('/^[a-z][a-z-]*$/D',$key) || in_array($key,['id','name','type','value','required','placeholder'],true))
            throw new LogicException('input() cannot take the attribute '.json_encode($key).'.');
        if($attribute!==null) $extra.=' '.$key.'="'.e((string)$attribute).'"';
    }
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    $labelClass=$label===''?' class="visually-hidden"':'';
    echo '<div class="field"><label'.$labelClass.' for="'.e($id).'">'.e($label!==''?$label:($placeholder!==''?$placeholder:$name)).($required?' <span aria-hidden="true">*</span>':'').'</label>';
    $ph=$placeholder!==''?' placeholder="'.e($placeholder).'"':'';
    if($type==='textarea')echo '<textarea id="'.e($id).'" name="'.e($name).'" rows="5"'.$ph.$extra.($required?' required':'').'>'.e($value).'</textarea>';
    else echo '<input id="'.e($id).'" name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"'.$ph.$extra.($required?' required':'').'>';
    if($hint)echo '<small>'.e($hint).'</small>';echo '</div>';
}

/**
 * What every box the sign-in address is typed into, or read from, carries
 * (ADR 0021, §1): sign-in, „vergessen", the read-only address beside a new
 * password, the invitation and the delete confirmation.
 *
 * autocomplete="username" although it is an address: that is the word the
 * password manager pairs with the password beside it, so an iPhone saves the
 * new password under this address and offers it again at sign-in. And it
 * neither capitalises the first letter, nor "corrects" lena.hofer into a word,
 * nor underlines it as a spelling mistake. No inputmode here: a box drawn as
 * type="email" already brings „@" and „." to the first keyboard layer, and the
 * sign-in and „vergessen" boxes, which are type="text" because they take a
 * username too (ADR 0023 §7), add inputmode="email" themselves. A username box
 * uses these too, so a phone saves the password under the username. Where the
 * browser must not fill in anything - the invitation, the
 * delete confirmation - the caller puts ['autocomplete'=>'off'] first in the
 * union, so it wins.
 */
function sign_in_address_attributes(): array {
    return ['autocomplete'=>'username','autocapitalize'=>'none','autocorrect'=>'off','spellcheck'=>'false'];
}

/** A box that asks for the password somebody already has, not a new one. */
function current_password_attributes(): array {
    return ['autocomplete'=>'current-password','minlength'=>null];
}
function select_field(string $name,string $label,array $options,mixed $value='',bool $required=false,bool $multiple=false,string $hint=''): void {
    $value=held_input($name,$value);
    $id='f_'.preg_replace('/[^a-zA-Z0-9_]/','_',$name).'_'.random_int(1000,9999);
    // The mark as input() prints it, so a required box and a required choice
    // look alike: a plain " *" was ink where every other one is teal.
    echo '<div class="field"><label for="'.e($id).'">'.e($label).($required?' <span aria-hidden="true">*</span>':'').'</label><select id="'.e($id).'" name="'.e($name).($multiple?'[]':'').'" '.($required?'required ':'').($multiple?'multiple size="4"':'').'>';
    if(!$multiple) echo select_options(['' => t('Auswählen','Select')]+$options,$value);
    else foreach($options as $k=>$v)
        echo '<option value="'.e($k).'" '.(in_array((string)$k,array_map('strval',is_array($value)?$value:[]),true)?'selected':'').'>'.e($v).'</option>';
    echo '</select>'.($hint!==''?'<small>'.e($hint).'</small>':'').'</div>';
}
/**
 * A tick box with its label. $hint is said under the label, inside it, where
 * every other field's hint sits and in the same size - and inside the label, so
 * it is part of what a thumb can tap. As a paragraph of its own beside the box
 * it was louder than any hint on the page. $required prints the mark input()
 * prints; the action decides whether an unticked box is refused, so the box
 * itself carries no required attribute.
 *
 * $switch draws it as a switch, label left and switch right, as iOS Settings
 * does (Part 0, C7): for a setting that is on or off - the mail ticks, „Jeden
 * Monat automatisch anlegen", „Im Portal veröffentlichen", an archive tick.
 * Never for an acknowledgement („Ich habe die Datenschutzhinweise gelesen"),
 * which is something one confirms, not something one switches. It is still a
 * checkbox underneath: without CSS a plain one, and it posts the same.
 */
function check_field(string $name,string $label,bool $value=false,string $hint='',bool $required=false,bool $switch=false): void {
    // An unticked box sends nothing at all, so "held, and absent" means unticked
    // rather than "no opinion" - checking holding_input() first is what tells the
    // two apart.
    if(holding_input()) $value=held_input($name,null)!==null;
    echo '<label class="check'.($switch?' switch':'').'"><input type="checkbox"'.($switch?' role="switch"':'').' name="'.e($name).'" value="1" '.($value?'checked':'').'><span>'.e($label).($required?' <span aria-hidden="true">*</span>':'')
        .($hint!==''?'<small>'.e($hint).'</small>':'').'</span></label>';
}

/**
 * Why an invitation cannot go out yet, in one wording for the three places it
 * can be blocked: the create form, a student's access card and Konten (ADR
 * 0020, §10d). account_mail_missing() names the missing steps. The setup
 * checklist is an administrator's page, so a trainer is told who does it
 * instead of being given a link she cannot open. It only reads.
 */
function mail_not_ready_notice(array $user): void {
    $missing=account_mail_missing();
    if($missing==='') return;
    echo '<div class="notice"><strong>'.e(t('Einladen geht noch nicht','Inviting is not possible yet')).'</strong><p>'.e($missing).'</p>';
    if(is_admin($user)) echo '<div class="row-actions">'.link_button(t('Zur Einrichtung','Go to the setup'),'start',[],'secondary').'</div>';
    else echo '<p>'.e(t('Das richtet eine Administratorin unter „Einstellungen“ ein.','An administrator sets this up under “Settings”.')).'</p>';
    echo '</div>';
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
/**
 * The large title a page opens with, the line under it, and its one action.
 * The title is also what the bar at the top shows once it has scrolled away
 * (page_title()).
 */
function page_head(string $title,string $description='',string $action=''): void {
    page_title($title);
    echo '<div class="page-heading"><div><h1>'.e($title).'</h1>'.($description?'<p class="muted">'.e($description).'</p>':'').'</div>'.$action.'</div>';
}
/**
 * The title page_head() was given on this request, '' before it was called.
 * The layout is drawn after the page, so by then it is known. Passing a title
 * records it.
 */
function page_title(?string $title=null): string {
    static $recorded='';
    if($title!==null) $recorded=$title;
    return $recorded;
}
/**
 * A link drawn as a button, to url($page,$params): a '#' among $params is the id
 * on that page it lands on, as url() takes it everywhere else - one way to say
 * it, so no address is built two ways that encode it differently.
 */
function link_button(string $label,string $page,array $params=[],string $class='primary'): string {
    return '<a class="button '.e($class).'" href="'.e(url($page,$params)).'">'.e($label).'</a>';
}
/** Nothing here yet: an icon for what would be here, a title, one sentence and one way on (Part 0, C13). */
function empty_state(string $title,string $body='',string $action='',string $icon='users'): void { echo '<div class="empty"><div class="empty-icon">'.icon($icon).'</div><h2>'.e($title).'</h2>'.($body?'<p>'.e($body).'</p>':'').$action.'</div>'; }
/**
 * A row of tabs. An item is a label, which opens $page with tab=<key>, or
 * ['label'=>…, 'page'=>…, 'params'=>[…]], which opens a page of its own - the
 * „Beiträge · Rechnungen" switch is two pages that read as one.
 *
 * Two or three are a segmented control, one choice between equal parts (Part 0,
 * C8); more scroll sideways, until the pages that have them become lists that
 * lead to each part. $label is what a screen reader calls the row.
 */
function tabs(array $items,string $active,string $page,array $params=[],string $label=''): void {
    echo '<nav class="tabs'.(count($items)<=3?' is-segmented':'').'" aria-label="'.e($label!==''?$label:t('Bereiche','Sections')).'">';
    foreach($items as $key=>$item) {
        $href=is_array($item)?url($item['page'],$item['params']??[]):url($page,['tab'=>$key]+$params);
        echo '<a '.($key===$active?'aria-current="page"':'').' href="'.e($href).'">'.e(is_array($item)?$item['label']:$item).'</a>';
    }
    echo '</nav>';
}
/** Beiträge and Rechnungen, one of Geld's two pages each (ADR 0011). */
function money_switch(string $active): void {
    tabs(['payments'=>['label'=>t('Beiträge','Payments'),'page'=>'payments'],
          'invoices'=>['label'=>t('Rechnungen','Invoices'),'page'=>'invoices']],$active,$active);
}
/**
 * How far along a wizard is: one capsule per step under its large title, the
 * steps done and the one open now in the tint (Part 0, C15). Decoration only -
 * the words „Schritt 2 von 3" stay in page_head()'s description, which is what
 * a screen reader reads, so the line is hidden from it.
 */
function wizard_progress(int $step,int $total): void {
    echo '<ol class="steps" aria-hidden="true">';
    for($i=1;$i<=$total;$i++) echo '<li'.($i<=$step?' class="is-done"':'').'></li>';
    echo '</ol>';
}
function badge(string $text,string $style=''): void {echo '<span class="badge '.e($style).'">'.e($text).'</span>';}
/**
 * One student in a list.
 *
 * $s['due_cents'] and $s['course_price'] may be supplied by a caller that
 * resolved every card in one query (balances(), course_prices_by_student());
 * without them the card looks up its own, which is correct but costs queries
 * per card.
 */
function student_card(array $s): void {
    $due=array_key_exists('due_cents',$s)?(int)$s['due_cents']:balance((int)$s['id'],true);
    $price=array_key_exists('course_price',$s)?(string)$s['course_price']:course_price_label(student_enrolments((int)$s['id']));
    echo '<a class="student-card" href="'.e(url('student',['id'=>$s['id']])).'">'.avatar($s,'','student').'<div class="student-card-name"><h3>'.e($s['first_name'].' '.$s['last_name']).'</h3><p>'.e(implode(' · ',array_filter([$s['level_name']??'',age_group_name($s),$price]))).'</p></div><div class="student-card-status">';
    badge(status_label($s['status']),$s['status']==='active'?'green':'');
    if($due)echo '<span class="due">'.e(money($due)).' '.e(t('überfällig','overdue')).'</span>';
    echo '</div>'.icon('chevron').'</a>';
}
/**
 * What a child pays, from the courses they are in now: the price is the
 * course's (ADR 0011), so a list that said „Kein Tarif" from the child's own,
 * unused tariff was saying something about nothing. $enrolments are rows as
 * student_enrolments() and billing_enrolments() give them.
 */
function course_price_label(array $enrolments): string {
    $current=array_filter($enrolments,fn($row)=>$row['left_on']===null);
    if(!$current) return t('in keinem Kurs','in no course');
    $prices=[];
    foreach($current as $row) {
        $cents=enrolment_price($row)['cents'];
        if($cents!==null) $prices[]=money($cents).' '.billing_interval_label((int)($row['interval_months']??1));
    }
    return $prices?implode(' + ',$prices):t('Kurs ohne Preis','course without a price');
}
/** course_price_label() for every child at once, id => label: one query for a whole list. */
function course_prices_by_student(): array {
    $by=[];
    foreach(billing_enrolments() as $row) $by[(int)$row['student_id']][]=$row;
    return array_map('course_price_label',$by);
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
    check_field('overdue',t('Nur überfällige Beiträge','Overdue charges only'),!empty($f['overdue']));
    submit_button(t('Filtern','Filter'),'secondary');echo '</form>';
}

/**
 * The main menu: one flat list, no sections (ADR 0011).
 *
 * Seven entries for staff, because a section hides what it holds and seven fit
 * on a phone. Every page that has no entry of its own is reached from the page
 * that owns it (nav_owner()), and that entry is the one highlighted there.
 *
 *   administrator  (Einrichtung, while unfinished) · Übersicht · Schüler · Kurse
 *                  · Anwesenheit · Geld · Nachrichten · Einstellungen
 *   trainer        the same, with Verwaltung last: Einstellungen is an
 *                  administrator's page, Verwaltung is what she can open
 *   family         Übersicht · Beiträge · Nachrichten · Profil, the same four as
 *                  on the bar at the bottom of a phone (mobile_nav_entries())
 *
 * Each entry is ['route'=>…, 'params'=>[…], 'icon'=>…, 'label'=>…, 'count'=>int].
 */
function nav_entries(array $user): array {
    $entry=fn(string $route,string $symbol,string $label,int $count=0)=>
        ['route'=>$route,'params'=>[],'icon'=>$symbol,'label'=>$label,'count'=>$count];
    if(!is_staff($user)) {
        $own=family_nav_entries($user);
        return array_values(array_filter([$entry('dashboard','home',t('Übersicht','Overview')),$own['payments']??null,
            $entry('messages','chat',t('Nachrichten','Messages'),unread_count($user)),$own['profile']??null]));
    }
    $admin=is_admin($user);
    $out=[];
    if($admin && setup_unfinished()) $out[]=$entry('start','check',t('Einrichtung','Setup'));
    $out[]=$entry('dashboard','home',t('Übersicht','Overview'));
    $out[]=people_nav_entry($user);
    $out[]=$entry('classes','calendar',t('Kurse','Courses'),pending_request_count());
    $out[]=$entry('attendance','check',t('Anwesenheit','Attendance'));
    $out[]=$entry('payments','wallet',t('Geld','Money'));
    $out[]=$entry('messages','chat',t('Nachrichten','Messages'),unread_count($user));
    $out[]=$admin?$entry('settings','settings',t('Einstellungen','Settings'))
                 :$entry('manage','settings',t('Verwaltung','Management'));
    return $out;
}

/**
 * The route of the menu entry that stands for $page, for $user.
 *
 * The one table of which page belongs where. A page with an entry of its own
 * is its own owner; a page without one names the entry it is reached from,
 * and that entry is highlighted while it is open:
 *
 *   invoices                  → payments (Geld): the „Beiträge · Rechnungen" switch
 *   outbox                    → messages: the link at the top of Nachrichten
 *   news                      → messages for staff (same links); the overview for a
 *                               family, whose news group links to all of them
 *   manage, accounts, history → settings for an administrator (the hub at the top
 *                               of Einstellungen); manage for a trainer, whose
 *                               Verwaltung links to Team und Zugänge
 *   start                     → itself while it is in the menu; settings once hidden
 *                               („Einrichtung ansehen" on the hub)
 *   student                   → students for staff (the list); a family's own
 *                               child's page, whose Beiträge and Profil both lead
 *                               there (nav_is_current() tells the two apart)
 *   students                  → itself for staff; a family's child's page, because
 *                               a family's list holds their one child and no menu
 *                               entry of theirs leads to it (ADR 0010)
 *   download                  → payments for staff (invoices), Profil for a family
 *
 *   profile                   → '' for staff (the account in the top bar); for a
 *                               family their child's page, where Profil has the row
 *                               „Anmeldung und Darstellung" that leads to it
 *
 * '' for a page that belongs to no entry: profile for staff, privacy (Mein
 * Konto and the side menu's foot) and the pages shown to nobody signed in.
 */
function nav_owner(string $page,?array $user=null): string {
    $user??=current_user();
    if(!$user) return '';
    $staff=is_staff($user); $admin=is_admin($user);
    return match($page) {
        'invoices'                    => 'payments',
        'outbox'                      => 'messages',
        'news'                        => $staff?'messages':'dashboard',
        'manage','accounts','history' => $admin?'settings':'manage',
        'start'                       => $admin && setup_unfinished()?'start':'settings',
        'student'                     => $staff?'students':'student',
        'student_new'                 => 'students',
        'download'                    => $staff?'payments':'student',
        'students'                    => $staff?'students':'student',
        'dashboard','classes','attendance','payments','messages','settings' => $page,
        'profile'                     => $staff?'':'student',
        default                       => '',
    };
}

/**
 * Whether a menu entry is the one standing for the page being looked at.
 *
 * A family has two entries on their own child's page (family_nav_entries()):
 * „Beiträge" stands for its money tabs, „Profil" for every other one, and for
 * Mein Konto, which is reached from it. $params are the entry's own and $tab is
 * the tab being looked at; without a tab the route alone decides.
 */
function nav_is_current(string $route,string $page,?array $user=null,array $params=[],?string $tab=null): bool {
    if($route==='' || $route!==nav_owner($page,$user)) return false;
    if($route!=='student' || $tab===null) return true;
    $money=$page==='student' && in_array($tab,family_money_tabs(),true);
    return isset($params['tab'])===$money;
}

/** nav_is_current() for a whole entry, on the tab the address asks for. */
function nav_item_current(array $item,string $page,?array $user=null): bool {
    return nav_is_current($item['route'],$page,$user,$item['params']??[],(string)($_GET['tab']??''));
}

/** The tabs of a family's child page that „Beiträge" stands for; Profil is the rest. */
function family_money_tabs(): array { return ['payments','invoices']; }

/**
 * A family's two entries, both on their own child's page (ADR 0010): „Beiträge",
 * its money tabs, and „Profil", the record - the same in the side menu and on the
 * bar. "Mein Konto" stays what it is, the login: its address, its password, how
 * the portal looks, reached from a row on Profil. A login no student points to
 * has no page to lead to, and gets neither rather than two that lead nowhere.
 */
function family_nav_entries(array $user): array {
    $id=login_student_id((int)$user['id']);
    if(!$id) return [];
    return ['payments'=>['route'=>'student','params'=>['id'=>$id,'tab'=>'payments'],'icon'=>'wallet','label'=>t('Beiträge','Payments'),'count'=>0],
            'profile'=>['route'=>'student','params'=>['id'=>$id],'icon'=>'person','label'=>t('Profil','Profile'),'count'=>0]];
}

/**
 * The menu's "people" entry, the same in the side menu and the bar at the bottom:
 * the list of students for staff, a family's Profil (family_nav_entries()).
 */
function people_nav_entry(array $user): ?array {
    if(is_staff($user)) return ['route'=>'students','params'=>[],'icon'=>'users','label'=>t('Schüler','Students'),'count'=>0];
    return family_nav_entries($user)['profile']??null;
}

/**
 * The bar along the bottom of a phone, as an iOS tab bar: four places and, for
 * staff, „Mehr", which the layout adds as the button that opens the side menu.
 *
 *   staff   Übersicht · Schüler · Anwesend · Chats (· Mehr)
 *   family  Übersicht · Beiträge · Chats · Profil
 *
 * A family has no „Mehr": everything in their side menu is already on the bar,
 * news reaches them through the bell and the overview, and the privacy notice
 * and the version are on „Mein Konto", a row on Profil.
 * 'short' is the label the bar prints, 'label' the full name a screen reader
 * says: „Chats" on the bar, „Nachrichten" read out.
 */
function mobile_nav_entries(array $user): array {
    $entry=fn(string $route,string $symbol,string $label,string $short='',int $count=0)=>
        ['route'=>$route,'params'=>[],'icon'=>$symbol,'label'=>$label,'short'=>$short!==''?$short:$label,'count'=>$count];
    $messages=$entry('messages','chat',t('Nachrichten','Messages'),t('Chats','Chats'),unread_count($user));
    if(is_staff($user))
        return [$entry('dashboard','home',t('Übersicht','Overview')),
                people_nav_entry($user)+['short'=>t('Schüler','Students')],
                $entry('attendance','check',t('Anwesenheit','Attendance'),t('Anwesend','Attendance')),
                $messages];
    $own=array_map(fn(array $item)=>$item+['short'=>$item['label']],family_nav_entries($user));
    return array_values(array_filter([$entry('dashboard','home',t('Übersicht','Overview')),$own['payments']??null,
        $messages,$own['profile']??null]));
}

/**
 * Where the bar's back button leads on a phone, and what it says (Part 0, C1):
 * up to the page above this one, as an iOS navigation stack goes - never back
 * through the browser's history, which in the home-screen app can lead out of
 * the portal or nowhere. null on a page nothing is above, which shows the club's
 * mark there instead.
 *
 *   a child's page, staff            → Schüler
 *   a course, any of its tabs        → Kurse
 *   a course's form                  → the course, or Kurse for a new one
 *   a chat, „Neue Nachricht"          → Chats
 *   a group's members                → the chat
 *   one news item, or its form       → Neuigkeiten
 *   all news                         → Chats for staff, Übersicht for a family
 *   Postausgang                      → Chats
 *   „Schüler anlegen", step 2         → step 1
 *   „Schüler anlegen", step 1, done  → where it was opened from
 *   one record's changes             → Änderungen
 *   Verwaltung, Team und Zugänge,
 *   Änderungen, Einrichtung (hidden) → Einstellungen, for an administrator
 *   Team und Zugänge, for a trainer  → Verwaltung
 *   Mein Konto, for a family         → Profil
 *
 * Kurse, Geld, Rechnungen, Einstellungen and staff's Mein Konto are reached from
 * „Mehr", which is not a page yet (ADR 0011), so they have no back button.
 *
 * ['href', 'label', 'name']: 'name' is the parent's, for „Zurück zu {name}";
 * 'label' is what the button shows, the name or, past twelve characters,
 * „Zurück". $query is the address being looked at.
 */
function nav_back(string $page,?array $user=null,?array $query=null): ?array {
    $user??=current_user();
    if(!$user) return null;
    $query??=$_GET;
    $staff=is_staff($user); $admin=is_admin($user);
    $id=(int)($query['id']??0);
    $to=fn(string $name,string $target,array $params=[])=>
        ['href'=>url($target,$params),'name'=>$name,'label'=>mb_strlen($name)>12?t('Zurück','Back'):$name];
    $chats=fn()=>$to(t('Chats','Chats'),'messages');
    switch($page) {
        case 'student':
            return $staff?$to(t('Schüler','Students'),'students'):null;
        case 'classes':
            if(!empty($query['edit']) && $id) return $to((string)(scalar('SELECT name FROM classes WHERE id=?',[$id])?:t('Kurs','Course')),'classes',['id'=>$id]);
            return $id || !empty($query['new']) ? $to(t('Kurse','Courses'),'classes') : null;
        case 'messages':
            if($id && !empty($query['members'])) return $to(t('Chat','Chat'),'messages',['id'=>$id,'#'=>'chat-end']);
            return $id || !empty($query['with']) || !empty($query['new']) || !empty($query['contacts']) ? $chats() : null;
        case 'news':
            if($id || !empty($query['new'])) return $to(t('Neuigkeiten','News'),'news');
            return $staff ? $chats() : $to(t('Übersicht','Overview'),'dashboard');
        case 'outbox':
            return $chats();
        case 'student_new':
            // The wizard's own rule for which step is showing, and its own way
            // back to step 1 - the link „Ändern" beside the details.
            $from=in_array($query['from']??'',['dashboard','students','start'],true)?(string)$query['from']:'';
            if(!in_array($query['step']??'',['1','done'],true) && student_draft((string)($query['draft']??''))!==null && !held_for('student_draft'))
                return $to(t('Schritt 1','Step 1'),'student_new',['draft'=>(string)$query['draft'],'step'=>'1']+($from!==''?['from'=>$from]:[]));
            return match($from) {
                'dashboard' => $to(t('Übersicht','Overview'),'dashboard'),
                'start'     => $to(t('Einrichtung','Setup'),'start'),
                default     => $to(t('Schüler','Students'),'students'),
            };
        case 'history':
            if(!empty($query['entity']) && !empty($query['record'])) return $to(t('Änderungen','Changes'),'history');
            return $admin ? $to(t('Einstellungen','Settings'),'settings') : null;
        case 'manage':
            return $admin ? $to(t('Einstellungen','Settings'),'settings') : null;
        case 'accounts':
            return $admin ? $to(t('Einstellungen','Settings'),'settings') : $to(t('Verwaltung','Management'),'manage');
        case 'start':
            return $admin && !setup_unfinished() ? $to(t('Einstellungen','Settings'),'settings') : null;
        case 'profile':
            $own=$staff ? null : (family_nav_entries($user)['profile']??null);
            return $own ? $to($own['label'],'student',$own['params']) : null;
        default:
            return null;
    }
}

/** One menu row: the link, its label, and the number waiting behind it. */
function nav_link(array $item,string $page,?array $user=null): string {
    $count=(int)($item['count']??0);
    return '<a href="'.e(url($item['route'],$item['params']??[])).'" '.(nav_item_current($item,$page,$user)?'aria-current="page"':'').'>'
        .icon($item['icon']).'<span>'.e($item['label']).'</span>'
        .($count?'<span class="count" aria-label="'.e($count.' '.t('wartet','waiting')).'">'.e((string)$count).'</span>':'')
        .'</a>';
}

/** The main menu as markup. */
function sidebar_nav(array $user,string $page): string {
    $html='<nav aria-label="'.e(t('Hauptmenü','Main menu')).'">';
    foreach(nav_entries($user) as $entry) $html.=nav_link($entry,$page,$user);
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
 */
function contact_fields(array $contact=[]): void {
    echo '<div class="grid two">';
    input('owner_name',t('Name der Kontaktperson','Name of the contact'),$contact['owner_name']??'','text',true,
          '',t('z. B. Maria Hofer','e.g. Maria Hofer'));
    input('relation_label',t('Beziehung zum Kind','Relationship to the child'),$contact['relation_label']??'','text',true,
          '',t('z. B. Mutter','e.g. mother'));
    input('phone',t('Telefonnummer','Phone number'),$contact['phone']??'','tel',false,
          t('Das Wichtigste an einem Notfallkontakt.','The thing that makes an emergency contact useful.'));
    input('email',t('E-Mail-Adresse (optional)','Email address (optional)'),$contact['email']??'','email',false,
          t('Nur als Notiz. Rechnungen und Einladungen gehen an die Adresse des Kindes.','A note only. Invoices and invitations go to the child’s own address.'));
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

/**
 * A numbered list of things to do, each leading to where it is done.
 *
 * On a record that has just been created it is what is still missing, in the
 * order she would do it, at the top of the record: the bottom of a page is
 * where things go to be forgotten. It disappears the moment nothing is left.
 *
 * The start checklist (ADR 0011) is the same list with a state on every step:
 * a step carrying 'done' is shown whether or not it is done - with its number
 * or a tick, the word „Erledigt" (a tick alone says nothing to a screen
 * reader, or to anybody unsure what a tick means here), and a button rather
 * than a linked title: „Eintragen", filled for the one to do next ('next'),
 * or „Ändern" once done. A step with 'blocked' has no button, and says which
 * steps it waits for; $numbers maps each key to the number it is shown with,
 * so "Schritt 7 und 8" is worked out rather than written down.
 *
 * $heading is the card's title and $start the number of its first step, so one
 * list can be shown as several groups.
 */
function next_steps_card(array $steps, string $heading = '', int $start = 1, array $numbers = []): void {
    if (!$steps) return;
    $checklist = array_key_exists('done', $steps[0]);
    echo '<section class="card next-steps'.($checklist ? ' is-checklist' : '').'"><h2>'.e($heading !== '' ? $heading : t('Noch zu tun','Still to do')).'</h2>'
        .'<ol'.($start !== 1 ? ' start="'.$start.'"' : '').'>';
    foreach (array_values($steps) as $i => $step) {
        $href = url($step['page'], $step['params'] + ['#' => (string)($step['anchor'] ?? '')]);
        if (!$checklist) {
            echo '<li><a href="'.e($href).'">'.e($step['what']).'</a><small>'.e($step['why']).'</small></li>';
            continue;
        }
        $state = $step['done'] ? 'is-done' : (!empty($step['blocked']) ? 'is-blocked' : 'is-open');
        echo '<li class="step '.$state.'">'
            .'<span class="step-mark">'.($step['done'] ? icon('check').'<span class="visually-hidden">'.e((string)($start + $i)).'</span>' : e((string)($start + $i))).'</span>'
            .'<div class="step-text"><p class="badge-line"><strong>'.e($step['what']).'</strong>';
        if ($step['done']) badge(t('Erledigt','Done'),'green');
        echo '</p><small>'.e($step['why']).'</small>';
        if (!$step['done'] && !empty($step['blocked'])) {
            $waiting = [];
            foreach ($step['blocked_by'] as $key) if (isset($numbers[$key]) && !($numbers[$key]['done'] ?? false)) $waiting[] = $numbers[$key]['n'];
            // Whole sentences with the numbers dropped in, so each language
            // keeps its own word order; a step blocked by nothing numbered here
            // still says why it has no button.
            $list = count($waiting) > 1 ? implode(', ', array_slice($waiting, 0, -1)).t(' und ',' and ').end($waiting) : (string)($waiting[0] ?? '');
            echo '<p class="step-blocked">'.e(match (true) {
                $waiting === []     => t('Geht, sobald die Schritte davor erledigt sind.','Possible once the steps before it are done.'),
                count($waiting) === 1 => strtr(t('Geht, sobald Schritt {n} erledigt ist.','Possible once step {n} is done.'), ['{n}' => $list]),
                default             => strtr(t('Geht, sobald Schritt {n} erledigt sind.','Possible once steps {n} are done.'), ['{n}' => $list]),
            }).'</p>';
        }
        echo '</div>';
        if ($step['done'] || empty($step['blocked']))
            echo '<a class="button '.($step['done'] || empty($step['next']) ? 'secondary' : 'primary').' step-button" href="'.e($href).'">'
                .e($step['done'] ? t('Ändern','Change') : t('Eintragen','Fill in'))
                .'<span class="visually-hidden">: '.e($step['what']).'</span></a>';
        echo '</li>';
    }
    echo '</ol></section>';
}

/**
 * The password for the example accounts, just after they were made, and the
 * addresses to sign in with (ADR 0021, §1).
 *
 * Held in the session by demo_data and nowhere else, and shown wherever the
 * fill returns to - the System tab or the checklist - because its message
 * says the password is shown below it. The addresses are read back from the
 * database (demo_logins()) rather than written here, so the notice cannot name
 * a login the fill did not make.
 */
function demo_password_notice(): void {
    if (empty($_SESSION['demo_password']) || !demo_present()) return;
    $addresses = array_map(fn($login) => '<span class="mono">'.e((string)$login['email']).'</span>', demo_logins());
    echo '<div class="notice">'.e(t('Passwort für alle Beispielkonten','Password for every example account')).': <strong class="mono">'.e((string)$_SESSION['demo_password']).'</strong><br>'
        .e(t('Es wird nur hier gezeigt und nirgends gespeichert. Anmelden mit: ','Shown only here and stored nowhere. Sign in with: '))
        .implode(', ', $addresses)
        .'</div>';
}

/**
 * A notice naming children who still need something, each name a way in.
 *
 * One copy for every such list on the students page - nobody to ring, no
 * address, an address to invite that a student already carries. Each name is a
 * button rather than a word in a sentence: as a comma-separated list they were
 * 17px tall and touching each other, so on a phone the way to fix one child's
 * record was a target a third of the minimum with another one beside it.
 *
 * $params and $anchor say where on the child's page the name leads. $tone is
 * 'warn' for something that costs somebody something while it waits, and ''
 * for a plain notice.
 */
function students_notice(array $students,string $heading,string $body='',array $params=[],string $anchor='',string $tone='warn'): void {
    if (!$students) return;
    echo '<div class="notice'.($tone!==''?' '.e($tone):'').'"><strong>'.e($heading).'</strong>';
    if ($body !== '') echo '<p>'.e($body).'</p>';
    echo '<p class="gap-names">';
    foreach (array_slice($students,0,6) as $m)
        echo '<a class="chip" href="'.e(url('student',['id'=>$m['id']]+$params+['#'=>$anchor])).'">'.e($m['first_name'].' '.$m['last_name']).'</a>';
    if (count($students) > 6) echo '<span class="muted">'.e(t('und weitere','and more')).'</span>';
    echo '</p></div>';
}

/**
 * Where a login stands, as a badge: the same words and colours on the student
 * page and on the Konten page. Worked out from the state and the columns rather
 * than stored (ADR 0023 §3): an invitation waiting at an address is
 * „Eingeladen", a username waiting for its first sign-in „Noch nicht
 * angemeldet", a placeholder „Ohne Anmeldung". $account is null for a student
 * no update has given a login yet - „Kein Zugang", which should never show.
 */
function login_state_badge(?array $account): void {
    $state = $account !== null && username_login_waiting($account) ? 'waiting' : ($account['state'] ?? 'none');
    badge(match ($state) {
        'active'      => t('Aktiv','Active'),
        'invited'     => t('Eingeladen','Invited'),
        'waiting'     => t('Noch nicht angemeldet','Not signed in yet'),
        'placeholder' => t('Ohne Anmeldung','No sign-in'),
        'suspended'   => t('Gesperrt','Suspended'),
        default       => t('Kein Zugang','No access'),
    }, match ($state) { 'active' => 'green', 'invited', 'waiting' => 'amber', 'suspended' => 'red', default => '' });
}

/**
 * The address a login signs in with and its mail goes to, as text to read
 * rather than a box to type in (ADR 0021, §1). One copy for Mein Konto and the
 * access card, so the two say it in the same words. $addressNote follows the
 * address in a lighter weight - „· bestätigt" on Mein Konto - in a <small>,
 * because a plain span took on the bold of the value beside it.
 *
 * Only for the login's holder and for staff (ADR 0019, §8).
 */
function login_facts(array $account,string $addressNote=''): void {
    // A username login without an address shows its username instead (ADR 0023 §1).
    $byUsername=(string)($account['email']??'')==='' && (string)($account['username']??'')!=='';
    echo '<dl class="facts login-facts">'
        .'<div class="fact-wide"><dt>'.e($byUsername?t('Benutzername','Username'):t('E-Mail-Adresse','Email address')).'</dt><dd'.($byUsername?' class="mono"':'').'>'.e(sign_in_name($account))
        .($addressNote!==''?'<small class="muted">'.e(' · '.$addressNote).'</small>':'').'</dd></div>'
        .'</dl>';
}

/**
 * A calendar date the way a person says it: „Heute", „Gestern", „Mo 29.09.",
 * and with the year once it is another year's. One rule for the online history
 * and the chat's day separators.
 */
function day_label(string $date, ?string $today = null): string {
    $today ??= today();
    if ($date === $today) return t('Heute', 'Today');
    if ($date === date('Y-m-d', (int)strtotime($today.' -1 day'))) return t('Gestern', 'Yesterday');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d) return $date;
    if ($d->format('Y') !== substr($today, 0, 4)) return $d->format(locale() === 'de' ? 'd.m.Y' : 'j M Y');
    return locale() === 'de' ? mb_substr(weekdays()[(int)$d->format('N')], 0, 2).' '.$d->format('d.m.') : $d->format('D j M');
}

/** The emoji $account shows beside their name, or ''. Decoration: a screen reader would otherwise say „Lena Fuchs". */
function status_emoji_mark(array $account): string {
    $emoji = status_emoji($account);
    return $emoji ? '<span class="status-emoji" aria-hidden="true">'.e($emoji[0]).'</span>' : '';
}

/** A name as the chat prints it, escaped, with the person's emoji after it. */
function chat_name(?array $account): string {
    $name = (string)($account['name'] ?? '');
    return e($name !== '' ? $name : t('Gelöschtes Konto', 'Deleted account')).status_emoji_mark($account ?? []);
}

/**
 * The colour a group's picture or a sender's name is drawn in, the same for
 * the same id every time: six of the accent colours, not grey, which reads as
 * faded text.
 */
function chat_hue(int $id): string {
    return ['blue', 'violet', 'pink', 'red', 'orange', 'green'][$id % 6];
}

/*
 * Presence, as the pages show it (ADR 0015, 0022). Who may see what is decided
 * in app/presence.php and asked here before anything is drawn: everybody gets
 * the dot, and only staff the lines and history that say when somebody was here.
 */

/** The dot itself, for a state; its label for a screen reader unless the text beside it already says it. */
function presence_dot_for(string $state, bool $labelled=true): string {
    $state = in_array($state, ['online','recent','away','offline'], true) ? $state : 'offline';
    return '<span class="presence-dot is-'.$state.'"'.($labelled ? '' : ' aria-hidden="true"').'>'
        .($labelled ? '<span class="visually-hidden">'.e(presence_state_label($state)).'</span>' : '').'</span>';
}

/**
 * Whether a page shows $viewer when $account was here - the line and the
 * history on staff's pages. An invitation nobody has taken up has never been
 * used, so it has nothing to show rather than a grey „Offline".
 */
function presence_shown_for(array $viewer, ?array $account): bool {
    return $account !== null && ($account['state'] ?? '') !== 'invited' && presence_details_visible_to($viewer);
}

/** $subject's dot as $viewer may see it, or '' for nobody signed in. */
function presence_dot(array $viewer, array $subject): string {
    return presence_visible_to($viewer, $subject) ? presence_dot_for(presence_state($subject)) : '';
}

/**
 * $subject's dot and, in words, what it means: „Online“, or the state and when
 * they were last here, as $viewer may know it. '' for anybody but staff. An
 * administrator looking at somebody who appears offline is told so, because the
 * time shown is then one a trainer does not see.
 */
function presence_line(array $viewer, array $subject): string {
    if (!presence_details_visible_to($viewer)) return '';
    $state = presence_state($subject);
    $text = presence_state_label($state);
    if ($state !== 'online' && ($seen = presence_last_seen_for($viewer, $subject)) !== null)
        $text .= ' · '.t('zuletzt ', 'last seen ').fmt_datetime($seen);
    if (is_admin($viewer) && presence_choice($subject) === 'hidden')
        $text .= t(' (als offline angezeigt)', ' (appearing offline)');
    return '<span class="presence-line">'.presence_dot_for($state, false).'<span>'.e($text).'</span></span>';
}

/**
 * When somebody was online over the days kept, folded away: how many days, a
 * strip of them, and the times, newest first. $periods is this account's entry
 * from presence_history(), which has already left out what $viewer may not see
 * - a trainer gets no hidden periods but her own - so whatever hidden period
 * arrives here is marked rather than dropped. $recordedSince is
 * presence_recorded_since(), which the page asks once for all its people.
 */
function presence_history_details(array $viewer, array $periods, ?string $recordedSince): void {
    if (!is_staff($viewer)) return;
    $n = presence_history_days();
    $days = presence_days($periods);
    $today = $days[count($days) - 1]['date'];
    $active = array_values(array_filter(array_reverse($days), fn($d) => $d['state'] !== 'none'));
    $label = fn(string $date): string => day_label($date, $today);
    echo '<details class="presence-history"><summary>'
        .e(t('Wann online? Letzte ', 'When online? Last ').plural($n, 'Tag', 'Tage', 'day', 'days')).'</summary>';
    echo '<p>'.e($active
        ? t('An ', 'Online on ').count($active).t(' von ', ' of the last ').plural($n, 'Tag', 'Tagen', 'day', 'days').t(' online.', '.')
        : t('In den letzten ', 'Not online in the last ').plural($n, 'Tag', 'Tagen', 'day', 'days').t(' nicht online.', '.')).'</p>';
    // For the first weeks after the update the record is shorter than the
    // window, and "not online in 30 days" would be untrue of somebody who was
    // simply not recorded yet. presence_recorded_since(), asked once by the
    // page rather than once per person on a list.
    if ($recordedSince !== null)
        echo '<p class="muted">'.e(t('Aufgezeichnet wird seit dem ', 'Recorded since ').fmt_date($recordedSince).'.').'</p>';
    // The strip repeats the list below at a glance, so it is hidden from a
    // screen reader, which would otherwise read thirty empty cells.
    echo '<div class="presence-strip" aria-hidden="true">';
    foreach ($days as $day) echo '<span class="is-'.($day['state'] === 'on' ? 'on' : ($day['state'] === 'hidden' ? 'hidden' : 'none')).'"></span>';
    echo '</div><div class="presence-strip-scale" aria-hidden="true">'
        // The oldest cell is N-1 days back: today is a cell of its own.
        .($n > 1 ? '<span>'.e(t('vor ', '').plural($n - 1, 'Tag', 'Tagen', 'day', 'days').t('', ' ago')).'</span>' : '<span></span>')
        .'<span>'.e(t('heute', 'today')).'</span></div>';
    if ($active) {
        echo '<dl class="presence-days">';
        foreach ($active as $day) {
            // Within a day in the order they happened, as one reads a timetable.
            $times = array_map(fn($p) => ($p['from'] === $p['to'] ? $p['from'] : $p['from'].'–'.$p['to'])
                .($p['hidden'] ? t(' (als offline angezeigt)', ' (appearing offline)') : ''), $day['periods']);
            echo '<div><dt>'.e($label($day['date'])).'</dt><dd>'.e(implode(', ', $times)).'</dd></div>';
        }
        echo '</dl>';
    }
    echo '</details>';
}

/**
 * Deleting a login, folded away, with its address typed to confirm.
 *
 * Its own form and a <details> of its own, so it can sit in a row of buttons
 * without being one: the typed address is what stands between a thumb and a
 * login that cannot be brought back. The action compares both sides after
 * email_normalised(), so „Lena@Beispiel.test" confirms lena@beispiel.test.
 *
 * The address is said in the sentence above the box, not in its label: the
 * label's <span> is the teal of the required mark, and an address inside it
 * read as part of the asterisk. autocomplete="off" first, so the browser does
 * not fill in the signed-in person's own address and make the typing pointless.
 *
 * data-sheet: with JavaScript the fold opens as a sheet from the bottom of the
 * screen, with „Abbrechen" under it (app.js); without, it opens in place. The
 * same for every fold that removes or makes something (Part 0, C11).
 */
function login_delete_details(array $account,string $summary,string $explanation,string $button): void {
    // The sign-in name: the address, or the username of a login without one
    // (ADR 0023 §4). A username box is plain text; type="email" refuses one.
    $byUsername=(string)($account['email']??'')==='';
    echo '<details class="account-delete" data-sheet><summary>'.e($summary).'</summary>'
        .'<p>'.e($explanation.' '.($byUsername?t('Zur Bestätigung den Benutzernamen eintippen: ','To confirm, type the username: '):t('Zur Bestätigung die E-Mail-Adresse eintippen: ','To confirm, type the email address: ')))
        .'<strong class="mono">'.e(sign_in_name($account)).'</strong></p>';
    start_form('account_state',['id'=>$account['id'],'mode'=>'delete']);
    input('confirmation',$byUsername?t('Benutzername','Username'):t('E-Mail-Adresse','Email address'),'',$byUsername?'text':'email',true,'','',['autocomplete'=>'off']+sign_in_address_attributes());
    submit_button($button,'danger');
    echo '</form></details>';
}

/**
 * „Anmeldelink erstellen" on the access card (ADR 0023 §6), folded away like the
 * two folds beside it: what the link does, that it replaces an earlier one, and
 * for a login without sign-in the username it is given in the same POST. Only
 * offered where may_create_signin_link() says so; the action asks again. It
 * makes something rather than removing it, so its fold is not drawn in red.
 */
function signin_link_details(array $account, array $student, bool $askUsername): void {
    $firstName=(string)$student['first_name'];
    $hasLink=(bool)array_filter(signin_links_for((int)$account['id'],PASSWORD_RESET_SHOWN_DAYS),fn($l)=>$l['expires_at']!==null);
    echo '<details class="action-fold" data-sheet><summary>'.e($hasLink?t('Neuen Link erstellen','Create a new link'):t('Anmeldelink erstellen','Create a sign-in link')).'</summary>'
        .'<p>'.e(strtr(t('Mit dem Link meldet sich {name} einmal ohne Passwort an und legt dann ein neues fest. Er gilt 48 Stunden.',
                         'With the link {name} signs in once without a password and then chooses a new one. It is valid for 48 hours.'),['{name}'=>$firstName])).'</p>'
        .($hasLink?'<p>'.e(t('Der Link, den du vorher erstellt hast, gilt dann nicht mehr.','The link you created before then stops working.')).'</p>':'');
    start_form('signin_link',['student_id'=>(int)$student['id'],'mode'=>'create']);
    if($askUsername)
        input('username',t('Benutzername','Username'),username_suggested($firstName,(string)$student['last_name']),'text',true,
              strtr(t('Kleinbuchstaben, Ziffern, Punkt und Bindestrich. Damit meldet sich {name} an.','Lower-case letters, digits, dot and hyphen. {name} signs in with it.'),['{name}'=>$firstName]),
              '',sign_in_address_attributes()+['maxlength'=>'30']);
    submit_button(t('Link erstellen','Create the link'));
    echo '</form></details>';
}

/**
 * Withdrawing an invitation nobody has taken up, folded away like deleting a
 * login but with nothing to type (ADR 0021, §3): nothing is lost - no password
 * was ever set, and a student stays where they were - and the way back is to
 * invite again, which the explanation says. One copy for the open invitations
 * on the students list and for the access card, so the two cannot drift. The
 * action allows it only for a login never set up (verified_at IS NULL). A
 * username waiting for its first sign-in is withdrawn the same way, under its
 * own $button (ADR 0023 §4).
 */
function invitation_withdraw_details(array $account,string $summary,string $explanation,string $button=''): void {
    echo '<details class="account-delete" data-sheet><summary>'.e($summary).'</summary><p>'.e($explanation).'</p>';
    start_form('account_state',['id'=>$account['id'],'mode'=>'withdraw']);
    submit_button($button!==''?$button:t('Einladung zurückziehen','Withdraw the invitation'),'danger');
    echo '</form></details>';
}

/**
 * The club's name and mark, top left (ADR 0014): its logo on a white plate,
 * else its icon, else the „B". brand_header() decides which and whether the
 * name and the line under it are shown; this only draws it.
 *
 *   $where  'sidebar'  the menu, with the „Verwaltung“ / „Mein Portal“ line
 *           'public'   the sign-in page's header, without that line
 *           'bar'      the phone's top bar: the logo alone, or the icon alone
 *                      when the name is switched off, or else the name
 *   $href   where it leads; null draws it without a link, for the preview on
 *           the „Aussehen" card
 *
 * A name that is switched off stays in the markup for a screen reader, and the
 * pictures have an empty alt, so the name is read once, never twice.
 */
function brand_block(?array $user, string $where, ?string $href): void {
    $b = brand_header($user);
    $name = $b['name'];
    $kind = $b['logo'] !== '' ? 'logo' : ($b['icon'] !== '' ? 'icon' : 'mark');
    $bar = $where === 'bar';
    // In the bar there is room for one thing: the logo, or the icon when the
    // name is switched off, or else the name as it always was.
    if ($bar && $kind === 'icon' && $b['show_name']) $kind = 'none';
    if ($bar && $kind === 'mark') $kind = 'none';
    $tag = $href === null ? 'span' : 'a';
    echo '<'.$tag.' class="'.($bar ? 'mobile-brand' : 'brand').' brand-is-'.$kind.'"'.($href === null ? '' : ' href="'.e($href).'"').'>';
    if ($kind === 'logo')
        echo '<span class="brand-plate"><img class="brand-logo" src="'.e($b['logo']).'" alt="" width="'.(int)$b['logo_width'].'" height="'.(int)$b['logo_height'].'"></span>';
    elseif ($kind === 'icon')
        echo '<span class="brand-mark brand-icon"><img src="'.e($b['icon']).'" alt="" width="44" height="44"></span>';
    elseif ($kind === 'mark')
        echo '<span class="brand-mark">B<span></span></span>';
    if ($bar) {
        // The name only where it is the whole of it; beside a picture it is for
        // a screen reader. Two lines at most, so a long name cannot push the
        // bar taller than the bar.
        echo '<span class="'.($kind === 'none' ? 'mobile-brand-name' : 'visually-hidden').'">'.e($name).'</span>';
    } else {
        $sub = $where === 'sidebar' ? '<small'.($b['show_subtitle'] ? '' : ' class="visually-hidden"').'>'.e($b['subtitle']).'</small>' : '';
        echo '<span class="brand-name"><span'.($b['show_name'] ? '' : ' class="visually-hidden"').'>'.e($name).'</span>'.$sub.'</span>';
    }
    echo '</'.$tag.'>';
}

/*
 * A problem report's way there, as Einstellungen → Rückmeldungen shows it
 * (ADR 0009). Two shapes are stored, and both are read here rather than in the
 * view, because the view is rendered once per report and a rule written twice
 * - once for each shape - is the one that drifts.
 */

/**
 * Where a report was sent from, what came before it, and the steps.
 *
 * Reports filed since the trail exists carry `steps` (the last requests,
 * oldest first) and `on` (the page, record and tab the report's own form was
 * on). Older ones carry `query` and `referer`, which were empty in every report
 * ever filed; they get an address from the page name and no "before", because
 * nothing was recorded that could say.
 *
 * The page it was sent from is the last GET that showed `on`, not simply the
 * last step: "Zurück" shows a page without asking for it again, and then the
 * last step is the page she went back from.
 *
 *   address   path and query of the page the report was sent from
 *   before    the step before it, one line; '' when that was outside the
 *             portal; null when the report is too old to say
 *   steps     oldest first: time, line, flash, flash_kind
 *   here      index of the reported page in steps, or null
 *   recorded  whether the report carries a trail at all
 *
 * Whether the typed values are still there is not asked here: the page says so
 * from values_dropped_at, which feedback_forget_typed_values() writes.
 */
function report_trail(array $context): array {
    $recorded = array_key_exists('steps', $context);
    $steps = array_values(array_filter(is_array($context['steps'] ?? null) ? $context['steps'] : [], 'is_array'));
    $on = is_array($context['on'] ?? null) ? $context['on'] : null;
    $here = null;
    for ($i = count($steps) - 1; $i >= 0 && $here === null; $i--)
        if (($steps[$i]['method'] ?? '') === 'GET' && ($on === null || report_step_shows($steps[$i], $on))) $here = $i;

    if ($here !== null) $address = report_scalar($steps[$here]['url'] ?? '');
    elseif ($on !== null) $address = '?' . http_build_query(array_filter(['page' => report_scalar($on['page'] ?? ''),
                                           'id' => (int)report_scalar($on['id'] ?? 0), 'tab' => report_scalar($on['tab'] ?? '')]));
    elseif (is_array($context['query'] ?? null) && $context['query']) $address = '?' . http_build_query($context['query']);
    else $address = '?page=' . report_scalar($context['page'] ?? '');

    if (!$recorded) {
        $referer = report_scalar($context['referer'] ?? '');
        $before = $referer !== '' ? preg_replace('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', '', $referer) : null;
    } else {
        $prior = $here !== null ? $here - 1 : count($steps) - 1;
        $before = $prior >= 0 ? report_step_address($steps[$prior]) : '';
    }

    $shown = [];
    foreach ($steps as $step) {
        $at = local_time(report_scalar($step['at'] ?? ''));
        $flash = is_array($step['flash'] ?? null) ? $step['flash'] : [];
        $shown[] = ['time' => $at ? $at->format('H:i:s') : '', 'line' => report_step_line($step),
                    'flash' => report_scalar($flash['text'] ?? ''), 'flash_kind' => report_scalar($flash['kind'] ?? '')];
    }
    return ['address' => $address, 'before' => $before, 'steps' => $shown, 'here' => $here, 'recorded' => $recorded];
}

/** A stored value as text: a report is decoded JSON, so anything may be anything. */
function report_scalar(mixed $value): string { return is_scalar($value) ? (string)$value : ''; }

/** Whether a recorded request showed the page, record and tab a form was on. */
function report_step_shows(array $step, array $on): bool {
    parse_str((string)parse_url(report_scalar($step['url'] ?? ''), PHP_URL_QUERY), $query);
    // The router's own default, so index.php with no page is the start page.
    return report_scalar($query['page'] ?? 'dashboard') === report_scalar($on['page'] ?? '')
        && (int)report_scalar($query['id'] ?? 0) === (int)report_scalar($on['id'] ?? 0)
        && report_scalar($query['tab'] ?? '') === report_scalar($on['tab'] ?? '');
}

/** A step as an address: the path and query of a page, or the action a form sent. */
function report_step_address(array $step): string {
    return ($step['method'] ?? '') === 'POST'
        ? 'POST ?action=' . report_scalar($step['action'] ?? '')
        : report_scalar($step['url'] ?? '');
}

/**
 * A step on one line: "GET ?page=student&id=4" or
 * "POST ?action=enrolment_save  class_id=2 · tariff_id=5".
 *
 * The path is left out, because it is the same on every line; what was posted
 * follows the action, nested fields written the way the form named them.
 */
function report_step_line(array $step): string {
    if (($step['method'] ?? '') !== 'POST') {
        $url = report_scalar($step['url'] ?? '');
        return 'GET ' . (($q = strpos($url, '?')) !== false ? substr($url, $q) : $url);
    }
    $parts = array_merge(report_values(is_array($step['fields'] ?? null) ? $step['fields'] : []),
                         report_files_text(is_array($step['files'] ?? null) ? $step['files'] : []));
    return report_step_address($step) . ($parts ? '  ' . implode(' · ', $parts) : '');
}

/**
 * Posted values as name=value, flattened: rate_price[0]=… for a nested field.
 *
 * null is a value deleted when the report was marked done, and says so; a key
 * '…' is where the recorder stopped keeping fields.
 */
function report_values(array $values, string $prefix = ''): array {
    $out = [];
    foreach ($values as $key => $value) {
        if ($key === '…') { $out[] = '…'; continue; }
        $name = $prefix === '' ? (string)$key : $prefix . '[' . $key . ']';
        if (is_array($value)) array_push($out, ...report_values($value, $name));
        else $out[] = $name . '=' . ($value === null ? t('(gelöscht)', '(deleted)') : report_scalar($value));
    }
    return $out;
}

/**
 * Attached files as name=size and type. Never more: the recorder keeps
 * neither the file's name nor its content.
 */
function report_files_text(array $files, string $prefix = ''): array {
    $out = [];
    foreach ($files as $field => $file) {
        $name = $prefix === '' ? (string)$field : $prefix . '[' . $field . ']';
        if ($file === null) $out[] = $name . '=' . t('(gelöscht)', '(deleted)');
        elseif (is_array($file) && array_is_list($file)) array_push($out, ...report_files_text($file, $name));
        elseif (is_array($file)) $out[] = $name . '=' . report_file_text($file);
    }
    return $out;
}

/** One file: its size and type, or why there was none. */
function report_file_text(array $file): string {
    $error = (int)report_scalar($file['error'] ?? 0);
    if ($error === UPLOAD_ERR_NO_FILE) return t('(keine Datei)', '(no file)');
    // The upload's own failure is the clue, and PHP's number for it is what to look up.
    if ($error !== UPLOAD_ERR_OK) return t('(Upload-Fehler ', '(upload error ') . $error . ')';
    $bytes = (int)report_scalar($file['bytes'] ?? 0);
    $size = $bytes < 1024 ? $bytes . ' B'
          : ($bytes < 1048576 ? round($bytes / 1024) . ' kB'
          : megabytes_label($bytes));
    return trim($size . ' ' . report_scalar($file['type'] ?? ''));
}
