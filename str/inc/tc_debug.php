<?php
// Minimal debug logging helper for Phase 0 stabilization.
// Writes JSON lines to uploads/tc_debug.jsonl. Safe, no DB changes.
if (!function_exists('tc_debug_log')) {
    function tc_debug_log($tag, $event, $data = array()) {
        try {
            $out = array();
            $out['ts'] = date('c');
            $out['host'] = php_uname('n');
            $out['pid'] = getmypid();
            $out['remote_addr'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
            $out['script'] = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null;
            $out['tag'] = (string)$tag;
            $out['event'] = (string)$event;
            $out['data'] = $data;
            $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $dir = __DIR__ . '/../uploads';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $file = $dir . '/tc_debug.jsonl';
            @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // best-effort: do not throw
        }
    }
}
