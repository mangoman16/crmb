import os,sys,ssl,socketserver,threading,subprocess,json,base64,datetime,ipaddress,re,urllib.request,urllib.error,tempfile
from pathlib import Path
from cryptography import x509
from cryptography.x509.oid import NameOID
from cryptography.hazmat.primitives import hashes,serialization
from cryptography.hazmat.primitives.asymmetric import rsa
root=Path(tempfile.mkdtemp(prefix='badminton-smtp-test-'));app=Path(__file__).resolve().parents[1];sys.path.insert(0,str(app/'tests'))
from integration import db,scalar,Client,PASSWORD,mail_body
if os.environ.get('CRM_TEST_ALLOW_DESTRUCTIVE')!='1':raise RuntimeError('Set CRM_TEST_ALLOW_DESTRUCTIVE=1 only for the disposable test setup.')
php=os.environ.get('CRM_TEST_PHP','php');now=datetime.datetime.now(datetime.timezone.utc);key=rsa.generate_private_key(public_exponent=65537,key_size=2048);name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'localhost')])
cert=x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(days=1)).not_valid_after(now+datetime.timedelta(days=1)).add_extension(x509.SubjectAlternativeName([x509.DNSName('localhost'),x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]),False).add_extension(x509.BasicConstraints(ca=True,path_length=None),True).sign(key,hashes.SHA256())
cp=root/'qa-cert.pem';kp=root/'qa-key.pem';cp.write_bytes(cert.public_bytes(serialization.Encoding.PEM));kp.write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()));tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cp,kp)
received=[];tls_count=[];auth=[]
class SMTP(socketserver.StreamRequestHandler):
 def reply(self,msg):self.wfile.write((msg+'\r\n').encode());self.wfile.flush()
 def handle(self):
  self.reply('220 localhost test')
  while True:
   line=self.rfile.readline().decode().strip()
   if not line:return
   cmd=line.split()[0].upper()
   if cmd in ['EHLO','HELO']:self.reply('250-localhost\r\n250-STARTTLS\r\n250 AUTH LOGIN PLAIN')
   elif cmd=='STARTTLS':
    self.reply('220 Ready');self.connection=tls.wrap_socket(self.connection,server_side=True);self.rfile=self.connection.makefile('rb');self.wfile=self.connection.makefile('wb');tls_count.append(1)
   elif cmd=='AUTH':
    self.reply('334 VXNlcm5hbWU6');u=base64.b64decode(self.rfile.readline().strip()).decode();self.reply('334 UGFzc3dvcmQ6');p=base64.b64decode(self.rfile.readline().strip()).decode();auth.append(u=='test-user' and p=='test-smtp-secret');self.reply('235 authenticated')
   elif cmd in ['MAIL','RCPT','RSET','NOOP']:self.reply('250 OK')
   elif cmd=='DATA':
    self.reply('354 data');parts=[]
    while True:
     b=self.rfile.readline()
     if b==b'.\r\n':break
     if not b:return
     parts.append(b)
    received.append(b''.join(parts).decode());self.reply('250 accepted')
   elif cmd=='QUIT':self.reply('221 bye');return
   else:self.reply('500 unsupported')
class Server(socketserver.ThreadingTCPServer):allow_reuse_address=True;daemon_threads=True
server=Server(('127.0.0.1',2525),SMTP);threading.Thread(target=server.serve_forever,daemon=True).start()
checks=[]
def ok(c,n):
 if not c:raise AssertionError(n)
 checks.append(n)
