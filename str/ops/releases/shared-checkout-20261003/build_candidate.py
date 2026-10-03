"""Reproduce the selective production candidate from exact baseline files.

Usage: python3 build_candidate.py BASELINE_STR OUTPUT_STR
Baseline must match manifest hashes. No fuzzy patching or copying a branch's
entire checkout over production. No database files are read or modified.
"""
from pathlib import Path
import hashlib,json,subprocess,sys
here=Path(__file__).resolve().parent
repo=here.parents[3]
base=Path(sys.argv[1]).resolve(); output=Path(sys.argv[2]).resolve()
assert base!=output,'baseline/output must be separate directories'
manifest=json.loads((here/'manifest.json').read_text())
for item in manifest['files']:
    relative=Path(item['path']).relative_to('str')
    target=output/relative; target.parent.mkdir(parents=True,exist_ok=True)
    if item['base_sha256']:
        original=(base/relative).read_bytes()
        assert hashlib.sha256(original).hexdigest()==item['base_sha256'], 'baseline drift: '+str(relative)
        target.write_bytes(original)
        subprocess.run(['patch','--binary','--fuzz=0',str(target),str(here/item['patch'])],check=True)
    else:
        target.write_bytes((repo/item['source']).read_bytes())
    assert hashlib.sha256(target.read_bytes()).hexdigest()==item['result_sha256'],'candidate mismatch: '+str(relative)
print('candidate hashes match manifest')
