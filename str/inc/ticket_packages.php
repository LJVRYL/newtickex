<?php

if (!function_exists('tickex_ticket_qr_quantity')) {
    function tickex_ticket_qr_quantity($value)
    {
        $qty = (int)$value;
        if ($qty < 1) $qty = 1;
        if ($qty > 10) $qty = 10;
        return $qty;
    }
}
if (!function_exists('tickex_ticket_package_capacity')) {
    function tickex_ticket_package_capacity($availableQr, $qrQuantity)
    {
        if ($availableQr === null) return null;
        $availableQr = max(0, (int)$availableQr);
        $qrQuantity = tickex_ticket_qr_quantity($qrQuantity);
        return (int)floor($availableQr / $qrQuantity);
    }
}

if (!function_exists('tickex_ticket_issued_quantity')) {
    function tickex_ticket_issued_quantity($packageQuantity, $qrQuantity)
    {
        return max(0, (int)$packageQuantity) * tickex_ticket_qr_quantity($qrQuantity);
    }
}

if (!function_exists('tickex_ticket_amount_per_qr')) {
    function tickex_ticket_amount_per_qr($packagePrice, $qrQuantity)
    {
        return (float)$packagePrice / tickex_ticket_qr_quantity($qrQuantity);
    }
}
