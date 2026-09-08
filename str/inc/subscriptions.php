<?php

if (!function_exists('tickex_subscriptions_ensure_schema')) {
    function tickex_subscriptions_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT,
            monthly_price REAL,
            service_fee_percent REAL NOT NULL DEFAULT 15,
            qr_limit_monthly INTEGER,
            features_json TEXT,
            status TEXT NOT NULL DEFAULT 'draft',
            sort_order INTEGER NOT NULL DEFAULT 100,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL UNIQUE,
            plan_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            starts_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ends_at TEXT,
            assigned_by_admin_id INTEGER,
            limit_exempt INTEGER NOT NULL DEFAULT 0,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_change_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL,
            from_plan_id INTEGER,
            to_plan_id INTEGER,
            from_status TEXT,
            to_status TEXT,
            changed_by_admin_id INTEGER,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_settings (
            id INTEGER PRIMARY KEY CHECK (id=1),
            enforcement_enabled INTEGER NOT NULL DEFAULT 0,
            updated_by_admin_id INTEGER,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_subscriptions_plan ON admin_subscriptions(plan_id,status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_subscription_log_admin ON subscription_change_log(admin_id,created_at)");
        $pdo->exec("INSERT OR IGNORE INTO subscription_settings (id,enforcement_enabled) VALUES (1,0)");
        $columns=$pdo->query("PRAGMA table_info('admin_subscriptions')")->fetchAll(PDO::FETCH_ASSOC);$hasExempt=false;
        foreach($columns as $column)if($column['name']==='limit_exempt')$hasExempt=true;
        if(!$hasExempt)$pdo->exec('ALTER TABLE admin_subscriptions ADD COLUMN limit_exempt INTEGER NOT NULL DEFAULT 0');

        $defaults = array(
            array('initial','Inicial','Para empezar a vender con todas las herramientas esenciales.',0,15,300,'["Eventos y check-in","Mercado Pago Split","Staff y comunicación"]','active',10),
            array('growth','Crecimiento','Más capacidad para equipos y eventos en expansión.',null,12.5,2000,'["Todo Inicial","Mayor volumen mensual","Soporte prioritario"]','draft',20),
            array('professional','Profesional','Operación de alto volumen con condiciones personalizadas.',null,10,null,'["Todo Crecimiento","Volumen personalizado","Acompañamiento comercial"]','draft',30),
        );
        $st = $pdo->prepare('INSERT OR IGNORE INTO subscription_plans (code,name,description,monthly_price,service_fee_percent,qr_limit_monthly,features_json,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($defaults as $row) $st->execute($row);

        // Toda cuenta organizadora queda visible en el módulo desde el primer día.
        // No altera cobros ni limita emisiones mientras enforcement_enabled sea 0.
        $initialId = (int)$pdo->query("SELECT id FROM subscription_plans WHERE code='initial' LIMIT 1")->fetchColumn();
        $hasAdmins = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='usuarios_admin'")->fetchColumn() > 0;
        if ($initialId > 0 && $hasAdmins) {
            $st = $pdo->prepare("INSERT OR IGNORE INTO admin_subscriptions (admin_id,plan_id,status,starts_at,note)
                SELECT id,:plan,'active',CURRENT_TIMESTAMP,'Asignación inicial automática'
                FROM usuarios_admin WHERE tipo_global='admin_evento'");
            $st->execute(array(':plan'=>$initialId));
            $hasPolicies=(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mercadopago_admin_policies'")->fetchColumn()>0;
            if($hasPolicies)$pdo->exec("UPDATE admin_subscriptions SET limit_exempt=1 WHERE admin_id IN (SELECT admin_id FROM mercadopago_admin_policies WHERE account_type='str_owner')");
        }
        $hasEvents=(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='eventos'")->fetchColumn()>0;
        $hasEntries=(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='entradas'")->fetchColumn()>0;
        if($hasAdmins&&$hasEvents&&$hasEntries){
            $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_subscription_qr_limit BEFORE INSERT ON entradas
            WHEN COALESCE((SELECT enforcement_enabled FROM subscription_settings WHERE id=1),0)=1
             AND EXISTS (SELECT 1 FROM eventos ev JOIN usuarios_admin ua ON ua.id=ev.creado_por_admin_id WHERE ev.id=NEW.evento_id AND ua.tipo_global='admin_evento')
             AND COALESCE((SELECT s.limit_exempt FROM admin_subscriptions s JOIN eventos ev ON ev.creado_por_admin_id=s.admin_id WHERE ev.id=NEW.evento_id),0)=0
             AND (
               NOT EXISTS (SELECT 1 FROM admin_subscriptions s JOIN subscription_plans p ON p.id=s.plan_id JOIN eventos ev ON ev.creado_por_admin_id=s.admin_id WHERE ev.id=NEW.evento_id AND s.status IN ('active','trial') AND p.status='active' AND (s.ends_at IS NULL OR s.ends_at='' OR datetime(s.ends_at)>=datetime('now')))
               OR COALESCE((SELECT p.qr_limit_monthly FROM admin_subscriptions s JOIN subscription_plans p ON p.id=s.plan_id JOIN eventos ev ON ev.creado_por_admin_id=s.admin_id WHERE ev.id=NEW.evento_id),0)>0
                  AND (SELECT COUNT(*) FROM entradas en JOIN eventos owner_ev ON owner_ev.id=en.evento_id WHERE owner_ev.creado_por_admin_id=(SELECT creado_por_admin_id FROM eventos WHERE id=NEW.evento_id) AND COALESCE(en.oculto,0)=0 AND en.fecha_registro>=datetime('now','start of month') AND en.fecha_registro<datetime('now','start of month','+1 month')) >= (SELECT p.qr_limit_monthly FROM admin_subscriptions s JOIN subscription_plans p ON p.id=s.plan_id JOIN eventos ev ON ev.creado_por_admin_id=s.admin_id WHERE ev.id=NEW.evento_id)
             )
            BEGIN SELECT RAISE(ABORT,'Límite mensual de QR alcanzado. Revisá el plan del organizador.'); END");
        }
    }
}

if (!function_exists('tickex_subscription_statuses')) {
    function tickex_subscription_statuses() { return array('trial'=>'Prueba','active'=>'Activo','paused'=>'Pausado','cancelled'=>'Cancelado'); }
}

if (!function_exists('tickex_subscription_settings')) {
    function tickex_subscription_settings($pdo)
    {
        tickex_subscriptions_ensure_schema($pdo);
        $row = $pdo->query('SELECT * FROM subscription_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return $row ?: array('enforcement_enabled'=>0);
    }
}

if (!function_exists('tickex_subscription_set_enforcement')) {
    function tickex_subscription_set_enforcement($pdo, $enabled, $updatedBy)
    {
        tickex_subscriptions_ensure_schema($pdo);
        $st=$pdo->prepare('UPDATE subscription_settings SET enforcement_enabled=:enabled,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=1');
        $st->execute(array(':enabled'=>$enabled?1:0,':admin'=>(int)$updatedBy));
    }
}

if (!function_exists('tickex_subscription_plans')) {
    function tickex_subscription_plans($pdo, $includeDrafts)
    {
        tickex_subscriptions_ensure_schema($pdo);
        $sql='SELECT * FROM subscription_plans'.($includeDrafts?'':" WHERE status='active'").' ORDER BY sort_order,id';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('tickex_subscription_save_plan')) {
    function tickex_subscription_save_plan($pdo, $data)
    {
        tickex_subscriptions_ensure_schema($pdo);
        $id=isset($data['id'])?(int)$data['id']:0;
        $name=trim(isset($data['name'])?(string)$data['name']:'');
        $code=strtolower(trim(isset($data['code'])?(string)$data['code']:''));
        $code=preg_replace('/[^a-z0-9_-]+/','-',$code);
        if($name===''||$code==='') throw new RuntimeException('Completá el nombre y código del plan.');
        $fee=max(0,min(100,(float)str_replace(',','.',isset($data['service_fee_percent'])?$data['service_fee_percent']:0)));
        $limit=trim(isset($data['qr_limit_monthly'])?(string)$data['qr_limit_monthly']:'')===''
            ? null : max(0,(int)$data['qr_limit_monthly']);
        if($limit===0)$limit=null;
        $price=trim(isset($data['monthly_price'])?(string)$data['monthly_price']:'')===''
            ? null : max(0,(float)str_replace(',','.',(string)$data['monthly_price']));
        $status=isset($data['status'])&&$data['status']==='active'?'active':'draft';
        $features=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',isset($data['features'])?(string)$data['features']:''))));
        $params=array(':code'=>$code,':name'=>$name,':description'=>trim(isset($data['description'])?(string)$data['description']:''),':price'=>$price,':fee'=>$fee,':limit'=>$limit,':features'=>json_encode($features,JSON_UNESCAPED_UNICODE),':status'=>$status,':sort'=>isset($data['sort_order'])?(int)$data['sort_order']:100);
        if($id>0){$params[':id']=$id;$st=$pdo->prepare('UPDATE subscription_plans SET code=:code,name=:name,description=:description,monthly_price=:price,service_fee_percent=:fee,qr_limit_monthly=:limit,features_json=:features,status=:status,sort_order=:sort,updated_at=CURRENT_TIMESTAMP WHERE id=:id');}
        else{$st=$pdo->prepare('INSERT INTO subscription_plans(code,name,description,monthly_price,service_fee_percent,qr_limit_monthly,features_json,status,sort_order) VALUES(:code,:name,:description,:price,:fee,:limit,:features,:status,:sort)');}
        $st->execute($params);
        return $id>0?$id:(int)$pdo->lastInsertId();
    }
}

if (!function_exists('tickex_subscription_for_admin')) {
    function tickex_subscription_for_admin($pdo, $adminId)
    {
        tickex_subscriptions_ensure_schema($pdo);
        $st=$pdo->prepare('SELECT s.*,p.code AS plan_code,p.name AS plan_name,p.description AS plan_description,p.monthly_price,p.service_fee_percent,p.qr_limit_monthly,p.features_json,p.status AS plan_status FROM admin_subscriptions s JOIN subscription_plans p ON p.id=s.plan_id WHERE s.admin_id=:admin LIMIT 1');
        $st->execute(array(':admin'=>(int)$adminId));
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('tickex_subscription_assign')) {
    function tickex_subscription_assign($pdo,$adminId,$planId,$status,$endsAt,$note,$changedBy,$limitExempt=null)
    {
        tickex_subscriptions_ensure_schema($pdo);
        if(!isset(tickex_subscription_statuses()[$status])) throw new RuntimeException('Estado de suscripción inválido.');
        $st=$pdo->prepare("SELECT id FROM usuarios_admin WHERE id=:id AND tipo_global='admin_evento' LIMIT 1");$st->execute(array(':id'=>(int)$adminId));
        if(!$st->fetchColumn()) throw new RuntimeException('El usuario seleccionado no es un organizador.');
        $st=$pdo->prepare('SELECT id FROM subscription_plans WHERE id=:id');$st->execute(array(':id'=>(int)$planId));if(!$st->fetchColumn())throw new RuntimeException('Plan inválido.');
        if ($endsAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsAt)) $endsAt .= ' 23:59:59';
        $before=tickex_subscription_for_admin($pdo,$adminId);
        if($limitExempt===null)$limitExempt=$before&&!empty($before['limit_exempt']);
        $pdo->beginTransaction();
        try{
            $st=$pdo->prepare("INSERT OR REPLACE INTO admin_subscriptions(admin_id,plan_id,status,starts_at,ends_at,assigned_by_admin_id,limit_exempt,note,created_at,updated_at) VALUES(:admin,:plan,:status,COALESCE((SELECT starts_at FROM admin_subscriptions WHERE admin_id=:admin),CURRENT_TIMESTAMP),:ends,:by,:exempt,:note,COALESCE((SELECT created_at FROM admin_subscriptions WHERE admin_id=:admin),CURRENT_TIMESTAMP),CURRENT_TIMESTAMP)");
            $st->execute(array(':admin'=>(int)$adminId,':plan'=>(int)$planId,':status'=>$status,':ends'=>$endsAt!==''?$endsAt:null,':by'=>(int)$changedBy,':exempt'=>$limitExempt?1:0,':note'=>trim((string)$note)));
            $log=$pdo->prepare('INSERT INTO subscription_change_log(admin_id,from_plan_id,to_plan_id,from_status,to_status,changed_by_admin_id,note) VALUES(?,?,?,?,?,?,?)');
            $log->execute(array((int)$adminId,$before?(int)$before['plan_id']:null,(int)$planId,$before?$before['status']:null,$status,(int)$changedBy,trim((string)$note)));
            $pdo->commit();
        }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return tickex_subscription_for_admin($pdo,$adminId);
    }
}

if (!function_exists('tickex_subscription_period')) {
    function tickex_subscription_period($timestamp=null)
    {
        $ts=$timestamp===null?time():(int)$timestamp;
        return array(date('Y-m-01 00:00:00',$ts),date('Y-m-t 23:59:59',$ts));
    }
}

if (!function_exists('tickex_subscription_usage')) {
    function tickex_subscription_usage($pdo,$adminId,$timestamp=null)
    {
        list($from,$to)=tickex_subscription_period($timestamp);
        $st=$pdo->prepare("SELECT COUNT(*) FROM entradas en JOIN eventos ev ON ev.id=en.evento_id WHERE ev.creado_por_admin_id=:admin AND COALESCE(en.oculto,0)=0 AND en.fecha_registro>=:from AND en.fecha_registro<=:to");
        $st->execute(array(':admin'=>(int)$adminId,':from'=>$from,':to'=>$to));
        return array('used'=>(int)$st->fetchColumn(),'from'=>$from,'to'=>$to);
    }
}

if (!function_exists('tickex_subscription_access')) {
    function tickex_subscription_access($pdo,$adminId,$additionalQr=0)
    {
        $settings=tickex_subscription_settings($pdo);$subscription=tickex_subscription_for_admin($pdo,$adminId);$usage=tickex_subscription_usage($pdo,$adminId);
        $limit=$subscription&&$subscription['qr_limit_monthly']!==null&&(int)$subscription['qr_limit_monthly']>0?(int)$subscription['qr_limit_monthly']:null;
        $statusOk=$subscription&&in_array($subscription['status'],array('active','trial'),true)&&($subscription['ends_at']===''||$subscription['ends_at']===null||strtotime($subscription['ends_at'])>=time());
        $within=$limit===null||($usage['used']+max(0,(int)$additionalQr)<=$limit);
        $exempt=$subscription&&!empty($subscription['limit_exempt']);
        return array('allowed'=>empty($settings['enforcement_enabled'])||$exempt||($statusOk&&$within),'observing'=>empty($settings['enforcement_enabled']),'exempt'=>$exempt,'subscription'=>$subscription,'usage'=>$usage,'limit'=>$limit,'remaining'=>$limit===null?null:max(0,$limit-$usage['used']));
    }
}

if (!function_exists('tickex_subscription_service_fee')) {
    function tickex_subscription_service_fee($pdo,$adminId,$fallback)
    {
        $settings=tickex_subscription_settings($pdo);
        if(empty($settings['enforcement_enabled'])) return (float)$fallback;
        $subscription=tickex_subscription_for_admin($pdo,$adminId);
        if(!$subscription||!in_array($subscription['status'],array('active','trial'),true)||$subscription['plan_status']!=='active') return (float)$fallback;
        return max(0,min(100,(float)$subscription['service_fee_percent']));
    }
}

if (!function_exists('tickex_subscription_admin_rows')) {
    function tickex_subscription_admin_rows($pdo,$query='')
    {
        tickex_subscriptions_ensure_schema($pdo);
        $sql="SELECT a.id,a.email,a.nombre,a.apellido,a.activo,s.plan_id,s.status AS subscription_status,s.ends_at,s.limit_exempt,p.name AS plan_name,p.service_fee_percent,p.qr_limit_monthly FROM usuarios_admin a LEFT JOIN admin_subscriptions s ON s.admin_id=a.id LEFT JOIN subscription_plans p ON p.id=s.plan_id WHERE a.tipo_global='admin_evento'";
        $params=array();if(trim($query)!==''){$sql.=" AND (lower(a.email) LIKE :q OR lower(COALESCE(a.nombre,'')||' '||COALESCE(a.apellido,'')) LIKE :q)";$params[':q']='%'.strtolower(trim($query)).'%';}
        $sql.=' ORDER BY COALESCE(a.nombre,a.email),a.id';$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$usage=tickex_subscription_usage($pdo,(int)$row['id']);$row['used']=$usage['used'];}unset($row);
        return $rows;
    }
}

if (!function_exists('tickex_subscription_recent_changes')) {
    function tickex_subscription_recent_changes($pdo,$limit=20)
    {
        tickex_subscriptions_ensure_schema($pdo);
        $limit=max(1,min(100,(int)$limit));
        $sql="SELECT l.*,a.email,a.nombre,a.apellido,oldp.name AS from_plan_name,newp.name AS to_plan_name,changer.email AS changed_by_email FROM subscription_change_log l JOIN usuarios_admin a ON a.id=l.admin_id LEFT JOIN subscription_plans oldp ON oldp.id=l.from_plan_id LEFT JOIN subscription_plans newp ON newp.id=l.to_plan_id LEFT JOIN usuarios_admin changer ON changer.id=l.changed_by_admin_id ORDER BY l.id DESC LIMIT ".$limit;
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
