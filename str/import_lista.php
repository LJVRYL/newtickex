<?php
// import_lista.php
//
// Lee lista_nombres.txt (una persona por línea)
// y crea una entrada por cada línea en la tabla `entradas`.
// No borra nada, solo inserta nuevas filas.

header('Content-Type: text/plain; charset=utf-8');

$dbFile  = __DIR__ . '/save_the_rave.sqlite';
$txtFile = __DIR__ . '/lista_nombres.txt';

if (!file_exists($dbFile)) {
    echo "ERROR: Base de datos no encontrada: $dbFile\n";
    exit(1);
}

if (!file_exists($txtFile)) {
    echo "ERROR: Archivo lista_nombres.txt no encontrado.\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo "ERROR: No se pudo conectar a la base: " . $e->getMessage() . "\n";
    exit(1);
}

$fh = fopen($txtFile, 'r');
if (!$fh) {
    echo "ERROR: No se pudo abrir lista_nombres.txt para lectura.\n";
    exit(1);
}

$insertados = 0;
$saltados   = 0;
$lineaNro   = 0;

while (($line = fgets($fh)) !== false) {
    $lineaNro++;
    $nombre = trim($line);

    // Saltar líneas vacías
    if ($nombre === '') {
        $saltados++;
        continue;
    }

    // Email vacío (no lo tenemos, se completa después si hace falta)
    $email = '';

    // Fecha/hora AR
    $dt = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    $fechaRegistro = $dt->format('Y-m-d H:i:s');

    // Intentamos generar un código único y grabar
    $maxIntentos = 5;
    $okInsercion = false;

    for ($i = 0; $i < $maxIntentos; $i++) {
        if (function_exists('random_bytes')) {
            $codigo = bin2hex(random_bytes(5)); // 10 caracteres hex
        } else {
            $codigo = substr(sha1(uniqid('', true)), 0, 10);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO entradas (nombre, email, fecha_registro, codigo, checked_in)
                VALUES (:nombre, :email, :fecha_registro, :codigo, 0)
            ");

            $stmt->execute(array(
                ':nombre'         => $nombre,
                ':email'          => $email,
                ':fecha_registro' => $fechaRegistro,
                ':codigo'         => $codigo,
            ));

            $insertados++;
            $okInsercion = true;
            break; // salimos del for si funcionó
        } catch (Exception $e) {
            // Si el problema es el UNIQUE del código, probamos otro
            $msg = $e->getMessage();
            if (strpos($msg, 'UNIQUE') !== false || strpos($msg, 'unique') !== false) {
                // genera otro código e intenta de nuevo
                continue;
            } else {
                echo "Línea $lineaNro: ERROR al insertar '" . $nombre . "': " . $msg . "\n";
                $saltados++;
                $okInsercion = false;
                break;
            }
        }
    }

    if (!$okInsercion) {
        // No pudimos insertar después de varios intentos
        // Lo marcamos como saltado
        if ($maxIntentos > 1) {
            $saltados++;
        }
    }
}

fclose($fh);

echo "Importación terminada.\n";
echo "Entradas insertadas: $insertados\n";
echo "Líneas saltadas: $saltados\n";
