<?php
require_once __DIR__ . '/inc/internal_cli_only.php';
require_once __DIR__ . "/str_diag.php";
str_diag_log("[STR][TEST] diag_test hit");
undefined_function_call(); // fatal
