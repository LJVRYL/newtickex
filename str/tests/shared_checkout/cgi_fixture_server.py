import os, subprocess, sys
from http.server import HTTPServer,BaseHTTPRequestHandler
from socketserver import ThreadingMixIn
from pathlib import Path
root=Path(__file__).resolve().parent/'fixture'
class Handler(BaseHTTPRequestHandler):
    def do_GET(self): self.run_cgi()
    def do_POST(self): self.run_cgi()
    def run_cgi(self):
        path,_,query=self.path.partition('?')
        length=int(self.headers.get('Content-Length','0'))
        body=self.rfile.read(length)
        env=dict(os.environ,REDIRECT_STATUS='1',GATEWAY_INTERFACE='CGI/1.1',
            REQUEST_METHOD=self.command,QUERY_STRING=query,CONTENT_LENGTH=str(length),
            CONTENT_TYPE=self.headers.get('Content-Type',''),HTTP_COOKIE=self.headers.get('Cookie',''),
            SCRIPT_FILENAME=str(root/'index.php'),SCRIPT_NAME='/index.php',REQUEST_URI=self.path,
            DOCUMENT_ROOT=str(root),SERVER_NAME='127.0.0.1',SERVER_PORT=str(self.server.server_port),
            SERVER_PROTOCOL='HTTP/1.1',REMOTE_ADDR='127.0.0.1')
        proc=subprocess.run([os.environ['FIXTURE_PHP_BIN'],'-d','doc_root=','-d','open_basedir=','-d','cgi.force_redirect=0'],env=env,input=body,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        sys.stderr.buffer.write(proc.stderr)
        head,sep,out=proc.stdout.partition(b'\r\n\r\n')
        if not sep: head,sep,out=proc.stdout.partition(b'\n\n')
        headers=[]; status=200
        for line in head.decode('utf-8','replace').splitlines():
            name,sep,value=line.partition(':')
            if name.lower()=='status': status=int(value.strip().split(' ')[0])
            elif sep: headers.append((name,value.strip()))
        self.send_response(status)
        for name,value in headers: self.send_header(name,value)
        self.end_headers(); self.wfile.write(out)
class Server(ThreadingMixIn,HTTPServer): daemon_threads=True
Server(('127.0.0.1',int(sys.argv[1])),Handler).serve_forever()
