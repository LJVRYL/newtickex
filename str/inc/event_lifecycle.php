<?php

if (!function_exists('tickex_event_end_timestamp')) {
    function tickex_event_end_timestamp($value)
    {
        $value = trim((string)$value);
        if ($value === '') return false;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 23:59:59';
        }
        return strtotime($value);
    }
}
if (!function_exists('tickex_event_is_current')) {
    function tickex_event_is_current($event, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $end = !empty($event['fecha_hasta'])
            ? tickex_event_end_timestamp($event['fecha_hasta'])
            : false;
        if ($end !== false) return $end >= $now;

        $startDayEnd = !empty($event['fecha_desde'])
            ? tickex_event_end_timestamp($event['fecha_desde'])
            : false;
        if ($startDayEnd !== false) return $startDayEnd >= $now;

        return false;
    }
}
