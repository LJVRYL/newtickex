<?php
/** No credentials or financial mutations. One private SQLite database per brand. */
final class GESalesStore {
    private $pdo;
    public function __construct($path) {
        $this->pdo = new PDO('sqlite:'.$path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout=3000');
        $this->pdo->exec('PRAGMA secure_delete=ON');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS browsers (id TEXT PRIMARY KEY, active INTEGER NOT NULL, expires INTEGER NOT NULL)');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contexts (order_id INTEGER PRIMARY KEY, browser TEXT NOT NULL, context TEXT NOT NULL, created INTEGER NOT NULL, state TEXT NOT NULL DEFAULT 'pending', result TEXT NOT NULL DEFAULT '')");
    }
    public function permission($token, $grant, $now=null) {
        $now=$now===null?time():$now;
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('Invalid browser');
        $hash=hash('sha256',$token);
        $st=$this->pdo->prepare('INSERT OR REPLACE INTO browsers(id,active,expires) VALUES(?,?,?)');
        $st->execute(array($hash,$grant?1:0,$now+180*86400));
        if (!$grant) {
            $st=$this->pdo->prepare("UPDATE contexts SET context='', state=CASE WHEN state='pending' THEN 'revoked' ELSE state END WHERE browser=?");
            $st->execute(array($hash));
        }
    }
    public function capture($orderId, array $cookies, $now=null) {
        $now=$now===null?time():$now;
        $token=$cookies['ge_sales_browser']??'';
        if (!is_int($orderId)||$orderId<=0||!preg_match('/^[a-f0-9]{64}$/D',$token)) return false;
        if (($cookies['ge_growth_consent']??'')!=='granted') return false;
        $hash=hash('sha256',$token);
        $st=$this->pdo->prepare('SELECT active,expires FROM browsers WHERE id=?');$st->execute(array($hash));$permission=$st->fetch(PDO::FETCH_ASSOC);
        if (!$permission||!$permission['active']||$permission['expires']<=$now) return false;
        if (!preg_match('/^GA\d+\.\d+\.(\d{1,20}\.\d{1,20})$/D',$cookies['_ga']??'',$match)) return false;
        $context=array('client_id'=>$match[1],'consented_at'=>$now,'scope'=>'sales-v1');
        $sid=$cookies['ge_growth_session']??'';
        if (preg_match('/^[0-9]{1,12}$/D',$sid)&&((int)$sid)>0) $context['session_id']=(int)$sid;
        $st=$this->pdo->prepare('INSERT OR IGNORE INTO contexts(order_id,browser,context,created) VALUES(?,?,?,?)');
        $st->execute(array($orderId,$hash,json_encode($context),$now));return $st->rowCount()===1;
    }
    public function pending($now=null) {
        $now=$now===null?time():$now;
        $this->pdo->prepare("UPDATE contexts SET context='',state='expired' WHERE state='pending' AND created<?")->execute(array($now-180*86400));
        $this->pdo->prepare("UPDATE contexts SET context='',browser='' WHERE browser IN (SELECT id FROM browsers WHERE expires<=?)")->execute(array($now));
        $this->pdo->prepare('DELETE FROM browsers WHERE expires<=?')->execute(array($now));
        $st=$this->pdo->prepare("SELECT c.order_id,c.context,c.created FROM contexts c JOIN browsers b ON b.id=c.browser WHERE c.state='pending' AND b.active=1 AND b.expires>? ORDER BY c.order_id LIMIT 100");$st->execute(array($now));return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public function finish($orderId,$state,$result='') {
        if (!in_array($state,array('sent','invalid','expired','unconfirmed','transport_error','revoked'),true)) throw new RuntimeException('Invalid result');
        $st=$this->pdo->prepare("UPDATE contexts SET state=?,result=?,context='' WHERE order_id=? AND state='pending'");$st->execute(array($state,substr($result,0,80),$orderId));
    }
    public function guardedSend($orderId, callable $send) {
        // Serialize withdrawal and sending. A queued event cannot pass a committed withdrawal.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $st=$this->pdo->prepare("SELECT c.context FROM contexts c JOIN browsers b ON b.id=c.browser WHERE c.order_id=? AND c.state='pending' AND b.active=1 AND b.expires>?");$st->execute(array($orderId,time()));
            $context=$st->fetchColumn();
            if ($context===false) {$this->pdo->exec('COMMIT');return false;}
            $result=$send(json_decode($context,true));
            $this->finish($orderId,$result['state'],$result['result']??'');
            $this->pdo->exec('COMMIT');return true;
        } catch(Throwable $e) {$this->pdo->exec('ROLLBACK');throw $e;}
    }
}

function ge_sales_permission_endpoint($brand,$origin) {
    header('Cache-Control: no-store');header('Content-Type: application/json');header('X-Robots-Tag: noindex, nofollow');
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_ORIGIN']??'')!==$origin) {http_response_code(403);echo '{"ok":false}';return;}
    $action=$_POST['action']??'';
    if (!in_array($action,array('grant','revoke'),true)) {http_response_code(400);echo '{"ok":false}';return;}
    try {
        $token=$_COOKIE['ge_sales_browser']??'';
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) $token=bin2hex(random_bytes(32));
        $store=new GESalesStore(($brand==='graphex'?'/home/graphexpress/public_html/.ge-sales-context':'/opt/ferozo3/web/str/.ge-sales-context').'/queue.sqlite');
        $store->permission($token,$action==='grant');
        setcookie('ge_sales_browser',$token,array('expires'=>time()+180*86400,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'));
        echo '{"ok":true}';
    } catch(Throwable $e) {http_response_code(503);echo '{"ok":false}';}
}
