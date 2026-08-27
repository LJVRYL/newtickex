<?php

if (!function_exists('tickex_event_trash_ensure_schema')) {
    function tickex_event_trash_ensure_schema($pdo)
    {
        $cols = $pdo->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cols)) throw new Exception('La tabla de eventos no existe.');
        foreach ($cols as $col) {
            if (isset($col['name']) && $col['name'] === 'borrado_en') return true;
        }
        $pdo->exec('ALTER TABLE eventos ADD COLUMN borrado_en TEXT');
        return true;
    }
}

if (!function_exists('tickex_event_soft_delete')) {
    function tickex_event_soft_delete($pdo, $eventId)
    {
        tickex_event_trash_ensure_schema($pdo);
        $st = $pdo->prepare("UPDATE eventos SET borrado_en = COALESCE(borrado_en, datetime('now')) WHERE id = :id");
        $st->execute(array(':id' => (int)$eventId));
        return $st->rowCount() === 1;
    }
}

if (!function_exists('tickex_event_restore')) {
    function tickex_event_restore($pdo, $eventId)
    {
        tickex_event_trash_ensure_schema($pdo);
        $st = $pdo->prepare('UPDATE eventos SET borrado_en = NULL WHERE id = :id AND borrado_en IS NOT NULL');
        $st->execute(array(':id' => (int)$eventId));
        return $st->rowCount() === 1;
    }
}
