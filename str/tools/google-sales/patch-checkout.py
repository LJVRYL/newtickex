from pathlib import Path
import hashlib,sys
path=Path(sys.argv[1])
original=path.read_bytes()
if len(sys.argv)>2 and hashlib.sha256(original).hexdigest()!=sys.argv[2]:
    raise SystemExit('Checkout changed: refuse to overwrite')
text=original.decode('utf-8')
needle="                $ordRow = $stOrdId->fetch(PDO::FETCH_ASSOC);"
assert text.count(needle)==1,'Unexpected checkout anchor'
assert 'tickex_sales_capture_order' not in text,'Already patched'
text=text.replace(needle,needle+"\n                try { require_once __DIR__ . '/inc/sales_measurement.php'; if ($ordRow) tickex_sales_capture_order((int)$ordRow['id']); } catch (Throwable $_measurementError) {}")
path.write_bytes(text.encode('utf-8'))
print('One optional context capture added; payment logic unchanged.')
