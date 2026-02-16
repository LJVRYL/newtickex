<?php
// inc/senforms.php
// Conexión y helpers para operar sobre la base MySQL/MariaDB de SenForms

function sf_db(){
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = getenv('SENFORMS_DB_HOST') ?: 'localhost';
    $db   = getenv('SENFORMS_DB_NAME') ?: 'SenForms';
    $user = getenv('SENFORMS_DB_USER') ?: 'fer';
    $pass = getenv('SENFORMS_DB_PASS') ?: 'Peronistan98@';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $opts = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    );

    try {
        $pdo = new PDO($dsn, $user, $pass, $opts);
    } catch (Exception $ex) {
        // No abortar todo: propagar la excepción para que el caller decida (permite fallback en UI)
        throw $ex;
    }
    return $pdo;
}

// Pago aprobado en SenForms/Tickex (estados conocidos)
function sf_is_payment_state_paid($state){
    $val = strtoupper(trim((string)$state));
    if ($val === '') return false;
    $paid = array('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID','FINISHED');
    return in_array($val, $paid, true);
}

// Cantidad de tickets vendidos para un TicketType (SelectedType numérico)
function sf_count_ticket_type_sales($ticketTypeId){
    $pdo = sf_db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM Tickets WHERE CAST(SelectedType AS UNSIGNED) = ?");
    $st->execute(array((int)$ticketTypeId));
    return (int)$st->fetchColumn();
}

// TicketTypes de un evento con conteo de ventas
function sf_get_ticket_types_with_sales($eventId){
    $pdo = sf_db();
    $sql = "SELECT tt.*, COALESCE(s.sales_count,0) AS sales_count
            FROM TicketType tt
            LEFT JOIN (
              SELECT CAST(SelectedType AS UNSIGNED) AS tt_id, COUNT(*) AS sales_count
              FROM Tickets
              WHERE EventId = ?
              GROUP BY tt_id
            ) s ON s.tt_id = tt.Id
            WHERE tt.EventId = ?
            ORDER BY tt.Id ASC";
    $st = $pdo->prepare($sql);
    $st->execute(array((int)$eventId, (int)$eventId));
    return $st->fetchAll();
}

function sf_get_events(){
    $pdo = sf_db();
    $st = $pdo->query("SELECT * FROM Events ORDER BY Id DESC");
    return $st ? $st->fetchAll() : array();
}

function sf_get_ticket_types($eventId){
    $pdo = sf_db();
    $st = $pdo->prepare("SELECT * FROM TicketType WHERE EventId = ? ORDER BY Id ASC");
    $st->execute(array((int)$eventId));
    return $st->fetchAll();
}

function sf_create_ticket_type($eventId, $name, $price){
    $pdo = sf_db();
    $n = trim($name);
    $p = (float)$price;
    if ($n === '') throw new InvalidArgumentException('Nombre vacío');
    if ($p < 0) throw new InvalidArgumentException('Precio inválido');
    $st = $pdo->prepare("INSERT INTO TicketType (Name, Price, EventId) VALUES (?, ?, ?)");
    $st->execute(array($n, $p, (int)$eventId));
    return (int)$pdo->lastInsertId();
}

function sf_rename_ticket_type($ticketTypeId, $name){
    $pdo = sf_db();
    $n = trim($name);
    if ($n === '') throw new InvalidArgumentException('Nombre vacío');
    $st = $pdo->prepare("UPDATE TicketType SET Name = ? WHERE Id = ?");
    return $st->execute(array($n, (int)$ticketTypeId));
}

function sf_delete_ticket_type($ticketTypeId){
    $pdo = sf_db();
    $st = $pdo->prepare("DELETE FROM TicketType WHERE Id = ?");
    return $st->execute(array((int)$ticketTypeId));
}

function sf_get_tickets_by_event($eventId, $limit = 50){
    $pdo = sf_db();
    $lim = max(1, (int)$limit);
    $st = $pdo->prepare("SELECT * FROM Tickets WHERE EventId = ? ORDER BY Id DESC LIMIT {$lim}");
    $st->execute(array((int)$eventId));
    return $st->fetchAll();
}

function sf_delete_ticket($ticketId){
    $pdo = sf_db();
    $st = $pdo->prepare("DELETE FROM Tickets WHERE Id = ?");
    return $st->execute(array((int)$ticketId));
}

function sf_find_event_by_site($site){
    $pdo = sf_db();
    $st = $pdo->prepare("SELECT * FROM Events WHERE SiteName = ? LIMIT 1");
    $st->execute(array($site));
    return $st->fetch();
}

function sf_find_events_like($needle){
    $pdo = sf_db();
    $q = "%".trim($needle)."%";
    $st = $pdo->prepare("SELECT * FROM Events WHERE SiteName LIKE ? OR Name LIKE ? ORDER BY Id DESC LIMIT 50");
    $st->execute(array($q, $q));
    return $st->fetchAll();
}

