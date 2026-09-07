<?php
// inc/arca.php
// Funciones para integración con ARCA/AFIP (facturación electrónica)

function arca_config_file_path() {
    $configured = getenv('TICKEX_ARCA_CONFIG_FILE');
    if (is_string($configured) && trim($configured) !== '') return trim($configured);
    return dirname(__DIR__, 2) . '/.secrets/arca_config.json';
}

function arca_get_config() {
    $files = array(
        arca_config_file_path(),
        __DIR__ . '/../arca_config.json', // compatibilidad de solo lectura
    );
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    return null;
}

function arca_save_config($data) {
    $file = arca_config_file_path();
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento seguro.');
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar la configuración.');
    }
    @chmod($file, 0640);
    return true;
}

// TODO: Implementar autenticación WSAA y emisión de factura (WSFEv1)
// Puedes usar la librería https://github.com/afipsdk/afip.php como base
// Ejemplo de función stub para emitir factura:
function arca_emitir_factura($datosFactura) {
    // $datosFactura: array con los datos requeridos (ver documentación AFIP)
    // 1. Autenticarse (obtener TA)
    // 2. Preparar request de factura
    // 3. Enviar a AFIP y procesar respuesta
    // 4. Retornar CAE, PDF, errores, etc.
    return [
        'success' => false,
        'error' => 'Función no implementada aún'
    ];
}
