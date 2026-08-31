<?php
require_once __DIR__ . '/../inc/mercadopago_marketplace.php';

function mp_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

putenv('TICKEX_MP_CLIENT_ID=test-client');
putenv('TICKEX_MP_CLIENT_SECRET=test-secret');
putenv('TICKEX_MP_ENCRYPTION_KEY=test-encryption-key-with-enough-entropy');
putenv('TICKEX_MP_WEBHOOK_SECRET=test-webhook-secret');
putenv('TICKEX_MP_REDIRECT_URI=https://local.test/mercadopago_oauth_callback.php');
putenv('TICKEX_SITE_URL=https://local.test');
putenv('TICKEX_MP_SANDBOX=true');

$dbFile = tempnam(sys_get_temp_dir(), 'tickex-mp-');
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE eventos (id INTEGER PRIMARY KEY,nombre TEXT,creado_por_admin_id INTEGER,borrado_en TEXT)");
$pdo->exec("CREATE TABLE tc_orders (id INTEGER PRIMARY KEY AUTOINCREMENT,request_id TEXT UNIQUE,ref TEXT,amount REAL,state TEXT,payment_status TEXT,payment_confirmed_at TEXT,updated_at TEXT,seller_admin_id INTEGER,payment_provider TEXT)");
$pdo->exec("INSERT INTO eventos(id,nombre,creado_por_admin_id) VALUES(15,'Evento STR',7)");
$pdo->exec("INSERT INTO eventos(id,nombre,creado_por_admin_id) VALUES(16,'Evento cliente',8)");
tickex_mp_ensure_schema($pdo);

