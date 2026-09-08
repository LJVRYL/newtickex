<?php
if (!function_exists('tickex_event_creation_length')) {
    function tickex_event_creation_length($value) { return function_exists('mb_strlen') ? mb_strlen((string)$value, 'UTF-8') : strlen((string)$value); }
}
if (!function_exists('tickex_event_creation_slugify')) {
    function tickex_event_creation_slugify($value) {
        $value=trim((string)$value); if ($value==='') return '';
        $value=strtr($value,array(
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'º'=>'o','ª'=>'a'
        ));
        if (function_exists('iconv')) { $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value); if ($ascii!==false) $value=$ascii; }
        return trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower($value)),'-');
    }
}
if (!function_exists('tickex_event_creation_valid_date')) {
    function tickex_event_creation_valid_date($value) { $date=DateTime::createFromFormat('!Y-m-d',(string)$value); return $date instanceof DateTime && $date->format('Y-m-d')===(string)$value; }
}
if (!function_exists('tickex_event_creation_validate')) {
    function tickex_event_creation_validate($data) {
        $name=trim(isset($data['nombre'])?(string)$data['nombre']:''); $slug=trim(isset($data['slug'])?(string)$data['slug']:'');
        $description=trim(isset($data['descripcion'])?(string)$data['descripcion']:''); $capacity=isset($data['capacidad_total'])?(int)$data['capacidad_total']:0;
        $from=trim(isset($data['fecha_desde'])?(string)$data['fecha_desde']:''); $until=trim(isset($data['fecha_hasta'])?(string)$data['fecha_hasta']:'');
        if ($name==='') return 'Escribí el nombre del evento.';
        if (tickex_event_creation_length($name)>120) return 'El nombre puede tener hasta 120 caracteres.';
        if ($slug==='' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) return 'Definí un identificador público con letras minúsculas, números y guiones.';
        if (tickex_event_creation_length($slug)>80) return 'El identificador público puede tener hasta 80 caracteres.';
        if ($capacity<1 || $capacity>1000000) return 'Definí un cupo total entre 1 y 1.000.000.';
        if (!tickex_event_creation_valid_date($from) || !tickex_event_creation_valid_date($until)) return 'Definí la fecha de inicio y la fecha de finalización.';
        if ($until<$from) return 'La fecha de finalización no puede ser anterior a la fecha de inicio.';
        if (tickex_event_creation_length($description)>3000) return 'La descripción puede tener hasta 3.000 caracteres.';
        return '';
    }
}
