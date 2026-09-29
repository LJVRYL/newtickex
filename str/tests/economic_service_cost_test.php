<?php
require_once __DIR__ . '/../inc/manual_income.php';
require_once __DIR__ . '/../inc/unified_tickets.php';

function economic_cost_test_ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER, monto_pagado REAL, tipo TEXT)");
$pdo->exec("CREATE TABLE tc_orders (id INTEGER PRIMARY KEY, request_id TEXT, evento_id INTEGER, amount REAL, state TEXT, payment_status TEXT, payment_provider TEXT, service_fee_amount REAL)");
$pdo->exec("CREATE TABLE manual_income (id INTEGER PRIMARY KEY, evento_id INTEGER, concepto TEXT, monto REAL, tipo TEXT)");
$pdo->exec("INSERT INTO entradas (id,evento_id,monto_pagado,tipo) VALUES (1,15,1000,'General'),(2,15,2000,'General'),(3,15,1000,'General')");
$pdo->exec("INSERT INTO tc_orders (id,request_id,evento_id,amount,state,payment_status,payment_provider,service_fee_amount) VALUES
    (1,'tc-one',15,1000,'SUCCESS','confirmed','totalcoin',0),
    (2,'tc-two',15,2000,'SUCCESS','confirmed',NULL,0),
    (3,'tc-pending',15,9000,'created','pending','totalcoin',0),
    (4,'mp-one',15,1100,'approved','confirmed','mercadopago',100),
    (5,'bridge-15-old',15,500,'bridge_synced','confirmed',NULL,0)");
$pdo->exec("INSERT INTO manual_income (id,evento_id,concepto,monto,tipo) VALUES
    (1,15,'Ingreso de prueba',500,'ingreso'),
    (2,15,'Egreso de prueba',-200,'egreso')");

$stats = get_economic_stats($pdo, 15);
economic_cost_test_ok(abs((float)$stats['totalcoin_checkout_gross'] - 3000.0) < 0.001, 'only confirmed native TotalCoin orders form the cost base');
economic_cost_test_ok(abs((float)$stats['totalcoin_checkout_fee_3pct'] - 90.0) < 0.001, 'TotalCoin cost is three percent');
economic_cost_test_ok(abs((float)$stats['service_fee_charged'] - 100.0) < 0.001, 'buyer service charge is reported separately');
economic_cost_test_ok(abs((float)$stats['total_recaudado'] - 4500.0) < 0.001, 'gross collected total never subtracts provider or manual costs');
economic_cost_test_ok(abs((float)$stats['resultado_neto_base'] - 4210.0) < 0.001, 'net result subtracts provider and manual costs exactly once');
economic_cost_test_ok((int)$stats['entradas_vendidas'] === 3, 'cost calculation never changes issued ticket count');

echo "ALL ECONOMIC SERVICE COST TESTS PASSED\n";