function sf_delete_event($eventId){
    $pdo = sf_db();
    $st = $pdo->prepare("DELETE FROM Events WHERE Id = ?");
    return $st->execute(array((int)$eventId));
}

function sf_update_ticket_type_price($ticketTypeId, $price){
    $pdo = sf_db();
    $p = (float)$price;
    if ($p < 0) throw new InvalidArgumentException('Precio inválido');
    $sales = sf_count_ticket_type_sales($ticketTypeId);
    if ($sales > 0) {
        throw new RuntimeException('Precio bloqueado: ya hay '.$sales.' ventas. Crea un TicketType nuevo.');
    }
    $st = $pdo->prepare("UPDATE TicketType SET Price = ? WHERE Id = ?");
    return $st->execute(array($p, (int)$ticketTypeId));
}

function sf_update_event_limit($eventId, $limit){
    $pdo = sf_db();
    $l = (int)$limit;
    if ($l < 0) throw new InvalidArgumentException('Límite inválido');
    $st = $pdo->prepare("UPDATE Events SET TicketAmountLimit = ?, LastUpdatedAt = NOW(6) WHERE Id = ?");
    return $st->execute(array($l, (int)$eventId));
}

// Reporte real de ventas por SelectedType usando Tickets.Price
function sf_sales_report_by_type($eventId){
        $pdo = sf_db();
        $sql = "SELECT 
                            COALESCE(NULLIF(t.SelectedType, ''), '0') AS selected_type,
                            CAST(NULLIF(t.SelectedType, '') AS UNSIGNED) AS selected_type_id,
                            COUNT(*) AS qty,
                            SUM(t.Price) AS total_real,
                            MAX(t.PaymentState) AS last_state,
                            tt.Name AS ticket_type_name
                        FROM Tickets t
                        LEFT JOIN TicketType tt ON tt.Id = CAST(NULLIF(t.SelectedType, '') AS UNSIGNED)
                        WHERE t.EventId = ?
                            AND UPPER(COALESCE(t.PaymentState, '')) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID','FINISHED')
                        GROUP BY selected_type, selected_type_id, tt.Name
                        ORDER BY qty DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array((int)$eventId));
        return $st->fetchAll();
}

function sf_create_event($data){
    $pdo = sf_db();

    $now = date('Y-m-d H:i:s.u');
    $ev = array(
        'EventStartDate'   => $data['start'] ?? $now,
        'EventEndDate'     => $data['end'] ?? $now,
        'Location'         => $data['location'] ?? '',
        'Name'             => $data['name'] ?? 'Nuevo evento',
        'Flyer'            => $data['flyer'] ?? null,
        'Active'           => !empty($data['active']) ? 1 : 0,
        'SiteName'         => $data['site'] ?? uniqid('evt'),
        'TicketAmountLimit'=> isset($data['limit']) ? (int)$data['limit'] : 0,
        'CreatedAt'        => $now,
        'CreatedBy'        => 1,
        'LastUpdatedAt'    => $now,
        'LastUpdatedBy'    => 1,
        'EventPublicId'    => $data['public_id'] ?? (function_exists('uuid_create') ? uuid_create(UUID_TYPE_RANDOM) : uniqid('pub')),
    );

    $sql = "INSERT INTO Events (EventStartDate, EventEndDate, Location, Name, Flyer, Active, SiteName, TicketAmountLimit, CreatedAt, CreatedBy, LastUpdatedAt, LastUpdatedBy, EventPublicId)
            VALUES (:start, :end, :loc, :name, :flyer, :active, :site, :limit, :created, :cby, :updated, :uby, :pub)";
    $st = $pdo->prepare($sql);
    $st->execute(array(
        ':start'   => $ev['EventStartDate'],
        ':end'     => $ev['EventEndDate'],
        ':loc'     => $ev['Location'],
        ':name'    => $ev['Name'],
        ':flyer'   => $ev['Flyer'],
        ':active'  => $ev['Active'],
        ':site'    => $ev['SiteName'],
        ':limit'   => $ev['TicketAmountLimit'],
        ':created' => $ev['CreatedAt'],
        ':cby'     => $ev['CreatedBy'],
        ':updated' => $ev['LastUpdatedAt'],
        ':uby'     => $ev['LastUpdatedBy'],
        ':pub'     => $ev['EventPublicId'],
    ));

    $eventId = (int)$pdo->lastInsertId();

    // Crear un tipo de entrada inicial
    if (!empty($data['ticket_name'])) {
        $price = isset($data['ticket_price']) ? (float)$data['ticket_price'] : 0;
        $stT = $pdo->prepare("INSERT INTO TicketType (Name, Price, EventId) VALUES (?, ?, ?)");
        $stT->execute(array($data['ticket_name'], $price, $eventId));
    }

    return $eventId;
}

function sf_get_event($eventId){
    $pdo = sf_db();
    $st = $pdo->prepare("SELECT * FROM Events WHERE Id = ? LIMIT 1");
    $st->execute(array((int)$eventId));
    return $st->fetch();
}
