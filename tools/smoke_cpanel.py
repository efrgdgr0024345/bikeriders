"""Opt-in HTTP smoke test: downloads the published GitHub cPanel release.

Run with PHP on PATH (or PHP_BIN) and the project's dev dependencies installed.
Creates an isolated test website; never touches a real hosting account.
"""
import json
import hashlib
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
from pathlib import Path

import httpx

ROOT = Path(__file__).resolve().parents[1]


def main():
    php = os.getenv("PHP_BIN") or shutil.which("php")
    if not php:
        raise SystemExit("PHP CLI with cURL is required")
    with tempfile.TemporaryDirectory(prefix="bikeriders-hosting-") as directory:
        workspace = Path(directory)
        public, private = workspace / "public", workspace / "private"
        public.mkdir()
        # A harmless comment forces the live self-update/backup path to execute.
        candidate = (ROOT / "loader.php").read_text().replace("<?php\n", "<?php\n// Hosting smoke-test variant.\n", 1)
        (public / "loader.php").write_text(candidate)
        original_hash = hashlib.sha256((public / "loader.php").read_bytes()).hexdigest()
        (private / "sessions").mkdir(parents=True)
        environment = {name: value for name, value in os.environ.items() if name != "BIKERIDERS_SETUP_KEY"}
        environment["BIKERIDERS_PRIVATE_DIR"] = str(private)
        with socket.socket() as socket_handle:
            socket_handle.bind(("127.0.0.1", 0))
            port = socket_handle.getsockname()[1]
        with (workspace / "server.log").open("w") as log:
            process = subprocess.Popen([php, "-d", f"session.save_path={private / 'sessions'}", "-S", f"127.0.0.1:{port}", "-t", str(public)],
                                       env=environment, stdout=log, stderr=log)
            try:
                with httpx.Client(base_url=f"http://127.0.0.1:{port}", timeout=180, trust_env=False) as client:
                    for _ in range(100):
                        try:
                            response = client.get("/loader.php")
                            response.raise_for_status()
                            break
                        except httpx.ConnectError:
                            time.sleep(0.05)
                    else:
                        raise RuntimeError("PHP server did not become ready")
                    key = json.loads((public / "var/setup-token.php").read_text().split("\n", 1)[1])["setup_token"]
                    assert key not in response.text
                    csrf = re.search(r'name="csrf" value="([^"]+)"', response.text)[1]
                    assert client.post("/loader.php", data={"action": "login", "csrf": csrf, "key": "wrong"}).status_code == 403
                    response = client.post("/loader.php", data={"action": "login", "csrf": csrf, "key": key})
                    assert "Maintenance unlocked" in response.text
                    assert not (public / "var/setup-token.php").exists()
                    csrf = re.search(r'name="csrf" value="([^"]+)"', response.text)[1]
                    install = {"action": "install", "csrf": csrf, "confirm": "yes"}
                    index = public / "index.php"
                    index.write_text("KEEP EXISTING WEBSITE")
                    response = client.post("/loader.php", data=install)
                    assert "existing index.php" in response.text, response.text
                    assert index.read_text() == "KEEP EXISTING WEBSITE"
                    index.unlink()
                    response = client.post("/loader.php", data={**install, "live": "1"})
                    result = response.json()
                    assert result["error"] is None, result
                    assert "Installed " in result["message"], result
                    assert result["status"]["progress"]["status"] == "complete"
                    expected_loader = json.loads((ROOT / "deploy/cpanel/manifest.json").read_text())["loader_sha256"]
                    assert hashlib.sha256((public / "loader.php").read_bytes()).hexdigest() == expected_loader
                    assert hashlib.sha256((private / "backups" / f"loader-{original_hash}.php").read_bytes()).hexdigest() == original_hash
                    assert "Unlock maintenance" not in client.get("/loader.php").text
                    expected_version = json.loads((ROOT / "deploy/cpanel/manifest.json").read_text())["version"]
                    state = json.loads((private / "state.json").read_text())
                    assert state["current"].startswith(expected_version + "-"), state
                    assert "collection starts" in client.get("/index.php").text
                    assert client.get("/index.php?asset=style.css").headers["content-type"].startswith("text/css")
                    assert client.get("/index.php?asset=../state.json").status_code == 404
                    catalog = {"schema": 1, "sightings": [
                        {"approved": True, "bike": "<script>test</script>", "kit": "Navy kit", "setup_id": "S1"},
                        {"approved": False, "bike": "PRIVATE_RECORD"},
                    ]}
                    catalog_path = private / "data/catalog.json"
                    catalog_path.write_text(json.dumps(catalog))
                    response = client.post("/loader.php", data=install)
                    assert "Installed " in response.text, response.text
                    assert json.loads(catalog_path.read_text()) == catalog
                    html = client.get("/index.php").text
                    assert "&lt;script&gt;test&lt;/script&gt;" in html
                    assert "PRIVATE_RECORD" not in html
                    assert client.get("/state.json").status_code == 404
                    assert client.get("/data/catalog.json").status_code == 404
                    response = client.post("/loader.php", data={"action": "check", "csrf": csrf})
                    assert "Available version:" in response.text
                    print("Published GitHub downloads, authenticated install, existing-site preservation, repeat install, data preservation, asset routes, approval and escaping: passed")
            finally:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()


if __name__ == "__main__":
    main()
