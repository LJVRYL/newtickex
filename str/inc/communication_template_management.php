<?php

if (!function_exists('communication_template_reference_counts')) {
    function communication_template_reference_counts($pdo, $templateId)
    {
        $templateId = (int)$templateId;
        $counts = array('campaigns' => 0, 'newsletters' => 0, 'copies' => 0, 'total' => 0);
        if ($templateId <= 0) return $counts;

        $tables = array(
            'campaigns' => array('communication_campaigns', 'template_id'),
            'newsletters' => array('communication_event_newsletters', 'template_id'),
            'copies' => array('communication_templates', 'parent_template_id'),
        );
        foreach ($tables as $key => $definition) {
            $table = $definition[0];
            $column = $definition[1];
            $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table) . ' LIMIT 1')->fetchColumn();
            if (!$exists) continue;
            $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = :id');
            $st->execute(array(':id' => $templateId));
            $counts[$key] = (int)$st->fetchColumn();
            $counts['total'] += $counts[$key];
        }
        return $counts;
    }
}

if (!function_exists('communication_template_delete')) {
    function communication_template_delete($pdo, $templateId, $scopeSql, $scopeParams)
    {
        $templateId = (int)$templateId;
        if ($templateId <= 0) return array('ok' => false, 'message' => 'Plantilla invalida.');

        $st = $pdo->prepare('SELECT id, name, slug, organization_id, is_system_locked FROM communication_templates WHERE id = :id AND ' . $scopeSql . ' AND status <> "deleted" LIMIT 1');
        $st->execute(array(':id' => $templateId) + $scopeParams);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return array('ok' => false, 'message' => 'No se encontro la plantilla para eliminar.');
        if ((int)$row['is_system_locked'] === 1) return array('ok' => false, 'message' => 'Las plantillas del sistema estan protegidas. Duplica una para personalizarla.');

        $references = communication_template_reference_counts($pdo, $templateId);
        if ($references['total'] === 0) {
            $delete = $pdo->prepare('DELETE FROM communication_templates WHERE id = :id');
            $delete->execute(array(':id' => $templateId));
            return array('ok' => true, 'mode' => 'deleted', 'message' => 'Plantilla eliminada.');
        }

        $base = substr((string)$row['slug'], 0, 120) . '-eliminada-' . $templateId;
        $slug = communication_templates_unique_slug($pdo, (int)$row['organization_id'], $base, $templateId);
        $soft = $pdo->prepare('UPDATE communication_templates SET status = "deleted", slug = :slug, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $soft->execute(array(':slug' => $slug, ':id' => $templateId));
        return array('ok' => true, 'mode' => 'retired', 'message' => 'Plantilla eliminada de la gestion. Las campanas y newsletters anteriores conservan su historial.');
    }
}

if (!function_exists('communication_template_reference_map')) {
    function communication_template_reference_map($pdo, $rows)
    {
        $map = array();
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id > 0) $map[$id] = communication_template_reference_counts($pdo, $id);
        }
        return $map;
    }
}
