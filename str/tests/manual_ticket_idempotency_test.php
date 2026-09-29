<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../inc/manual_ticket_issuance.php';
function manual_idempotency_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, creado_por_admin_id INTEGER)');
$pdo->exec('CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY, evento_id INTEGER, nombre TEXT, tipo TEXT, precio REAL, cantidad_disponible INTEGER, qr_quantity INTEGER)');
$pdo->exec('CREATE TABLE tc_orders (id INTEGER PRIMARY KEY, request_id TEXT UNIQUE, evento_id INTEGER, buyer_email TEXT, payment_provider TEXT, selected_tickets_json TEXT, amount REAL, processing_status TEXT, email_status TEXT, email_last_error TEXT)');
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY, tc_order_request_id TEXT)');
$pdo->exec("INSERT INTO eventos VALUES (1, 'QA', 7)");
$pdo->exec("INSERT INTO tipos_entrada VALUES (2, 1, 'Invitación', 'cortesia', 0, 0, 1)");
$requestId = 'mobile-manual-1234567890abcdef1234567890abcdef';
$insert = $pdo->prepare("INSERT INTO tc_orders VALUES (3, :request, 1, :email, :mode, :tickets, 0, 'issued', :status, :error)");
$insert->execute(array(':request' => $requestId, ':email' => 'qa@example.invalid', ':mode' => 'courtesy', ':tickets' => json_encode(array(array('id' => 2, 'qty' => 1))), ':status' => 'sent', ':error' => ''));
$pdo->exec("INSERT INTO entradas VALUES (4, '$requestId')");
$data = array('evento_id' => 1, 'tipo_id' => 2, 'cantidad' => 1, 'modo' => 'courtesy', 'email' => 'qa@example.invalid', 'admin_id' => 7, 'restrict_to_admin' => true, 'request_id' => $requestId);
$repeat = tickex_manual_issue_package($pdo, $data);
manual_idempotency_assert((int)$repeat['order_id'] === 3 && (int)$repeat['issued_quantity'] === 1, 'retry returns existing issued order despite zero stock');
manual_idempotency_assert((int)$pdo->query('SELECT COUNT(*) FROM tc_orders')->fetchColumn() === 1, 'retry does not create order');
$data['email'] = 'different@example.invalid';
$rejected = false;
try { tickex_manual_issue_package($pdo, $data); } catch (InvalidArgumentException $error) { $rejected = true; }
manual_idempotency_assert($rejected, 'same key with different recipient is rejected');
$failedId = 'mobile-manual-abcdef1234567890abcdef1234567890';
$failedInsert = $pdo->prepare("INSERT INTO tc_orders VALUES (5, :request, 1, 'qa@example.invalid', 'courtesy', :tickets, 0, 'failed', 'pending', '')");
$failedInsert->execute(array(':request' => $failedId, ':tickets' => json_encode(array(array('id' => 2, 'qty' => 1)))));
$data['email'] = 'qa@example.invalid'; $data['request_id'] = $failedId;
$failedRetryRejected = false;
try { tickex_manual_issue_package($pdo, $data); } catch (RuntimeException $error) { $failedRetryRejected = true; }
manual_idempotency_assert($failedRetryRejected, 'failed issuance is never reported as a successful retry');
tickex_event_sales_set_paused($pdo, 1, 7, true, 'Aforo completo');
$data['request_id'] = 'mobile-manual-fedcba0987654321fedcba0987654321';
$pausedRejected = false;
try { tickex_manual_issue_package($pdo, $data); } catch (RuntimeException $error) { $pausedRejected = strpos($error->getMessage(), 'pausadas') !== false; }
manual_idempotency_assert($pausedRejected, 'new manual issuance is blocked while event sales are paused');
$data['request_id'] = $requestId;
$repeatWhilePaused = tickex_manual_issue_package($pdo, $data);
manual_idempotency_assert((int)$repeatWhilePaused['order_id'] === 3, 'idempotent status lookup still works after pause');
$pdo->exec("INSERT INTO tc_orders VALUES (6, 'mobile-manual-00112233445566778899aabbccddeeff', 1, 'qa@example.invalid', 'manual_transfer', '[{\"id\":0,\"qty\":1}]', 115, 'issued', 'sent', '')");
$pdo->exec("INSERT INTO entradas VALUES (7, 'mobile-manual-00112233445566778899aabbccddeeff')");
$uncategorized = array('evento_id' => 1, 'sin_tipo' => true, 'cantidad' => 1, 'modo' => 'manual_transfer', 'monto_total' => '115', 'email' => 'qa@example.invalid', 'admin_id' => 7, 'restrict_to_admin' => true, 'request_id' => 'mobile-manual-00112233445566778899aabbccddeeff');
$uncategorizedRepeat = tickex_manual_issue_package($pdo, $uncategorized);
manual_idempotency_assert((int)$uncategorizedRepeat['order_id'] === 6, 'uncategorized manual sale can be checked without issuing twice');
$uncategorized['monto_total'] = '116';
$wrongAmountRejected = false;
try { tickex_manual_issue_package($pdo, $uncategorized); } catch (InvalidArgumentException $error) { $wrongAmountRejected = true; }
manual_idempotency_assert($wrongAmountRejected, 'same manual request cannot be replayed with a different amount');
echo "manual ticket idempotency PASS\n";
