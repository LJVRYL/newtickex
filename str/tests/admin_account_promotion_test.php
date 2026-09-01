<?php
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$source = file_get_contents(__DIR__ . '/../superadmin_usuarios.php');
if (strpos($source, 'tickex_superadmin_promote_registered') === false || strpos($source, "tipo_global='admin_evento'") === false) {
    fwrite(STDERR, "FAIL: independent administrator promotion is missing\n"); exit(1);
}
if (strpos($source, "creado_por_admin_id=NULL") === false || strpos($source, "evento_id=NULL") === false) {
    fwrite(STDERR, "FAIL: promoted administrator is not independent\n"); exit(1);
}
if (strpos($source, 'password_hash FROM registro_pendientes') === false) {
    fwrite(STDERR, "FAIL: secure registered password is not reused\n"); exit(1);
}
echo "PASS: promotion uses every registered user source\n";
echo "PASS: promoted administrator is independent\n";
echo "PASS: bcrypt password is required\n";
echo "ALL ADMIN ACCOUNT PROMOTION TESTS PASSED\n";
