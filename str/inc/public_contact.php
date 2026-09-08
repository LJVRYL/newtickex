<?php

if (!function_exists('tickex_public_contact_ensure_schema')) {
    function tickex_public_contact_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS public_contact_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT,
            organization TEXT,
            event_type TEXT,
            message TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'landing',
            status TEXT NOT NULL DEFAULT 'new',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_public_contact_status ON public_contact_requests(status, created_at)');
    }
}

if (!function_exists('tickex_public_contact_statuses')) {
    function tickex_public_contact_statuses()
    {
        return array('new'=>'Nueva', 'contacted'=>'Contactada', 'closed'=>'Cerrada');
    }
}

if (!function_exists('tickex_public_contact_create')) {
    function tickex_public_contact_create($pdo, $data)
    {
        tickex_public_contact_ensure_schema($pdo);
        $name = trim(isset($data['name']) ? (string)$data['name'] : '');
        $email = strtolower(trim(isset($data['email']) ? (string)$data['email'] : ''));
        $phone = trim(isset($data['phone']) ? (string)$data['phone'] : '');
        $organization = trim(isset($data['organization']) ? (string)$data['organization'] : '');
        $eventType = trim(isset($data['event_type']) ? (string)$data['event_type'] : '');
        $message = trim(isset($data['message']) ? (string)$data['message'] : '');
        if ($name === '' || strlen($name) > 100) return array(false, 'Ingresá tu nombre.', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return array(false, 'Ingresá un email válido.', '');
        if (strlen($phone) > 50 || strlen($organization) > 140 || strlen($eventType) > 100) return array(false, 'Revisá los datos ingresados.', '');
        if (strlen($message) < 10 || strlen($message) > 3000) return array(false, 'Contanos un poco más sobre tu evento.', '');
        try { $suffix = strtoupper(bin2hex(random_bytes(3))); }
        catch (Exception $e) { $suffix = strtoupper(substr(sha1(uniqid('', true)), 0, 6)); }
        $publicId = 'CON-' . date('ymd') . '-' . $suffix;
        $st = $pdo->prepare("INSERT INTO public_contact_requests(public_id,name,email,phone,organization,event_type,message,source,status,created_at,updated_at) VALUES(:public,:name,:email,:phone,:organization,:event_type,:message,'landing','new',datetime('now'),datetime('now'))");
        $st->execute(array(':public'=>$publicId, ':name'=>$name, ':email'=>$email, ':phone'=>$phone, ':organization'=>$organization, ':event_type'=>$eventType, ':message'=>$message));
        return array(true, 'Recibimos tu consulta. El equipo de Tickex se va a comunicar con vos.', $publicId);
    }
}

