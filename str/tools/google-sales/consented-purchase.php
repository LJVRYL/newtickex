<?php
/** Prepared server-side contract. No credentials, network calls or automatic backfill. */
final class ConsentedPurchase {
    public static function context(array $cookies, $now = null) {
        $now = $now === null ? time() : (int) $now;
        if (($cookies['ge_growth_consent'] ?? '') !== 'granted' || ($cookies['ge_sales_scope_v1'] ?? '') !== 'granted') return null;
        if (!preg_match('/^GA\d+\.\d+\.(\d{1,20}\.\d{1,20})$/', $cookies['_ga'] ?? '', $match)) return null;
        $touch = json_decode($cookies['ge_growth_touch'] ?? '', true);
        $context = array('client_id' => $match[1], 'consented_at' => $now, 'scope'=>'sales-v1');
        if (is_array($touch) && (int)($touch['expires_at'] ?? 0) > $now) {
            $last = $touch['last'] ?? array();
            foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term') as $key) {
                $value = $last[$key] ?? '';
                if (is_string($value) && preg_match('/^[a-zA-Z0-9_.~+\-]{1,100}$/D', $value)) $context[$key] = $value;
            }
        }
        return $context;
    }

    public static function payload($brand, array $context, array $payment, $now = null) {
        $now = $now === null ? time() : (int)$now;
        if (!in_array($brand, array('graphex', 'tickex'), true)) return null;
        if (($context['scope']??'')!=='sales-v1') return null;
        if (!preg_match('/^\d{1,20}\.\d{1,20}$/D', $context['client_id'] ?? '')) return null;
        $consentTime = (int)($context['consented_at'] ?? 0);
        if ($consentTime <= 0 || $consentTime > $now || $now - $consentTime > 180 * 86400) return null;
        if (($payment['confirmed'] ?? false) !== true || ($payment['refunded'] ?? false) === true) return null;
        $id = $payment['order_id'] ?? '';
        if (!is_int($id) || $id <= 0) return null;
        $value = $payment['value'] ?? null;
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) return null;
        $currency = $payment['currency'] ?? '';
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) return null;
        $items = array();
        foreach ($payment['items'] ?? array() as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null) || $item['id'] <= 0
                || !is_int($item['quantity'] ?? null) || $item['quantity'] <= 0
                || !is_numeric($item['price'] ?? null) || !is_finite((float)$item['price']) || (float)$item['price'] < 0) return null;
            $items[] = array('item_id' => $brand . '-' . $item['id'], 'quantity' => $item['quantity'], 'price' => round((float)$item['price'], 2));
        }
        if (!$items) return null;
        $params = array('transaction_id' => $brand . '-' . $id, 'currency' => $currency,
            'value' => round((float)$value, 2), 'items' => $items);
        // No URLs, names, contact details, order references or arbitrary provider payload.
        foreach (array('utm_source'=>'source', 'utm_medium'=>'medium', 'utm_campaign'=>'campaign', 'utm_content'=>'content', 'utm_term'=>'term') as $key=>$field) {
            $value = $context[$key] ?? '';
            if (is_string($value) && preg_match('/^[a-zA-Z0-9_.~+\-]{1,100}$/D', $value)) $params[$field] = $value;
        }
        return array('client_id'=>$context['client_id'], 'consent'=>array('ad_user_data'=>'DENIED','ad_personalization'=>'DENIED'),
            'events'=>array(array('name'=>'purchase', 'params'=>$params)));
    }
}

final class PurchaseOutbox {
    private $pdo;
    public function __construct(PDO $pdo) {
        $this->pdo=$pdo;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS purchase_outbox (transaction_id TEXT PRIMARY KEY, client_id TEXT NOT NULL, payload TEXT NOT NULL, state TEXT NOT NULL DEFAULT \'pending\', created_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0)');
    }
    public function enqueue($brand, array $context, array $payment, $now=null) {
        $payload=ConsentedPurchase::payload($brand,$context,$payment,$now);
        if (!$payload) return false;
        $st=$this->pdo->prepare('INSERT OR IGNORE INTO purchase_outbox(transaction_id,client_id,payload,created_at) VALUES(?,?,?,?)');
        $st->execute(array($payload['events'][0]['params']['transaction_id'],$payload['client_id'],json_encode($payload),$now===null?time():(int)$now));
        return $st->rowCount()===1;
    }
    public function revoke($clientId) {
        // Keep a deduplication tombstone, erase data queued for transmission.
        $st=$this->pdo->prepare("UPDATE purchase_outbox SET state='revoked',client_id='',payload='' WHERE client_id=? AND state='pending'");
        $st->execute(array($clientId));
    }
}
