<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");

require_once __DIR__ . '/../inc/ticket_packages.php';

function package_test_ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
    echo "PASS: " . $message . "\n";
}

package_test_ok(tickex_ticket_qr_quantity(null) === 1, 'legacy ticket types default to one QR');
package_test_ok(tickex_ticket_qr_quantity(4) === 4, 'configured QR quantity is preserved');
package_test_ok(tickex_ticket_qr_quantity(99) === 10, 'QR quantity is capped safely');
package_test_ok(tickex_ticket_package_capacity(9, 4) === 2, 'checkout exposes only complete packages');
package_test_ok(tickex_ticket_package_capacity(3, 4) === 0, 'incomplete stock cannot sell a package');
package_test_ok(tickex_ticket_issued_quantity(2, 4) === 8, 'package quantity expands to independent QR entries');
package_test_ok(abs(tickex_ticket_amount_per_qr(300, 4) - 75.0) < 0.001, 'package price is allocated without duplicating revenue');

echo "ALL TICKET PACKAGE TESTS PASSED\n";
