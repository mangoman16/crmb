"""A local SMTP sink for tests/e2e.sh: accepts everything, delivers nothing.

    python3 tests/e2e_smtp.py <port> <cert.pem> <key.pem> <maildir>

Speaks just enough SMTP for PHPMailer: EHLO, STARTTLS (with the throwaway
certificate the wrapper made, which the PHP server is told to trust through
openssl.cafile), AUTH LOGIN/PLAIN, MAIL, RCPT, DATA. Every accepted message is
written to <maildir>/<n>.eml so the browser test can read the invitation link
out of it exactly as a parent would read it out of their inbox. Standard
library only, so it runs wherever python3 does.
"""
import base64, os, socketserver, ssl, sys, threading

port, cert, key, maildir = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
os.makedirs(maildir, exist_ok=True)
tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
tls.load_cert_chain(cert, key)
lock = threading.Lock()


class Handler(socketserver.StreamRequestHandler):
    def reply(self, text):
        self.wfile.write((text + '\r\n').encode())
        self.wfile.flush()

    def handle(self):
        self.reply('220 localhost e2e sink')
        secure = False
        rcpt = []
        while True:
            raw = self.rfile.readline()
            if not raw:
                return
            line = raw.decode(errors='replace').strip()
            cmd = (line.split() or [''])[0].upper()
            if cmd in ('EHLO', 'HELO'):
                self.reply('250-localhost' + ('' if secure else '\r\n250-STARTTLS') + '\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME')
            elif cmd == 'STARTTLS':
                self.reply('220 Ready')
                self.connection = tls.wrap_socket(self.connection, server_side=True)
                self.rfile = self.connection.makefile('rb')
                self.wfile = self.connection.makefile('wb')
                secure = True
            elif cmd == 'AUTH':
                parts = line.split()
                if len(parts) >= 2 and parts[1].upper() == 'PLAIN':
                    if len(parts) < 3:
                        self.reply('334 ')
                        self.rfile.readline()
                else:
                    self.reply('334 VXNlcm5hbWU6')
                    self.rfile.readline()
                    self.reply('334 UGFzc3dvcmQ6')
                    self.rfile.readline()
                self.reply('235 authenticated')
            elif cmd == 'MAIL':
                rcpt = []
                self.reply('250 OK')
            elif cmd == 'RCPT':
                rcpt.append(line.split(':', 1)[-1].strip().strip('<>'))
                self.reply('250 OK')
            elif cmd in ('RSET', 'NOOP'):
                self.reply('250 OK')
            elif cmd == 'DATA':
                self.reply('354 go ahead')
                chunks = []
                while True:
                    b = self.rfile.readline()
                    if not b:
                        return
                    if b == b'.\r\n':
                        break
                    chunks.append(b[1:] if b.startswith(b'..') else b)
                with lock:
                    n = len([f for f in os.listdir(maildir) if f.endswith('.eml')]) + 1
                    path = os.path.join(maildir, '%04d.eml' % n)
                    with open(path + '.tmp', 'wb') as f:
                        f.write(('X-E2E-Rcpt: ' + ','.join(rcpt) + '\r\n').encode() + b''.join(chunks))
                    os.replace(path + '.tmp', path)
                self.reply('250 accepted')
            elif cmd == 'QUIT':
                self.reply('221 bye')
                return
            else:
                self.reply('500 unsupported')


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


Server(('127.0.0.1', port), Handler).serve_forever()
