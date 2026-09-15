"""Disposable local integration test. See tests/README.md. No real mail is sent."""
import os, re, json, subprocess, urllib.request, urllib.parse, http.cookiejar
from pathlib import Path
from html.parser import HTMLParser

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('CRM_TEST_PHP', 'php')
BASE = os.environ.get('CRM_TEST_URL', 'http://127.0.0.1:4173')
APP_URL = os.environ.get('CRM_TEST_APP_URL', BASE)
PASSWORD = 'Temporary-CRM-2026!'
checks = []

def assert_ok(condition, name):
    if not condition: raise AssertionError(name)
    checks.append(name)

def db(sql, params=None):
    p = subprocess.run([PHP, str(ROOT/'tests/db.php')], input=json.dumps({'sql':sql,'params':params or []}), text=True, capture_output=True, check=True)
    return json.loads(p.stdout)

def scalar(sql, params=None):
    rows = db(sql,params)
    return next(iter(rows[0].values())) if rows else None

def mail_body(job_id):
    return subprocess.run([PHP,str(ROOT/'tests/db.php')],input=json.dumps({'decrypt_job':job_id}),capture_output=True,text=True,check=True).stdout

class Forms(HTMLParser):
    def __init__(self, text):
        super().__init__(); self.forms=[];self.form=None;self.textarea=None;self.select=None;self.feed(text)
    def handle_starttag(self, tag, attrs):
        a=dict(attrs)
        if tag=='form': self.form={};self.forms.append(self.form)
        if self.form is None:return
        if tag=='input' and a.get('name'):
            if a.get('type') in ['checkbox','radio'] and 'checked' not in a:return
            if 'disabled' in a:return
            self.form[a['name']]=a.get('value','')
        if tag=='textarea':self.textarea=a.get('name');self.form[self.textarea]=''
        if tag=='select':self.select=a.get('name');self.form[self.select]=''
        if tag=='option' and self.select and 'selected' in a:self.form[self.select]=a.get('value','')
    def handle_data(self,data):
        if self.form is not None and self.textarea:self.form[self.textarea]+=data
    def handle_endtag(self,tag):
        if tag=='textarea':self.textarea=None
        if tag=='select':self.select=None
        if tag=='form':self.form=None

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None

class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar),NoRedirect());self.html='';self.status=0;self.headers={}
    def request(self,path='',data=None):
        target=path.replace(APP_URL,BASE) if path.startswith('http') else BASE+'/index.php'+(('?'+path) if path else '')
        body=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None
        try:r=self.opener.open(target,body,timeout=15)
        except urllib.error.HTTPError as e:r=e
        self.status=r.code;self.headers=dict(r.headers);self.html=r.read().decode()
        if self.status in [301,302,303]:return self.request(self.headers['Location'])
        if self.status>=500:raise AssertionError('Server error: '+self.html)
        return self.html
    def form(self,action):
        forms=Forms(self.html).forms
        for f in forms:
            if f.get('action')==action:return dict(f)
        raise AssertionError('Missing form '+action+' in '+self.html[:300])
    def post(self,action,values=None):
        data=self.form(action);data.update(values or {});return self.request(data=data)
    def raw(self,action,values):
        if not any('csrf' in f for f in Forms(self.html).forms):self.request('page=profile')
        f=next(f for f in Forms(self.html).forms if 'csrf' in f)
        return self.request(data={'action':action,'csrf':f['csrf'],'request_id':os.urandom(32).hex(),**values})
    def login(self,email):
        self.request('page=login');self.post('login',{'email':email,'password':PASSWORD})