mp_test_assert((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('mercadopago_marketplace_accounts','mercadopago_event_configs','mercadopago_oauth_states','mercadopago_webhook_events')")->fetchColumn() === 4, 'marketplace schema is created');
mp_test_assert((int)$pdo->query("SELECT COUNT(*) FROM pragma_table_info('tc_orders') WHERE name IN ('payment_provider','provider_payment_id','provider_preference_id','marketplace_fee','seller_admin_id')")->fetchColumn() === 5, 'payment attribution columns are created');
mp_test_assert((int)$pdo->query("SELECT COUNT(*) FROM pragma_table_info('tc_orders') WHERE name IN ('ticket_subtotal','service_fee_amount','service_fee_percent','mp_cost_estimate_percent')")->fetchColumn() === 4, 'service cost audit columns are created');

$encrypted = tickex_mp_encrypt('APP_USR-secret-value');
mp_test_assert(strpos($encrypted, 'APP_USR-secret-value') === false, 'access token is never stored as plain text');
mp_test_assert(tickex_mp_decrypt($encrypted) === 'APP_USR-secret-value', 'encrypted token can be recovered');
$tampered = substr($encrypted, 0, -1) . (substr($encrypted, -1) === 'A' ? 'B' : 'A');
$tamperRejected = false;
try { tickex_mp_decrypt($tampered); } catch (Exception $e) { $tamperRejected = true; }
mp_test_assert($tamperRejected, 'tampered credentials are rejected');

tickex_mp_save_account_tokens($pdo, 7, array(
    'access_token' => 'APP_USR-seller-token',
    'refresh_token' => 'TG-refresh-token',
    'public_key' => 'APP_USR-public',
    'user_id' => '70001',
    'expires_in' => 15552000,
));
$account = tickex_mp_account($pdo, 7, true);
mp_test_assert($account && $account['status'] === 'connected' && $account['mp_user_id'] === '70001', 'seller account is linked to its Tickex administrator');
mp_test_assert($account['access_token'] === 'APP_USR-seller-token', 'seller token is decrypted only when required');

tickex_mp_save_account_tokens($pdo, 8, array(
    'access_token' => 'APP_USR-client-token',
    'refresh_token' => 'TG-client-refresh-token',
    'user_id' => '80001',
    'expires_in' => 15552000,
));

$default = tickex_mp_event_config($pdo, 15);
mp_test_assert($default['provider'] === 'totalcoin', 'existing events remain on TotalCoin by default');
$missingEstimateRejected = false;
try { tickex_mp_save_platform_settings($pdo, 10, 0, true, 1); } catch (Exception $e) { $missingEstimateRejected = true; }
mp_test_assert($missingEstimateRejected, 'commercial policy cannot be enabled without a Mercado Pago cost estimate');
tickex_mp_save_platform_settings($pdo, 10, 7.99, true, 1);
tickex_mp_save_admin_policy($pdo, 7, 'str_owner', '', 1);
$saved = tickex_mp_save_event_config($pdo, 15, 7, 'totalcoin', 0);
mp_test_assert($saved['provider'] === 'totalcoin', 'SAVE THE RAVE keeps TotalCoin');
$clientConfig = tickex_mp_event_config($pdo, 16);
mp_test_assert($clientConfig['provider'] === 'mercadopago', 'client organizers are forced to Mercado Pago');
mp_test_assert(abs($clientConfig['service_charge_percent'] - 10.0) < 0.001, 'client checkout adds the configured ten percent service charge');
mp_test_assert(abs($clientConfig['marketplace_fee_percent'] - 1.1009) < 0.001, 'Tickex fee is calculated over the final checkout total');
$forced = tickex_mp_save_event_config($pdo, 16, 8, 'totalcoin', 0);
mp_test_assert($forced['provider'] === 'mercadopago', 'client cannot switch its event to TotalCoin');
$override = tickex_mp_save_admin_policy($pdo, 8, 'client', '12', 1);
$overriddenConfig = tickex_mp_event_config($pdo, 16);
mp_test_assert(abs($overriddenConfig['service_charge_percent'] - 12.0) < 0.001, 'superadministrator can set a special service charge per client');
mp_test_assert(abs($overriddenConfig['marketplace_fee_percent'] - 2.7243) < 0.001, 'special service charge preserves the organizer nominal price');
$insufficientOverrideRejected = false;
try { tickex_mp_save_admin_policy($pdo, 8, 'client', '5', 1); } catch (Exception $e) { $insufficientOverrideRejected = true; }
mp_test_assert($insufficientOverrideRejected, 'service charge cannot leave the organizer paying the estimated Mercado Pago fee');
$otherAdminRejected = false;
try { tickex_mp_save_event_config($pdo, 15, 8, 'totalcoin', 0); } catch (Exception $e) { $otherAdminRejected = true; }
mp_test_assert($otherAdminRejected, 'another administrator cannot change the event payment account');
mp_test_assert(abs(tickex_mp_marketplace_fee(10000, 8.5) - 850) < 0.001, 'marketplace fee is calculated once over the order total');
$breakdown = tickex_mp_checkout_breakdown(10000, 10, 7.99);
mp_test_assert(abs($breakdown['checkout_total'] - 11000) < 0.001, 'buyer pays ten percent service charge over ticket prices');
mp_test_assert(abs($breakdown['mp_cost_estimate'] - 878.90) < 0.001, 'Mercado Pago estimated cost is calculated over the checkout total');
mp_test_assert(abs($breakdown['marketplace_fee'] - 121.10) < 0.001, 'Tickex receives only the remaining service charge');
mp_test_assert(abs($breakdown['organizer_net_estimate'] - 10000) < 0.001, 'organizer keeps the full nominal ticket price');

$capturedPayload = null;
$checkout = tickex_mp_create_preference($pdo, 7, array(
    'amount' => 11000,
    'concept' => 'Entrada Evento de prueba',
    'dni' => '12345678',
    'reference' => 'tickex-mp-test-ref',
    'last_name' => 'Prueba',
    'first_name' => 'Persona',
    'email' => 'persona@example.invalid',
    'event_id' => 15,
    'fee_percent' => $breakdown['marketplace_fee_percent'],
    'marketplace_fee' => $breakdown['marketplace_fee'],
), function ($payload, $seller) use (&$capturedPayload) {
    $capturedPayload = $payload;
    return array('id' => 'TEST-PREFERENCE-1', 'sandbox_init_point' => 'https://sandbox.mercadopago.test/checkout?pref_id=TEST-PREFERENCE-1');
});
mp_test_assert($checkout['request_id'] === 'mp-TEST-PREFERENCE-1', 'preference receives a provider-scoped request id');
mp_test_assert(abs($capturedPayload['marketplace_fee'] - 121.10) < 0.001, 'Checkout Pro receives only the Tickex remainder');
mp_test_assert(abs($capturedPayload['items'][0]['unit_price'] - 11000) < 0.001, 'Checkout Pro charges the buyer the ticket subtotal plus service cost');
mp_test_assert($capturedPayload['external_reference'] === 'tickex-mp-test-ref', 'preference carries the internal Tickex reference');
mp_test_assert(strpos($capturedPayload['notification_url'], 'mercadopago_webhook.php') !== false, 'preference registers the HTTPS webhook');

$stateUrl = tickex_mp_begin_oauth($pdo, 7);
parse_str(parse_url($stateUrl, PHP_URL_QUERY), $stateQuery);
mp_test_assert(!empty($stateQuery['state']) && !empty($stateQuery['client_id']), 'OAuth authorization uses state and marketplace client id');
mp_test_assert(tickex_mp_consume_oauth_state($pdo, $stateQuery['state']) === 7, 'OAuth state resolves the correct administrator');
mp_test_assert(tickex_mp_consume_oauth_state($pdo, $stateQuery['state']) === 0, 'OAuth state can be used only once');

$ts = '1742505638683';
$requestId = 'request-abc';
$dataId = '123456789';
$manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $ts . ';';
$signature = 'ts=' . $ts . ',v1=' . hash_hmac('sha256', $manifest, 'test-webhook-secret');
mp_test_assert(tickex_mp_verify_webhook_signature($signature, $requestId, $dataId), 'valid Mercado Pago webhook signature is accepted');
mp_test_assert(!tickex_mp_verify_webhook_signature($signature, $requestId, '999'), 'signature cannot be reused for another payment');

$pdo->prepare("INSERT INTO tc_orders(request_id,ref,amount,state,payment_status,seller_admin_id,payment_provider) VALUES('mp-TEST-PREFERENCE-1','tickex-mp-test-ref',10000,'created','pending',7,'mercadopago')")->execute();
$order = $pdo->query("SELECT * FROM tc_orders WHERE request_id='mp-TEST-PREFERENCE-1'")->fetch(PDO::FETCH_ASSOC);
$payment = array('id' => 'PAY-1', 'status' => 'approved', 'external_reference' => 'tickex-mp-test-ref', 'transaction_amount' => 10000, 'collector_id' => '70001');
$confirmed = tickex_mp_confirm_payment($pdo, $order, $payment);
mp_test_assert(!empty($confirmed['confirmed']), 'approved payment confirms a matching Tickex order');
mp_test_assert($pdo->query("SELECT payment_status FROM tc_orders WHERE id=" . (int)$order['id'])->fetchColumn() === 'confirmed', 'confirmed status is persisted');
$wrongAmount = $payment;
$wrongAmount['transaction_amount'] = 9000;
mp_test_assert(tickex_mp_confirm_payment($pdo, $order, $wrongAmount)['result'] === 'amount_mismatch', 'wrong amount can never confirm an order');
$wrongCollector = $payment;
$wrongCollector['collector_id'] = 'another-seller';
mp_test_assert(tickex_mp_confirm_payment($pdo, $order, $wrongCollector)['result'] === 'collector_mismatch', 'payment from another seller can never confirm an order');

@unlink($dbFile);
echo 'ALL MERCADO PAGO MARKETPLACE TESTS PASSED' . PHP_EOL;
