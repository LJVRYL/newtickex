import concurrent.futures, http.cookiejar, os, re, sqlite3, subprocess, time, urllib.request, urllib.parse, socket, uuid
from pathlib import Path
root=Path(__file__).resolve().parent
dbpath=root/('fixture/integration-'+uuid.uuid4().hex+'.sqlite')
env=dict(os.environ,TICKEX_DB_FILE=str(dbpath),TICKEX_MAIL_TRANSPORT='fake')
logs=[]; servers=[]
ports=[]
for _ in range(2):
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); ports.append(s.getsockname()[1])
php=os.environ.get('FIXTURE_PHP_BIN','php')
php_flags=['-d','doc_root=','-d','open_basedir=','-d','cgi.force_redirect=0','-d','display_errors=1'] if os.environ.get('FIXTURE_CGI') else []
setup_php=os.environ.get('FIXTURE_SETUP_PHP_BIN',php)
subprocess.run([setup_php]+php_flags+['-q',str(root/'fixture/setup.php')],env=env,check=True)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
def client():
    jar=http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect()),jar
def req(c,path,data=None,port=None):
    if port is None: port=ports[0]
    url=f'http://127.0.0.1:{port}/index.php?'+path
    body=urllib.parse.urlencode(data,doseq=True).encode() if data else None
    try: r=c.open(url,body,timeout=20)
    except urllib.error.HTTPError as e: r=e
    return r.status,r.read().decode(),r.headers
def fields(body):
    result={}
    for name in ('ref','checkout_attempt','csrf'):
        found=re.search(r'name="'+name+r'" value="([^"]+)"',body)
        assert found,(name,body[:1500])
        result[name]=found.group(1)
    return result
def paydata(body,email='guest@example.invalid',qty=1,typeid=7):
    return dict(fields(body),action='pay',email=email,dni='12345678',first_name='Fixture',last_name='Buyer',selected_id=[typeid],selected_qty=[qty],legal_acceptance='1',create_account='0')
def wire(data):
    data=dict(data)
    data['selected_id[]']=data.pop('selected_id'); data['selected_qty[]']=data.pop('selected_qty')
    return data
def check(condition,label):
    assert condition,label
    print('PASS:',label,flush=True)
def count():
    with sqlite3.connect(str(dbpath)) as db: return db.execute('select count(*) from fixture_gateway_calls').fetchone()[0]
def purchase(c,path,email='guest@example.invalid',qty=1,typeid=7):
    status,body,_=req(c,path)
    check(status==200,'checkout GET available')
    data=wire(paydata(body,email,qty,typeid))
    status,out,hdr=req(c,path,data)
    if status!=303:
        (root/'http-failure.html').write_text(out)
        raise AssertionError('pay expected 303: '+re.sub('<[^>]+>',' ',out)[-8000:])
    return data,hdr['Location']
