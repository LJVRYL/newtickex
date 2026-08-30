<?php
require_once __DIR__ . '/mail.php';

if (!function_exists('communication_transport_provider_legacy_mail_send')) {
    function communication_transport_provider_legacy_mail_send($pdo, $providerConfig, $message, $context)
    {
        $to = isset($message['to_email']) ? trim((string)$message['to_email']) : '';
        $subject = isset($message['subject']) ? (string)$message['subject'] : '';
        $bodyHtml = isset($message['body_html']) ? (string)$message['body_html'] : '';
        $bodyText = isset($message['body_text']) ? (string)$message['body_text'] : '';

        if ($to === '') {
            return array(
                'status' => 'permanent_error',
                'provider_name' => 'legacy_mail_php',
                'provider_message_id' => null,
                'response_code' => 'NO_RECIPIENT',
                'response_message' => 'Destinatario vacio.',
                'latency_ms' => 0,
                'retry_recommended' => false,
                'classification_reason' => 'validation',
            );
        }

        $isHtml = (trim($bodyHtml) !== '');
        $body = $isHtml ? $bodyHtml : $bodyText;

        $opts = array(
            'context' => 'communication_campaign',
            'related_table' => 'communication_campaign_runs',
            'related_id' => isset($context['campaign_run_id']) ? (int)$context['campaign_run_id'] : null,
            'is_html' => $isHtml ? 1 : 0,
        );
        $unsubscribeUrl = isset($message['unsubscribe_url']) ? trim((string)$message['unsubscribe_url']) : '';
        if ($unsubscribeUrl !== '') {
            $opts['headers_extra'] = 'List-Unsubscribe: <' . $unsubscribeUrl . ">\r\n" . 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }

        $ok = tickex_send_mail($to, $subject, $body, $opts);

        $providerMessageId = null;
        $responseMessage = $ok ? 'Accepted by legacy mail().' : 'mail() returned false';
        $responseCode = $ok ? 'OK' : 'MAIL_FALSE';

        try {
            $relatedId = isset($context['campaign_run_id']) ? (int)$context['campaign_run_id'] : 0;
            if ($relatedId > 0) {
                $st = $pdo->prepare("SELECT trace_id, error_text, mail_ok FROM email_logs WHERE related_table = 'communication_campaign_runs' AND related_id = :rid AND lower(to_email) = lower(:to) ORDER BY id DESC LIMIT 1");
                $st->execute(array(':rid' => $relatedId, ':to' => $to));
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if (!empty($row['trace_id'])) {
                        $providerMessageId = (string)$row['trace_id'];
                    }
                    if (!$ok && !empty($row['error_text'])) {
                        $responseMessage = (string)$row['error_text'];
                    }
                    if (isset($row['mail_ok']) && (int)$row['mail_ok'] === 1) {
                        $ok = true;
                        $responseCode = 'OK';
                    }
                }
            }
        } catch (Exception $e) {
            // ignore lookup failures
        }

        if ($ok) {
            return array(
                'status' => 'accepted',
                'provider_name' => 'legacy_mail_php',
                'provider_message_id' => $providerMessageId,
                'response_code' => $responseCode,
                'response_message' => $responseMessage,
                'latency_ms' => 0,
                'retry_recommended' => false,
                'classification_reason' => 'accepted',
            );
        }

        return array(
            'status' => 'transient_error',
            'provider_name' => 'legacy_mail_php',
            'provider_message_id' => $providerMessageId,
            'response_code' => $responseCode,
            'response_message' => $responseMessage,
            'latency_ms' => 0,
            'retry_recommended' => true,
            'classification_reason' => 'provider',
        );
    }
}
