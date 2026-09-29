<?php
function tickex_wizard_schema($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS event_wizard_drafts (admin_id INTEGER PRIMARY KEY, payload TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
}
function tickex_wizard_save($pdo,$adminId,$input,$flyer=null) {
    if ($adminId<=0) throw new RuntimeException('Acceso inválido.');
    $limits=array('nombre'=>120,'slug'=>80,'fecha_desde'=>10,'fecha_hasta'=>10,'hora_puertas'=>5,'hora_show'=>5,'capacidad_total'=>7,'descripcion'=>3000,'lugar'=>120,'direccion'=>220,'indicaciones_llegada'=>1200,'transporte_publico'=>1200,'politica_menores'=>600,'movilidad_reducida'=>600,'objetos_prohibidos'=>1200,'max_entradas_compra'=>2);
    $data=array();
    foreach($limits as $key=>$max){
        if(isset($input[$key])&&!is_scalar($input[$key])) throw new RuntimeException('Dato inválido.');
        $value=isset($input[$key])?trim((string)$input[$key]):'';
        if(tickex_event_creation_length($value)>$max) throw new RuntimeException('Revisá la extensión de los datos.');
        $data[$key]=$value;
    }
    if($data['nombre']==='')throw new RuntimeException('Escribí el nombre para guardar tu borrador.');
    $data['wizard_step']=max(0,min(4,isset($input['wizard_step'])?(int)$input['wizard_step']:0));
    $previous=tickex_wizard_load($pdo,$adminId);
    $data['saved_flyer']=$flyer!==null?$flyer:(isset($previous['saved_flyer'])?$previous['saved_flyer']:'');
    $json=json_encode($data,JSON_UNESCAPED_UNICODE);
    if($json===false)throw new RuntimeException('No pudimos interpretar los datos.');
    $st=$pdo->prepare('INSERT OR REPLACE INTO event_wizard_drafts(admin_id,payload,updated_at) VALUES(?,?,CURRENT_TIMESTAMP)');
    $st->execute(array($adminId,$json));
    return $data;
}
function tickex_wizard_upload($file){
    if(!$file || (int)$file['error']===UPLOAD_ERR_NO_FILE)return null;
    if((int)$file['error']!==UPLOAD_ERR_OK || (int)$file['size']>2097152)throw new RuntimeException('El flyer debe pesar hasta 2 MB.');
    $info=@getimagesize($file['tmp_name']);$types=array('image/jpeg'=>'jpg','image/png'=>'png');
    if(!$info||!isset($types[$info['mime']]))throw new RuntimeException('Elegí un flyer PNG o JPG válido.');
    $dir=dirname(__DIR__).'/event_flyers';
    if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('No pudimos guardar el flyer.');
    $path='event_flyers/wizard_'.bin2hex(random_bytes(16)).'.'.$types[$info['mime']];
    if(!move_uploaded_file($file['tmp_name'],dirname(__DIR__).'/'.$path))throw new RuntimeException('No pudimos guardar el flyer.');
    return $path;
}
function tickex_wizard_load($pdo,$adminId){
    $st=$pdo->prepare('SELECT payload FROM event_wizard_drafts WHERE admin_id=?');$st->execute(array($adminId));
    $data=json_decode((string)$st->fetchColumn(),true);return is_array($data)?$data:array();
}
