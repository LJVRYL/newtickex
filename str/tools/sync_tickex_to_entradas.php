<?php
require_once __DIR__ . '/../inc/bootstrap.php';

$eventoId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($eventoId <= 0) {
  fwrite(STDERR, "USO: /opt/php7-4/bin/php-cli tools/sync_tickex_to_entradas.php <evento_id>\n");
  exit(1);
}

$pdo = db();

/* columnas de entradas */
$colsEn = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_ASSOC);
$haveEn = array();
foreach ($colsEn as $c) {
  if (!empty($c['name'])) $haveEn[$c['name']] = true;
}
$colCheck = null;
if (isset($haveEn['checkin'])) $colCheck = 'checkin';
elseif (isset($haveEn['checked_in'])) $colCheck = 'checked_in';

/* existe map + view? */
$hasMap  = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='tickex_event_map' LIMIT 1")->fetchColumn();
$hasView = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type IN ('view','table') AND name='v_senforms_bridge_status' LIMIT 1")->fetchColumn();
if (!$hasMap || !$hasView) {
  echo "No hay tickex_event_map o v_senforms_bridge_status. Nada para sincronizar.\n";
  exit(0);
}

/* mapping */
$stMap = $pdo->prepare("SELECT event_slug FROM tickex_event_map WHERE str_event_id = :id LIMIT 1");
$stMap->execute(array(':id' => $eventoId));
$m = $stMap->fetch(PDO::FETCH_ASSOC);
if (!$m || empty($m['event_slug'])) {
  echo "No hay mapping en tickex_event_map para str_event_id=$eventoId\n";
  exit(0);
}
$eventSlug = (string)$m['event_slug'];

/* columnas disponibles en la view */
$colsV = $pdo->query("PRAGMA table_info(v_senforms_bridge_status)")->fetchAll(PDO::FETCH_ASSOC);
$haveV = array();
foreach ($colsV as $c) {
  if (!empty($c['name'])) $haveV[$c['name']] = true;
}
if (!isset($haveV['ticket_ref'])) {
  fwrite(STDERR, "La view v_senforms_bridge_status no tiene ticket_ref. No puedo mapear codigo.\n");
  exit(1);
}

$fields = array('ticket_ref');
foreach (array('email','first_name','last_name','price','is_checked_in','last_updated_at') as $f) {
  if (isset($haveV[$f])) $fields[] = $f;
}

$sql = "SELECT " . implode(',', $fields) . "
        FROM v_senforms_bridge_status
        WHERE event_slug = :s AND is_paid = 1";
$st = $pdo->prepare($sql);
$st->execute(array(':s' => $eventSlug));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$ins = 0; $upd = 0; $skip = 0;

foreach ($rows as $r) {
  $codigo = trim((string)$r['ticket_ref']);
  if ($codigo === '') { $skip++; continue; }

  $nombre = '';
  if (isset($r['first_name']) || isset($r['last_name'])) {
    $nombre = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
  }
  $email = isset($r['email']) ? trim((string)$r['email']) : '';
  $tipo  = 'Tickex';
  $chk   = (isset($r['is_checked_in']) && (int)$r['is_checked_in'] === 1) ? 1 : 0;

  // INSERT OR IGNORE
  $cols = array('evento_id','codigo');
  $vals = array(':eid' => $eventoId, ':cod' => $codigo);

  if (isset($haveEn['nombre'])) { $cols[]='nombre'; $vals[':nom']=$nombre; }
  if (isset($haveEn['email']))  { $cols[]='email';  $vals[':em']=$email; }
  if (isset($haveEn['tipo']))   { $cols[]='tipo';   $vals[':tip']=$tipo; }
  if ($colCheck !== null)       { $cols[]=$colCheck; $vals[':chk']=$chk; }

  $place = array();
  foreach ($cols as $c) {
    if ($c === 'evento_id') $place[] = ':eid';
    elseif ($c === 'codigo') $place[] = ':cod';
    elseif ($c === 'nombre') $place[] = ':nom';
    elseif ($c === 'email') $place[] = ':em';
    elseif ($c === 'tipo') $place[] = ':tip';
    elseif ($c === $colCheck) $place[] = ':chk';
  }

  $sqlIns = "INSERT OR IGNORE INTO entradas(" . implode(',', $cols) . ")
             VALUES(" . implode(',', $place) . ")";
  $stIns = $pdo->prepare($sqlIns);
  $stIns->execute($vals);
  if ($stIns->rowCount() > 0) $ins++;

  // UPDATE (para mantener sync)
  $set = array();
  $u = array(':eid' => $eventoId, ':cod' => $codigo);

  if (isset($haveEn['nombre'])) { $set[]='nombre=:nomu'; $u[':nomu']=$nombre; }
  if (isset($haveEn['email']))  { $set[]='email=:emu';   $u[':emu']=$email; }
  if (isset($haveEn['tipo']))   { $set[]='tipo=:tipu';   $u[':tipu']=$tipo; }
  if ($colCheck !== null)       { $s

  if (!empty($set)) {
    $sqlUp = "UPDATE entradas SET " . implode(',', $set) . " WHERE evento_id=:eid AND codigo=:cod";
    $stUp = $pdo->prepare($sqlUp);
    $stUp->execute($u);
    $upd += (int)$stUp->rowCount();
  }
}

echo "SYNC Tickex→entradas OK\n";
echo "evento_id=$eventoId event_slug=$eventSlug source_rows=" . count($rows) . " inserted=$ins updated=$upd skipped=$skip\n";
