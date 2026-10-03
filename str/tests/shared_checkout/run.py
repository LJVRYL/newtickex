"""Exercise real checkout forms/session locks/SQLite with synthetic transports.

Run: python3 str/tests/shared_checkout/run.py
Requires PHP CLI with PDO SQLite and Python 3.6+. No production data/network.
"""
from pathlib import Path
import shutil,subprocess,sys,tempfile
here=Path(__file__).resolve().parent
source=here.parent.parent
with tempfile.TemporaryDirectory(prefix='tickex-shared-checkout-') as temp:
    root=Path(temp); fixture=root/'fixture'; fixture.mkdir()
    shutil.copytree(source/'inc',fixture/'inc')
    shutil.copy2(source/'checkout_totalcoin.php',fixture/'checkout_totalcoin.php')
    shutil.copy2(here/'fixture_transport.php',fixture/'index.php')
    shutil.copy2(here/'fixture_setup.php',fixture/'setup.php')
    shutil.copy2(here/'http_fixture_test.py',root/'http_fixture_test.py')
    shutil.copy2(here/'cgi_fixture_server.py',root/'cgi_fixture_server.py')
    subprocess.run([sys.executable,str(root/'http_fixture_test.py')],check=True)