try:
    for port in ports:
        log=open(root/f'http-{port}.log','w'); logs.append(log)
        command=[php,'-S',f'127.0.0.1:{port}','-t',str(root/'fixture')]
        if os.environ.get('FIXTURE_CGI'): command=['python3',str(root/'cgi_fixture_server.py'),str(port)]
        servers.append(subprocess.Popen(command,env=env,stdout=log,stderr=log))
    time.sleep(.8)
    path='event=23&ref=already-used-public-link'
    with sqlite3.connect(str(dbpath)) as db:
        oldorder=db.execute("select request_id,state,evento_id,ref,amount,buyer_email,payment_url,payment_status,processing_status from tc_orders where ref='already-used-public-link'").fetchone()
        oldclaim=db.execute("select * from totalcoin_checkout_claims where ref='already-used-public-link'").fetchone()
        oldhold=db.execute("select * from checkout_capacity_holds where ref='shared-mp'").fetchone()
    a,_=client(); data,loc=purchase(a,path)
    with sqlite3.connect(str(dbpath)) as db:
        first=db.execute('select * from tc_orders where ref=?',(data['ref'],)).fetchone()
    b,_=client(); data_b,_=purchase(b,path,'other@example.invalid')
    check(data_b['ref']!=data['ref'],'shared used link creates another buyer order')
    c,_=client(); data_c,_=purchase(c,path)
    check(data_c['ref'] not in (data['ref'],data_b['ref']),'shared link with identical email/data creates independent purchase')
    fresh,_=client(); _,freshbody,_=req(fresh,path+'&double=1'); freshdata=wire(paydata(freshbody))
    before=count()
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        clicked=[f.result() for f in [pool.submit(req,fresh,path,freshdata,p) for p in ports]]
    check(all(r[0]==303 for r in clicked) and clicked[0][2]['Location']==clicked[1][2]['Location'] and count()==before+1,'concurrent first double click creates exactly one provider attempt')
    with sqlite3.connect(str(dbpath)) as db:
        check(db.execute('select count(*) from tc_orders where ref=?',(freshdata['ref'],)).fetchone()[0]==1 and db.execute('select count(*) from totalcoin_checkout_claims where ref=?',(freshdata['ref'],)).fetchone()[0]==1,'first double click has one DB order and one claim')
    before=count()
    responses=[]
    # Two real PHP workers share session files and a database.
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        futures=[pool.submit(req,a,path,data,p) for p in ports]
        responses=[f.result() for f in futures]
    check(all(x[0]==303 for x in responses) and count()==before,'concurrent retry returns same persisted checkout without extra provider call')
    check(responses[0][2]['Location']==loc==responses[1][2]['Location'],'retry keeps request id coherent')
    with sqlite3.connect(str(dbpath)) as db:
        check(first==db.execute('select * from tc_orders where ref=?',(data['ref'],)).fetchone(),'retry leaves previous order byte-for-byte unchanged')
    status,out,_=req(b,loc.split('?',1)[1])
    check('https://provider.invalid/checkout?' not in out,'foreign RID cannot expose payment URL')
    status,out,_=req(a,loc.split('?',1)[1])
    check('https://provider.invalid/checkout?' in out,'own PRG continuation still reaches payment')
    changed=dict(data,email='changed@example.invalid'); status,out,_=req(a,path,changed)
    check(status==200 and count()==before and 'Conservamos tu selección' in out and 'changed@example.invalid' in out,'changed paid attempt blocks extra charge and preserves entered data')
    d,_=client(); _,body,_=req(d,path)
    p=wire(paydata(body,'edited@example.invalid',2,8)); p['amount']='1'; status,out,hdr=req(d,path,p)
    check(status==303,'editing email, quantity and type before first pay succeeds')
    with sqlite3.connect(str(dbpath)) as db:
        row=db.execute('select amount,buyer_email,selected_tickets_json from tc_orders where ref=?',(p['ref'],)).fetchone()
        check(row[0]==400 and row[1]=='edited@example.invalid' and 'VIP' in row[2],'server prices and edited selection persisted correctly')
    logged,_=client(); data_l,_=purchase(logged,path+'&fixture_login=7','login@example.invalid')
    check(data_l['ref']!=data['ref'],'logged buyer checkout succeeds with own attempt')
    f,_=client(); _,body,_=req(f,path+'&failure=1'); failing=wire(paydata(body,'failure@example.invalid'))
    before=count(); status,out,_=req(f,path,failing)
    check(status==200 and count()==before+1,'simulated lost provider response recorded once')
    status,out,_=req(f,path,failing)
    check(status==200 and count()==before+1,'failed provider retry never dispatches duplicate')
    with sqlite3.connect(str(dbpath)) as db:
        db.execute("CREATE TRIGGER fixture_insert_failure BEFORE INSERT ON tc_orders WHEN NEW.buyer_email='persistfail@example.invalid' BEGIN SELECT RAISE(ABORT,'synthetic persistence failure'); END"); db.commit()
    persist,_=client(); _,persistbody,_=req(persist,path+'&persist=1'); persistdata=wire(paydata(persistbody,'persistfail@example.invalid'))
    before=count(); status,out,_=req(persist,path,persistdata)
    check(status==200 and count()==before+1 and 'https://provider.invalid/checkout?' not in out,'persistence failure never exposes an unauditable payment URL')
    with sqlite3.connect(str(dbpath)) as db:
        check(db.execute('select status from totalcoin_checkout_claims where ref=?',(persistdata['ref'],)).fetchone()[0]=='dispatching','persistence failure preserves ambiguous dispatch state')
    status,out,_=req(persist,path,persistdata)
    check(status==200 and count()==before+1,'persistence failure retry cannot create another provider payment')
    stolen,_=client(); before=count(); status,out,_=req(stolen,path,data)
    check(status==200 and count()==before and 'sesión de esta compra cambió' in out,'copied POST rejected before provider')
    legacy=dict(data); legacy.pop('checkout_attempt'); status,out,_=req(a,path,legacy)
    check(status==200 and count()==before,'legacy/expired form fails safely without silently rotating pay POST')
    with sqlite3.connect(str(dbpath)) as db:
        db.execute("update tc_orders set payment_status='confirmed' where ref=?",(data['ref'],)); db.commit()
    status,out,_=req(a,path,data)
    check(status==200 and count()==before and 'No reenviamos el pago' in out,'confirmed order cannot be returned as a fresh payment')
    mobref='mobile-'+'a'*32
    with sqlite3.connect(str(dbpath)) as db:
        db.execute('insert into mobile_checkout_intents values(?,7,23,?)',(mobref,'2026-12-31 23:59:59')); db.commit()
    mobile,_=client(); _,mobbody,_=req(mobile,'event=23&ref='+mobref)
    mobdata=wire(paydata(mobbody,'login@example.invalid'))
    check(mobdata['ref']==mobref,'mobile form keeps its authenticated intent reference')
    mobbad=dict(mobdata,email='wrong@example.invalid'); status,out,_=req(mobile,'event=23&ref='+mobref,mobbad)
    check(status==200 and count()==before,'mobile intent rejects another buyer before provider')
    status,out,mobhdr=req(mobile,'event=23&ref='+mobref,mobdata)
    check(status==303 and count()==before+1,'mobile checkout succeeds through existing web route')
    status,out,_=req(mobile,mobhdr['Location'].split('?',1)[1])
    check('https://provider.invalid/checkout?' in out,'mobile PRG continuation retains effective reference')
    before=count(); status,out,_=req(mobile,'event=23&ref='+mobref,mobdata)
    check(status==200 and count()==before,'mobile repeated pay preserves already-started guard')
    m,_=client(); mpdata,mploc=purchase(m,'event=24&ref=shared-mp','mp@example.invalid',2,9)
    with sqlite3.connect(str(dbpath)) as db:
        hold=db.execute('select quantity,status from checkout_capacity_holds where ref=?',(mpdata['ref'],)).fetchone()
        check(hold==(2,'active'),'MP reserve uses own reference and correct capacity')
        previous=db.execute('select * from checkout_capacity_holds where ref=?',(mpdata['ref'],)).fetchone()
    before=count(); status,out,hdr=req(m,'event=24&ref=shared-mp',mpdata)
    check(status==303 and count()==before,'MP retry reuses same preference without duplicate hold')
    othermp,_=client(); mp2,_=purchase(othermp,'event=24&ref=shared-mp','mp@example.invalid',2,9)
    with sqlite3.connect(str(dbpath)) as db:
        check(previous==db.execute('select * from checkout_capacity_holds where ref=?',(mpdata['ref'],)).fetchone(),'shared MP link preserves earlier reserve')
        check(db.execute('select count(*) from checkout_capacity_holds').fetchone()[0]==3,'identical MP data in new context creates distinct reserve')
        check(oldorder==db.execute("select request_id,state,evento_id,ref,amount,buyer_email,payment_url,payment_status,processing_status from tc_orders where ref='already-used-public-link'").fetchone() and oldclaim==db.execute("select * from totalcoin_checkout_claims where ref='already-used-public-link'").fetchone() and oldhold==db.execute("select * from checkout_capacity_holds where ref='shared-mp'").fetchone(),'old public-link order, claim and capacity hold preserved')
        payloads=db.execute("select ref,payload from fixture_gateway_calls where provider='totalcoin'").fetchall()
        import json
        check(all(urllib.parse.parse_qs(urllib.parse.urlparse(json.loads(v)['callbacks']['success']).query)['ref'][0]==r for r,v in payloads),'TotalCoin callbacks carry each effective reference')
        check(db.execute('pragma integrity_check').fetchone()[0]=='ok','synthetic DB integrity verified')
    print('HTTP fixture integration PASS',flush=True)
finally:
    for p in servers:
        p.terminate(); p.wait(timeout=5)
    for log in logs: log.close()

