<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
// === CONTADORES ===
$total    = (int) $pdo->query("SELECT COUNT(*) FROM entradas")->fetchColumn();
$checkins = (int) $pdo->query("SELECT COUNT(*) FROM entradas WHERE checked_in = 1")->fetchColumn();
$faltan   = max(0, $total - $checkins);

// Obtener entradas con filtros
$sql = "SELECT * FROM entradas $whereSql ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tipos distintos para combo
$tiposStmt = $pdo->query("SELECT DISTINCT tipo FROM entradas ORDER BY tipo ASC");
$tipos = $tiposStmt->fetchAll(PDO::FETCH_COLUMN);
SEARCH;

$replace = <<<'REPLACE'
// === CONTADORES (aplicando filtros de búsqueda y tipo) ===
$whereBase  = array();
$paramsBase = array();

// Aplicamos a los contadores solo búsqueda libre y tipo,
// así sirven como "ingresos por categoría".
// El filtro de estado se usa solo para el listado.

if ($q !== '') {
    $whereBase[]        = '(nombre LIKE :q OR email LIKE :q OR codigo LIKE :q)';
    $paramsBase[':q']   = '%' . $q . '%';
}
if ($filtroTipo !== '') {
    $whereBase[]        = 'tipo = :tipo';
    $paramsBase[':tipo'] = $filtroTipo;
}

$whereBaseSql = '';
if (!empty($whereBase)) {
    $whereBaseSql = 'WHERE ' . implode(' AND ', $whereBase);
}

// Total dentro del subconjunto (búsqueda + tipo)
$sqlTotal = "SELECT COUNT(*) FROM entradas $whereBaseSql";
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($paramsBase);
$total = (int) $stmtTotal->fetchColumn();

// Check-ins dentro del mismo subconjunto
if ($whereBaseSql === '') {
    $sqlCheckins = "SELECT COUNT(*) FROM entradas WHERE checked_in = 1";
} else {
    $sqlCheckins = "SELECT COUNT(*) FROM entradas $whereBaseSql AND checked_in = 1";
}
$stmtCheck = $pdo->prepare($sqlCheckins);
$stmtCheck->execute($paramsBase);
$checkins = (int) $stmtCheck->fetchColumn();

// Faltan = total - check-ins, dentro del subconjunto
$faltan = max(0, $total - $checkins);

// Obtener entradas con filtros (búsqueda + tipo + estado)
$sql = "SELECT * FROM entradas $whereSql ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tipos distintos para combo
$tiposStmt = $pdo->query("SELECT DISTINCT tipo FROM entradas ORDER BY tipo ASC");
$tipos = $tiposStmt->fetchAll(PDO::FETCH_COLUMN);
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque esperado en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Contadores de admin.php actualizados.\n";
