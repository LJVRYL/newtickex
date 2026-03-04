<?php
// inc/arca.php
// Funciones para integración con ARCA/AFIP (facturación electrónica)

function arca_get_config() {
    $file = __DIR__ . '/../arca_config.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return $data;
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
