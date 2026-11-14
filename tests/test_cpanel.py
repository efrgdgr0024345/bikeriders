import hashlib
import json
import os
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PHP = os.getenv("PHP_BIN") or shutil.which("php")


def test_release_manifest_matches_files():
    manifest = json.loads((ROOT / "deploy/cpanel/manifest.json").read_text())
    assert manifest["schema"] == 1
    assert set(manifest["files"]) == {"index.php", "style.css", "app.js"}
    for name, checksum in manifest["files"].items():
        assert hashlib.sha256((ROOT / "deploy/cpanel/app" / name).read_bytes()).hexdigest() == checksum


@pytest.mark.skipif(not PHP, reason="PHP CLI is required for installer integration tests")
def test_php_installer_lifecycle(tmp_path):
    for file in (ROOT / "loader.php", ROOT / "deploy/cpanel/app/index.php"):
        subprocess.run([PHP, "-l", str(file)], check=True, capture_output=True, text=True)
    result = subprocess.run([PHP, str(ROOT / "tests/php/loader_test.php"), str(tmp_path / "private")],
                            check=True, capture_output=True, text=True)
    assert "passed" in result.stdout


@pytest.mark.skipif(not PHP, reason="PHP CLI is required for public catalogue rendering")
def test_public_catalogue_approval_and_escaping(tmp_path):
    private = tmp_path / "private"
    (private / "data").mkdir(parents=True)
    catalog = {"schema": 1, "sightings": [
        {"approved": True, "bike": "<script>alert(1)</script>", "kit": "Red jersey", "setup_id": "S1"},
        {"approved": False, "bike": "PRIVATE_RECORD"},
        {"bike": "UNREVIEWED_RECORD"},
    ]}
    (private / "data/catalog.json").write_text(json.dumps(catalog))
    # Paths are passed as argv; never interpolate paths into PHP source.
    result = subprocess.run([PHP, "-r", "$brPrivate=$argv[1]; require $argv[2];", str(private),
                             str(ROOT / "deploy/cpanel/app/index.php")],
                            check=True, capture_output=True, text=True)
    assert "&lt;script&gt;alert(1)&lt;/script&gt;" in result.stdout
    assert "PRIVATE_RECORD" not in result.stdout
    assert "UNREVIEWED_RECORD" not in result.stdout
