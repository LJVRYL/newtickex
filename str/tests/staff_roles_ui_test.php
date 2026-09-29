<?php
$source=file_get_contents(__DIR__.'/../roles_staff.php');
function roles_ui_assert($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}echo 'PASS: '.$message.PHP_EOL;}
roles_ui_assert(strpos($source,'Roles predefinidos')!==false,'system roles have their own visual section');
roles_ui_assert(strpos($source,'Roles personalizados')!==false,'custom roles have their own visual section');
roles_ui_assert(strpos($source,'Cómo se organiza')!==false,'role assignment is explained before editing');
roles_ui_assert(strpos($source,'<details class="role-editor">')!==false,'role editors stay collapsed until requested');
roles_ui_assert(strpos($source,'Crear un rol personalizado')!==false,'custom role creation is a secondary workflow');
roles_ui_assert(strpos($source,'Historial de roles y permisos')!==false,'permission history remains accessible');
roles_ui_assert(strpos($source,"count($".'systemRoles'.")")!==false,'system role total is calculated separately');
roles_ui_assert(strpos($source,"count($".'customRoles'.")")!==false,'custom role total is calculated separately');
echo 'ALL STAFF ROLES UI TESTS PASSED'.PHP_EOL;
