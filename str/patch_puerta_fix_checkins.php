<?php
$f = __DIR__ . '/puerta.php';
$c = file_get_contents($f);

$search = <<<'SEARCH'
// ========================
//  CONTADORES (con filtros)
// ========================
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM entradas $whereSql");
$stmtTotal->execute($params);
$total = (int)$stmtTotal->fetchColumn();

$stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM entradas $whereSql AND checked_in = 1");
if ($whereSql === '') {
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE checked_in = 1");
}
$stmtCheck->execute($params);
$checkins = (int)$stmtCheck->fetchColumn();

$faltan = max(0, $total - $checkins);
SEARCH;

$replace = <<<'REPLACE'
// ========================
//  CONTADORES (con filtros)
// ========================

// Total con filtros
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM entradas $whereSql");
$stmtTotal->execute($params);
$total = (int)$stmtTotal->fetchColumn();

// Check-ins con filtros (armamos SQL válido siempre)
$checkSql = "SELECT COUNT(*) FROM entradas ";
if ($whereSql !== '') {
    $checkSql .= $whereSql . " AND checked_in = 1";
} else {
    $checkSql .= "WHERE checked_in = 1";
}
$stmtCheck = $pdo->prepare($checkSql);
$stmtCheck->execute($params);
$checkins = (int)$stmtCheck->fetchColumn();

$faltan = max(0, $total - $checkins);
REPLACE;

if (strpos($c, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque de contadores esperado en puerta.php\n");
    exit(1);
}

$c = str_replace($search, $replace, $c);
file_put_contents($f, $c);
echo "Bloque de contadores arreglado en puerta.php\n";
