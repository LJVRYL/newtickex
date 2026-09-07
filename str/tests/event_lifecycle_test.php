<?php
require_once __DIR__ . '/../inc/event_lifecycle.php';

function lifecycle_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$now = strtotime('2026-09-06 12:00:00');

lifecycle_assert(!tickex_event_is_current(array(), $now), 'event without dates is not active');
lifecycle_assert(tickex_event_is_current(array('fecha_desde' => '2026-09-07'), $now), 'future event is active');
lifecycle_assert(tickex_event_is_current(array('fecha_desde' => '2026-09-06'), $now), 'event remains active through its start day');
lifecycle_assert(!tickex_event_is_current(array('fecha_desde' => '2026-09-05'), $now), 'past start-only event becomes inactive');
lifecycle_assert(tickex_event_is_current(array('fecha_desde' => '2026-09-05', 'fecha_hasta' => '2026-09-06'), $now), 'event remains active through its end date');
lifecycle_assert(!tickex_event_is_current(array('fecha_desde' => '2026-09-04', 'fecha_hasta' => '2026-09-05'), $now), 'finished event is inactive');

echo 'ALL EVENT LIFECYCLE TESTS PASSED' . PHP_EOL;
