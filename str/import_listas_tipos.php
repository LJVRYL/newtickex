<?php
// import_listas_tipos.php
// Reconstruye todas las listas con TIPOS y numeración (nombre 1, nombre 2, etc.)
// No toca las entradas que ya existen con email distinto de vacío.

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

// Pequeña ayuda para acumular cantidades por nombre base
function addTickets(&$map, $nombre, $cant) {
    $base = trim($nombre);
    if ($base === '') return;
    if (!isset($map[$base])) {
        $map[$base] = 0;
    }
    $map[$base] += (int)$cant;
}

// Inserta un grupo con un tipo dado
// (SIN type hints para evitar el error raro)
function insertGroup($pdo, $map, $tipo, &$totalInsertados) {
    $dt = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    $fecha = $dt->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO entradas (nombre, email, fecha_registro, codigo, checked_in, tipo)
        VALUES (:nombre, '', :fecha, :codigo, 0, :tipo)
    ");

    foreach ($map as $baseNombre => $cantidad) {
        for ($i = 1; $i <= $cantidad; $i++) {
            // Si tiene más de 1 ticket, agregamos numerito: "Nombre 1", "Nombre 2", etc.
            $display = ($cantidad > 1) ? ($baseNombre . ' ' . $i) : $baseNombre;

            $maxIntentos = 5;
            for ($t = 0; $t < $maxIntentos; $t++) {
                if (function_exists('random_bytes')) {
                    $codigo = bin2hex(random_bytes(5));
                } else {
                    $codigo = substr(sha1(uniqid('', true)), 0, 10);
                }

                try {
                    $stmt->execute([
                        ':nombre' => $display,
                        ':fecha'  => $fecha,
                        ':codigo' => $codigo,
                        ':tipo'   => $tipo,
                    ]);
                    $totalInsertados++;
                    break;
                } catch (Exception $e) {
                    $msg = $e->getMessage();
                    if (stripos($msg, 'UNIQUE') !== false) {
                        // chocó el código, probamos otro
                        continue;
                    } else {
                        echo "ERROR al insertar '{$display}' ({$tipo}): {$msg}\n";
                        break 2;
                    }
                }
            }
        }
    }
}

// -----------------------------
// 1) ENTRADAS ANTICIPADAS
// -----------------------------
$anticipadasRaw = [
    ['agustina maria cabral', 1],
    ['Agustina María cabral', 1],
    ['alan augusto fornari', 1],
    ['axel david cantoni benitez', 1],
    ['Cache', 1],
    ['Cami Vega', 1],
    ['castro kairuz eliana', 1],
    ['Chenny', 1],
    ['daniel freddy palza', 1],
    ['David gastón fagotti', 1],
    ['facundo varas', 2],
    ['Ferna Brewer', 1],
    ['Frana Zabala', 1],
    ['franco joel roda', 1],
    ['german welchli', 2],
    ['Guillermina Catalano', 1],
    ['hernan patricio de rosa', 1],
    ['ivana ostertag', 1],
    ['Josefina lucero', 1],
    ['lisandro rodriguez cometta', 1],
    ['Lore Colmenares', 1],
    ['lu - kurda - lore (3)', 3],
    ['Lu Berti', 1],
    ['Lucas Insaurralde', 1],
    ['Luco', 1],
    ['Mak francinella', 1],
    ['malena del vecchio', 1],
    ['Malena del Vecchio', 1],
    ['Martina Kogan', 1],
    ['maximiliano javier espinosa', 1],
    ['Mori Mariani', 1],
    ['Nava Gonzalez victor', 2],
    ['Nico H', 1],
    ['Quintero salas Luis alejandro', 1],
    // acá ya guardamos sin el "+ 1" en el nombre
    ['Bernal romero', 2],
    ['ro', 1],
    ['samonta montserrat', 1],
    ['Santiago Luis damiani', 1],
    ['sorbo de vino', 1],
    ['urue a micaela lis', 1],
    ['varela facundo ramiro', 1],
    ['varela facundo ramiro', 2],
];

$anticipadas = [];
foreach ($anticipadasRaw as $row) {
    list($nombre, $cant) = $row;
    $nombre = trim($nombre);

    // Caso especial "lu - kurda - lore (3)" -> nombre base sin (3)
    $nombre = preg_replace('/\(\d+\)\s*$/u', '', $nombre);
    $nombre = trim($nombre);

    addTickets($anticipadas, $nombre, $cant);
}

