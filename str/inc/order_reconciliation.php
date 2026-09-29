<?php

if (!function_exists('tickex_totalcoin_pending_status_where')) {
    function tickex_totalcoin_pending_status_where()
    {
        return "payment_status = 'pending'"
            . " AND ref IS NOT NULL AND ref <> ''"
            . " AND created_at >= datetime('now', '-7 days')"
            . " AND (payment_provider = 'totalcoin' OR payment_provider IS NULL OR payment_provider = '')";
    }
}

