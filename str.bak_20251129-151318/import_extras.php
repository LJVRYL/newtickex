<?php
// import_extras.php
// Agrega entradas extra a la base con su tipo correcto,
// sin borrar nada y sin duplicar más de la cuenta.

header('Content-Type: text/plain; charset=utf-8');

$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    echo "ERROR: Base de datos no encontrada: $dbFile\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo "ERROR: No se pudo conectar a la base: " . $e->getMessage() . "\n";
    exit(1);
}

// Cuenta cuántas entradas ya existen para ese nombre/tipo (case-insensitive)
function countExisting($pdo, $nombre, $tipo) {
    $sql = "SELECT COUNT(*) AS c FROM entradas
            WHERE LOWER(nombre) = LOWER(:nombre) AND tipo = :tipo";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':nombre' => $nombre,
        ':tipo'   => $tipo,
    ));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;
    return (int)$row['c'];
}

// Agrega las entradas que falten para llegar al total deseado
function addExtra($pdo, $nombreRaw, $tipo, $totalDeseado, &$totalInsertadas) {
    $nombre = trim($nombreRaw);
    if ($nombre === '') return;

    $ya = countExisting($pdo, $nombre, $tipo);
    $faltan = $totalDeseado - $ya;

    echo "-> {$nombre} [tipo={$tipo}] deseadas={$totalDeseado}, ya_existen={$ya}, a_insertar={$faltan}\n";

    if ($faltan <= 0) {
        echo "   Nada que hacer para {$nombre}, ya tiene suficientes.\n";
        return;
    }

    $dt = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    $fecha = $dt->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO entradas (nombre, email, fecha_registro, codigo, checked_in, tipo)
        VALUES (:nombre, '', :fecha, :codigo, 0, :tipo)
    ");

    for ($i = 0; $i < $faltan; $i++) {
        $maxIntentos = 5;
        for ($t = 0; $t < $maxIntentos; $t++) {
            // Código único
            if (function_exists('random_bytes')) {
                $codigo = bin2hex(random_bytes(5));
            } else {
                $codigo = substr(sha1(uniqid('', true)), 0, 10);
            }

            try {
                $stmt->execute(array(
                    ':nombre' => $nombre,
                    ':fecha'  => $fecha,
                    ':codigo' => $codigo,
                    ':tipo'   => $tipo,
                ));
                $totalInsertadas++;
                break;
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'UNIQUE') !== false) {
                    // chocó el código, probamos otro
                    continue;
                } else {
                    echo "   ERROR al insertar '{$nombre}': {$msg}\n";
                    break 2; // salimos del for $i
                }
            }
        }
    }
}

$total = 0;

// ======================================
// 1) ANTICIPADAS
// ======================================
// "Hernan patricio de rosa x2" -> total 2 anticipadas
addExtra($pdo, 'Hernan patricio de rosa', 'ANTICIPADA', 2, $total);

// Kil, Ivana rivarola -> 1 cada una
addExtra($pdo, 'Kil',            'ANTICIPADA', 1, $total);
addExtra($pdo, 'Ivana rivarola', 'ANTICIPADA', 1, $total);

// ======================================
// 2) FREE
// ======================================
// Todos 1, salvo "Panchito +1" (2 total)
addExtra($pdo, 'Mariana Villamarín', 'FREE', 1, $total);
addExtra($pdo, 'Lu bauchi',          'FREE', 1, $total);
addExtra($pdo, 'Pistolera',          'FREE', 1, $total);
addExtra($pdo, 'Uma Rafecas',        'FREE', 1, $total);
addExtra($pdo, 'Poseso',             'FREE', 1, $total);

// Panchito +1 => 2 en total
addExtra($pdo, 'Panchito', 'FREE', 2, $total);

// Resto 1
addExtra($pdo, 'Ivan',       'FREE', 1, $total);
addExtra($pdo, 'Lautaro',    'FREE', 1, $total);
addExtra($pdo, 'Jhonatan f', 'FREE', 1, $total);

// ======================================
// 3) 2x10.000  -> usamos tipo PUERTA_10000
// ======================================
// Civil Hate +1 => 2 en total
addExtra($pdo, 'Civil Hate', 'PUERTA_10000', 2, $total);

// ======================================

echo "---------------------------------\n";
echo "Importación de extras terminada.\n";
echo "Entradas insertadas: " . $total . "\n";