def run():
    if os.environ.get('CRM_TEST_ALLOW_DESTRUCTIVE')!='1':raise RuntimeError('Set CRM_TEST_ALLOW_DESTRUCTIVE=1 only for the disposable test setup.')
    if urllib.parse.urlparse(BASE).hostname not in ['127.0.0.1','localhost']:raise RuntimeError('Tests require a local application URL.')
    admin=Client();admin.login('coach@example.test')
    assert_ok('Hallo, Test' in admin.html,'Administrator can sign in')
    assert_ok('frame-ancestors' in admin.headers.get('Content-Security-Policy',''),'CSP protects application pages')
    outsider=Client();outsider.request('page=students');assert_ok('Willkommen zurück' in outsider.html,'Private pages require authentication')
    # A configured local test relay; no worker is run in this suite.
    admin.request('page=settings&tab=smtp');admin.post('smtp_save',{'host':'localhost','port':'2525','username':'test-user','smtp_password':'test-smtp-secret','encryption':'tls','from_email':'noreply@example.test','from_name':'Test Badminton'})
    assert_ok('test-smtp-secret' not in admin.html,'SMTP password is not rendered')
    smtp=json.loads(scalar("SELECT setting_value FROM settings WHERE setting_key='smtp'"))
    assert_ok(smtp['password']!='test-smtp-secret','SMTP password encrypted at rest')
    # Only a synthetic test notice is marked ready; production draft stays unconfigured.
    admin.request('page=settings&tab=privacy');notice='Local test notice. No personal data or real accounts. '*12
    admin.post('privacy_save',{'privacy_de':notice,'privacy_en':notice,'privacy_ready':'1'})
    admin.request('page=settings&tab=tariffs');admin.post('tariff_save',{'name':'Junior','price':'40,00','period':'monthly','due_days':'14'})
    tariff=int(scalar('SELECT id FROM tariffs WHERE name=?',['Junior']))
    # Invitation before verification cannot log in; link is single-use.
    clients=[];ids=[]
    for name,email in [('Family One','family1@example.test'),('Family Two','family2@example.test')]:
        admin.request('page=accounts');admin.post('account_invite',{'name':name,'email':email,'locale':'de','role':'student'})
        aid=int(scalar('SELECT id FROM accounts WHERE email=?',[email]));ids.append(aid)
        client=Client();client.login(email);assert_ok('Anmeldung nicht möglich' in client.html,'Unverified account blocked: '+name)
        job=scalar('SELECT MAX(id) FROM mail_jobs WHERE account_id=?',[aid]);token=re.search(r'token=([a-f0-9]{64})',mail_body(job)).group(1)
        assert_ok(scalar('SELECT COUNT(*) FROM auth_tokens WHERE token_hash=?',[token])==0,'Invitation stored as a hash: '+name)
        client.request('page=activate&token='+token);client.post('activate',{'password':PASSWORD,'password_confirm':PASSWORD,'privacy_seen':'1','newsletter':'1','notifications':'1'})
        assert_ok('Dein Konto ist bereit' in client.html,'Email verified through invitation: '+name)
        reuse=Client();reuse.request('page=activate&token='+token);assert_ok('Link nicht mehr gültig' in reuse.html,'Invitation cannot be reused: '+name)
        clients.append(client)
    def add_student(first,last,account,price=''):
        admin.request('page=student');admin.post('student_save',{'first_name':first,'last_name':last,'account_id':str(account),'status':'active','tariff_id':str(tariff),'price':price,'internal_notes':'PRIVATE_COACH_NOTE','custom[1]':'Gruppe 1'})
        return int(scalar('SELECT id FROM students WHERE first_name=? AND last_name=?',[first,last]))
    s1=add_student('Anna','Test',ids[0]);s2=add_student('Leon','Test',ids[0],'30');s3=add_student('Mia','Other',ids[1])
    one,two=clients
    one.request('page=students');assert_ok('Anna Test' in one.html and 'Leon Test' in one.html and 'Mia Other' not in one.html,'One account sees multiple linked students only')
    one.request('page=student&id='+str(s1));assert_ok('PRIVATE_COACH_NOTE' not in one.html,'Internal notes never rendered to students')
    one.request('page=student&id='+str(s3));assert_ok(one.status==403 and 'Mia Other' not in one.html,'Cross-account student access denied')
    one.request('page=student&id='+str(s1));one.raw('student_save',{'id':str(s3),'first_name':'Hacked','last_name':'Other','return_page':'students'})
    assert_ok(scalar('SELECT first_name FROM students WHERE id=?',[s3])=='Mia','Cross-account student modification denied')
    one.request('page=settings');assert_ok(one.status==403,'Student cannot access administrator settings')
    one.request('page=student&id='+str(s1));stale=one.form('student_save')
    admin.request('page=student&id='+str(s1));admin.post('student_save',{'internal_notes':'PRIVATE_COACH_NOTE updated'})
    stale.update({'first_name':'Overwritten'});one.request(data=stale)
    assert_ok(scalar('SELECT first_name FROM students WHERE id=?',[s1])=='Anna','Stale form cannot overwrite a newer student record')
    admin.request('page=student&id='+str(s1)+'&tab=contacts');admin.post('contact_add',{'owner_name':'Test Parent','relation_label':'Mother','phone':'123','email':'parent@example.test'})
    contact=int(scalar('SELECT MAX(id) FROM contacts'));admin.request('page=student&id='+str(s1)+'&tab=contacts');admin.post('contact_save',{'id':str(contact),'phone':'456'})
    assert_ok(scalar('SELECT phone FROM contacts WHERE id=?',[contact])=='456','Labelled contact details can be edited')

    admin.request('page=student&id='+str(s1)+'&tab=payments');admin.post('charge_add',{'student_id':str(s1),'label':'September','amount':'40','period_from':'2026-09-01','period_to':'2026-09-30','due_on':'2020-01-01'})
    charge=int(scalar('SELECT MAX(id) FROM charges'))
    admin.request('page=student&id='+str(s1)+'&tab=payments');payment_form=admin.form('payment_add');payment_form.update({'charge_id':str(charge),'amount':'15','paid_on':'2026-09-15','method':'Überweisung'})
    admin.request(data=payment_form);assert_ok('40,00 € offen' in admin.html,'Unconfirmed payment does not reduce balance')
    admin.request(data=payment_form);assert_ok(scalar('SELECT COUNT(*) FROM payments WHERE charge_id=?',[charge])==1,'Repeated POST cannot duplicate payment')
    pay=int(scalar('SELECT MAX(id) FROM payments'));admin.request('page=student&id='+str(s1)+'&tab=payments');admin.raw('payment_state',{'id':str(pay),'mode':'confirm','return_page':'student','return_id':str(s1),'return_tab':'payments'})
    assert_ok('25,00 € offen' in admin.html,'Confirmed partial payment reduces balance correctly')
    one.request('page=student&id='+str(s1)+'&tab=payments');one.raw('payment_add',{'charge_id':str(charge),'amount':'25','paid_on':'2026-09-15','method':'Bar','confirmed':'1','return_page':'students'})
    assert_ok(scalar('SELECT COUNT(*) FROM payments WHERE charge_id=?',[charge])==1,'Student cannot record or confirm payments')
    admin.request('page=student&id='+str(s1)+'&tab=payments');admin.post('payment_add',{'amount':'26','paid_on':'2026-09-15','method':'Bar','confirmed':'1'})
    assert_ok(scalar('SELECT COUNT(*) FROM payments WHERE charge_id=?',[charge])==1,'Over-allocation is rejected')
    admin.request('page=settings&tab=tariffs&edit='+str(tariff));admin.post('tariff_save',{'name':'Junior','price':'70','period':'monthly','due_days':'10'})
    assert_ok(scalar('SELECT price_cents FROM students WHERE id=?',[s1])==4000 and scalar('SELECT price_cents FROM students WHERE id=?',[s2])==3000,'Tariff changes preserve agreed and individual prices')
    admin.request('page=settings&tab=fields&edit=1');admin.post('field_save',{'label':'Trainingsgruppe Neu','label_en':'Training group','field_type':'select','options':'Gruppe 1\nGruppe 2','visibility':'view','sort_order':'5','default_value':'Gruppe 2'})
    assert_ok(json.loads(scalar('SELECT value_json FROM field_values WHERE student_id=? AND field_id=1',[s1]))=='Gruppe 1','Renaming and changed defaults preserve custom values')
    admin.request('page=settings&tab=fields&edit=1');admin.post('field_save',{'label':'Trainingsgruppe Neu','field_type':'number','visibility':'view','sort_order':'5','default_value':'1'})
    assert_ok(scalar('SELECT field_type FROM field_definitions WHERE id=1')=='select','Field type cannot silently convert stored data')
    admin.request('page=settings&tab=fields&edit=1');f=admin.form('field_save');f['archived']='1';admin.request(data=f)
    assert_ok(scalar('SELECT COUNT(*) FROM field_values WHERE field_id=1')==3,'Archiving preserves all field values')
    one.request('page=student&id='+str(s1)+'&tab=absence');one.post('absence_add',{'reason':'sick','starts_on':'2020-01-01','ends_on':'2099-12-31'})
    admin.request('page=students&absence=sick');assert_ok('Anna Test' in admin.html and 'Leon Test' not in admin.html,'Absence filter respects student and dates')
    admin.request('page=students&overdue=1');assert_ok('Anna Test' in admin.html and 'Leon Test' not in admin.html,'Overdue filter uses confirmed balances')
    one.request('page=messages&new=1');one.post('message_send',{'subject':'Training question','body':'Is training happening? <script>alert(1)</script>'})
    thread=int(scalar('SELECT MAX(id) FROM threads'))
    assert_ok('&lt;script&gt;' in one.html and '<script>alert(1)' not in one.html,'Message text is escaped')
    two.request('page=messages&id='+str(thread));assert_ok(two.status==403,'Private conversation is isolated by account')
    admin.request('page=compose');admin.post('bulk_preview',{'student_ids[]':[str(s1),str(s2)],'subject':'Hello','body':'Hello {{first_name}}: {{outstanding}}','send_email':'1'})
    count_before=int(scalar('SELECT COUNT(*) FROM threads'));assert_ok('1 Konten' in admin.html,'Bulk preview groups siblings into one account')
    admin.post('bulk_send');assert_ok(int(scalar('SELECT COUNT(*) FROM threads'))==count_before+1,'Bulk sending creates one conversation per account')
    one.request('page=profile');form=one.form('preferences_save');form.pop('newsletter',None);form.pop('notifications',None);one.request(data=form)
    assert_ok(scalar('SELECT newsletter FROM accounts WHERE id=?',[ids[0]])==0,'Newsletter preference saved separately')
    mail_before=scalar('SELECT COUNT(*) FROM mail_jobs WHERE category=?',['newsletter'])
    admin.request('page=news&new=1');admin.post('news_save',{'title':'Training update','body':'Test announcement.','published':'1','send_email':'1'})
    assert_ok(scalar('SELECT COUNT(*) FROM mail_jobs WHERE category=?',['newsletter'])==mail_before+1,'News emails only subscribed accounts')
    one.request('page=news');assert_ok('Training update' in one.html,'Unsubscribed account can read news in app')
    # Snapshot synthetic screens for separate visual QA; no user data is involved.
    out=Path(os.environ.get('CRM_TEST_RENDER_DIR','/tmp/crm-test-render'));out.mkdir(parents=True,exist_ok=True)
    for name,query in [('dashboard','page=dashboard'),('student','page=student&id='+str(s1)),('payments','page=student&id='+str(s1)+'&tab=payments'),('fields','page=settings&tab=fields&edit=1'),('accounts','page=accounts')]:
        admin.request(query);assert_ok(admin.status==200,'View renders: '+name);(out/(name+'.html')).write_text(admin.html)
    for route in ['messages','compose','news','outbox','profile','settings&tab=tariffs','settings&tab=templates','settings&tab=defaults','settings&tab=smtp','settings&tab=privacy']:
        admin.request('page='+route+'&lang=en');assert_ok(admin.status==200,'English view renders: '+route)
    admin.request('page=accounts');admin.raw('account_state',{'id':str(ids[0]),'mode':'suspend','return_page':'accounts'})
    one.request('page=students');assert_ok('Willkommen zurück' in one.html,'Suspension invalidates an existing session')
    one.login('family1@example.test');assert_ok('Anmeldung nicht möglich' in one.html,'Suspended account cannot sign in')
    admin.request('page=accounts');admin.raw('account_state',{'id':str(ids[0]),'mode':'restore','return_page':'accounts'});one.login('family1@example.test');assert_ok('Anna Test' in one.html,'Restored account can sign in again')
    admin.request('page=accounts');admin.raw('account_state',{'id':str(ids[0]),'mode':'delete','confirmation':'family1@example.test','return_page':'accounts'})
    assert_ok(scalar('SELECT COUNT(*) FROM accounts WHERE id=?',[ids[0]])==0,'Account is deleted')
    assert_ok(scalar('SELECT account_id FROM students WHERE id=?',[s1]) is None and scalar('SELECT COUNT(*) FROM charges WHERE id=?',[charge])==1,'Account deletion keeps students and charges')
    assert_ok(scalar('SELECT COUNT(*) FROM threads WHERE account_id=?',[ids[0]])==0,'Account deletion removes private conversations')
    admin.request('page=student');before=scalar('SELECT COUNT(*) FROM students');bad=admin.form('student_save');bad.update({'csrf':'bad','first_name':'Forgery','last_name':'Test','status':'active'});admin.request(data=bad)
    assert_ok(scalar('SELECT COUNT(*) FROM students')==before,'CSRF forgery rejected')
    admin.request('page=accounts');admin.raw('account_state',{'id':'1','mode':'suspend','return_page':'accounts'})
    assert_ok(scalar('SELECT state FROM accounts WHERE id=1')=='active','Administrator cannot lock themselves out')
    print(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2))

if __name__=='__main__':run()
