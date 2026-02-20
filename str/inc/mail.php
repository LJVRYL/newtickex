<?php
// inc/mail.php
// Simple mail sender for Tickex (uses PHP mail())

function tickex_mail_forced_from_email()
{
    $v = getenv('TICKEX_MAIL_FROM_EMAIL');
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    return 'servicio@tickex.com.ar';
}

function tickex_mail_forced_from_name()
{
    $v = getenv('TICKEX_MAIL_FROM_NAME');
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    return 'Tickex';
}

function tickex_mail_forced_envelope_from_email($fallback)
{
    $v = getenv('TICKEX_MAIL_ENVELOPE_FROM');
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    return (is_string($fallback) && trim($fallback) !== '') ? trim($fallback) : '';
}

function tickex_mail_make_trace_id()
{
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(random_bytes(8));
        } catch (Exception $e) {
            // ignore
        }
    }
    return sha1(uniqid(mt_rand(), true));
}

function tickex_mail_db_file()
{
    return __DIR__ . '/../save_the_rave.sqlite';
}

function tickex_mail_ensure_schema($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        resend_of_id INTEGER,
        context TEXT,
        related_table TEXT,
        related_id INTEGER,
        to_email TEXT NOT NULL,
        from_email TEXT,
        from_name TEXT,
        reply_to TEXT,
        subject TEXT,
        body TEXT,
        headers TEXT,
        extra_params TEXT,
        mail_ok INTEGER NOT NULL DEFAULT 0,
        error_text TEXT
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_logs_created_at ON email_logs(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_logs_to ON email_logs(to_email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_logs_context ON email_logs(context)");

    // Plantillas editables por superadmin
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        context TEXT NOT NULL,
        name TEXT,
        enabled INTEGER NOT NULL DEFAULT 1,
        is_html INTEGER NOT NULL DEFAULT 0,
        from_email TEXT,
        from_name TEXT,
        reply_to TEXT,
        extra_params TEXT,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_email_templates_context ON email_templates(context)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_templates_enabled ON email_templates(enabled)");
}

function tickex_render_placeholders($text, $vars)
{
    if (!is_string($text)) {
        return '';
    }
    if (!is_array($vars) || empty($vars)) {
        return $text;
    }

    foreach ($vars as $k => $v) {
        if (!is_string($k) || $k === '') {
            continue;
        }
        if (is_array($v) || is_object($v)) {
            continue;
        }
        $text = str_replace('{{' . $k . '}}', (string)$v, $text);
    }
    return $text;
}

function tickex_get_email_template($context)
{
    $pdo = tickex_mail_open_log_db();
    if (!$pdo) {
        return null;
    }

    try {
        $st = $pdo->prepare('SELECT * FROM email_templates WHERE context = :c AND enabled = 1 LIMIT 1');
        $st->execute(array(':c' => $context));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * tickex_send_mail_template
 *
 * Envia usando una plantilla por `context` si existe; caso contrario usa un fallback.
 *
 * $fallback puede incluir:
 *   subject, body, from_email, from_name, reply_to, extra_params, is_html
 */
function tickex_send_mail_template($to, $context, $vars, $opts = array(), $fallback = null)
{
    $tpl = tickex_get_email_template($context);
    $use = null;
    if ($tpl) {
        $use = $tpl;
    } elseif (is_array($fallback)) {
        $use = $fallback;
    } else {
        return false;
    }

    $subject = isset($use['subject']) ? tickex_render_placeholders($use['subject'], $vars) : '';
    $body    = isset($use['body']) ? tickex_render_placeholders($use['body'], $vars) : '';

    $mailOpts = is_array($opts) ? $opts : array();

    if (!empty($use['from_email'])) $mailOpts['from_email'] = $use['from_email'];
    if (!empty($use['from_name']))  $mailOpts['from_name']  = $use['from_name'];
    if (!empty($use['reply_to']))   $mailOpts['reply_to']   = $use['reply_to'];
    if (!empty($use['extra_params'])) $mailOpts['extra_params'] = $use['extra_params'];
    if (isset($use['is_html'])) $mailOpts['is_html'] = ((int)$use['is_html'] === 1) ? 1 : 0;

    // context siempre en el log
    if (!isset($mailOpts['context']) || $mailOpts['context'] === null || $mailOpts['context'] === '') {
        $mailOpts['context'] = $context;
    }

    return tickex_send_mail($to, $subject, $body, $mailOpts);
}

function tickex_mail_open_log_db()
{
    $dbFile = tickex_mail_db_file();
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mitigar locks transitorios de SQLite (best-effort)
        try {
            $pdo->exec('PRAGMA busy_timeout = 15000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        } catch (Exception $e) {
            // ignore
        }

        tickex_mail_ensure_schema($pdo);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

function tickex_mail_log_row($data)
{
    $pdo = tickex_mail_open_log_db();
    if (!$pdo) {
        return;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO email_logs (resend_of_id, context, related_table, related_id, to_email, from_email, from_name, reply_to, subject, body, headers, extra_params, mail_ok, error_text) VALUES (:resend_of_id, :context, :related_table, :related_id, :to_email, :from_email, :from_name, :reply_to, :subject, :body, :headers, :extra_params, :mail_ok, :error_text)');
        $stmt->execute(array(
            ':resend_of_id' => isset($data['resend_of_id']) ? $data['resend_of_id'] : null,
            ':context'      => isset($data['context']) ? $data['context'] : null,
            ':related_table'=> isset($data['related_table']) ? $data['related_table'] : null,
            ':related_id'   => isset($data['related_id']) ? $data['related_id'] : null,
            ':to_email'     => isset($data['to_email']) ? $data['to_email'] : '',
            ':from_email'   => isset($data['from_email']) ? $data['from_email'] : null,
            ':from_name'    => isset($data['from_name']) ? $data['from_name'] : null,
            ':reply_to'     => isset($data['reply_to']) ? $data['reply_to'] : null,
            ':subject'      => isset($data['subject']) ? $data['subject'] : null,
            ':body'         => isset($data['body']) ? $data['body'] : null,
            ':headers'      => isset($data['headers']) ? $data['headers'] : null,
            ':extra_params' => isset($data['extra_params']) ? $data['extra_params'] : null,
            ':mail_ok'      => !empty($data['mail_ok']) ? 1 : 0,
            ':error_text'   => isset($data['error_text']) ? $data['error_text'] : null,
        ));
    } catch (Exception $e) {
        // no-op
    }
}

/**
 * tickex_send_mail
 *
 * Backward compatible:
 *   tickex_send_mail($to, $subject, $body, 'no-reply@tickex.com.ar')
 *
 * Extended:
 *   tickex_send_mail($to, $subject, $body, array(
 *     'from_email' => 'no-reply@tickex.com.ar',
 *     'from_name' => 'Tickex',
 *     'reply_to' => 'no-reply@tickex.com.ar',
 *     'extra_params' => '-f no-reply@tickex.com.ar',
 *     'context' => 'registro_step1',
 *     'related_table' => 'registro_pendientes',
 *     'related_id' => 123,
 *     'resend_of_id' => 55,
 *   ))
 */
function tickex_send_mail($to, $subject, $body, $fromOrOpts = 'no-reply@tickex.com.ar', $fromName = null)
{
    $opts = array();
    if (is_array($fromOrOpts)) {
        $opts = $fromOrOpts;
    } else {
        $opts['from_email'] = $fromOrOpts;
        if ($fromName !== null) {
            $opts['from_name'] = $fromName;
        }
    }

    $fromEmail = isset($opts['from_email']) ? $opts['from_email'] : 'no-reply@tickex.com.ar';
    $fromName2 = isset($opts['from_name']) ? $opts['from_name'] : '';
    $replyTo   = isset($opts['reply_to']) ? $opts['reply_to'] : $fromEmail;
    $extra     = isset($opts['extra_params']) ? $opts['extra_params'] : '';

    $traceId = tickex_mail_make_trace_id();

    // Forzar From/Reply-To/envelope sender para todos los emails del sistema
    $forcedFrom = tickex_mail_forced_from_email();
    if ($forcedFrom !== '') {
        $fromEmail = $forcedFrom;
        $replyTo = $forcedFrom;
        $envelopeFrom = tickex_mail_forced_envelope_from_email($forcedFrom);
        if ($envelopeFrom !== '') {
            $extra = '-f ' . $envelopeFrom;
        }
        if ($fromName2 === '') {
            $fromName2 = tickex_mail_forced_from_name();
        }
    }

    $fromHeader = $fromEmail;
    if ($fromName2 !== '') {
        $fromHeader = $fromName2 . ' <' . $fromEmail . '>';
    }

    $contentType = 'text/plain';
    if (!empty($opts['content_type'])) {
        $contentType = $opts['content_type'];
    } elseif (!empty($opts['is_html'])) {
        $contentType = 'text/html';
    }

    $headers  = 'From: ' . $fromHeader . "\r\n";
    $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: ' . $contentType . '; charset=UTF-8' . "\r\n";
    $headers .= 'X-Tickex-Trace: ' . $traceId . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion();

    if (!empty($opts['headers_extra'])) {
        $headers .= "\r\n" . $opts['headers_extra'];
    }

    $ok = false;
    $errText = null;
    if ($extra !== '') {
        $ok = @mail($to, $subject, $body, $headers, $extra);
    } else {
        $ok = @mail($to, $subject, $body, $headers);
    }

    if (!$ok) {
        $last = error_get_last();
        if (is_array($last) && isset($last['message'])) {
            $errText = $last['message'];
        }
    }

    tickex_mail_log_row(array(
        'resend_of_id' => isset($opts['resend_of_id']) ? $opts['resend_of_id'] : null,
        'context'      => isset($opts['context']) ? $opts['context'] : null,
        'related_table'=> isset($opts['related_table']) ? $opts['related_table'] : null,
        'related_id'   => isset($opts['related_id']) ? $opts['related_id'] : null,
        'to_email'     => $to,
        'from_email'   => $fromEmail,
        'from_name'    => $fromName2,
        'reply_to'     => $replyTo,
        'subject'      => $subject,
        'body'         => $body,
        'headers'      => $headers,
        'extra_params' => $extra,
        'mail_ok'      => $ok ? 1 : 0,
        'error_text'   => $errText,
    ));

    return $ok;
}
