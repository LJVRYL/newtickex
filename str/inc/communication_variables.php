<?php

if (!function_exists('communication_variables_catalog')) {
    function communication_variables_catalog()
    {
        return array(
            'nombre' => array(
                'label' => 'Nombre',
                'description' => 'Nombre de la persona destinataria.',
                'type' => 'string',
                'example' => 'Lucia',
            ),
            'evento' => array(
                'label' => 'Evento',
                'description' => 'Nombre del evento asociado.',
                'type' => 'string',
                'example' => 'Festival Tickex',
            ),
            'codigo' => array(
                'label' => 'Codigo',
                'description' => 'Codigo o referencia principal.',
                'type' => 'string',
                'example' => 'TKX-98231',
            ),
            'fecha' => array(
                'label' => 'Fecha',
                'description' => 'Fecha relevante para el mensaje.',
                'type' => 'string',
                'example' => '2026-08-20 21:00',
            ),
            'tipo_entrada' => array(
                'label' => 'Tipo de entrada',
                'description' => 'Categoria o nombre del ticket.',
                'type' => 'string',
                'example' => 'General',
            ),
            'ticket_url' => array(
                'label' => 'URL del ticket',
                'description' => 'Enlace directo al ticket.',
                'type' => 'url',
                'example' => 'https://tickex.com.ar/ticket/TKX-98231',
            ),
            'checkin_url' => array(
                'label' => 'URL check-in',
                'description' => 'Enlace para check-in.',
                'type' => 'url',
                'example' => 'https://tickex.com.ar/checkin/TKX-98231',
            ),
            'organizador' => array(
                'label' => 'Organizador',
                'description' => 'Nombre comercial del organizador.',
                'type' => 'string',
                'example' => 'Tickex Producciones',
            ),
        );
    }
}

if (!function_exists('communication_variables_default_sample')) {
    function communication_variables_default_sample()
    {
        $catalog = communication_variables_catalog();
        $out = array();
        foreach ($catalog as $key => $meta) {
            $out[$key] = isset($meta['example']) ? (string)$meta['example'] : '';
        }
        return $out;
    }
}

if (!function_exists('communication_variables_extract_from_content')) {
    function communication_variables_extract_from_content($text)
    {
        $text = (string)$text;
        $vars = array();
        if (preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $text, $m)) {
            foreach ($m[1] as $raw) {
                $key = strtolower(trim((string)$raw));
                if ($key !== '') {
                    $vars[$key] = true;
                }
            }
        }
        return array_keys($vars);
    }
}

if (!function_exists('communication_variables_extract_from_template_parts')) {
    function communication_variables_extract_from_template_parts($subjectTemplate, $bodyHtmlTemplate, $bodyTextTemplate)
    {
        $vars = array();
        $all = array(
            communication_variables_extract_from_content($subjectTemplate),
            communication_variables_extract_from_content($bodyHtmlTemplate),
            communication_variables_extract_from_content($bodyTextTemplate),
        );
        foreach ($all as $arr) {
            foreach ($arr as $k) {
                $vars[$k] = true;
            }
        }
        return array_keys($vars);
    }
}

if (!function_exists('communication_variables_unknown_keys')) {
    function communication_variables_unknown_keys($keys)
    {
        $keys = is_array($keys) ? $keys : array();
        $catalog = communication_variables_catalog();
        $unknown = array();
        foreach ($keys as $k) {
            $kk = strtolower(trim((string)$k));
            if ($kk === '') continue;
            if (!isset($catalog[$kk])) {
                $unknown[$kk] = true;
            }
        }
        return array_keys($unknown);
    }
}

if (!function_exists('communication_variables_sample_from_json')) {
    function communication_variables_sample_from_json($json)
    {
        $defaults = communication_variables_default_sample();
        $json = trim((string)$json);
        if ($json === '') return $defaults;

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return $defaults;

        foreach ($decoded as $k => $v) {
            $kk = strtolower(trim((string)$k));
            if ($kk === '') continue;
            if (is_array($v) || is_object($v)) continue;
            $defaults[$kk] = (string)$v;
        }
        return $defaults;
    }
}

if (!function_exists('communication_variables_schema_json_from_keys')) {
    function communication_variables_schema_json_from_keys($keys)
    {
        $keys = is_array($keys) ? $keys : array();
        $catalog = communication_variables_catalog();
        $out = array();

        foreach ($keys as $k) {
            $kk = strtolower(trim((string)$k));
            if ($kk === '') continue;
            if (isset($catalog[$kk])) {
                $out[$kk] = array(
                    'label' => isset($catalog[$kk]['label']) ? $catalog[$kk]['label'] : $kk,
                    'type' => isset($catalog[$kk]['type']) ? $catalog[$kk]['type'] : 'string',
                    'description' => isset($catalog[$kk]['description']) ? $catalog[$kk]['description'] : '',
                );
            } else {
                $out[$kk] = array(
                    'label' => $kk,
                    'type' => 'unknown',
                    'description' => 'Variable no registrada.',
                );
            }
        }

        if (empty($out)) return null;
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