// -----------------------------
// 2) ENTRADAS FREE
// -----------------------------
$freeRaw = [
    'agustin perez',
    'Agustín Rodríguez +1',
    'agustina cabral',
    'Agustina Muñiz',
    'alejandro gustavo',
    'andy jules jota',
    'azzurra',
    'berchi +1',
    'bicho sonoro',
    'cami',
    'campe',
    'cuax cristian',
    'Daian Gulvinowiez',
    'daro',
    'demi',
    'eddie garcia',
    'eliana castro kairuz',
    'emilio salazar',
    'fede kawill',
    'francisco macfarlane',
    'franquito',
    'galactica azul',
    'gonzalo andres',
    'grecia talavera',
    'guida lopez (ella)',
    'ivan kamenskii',
    'ivan olson',
    'jaqueline grajales',
    'javi shpsfht',
    'jhosten',
    'joana amarilla',
    'joaquito',
    'juani HE CLOUD',
    'juan miguel',
    'juli',
    'Julián Felipe',
    'julis perelov',
    'julis perelov',
    'kai',
    'kai martinez (cualquier prenombre)',
    'keka y luis',
    'leandro vannucci',
    'leandro vigo',
    'lei',
    'lucas las heras',
    'manu nube',
    'marta',
    'martin',
    'martu',
    'mauro dostal',
    'maxi jam',
    'micaela urueña',
    'miguel demaria',
    'milagros alegre',
    'montenegro americo',
    'montserrat samonta',
    'mora y juli',
    'natalia kriger',
    'nico birras',
    'pablo aguilar +1',
    'pit pedro',
    'renan ferrer',
    'rgb prod +2',
    'sarmiento +2',
    'saigg',
    'soberbio',
    'sofia bauhaus',
    'sofia chofiaa',
    'tanzi',
    'tanzi +3',
    'tena',
    'tiago',
    'toti',
    'verdun ulises',
    'wally',
    'yanina giovannetti',
];

$free = [];
foreach ($freeRaw as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // Patrón para cosas tipo "nombre +2" o "nombre + 3"
    if (preg_match('/^(.*?)(?:\s*\+\s*(\d+))$/u', $line, $m)) {
        $base  = trim($m[1]);
        $extra = (int)$m[2];
        $cant  = 1 + $extra; // titular + extras
    } else {
        $base  = $line;
        $cant  = 1;
    }

    addTickets($free, $base, $cant);
}

// -----------------------------
// 3) LISTA DESCUENTO PUERTA $10.000
// -----------------------------
$puerta10k = [];
$puerta10kRaw = [
    'maxi jam friends',
    'Agustín Rodríguez friends',
    'Darack Muchile',
    'Julián Felipe friends',
    'Matias Ciocca',
    'saigg friends',
];

foreach ($puerta10kRaw as $nombre) {
    addTickets($puerta10k, $nombre, 1);
}

// -----------------------------
// 4) LISTA PUERTA $15.000
// -----------------------------
$puerta15k = [];
$puerta15kRaw = [
    'Sasha ( Rusia )',
];

foreach ($puerta15kRaw as $nombre) {
    addTickets($puerta15k, $nombre, 1);
}

// -----------------------------
// 5) ENTRADAS “CON OTRO NOMBRE (ver quién pagó)”
// -----------------------------
$otroNombreRaw = [
    'Francesca fechino',
    'franco roda',
    'Cecilia Gómez',
    'Antonella Marabotto',
    'Andi sigalov',
    'Fernanda posdata + 2',
    'Delfina quintans',
    'Hernán + 2',
    'Juan Camilo Martínez',
    'Germán Welchli',
    'Paola Lalia',
    'Romina gigena',
];

$otroNombre = [];
foreach ($otroNombreRaw as $line) {
    $line = trim($line);
    if ($line === '') continue;

    if (preg_match('/^(.*?)(?:\s*\+\s*(\d+))$/u', $line, $m)) {
        $base  = trim($m[1]);
        $extra = (int)$m[2];
        $cant  = 1 + $extra;
    } else {
        $base  = $line;
        $cant  = 1;
    }
    addTickets($otroNombre, $base, $cant);
}

// -----------------------------
// Inserción en la base
// -----------------------------
$total = 0;

echo "Importando ANTICIPADAS...\n";
insertGroup($pdo, $anticipadas, 'ANTICIPADA', $total);

echo "Importando FREE...\n";
insertGroup($pdo, $free, 'FREE', $total);

echo "Importando PUERTA_10000...\n";
insertGroup($pdo, $puerta10k, 'PUERTA_10000', $total);

echo "Importando PUERTA_15000...\n";
insertGroup($pdo, $puerta15k, 'PUERTA_15000', $total);

echo "Importando OTRO_NOMBRE...\n";
insertGroup($pdo, $otroNombre, 'OTRO_NOMBRE', $total);

echo "---------------------------------\n";
echo "Importación terminada.\n";
echo "Entradas insertadas: {$total}\n";
