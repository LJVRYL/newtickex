<?php
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
readfile(__DIR__ . '/index.html');
