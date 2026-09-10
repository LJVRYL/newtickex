<?php
if (!function_exists('tickex_event_presentation_columns')) {
    function tickex_event_presentation_columns() {
        return array(
            'hora_puertas' => 'TEXT',
            'hora_show' => 'TEXT',
            'lugar' => 'TEXT',
            'direccion' => 'TEXT',
            'indicaciones_llegada' => 'TEXT',
            'transporte_publico' => 'TEXT',
            'politica_menores' => 'TEXT',
            'movilidad_reducida' => 'TEXT',
            'objetos_prohibidos' => 'TEXT',
            'max_entradas_compra' => 'INTEGER'
        );
    }
}

if (!function_exists('tickex_event_presentation_ensure_schema')) {
    function tickex_event_presentation_ensure_schema(PDO $pdo) {
        $existing = array();
        foreach ($pdo->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['name'])) $existing[(string)$row['name']] = true;
        }
        foreach (tickex_event_presentation_columns() as $name => $type) {
            if (!isset($existing[$name])) $pdo->exec('ALTER TABLE eventos ADD COLUMN ' . $name . ' ' . $type);
        }
    }
}

if (!function_exists('tickex_event_presentation_post')) {
    function tickex_event_presentation_post($source) {
        $text = array('lugar','direccion','indicaciones_llegada','transporte_publico','politica_menores','movilidad_reducida','objetos_prohibidos');
        $out = array(
            'hora_puertas' => isset($source['hora_puertas']) ? trim((string)$source['hora_puertas']) : '',
            'hora_show' => isset($source['hora_show']) ? trim((string)$source['hora_show']) : '',
            'max_entradas_compra' => isset($source['max_entradas_compra']) && $source['max_entradas_compra'] !== '' ? (int)$source['max_entradas_compra'] : 10,
        );
        foreach ($text as $field) $out[$field] = isset($source[$field]) ? trim((string)$source[$field]) : '';
        return $out;
    }
}

if (!function_exists('tickex_event_presentation_validate')) {
    function tickex_event_presentation_validate($data, $requireDoorTime) {
        $door = isset($data['hora_puertas']) ? trim((string)$data['hora_puertas']) : '';
        $show = isset($data['hora_show']) ? trim((string)$data['hora_show']) : '';
        if ($requireDoorTime && $door === '') return 'Definí el horario de apertura de puertas.';
        if ($door !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $door)) return 'El horario de puertas no es válido.';
        if ($show !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $show)) return 'El horario del show no es válido.';
        $limits = array('lugar'=>120,'direccion'=>220,'indicaciones_llegada'=>1200,'transporte_publico'=>1200,'politica_menores'=>600,'movilidad_reducida'=>600,'objetos_prohibidos'=>1200);
        foreach ($limits as $field => $limit) {
            $value = isset($data[$field]) ? (string)$data[$field] : '';
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($length > $limit) return 'El campo de información pública es demasiado extenso.';
        }
        $max = isset($data['max_entradas_compra']) ? (int)$data['max_entradas_compra'] : 10;
        if ($max < 1 || $max > 20) return 'El máximo por compra debe estar entre 1 y 20 entradas.';
        return '';
    }
}

if (!function_exists('tickex_event_presentation_params')) {
    function tickex_event_presentation_params($data) {
        $params = array();
        foreach (tickex_event_presentation_columns() as $field => $type) {
            $value = isset($data[$field]) ? $data[$field] : null;
            if ($type === 'INTEGER') $params[':' . $field] = max(1, min(20, (int)$value));
            else $params[':' . $field] = trim((string)$value) !== '' ? trim((string)$value) : null;
        }
        return $params;
    }
}

if (!function_exists('tickex_event_map_urls')) {
    function tickex_event_map_urls($address) {
        $address = trim((string)$address);
        if ($address === '') return array('link'=>'','embed'=>'');
        return array(
            'link' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address),
            'embed' => 'https://www.google.com/maps?q=' . rawurlencode($address) . '&output=embed'
        );
    }
}