try:
 admin=Client();admin.login('coach@example.test');family=Client();family.login('family2@example.test');aid=scalar('SELECT id FROM accounts WHERE email=?',['family2@example.test'])
 # Password reset must revoke old sessions and old tokens.
 anon=Client();anon.request('page=forgot');anon.post('forgot',{'email':'family2@example.test'})
 jid=scalar('SELECT MAX(id) FROM mail_jobs WHERE account_id=? AND category=?',[aid,'security']);token=re.search('token=([a-f0-9]{64})',mail_body(jid)).group(1)
 anon.request('page=activate&token='+token);anon.post('activate',{'password':PASSWORD,'password_confirm':PASSWORD})
 family.request('page=profile');ok('Willkommen zurück' in family.html,'Password reset invalidates older sessions')
 family.login('family2@example.test');family.request('page=profile');family.post('email_change',{'email':'new-family@example.test','password':PASSWORD})
 ok(scalar('SELECT email FROM accounts WHERE id=?',[aid])=='family2@example.test','Email stays unchanged until verification')
 jid=scalar('SELECT MAX(id) FROM mail_jobs WHERE account_id=? AND category=?',[aid,'security']);token=re.search('token=([a-f0-9]{64})',mail_body(jid)).group(1)
 family.request('page=activate&token='+token);family.post('activate')
 ok(scalar('SELECT email FROM accounts WHERE id=?',[aid])=='new-family@example.test','New address requires its verification token')
 # An expired invitation stays unusable.
 admin.request('page=accounts');admin.post('account_invite',{'name':'Expired Test','email':'expired@example.test','locale':'de','role':'student'})
 expired=scalar('SELECT id FROM accounts WHERE email=?',['expired@example.test']);jid=scalar('SELECT MAX(id) FROM mail_jobs WHERE account_id=?',[expired]);token=re.search('token=([a-f0-9]{64})',mail_body(jid)).group(1)
 db('UPDATE auth_tokens SET expires_at=? WHERE account_id=?',['2020-01-01 00:00:00',expired]);anon.request('page=activate&token='+token);ok('Link nicht mehr gültig' in anon.html,'Expired invitation rejected')
 # Force a new subscription delivery after email change.
 admin.request('page=news&new=1');admin.post('news_save',{'title':'SMTP newsletter','body':'A local delivery test.','published':'1','send_email':'1'})
 admin.request('page=settings&tab=smtp');admin.post('smtp_test')
 proc=subprocess.run([php,'-d','openssl.cafile='+str(cp),str(app/'bin/console.php'),'mail:work','100'],capture_output=True,text=True)
 ok(proc.returncode==0,'SMTP worker completes with valid STARTTLS certificate: '+proc.stdout.strip())
 count=json.loads(proc.stdout);ok(count['sent']>=2 and len(received)==count['sent'],'SMTP acceptance recorded per recipient')
 ok(all(auth) and len(auth)==count['sent'] and len(tls_count)==count['sent'],'SMTP authentication and TLS used for every email')
 ok(scalar('SELECT status FROM mail_jobs WHERE id=?',[jid])=='cancelled','Expired queued security email is cancelled')
 ok(all('Dieses Postfach wird nicht gelesen.' in x or 'This mailbox is not monitored.' in x for x in received),'No-reply notice included')
 from email import message_from_bytes
 decoded=[message_from_bytes(x.encode()).get_payload(decode=True).decode('utf-8') for x in received]
 news=next(x for x in decoded if 'A local delivery test.' in x)
 link=re.search(r'http[^\s]+page=unsubscribe[^\s]+',news).group(0)
 before=scalar('SELECT newsletter FROM accounts WHERE id=?',[aid]);unsub=Client();unsub.request(link)
 ok(scalar('SELECT newsletter FROM accounts WHERE id=?',[aid])==before,'Visiting unsubscribe link alone does not mutate preferences')
 unsub.post('unsubscribe');ok(scalar('SELECT newsletter FROM accounts WHERE id=?',[aid])==0,'Signed unsubscribe confirmation works without sign-in')
 # Maintenance commands use the shared flag and preserve data.
 before_counts=subprocess.run([php,str(app/'bin/console.php'),'check'],capture_output=True,text=True,check=True).stdout
 subprocess.run([php,str(app/'bin/console.php'),'maintenance:on'],capture_output=True,check=True)
 try:
  try:urllib.request.build_opener(urllib.request.ProxyHandler({})).open(os.environ['CRM_TEST_URL']+'/index.php',timeout=5);status=200
  except urllib.error.HTTPError as ex:status=ex.code
  ok(status==503,'Maintenance returns 503 before web access')
  stopped=subprocess.run([php,str(app/'bin/console.php'),'mail:work'],capture_output=True,text=True)
  ok(stopped.returncode!=0 and 'Maintenance' in stopped.stderr,'Maintenance stops new mail workers')
 finally:subprocess.run([php,str(app/'bin/console.php'),'maintenance:off'],capture_output=True,check=True)
 after=json.loads(subprocess.run([php,str(app/'bin/console.php'),'check'],capture_output=True,text=True,check=True).stdout);before=json.loads(before_counts)
 ok(before['rows']==after['rows'] and before['totals_cents']==after['totals_cents'],'Maintenance toggle preserves counts and payment totals')
 print(json.dumps({'passed':len(checks),'checks':checks},indent=2))
finally:server.shutdown();server.server_close()
