<?php
// inc/flash.php
function flash_set($type, $msg){
    if (!isset($_SESSION['_flash'])) $_SESSION['_flash'] = array();
    $_SESSION['_flash'][] = array('type'=>$type, 'msg'=>$msg);
}

function flash_get_all(){
    $out = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : array();
    unset($_SESSION['_flash']);
    return $out;
}
