<?php
/** Organizer access decisions. Run tickex_producer_requests_migrate() explicitly at deploy time. */
function tickex_producer_requests_migrate($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS producer_onboarding_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        email TEXT NOT NULL,
        organization_name TEXT NOT NULL,
        status TEXT NOT NULL,
        decision_reason TEXT NOT NULL DEFAULT '',
        admin_id INTEGER,
        decided_by_admin_id INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_producer_requests_status ON producer_onboarding_requests(status,created_at)');
    // Legacy self-service grants are recorded honestly as imported, not as new rule decisions.
    $pdo->exec("INSERT OR IGNORE INTO producer_onboarding_requests
        (user_id,email,organization_name,status,decision_reason,admin_id,created_at,decided_at)
        SELECT s.user_id,LOWER(TRIM(a.email)),s.organization_name,'imported_auto',
               'Alta automática anterior a la auditoría',s.admin_id,s.created_at,s.created_at
        FROM producer_self_service s JOIN usuarios_admin a ON a.id=s.admin_id");
}
function tickex_producer_requests_ready($pdo) {
    return (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='producer_onboarding_requests'")->fetchColumn();
}
function tickex_producer_request_policy($pdo, $user, $email, $organization) {
    if (trim((string)$organization)==='') return 'Falta el nombre de la organización.';
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) return 'El email no es válido.';
    if (trim((string)$user['nombre'])==='' || trim((string)$user['apellido'])==='') return 'Faltan nombre o apellido.';
    if (trim((string)$user['dni'])==='') return 'Falta el documento del titular.';
    if (trim((string)$user['completado_en'])==='') return 'El registro no está completo.';
    $st=$pdo->prepare('SELECT 1 FROM user_blocks WHERE lower(email)=? AND active=1');
    $st->execute(array($email));
    if ($st->fetchColumn()) return 'La cuenta requiere revisión de acceso.';
    $st=$pdo->prepare('SELECT 1 FROM usuarios_admin WHERE lower(email)=? OR lower(username)=?');
    $st->execute(array($email,$email));
    if ($st->fetchColumn()) return 'Ya existe un acceso administrativo con este email.';
    $hash=isset($user['password_hash'])?(string)$user['password_hash']:'';
    if ($hash!=='' && password_get_info($hash)['algo']) return '';
    $st=$pdo->prepare("SELECT 1 FROM auth_identities WHERE provider='google' AND account_type='buyer' AND account_id=? AND lower(email_at_link)=?");
    $st->execute(array((int)$user['id'],$email));
    return $st->fetchColumn() ? '' : 'Falta completar un método de ingreso verificado.';
}
