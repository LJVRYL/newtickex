<?php
session_start();
// Redirigimos siempre al panel del evento STR (id=1)
header('Location: panel_evento.php?id=1');
exit;
