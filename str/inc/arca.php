<?php
// inc/arca.php
// Funciones para integración con ARCA/AFIP (facturación electrónica)

function arca_config_file_path() {
    $override = getenv('TICKEX_ARCA_CONFIG_FILE');
    if ($override !== false && trim($override) !== '') return $override;
    return dirname(dirname(__DIR__)) . '/.secrets/arca_config.json';
}

function arca_get_config() {
    $file = arca_config_file_path();
    if (!is_file($file)) {
        // Read existing installations until their secret is migrated.
        $file = __DIR__ . '/../arca_config.json';
    }
    if (!is_file($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function arca_save_config($data) {
    $file = arca_config_file_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) return false;
    if (!is_writable($dir)) return false;
    $saved = file_put_contents($file, json_encode($data), LOCK_EX);
    if ($saved === false) return false;
    @chmod($file, 0600);
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
