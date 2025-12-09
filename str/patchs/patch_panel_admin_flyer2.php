<?php
$f = __DIR__ . '/panel_admin.php';
$c = file_get_contents($f);

$pattern = '~<td>\s*<\?php\s*if\s*\(!empty\(\$ev\[\'flyer_filename\'\]\)\s*&&\s*file_exists\([^\)]*\)\s*\):\s*\?>.*?<\?php\s*else:\s*\?>.*?<\?php\s*endif;\s*\?>\s*</td>~s';

$replacement = <<<'REPL'
<td>
  <?php if (!empty($ev['flyer_filename']) && file_exists(__DIR__ . '/' . $ev['flyer_filename'])): ?>
    <img src="<?php echo e($ev['flyer_filename']); ?>" class="flyer-thumb" alt="Flyer">
  <?php else: ?>
    <span class="muted">Sin flyer</span>
  <?php endif; ?>
</td>
REPL;

$before = $c;
$c = preg_replace($pattern, $replacement, $c, 1);

if ($c === null) {
    fwrite(STDERR, "Error en preg_replace().\n");
    exit(1);
}
if ($c === $before) {
    fwrite(STDERR, "No pude encontrar el bloque del flyer para reemplazar.\n");
    exit(1);
}

file_put_contents($f, $c);
echo "Bloque flyer arreglado (regex) en panel_admin.php\n";
