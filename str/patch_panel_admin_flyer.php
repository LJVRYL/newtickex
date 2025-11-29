<?php
$f = __DIR__ . '/panel_admin.php';
$c = file_get_contents($f);

$search = <<<'SEARCH'
          <td>
            <?php if (!empty($ev['flyer_filename']) && file_exists(
              <img src="<?php echo e($ev['flyer_filename']); ?>" class="flyer-thumb" alt="Flyer">
            <?php else: ?>
              <span class="muted">Sin flyer</span>
            <?php endif; ?>
          </td>
SEARCH;

$replace = <<<'REPLACE'
          <td>
            <?php if (!empty($ev['flyer_filename']) && file_exists(__DIR__ . '/' . $ev['flyer_filename'])): ?>
              <img src="<?php echo e($ev['flyer_filename']); ?>" class="flyer-thumb" alt="Flyer">
            <?php else: ?>
              <span class="muted">Sin flyer</span>
            <?php endif; ?>
          </td>
REPLACE;

if (strpos($c, $search) === false) {
    fwrite(STDERR, "No encontré el bloque roto del flyer para reemplazar.\n");
    exit(1);
}

$c = str_replace($search, $replace, $c);
file_put_contents($f, $c);
echo "Bloque flyer arreglado en panel_admin.php\n";
