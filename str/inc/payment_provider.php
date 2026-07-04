<?php
/**
 * Contrato de proveedor de pagos para Tickex.
 *
 * Un proveedor de pagos tiene una responsabilidad única:
 * recibir parámetros de checkout y devolver una URL de pago.
 * La notificación de estado (success/failed/inprocess) llega
 * por callback separado y es procesada por el Order Engine.
 *
 * Para agregar un nuevo proveedor:
 *  1. Crear un archivo en str/inc/providers/<nombre>.php
 *  2. Implementar tickex_provider_<nombre>_checkout()
 *  3. Crear el endpoint de callback correspondiente
 *  4. En el callback, llamar process_tc_order_by_request_id()
 *
 * Contrato de la función de checkout:
 *
 *   tickex_provider_<nombre>_checkout(array $params): string
 *
 *   $params obligatorios:
 *     'amount'    float  — monto total en ARS
 *     'concepto'  string — descripción del pago
 *     'dni'       string — DNI del comprador
 *     'referencia' string — referencia interna única
 *     'apellido'  string — apellido del comprador
 *     'nombre'    string — nombre del comprador
 *     'email'     string — email del comprador
 *
 *   $params opcionales:
 *     'logo_url'   string — URL del logo del evento
 *     'callbacks'  array  — ['success' => URL, 'inproc' => URL, 'failed' => URL]
 *
 *   Retorna: URL de pago (string) — el caller hace el redirect.
 *   Lanza:   RuntimeException en caso de fallo del gateway.
 *
 * El request_id para tc_orders se extrae de la URL devuelta por el proveedor.
 * Cada proveedor puede tener su propia lógica de extracción.
 *
 * Contrato del callback:
 *   - Recibe la notificación del proveedor (GET/POST)
 *   - Actualiza tc_orders.state
 *   - Llama process_tc_order_by_request_id($requestId)
 *   - Delega toda la lógica al Order Engine
 *
 * Estados válidos en tc_orders.state:
 *   'created'        — checkout creado, usuario no pagó todavía
 *   'success'        — pago aprobado (proveedor lo confirmó)
 *   'inprocess'      — pago en proceso (pendiente de confirmación)
 *   'failed'         — pago rechazado o expirado
 *   'bridge_synced'  — ticket importado del bridge externo (ya procesado)
 */

// Proveedor activo (configurable)
if (!function_exists('tickex_payment_provider')) {
    function tickex_payment_provider()
    {
        return 'totalcoin';
    }
}
