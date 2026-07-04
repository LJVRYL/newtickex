<?php
/**
 * Adapter TotalCoin — envuelve tc_checkout() en el contrato de proveedor.
 * Pasar $params con las claves definidas en payment_provider.php.
 */

require_once __DIR__ . '/../totalcoin.php';
require_once __DIR__ . '/../payment_provider.php';

if (!function_exists('tickex_provider_totalcoin_checkout')) {
    function tickex_provider_totalcoin_checkout(array $params)
    {
        $amount    = isset($params['amount'])    ? (float)$params['amount']    : 0.0;
        $concepto  = isset($params['concepto'])  ? (string)$params['concepto'] : '';
        $dni       = isset($params['dni'])       ? (string)$params['dni']      : '';
        $referencia = isset($params['referencia']) ? (string)$params['referencia'] : '';
        $apellido  = isset($params['apellido'])  ? (string)$params['apellido'] : '';
        $nombre    = isset($params['nombre'])    ? (string)$params['nombre']   : '';
        $email     = isset($params['email'])     ? (string)$params['email']    : '';
        $logoUrl   = isset($params['logo_url'])  ? (string)$params['logo_url'] : null;
        $callbacks = isset($params['callbacks']) ? $params['callbacks'] : null;

        return tc_checkout($amount, $concepto, $dni, $referencia, $apellido, $nombre, $email, $logoUrl, $callbacks);
    }
}

if (!function_exists('tickex_provider_totalcoin_extract_request_id')) {
    /**
     * Extrae el request_id de la URL de pago devuelta por TotalCoin.
     * Retorna string vacío si no encuentra.
     */
    function tickex_provider_totalcoin_extract_request_id($paymentUrl)
    {
        $paymentUrl = (string)$paymentUrl;
        $requestId = '';
        try {
            $u = @parse_url($paymentUrl);
            if (is_array($u) && isset($u['query'])) {
                $q = array();
                @parse_str($u['query'], $q);
                if (isset($q['requestId'])) {
                    $requestId = (string)$q['requestId'];
                }
            }
            if ($requestId === '') {
                if (preg_match('/[?&]requestId=([^&]+)/', $paymentUrl, $m)) {
                    $requestId = urldecode($m[1]);
                }
            }
        } catch (Exception $_e) {}
        return $requestId;
    }
}
