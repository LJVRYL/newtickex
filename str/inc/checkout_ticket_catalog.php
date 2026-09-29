<?php

if (!function_exists('tickex_checkout_legacy_fallback_allowed')) {
    function tickex_checkout_legacy_fallback_allowed($pdo, $eventId, $hasTypesTable)
    {
        if ((int)$eventId <= 0 || !$hasTypesTable) {
            return true;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM tipos_entrada WHERE evento_id = :event_id LIMIT 1');
        $stmt->execute(array(':event_id' => (int)$eventId));
        return !$stmt->fetchColumn();
    }
}
