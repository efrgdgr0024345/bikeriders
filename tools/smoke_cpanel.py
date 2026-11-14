"""Opt-in HTTP smoke test: downloads the published GitHub cPanel release.

Run with PHP on PATH (or PHP_BIN) and the project's dev dependencies installed.
Creates an isolated test website; never touches a real hosting account.
"""
import json
import os
import secrets
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
        shutil.copyfile(ROOT / "loader.php", public / "loader.php")
        key = secrets.token_hex(32)
        environment = {**os.environ, "BIKERIDERS_SETUP_KEY": key, "BIKERIDERS_PRIVATE_DIR": str(private)}
        with socket.socket() as socket_handle:
            socket_handle.bind(("127.0.0.1", 0))
            port = socket_handle.getsockname()[1]
        with (workspace / "server.log").open("w") as log:
            process = subprocess.Popen([php, "-S", f"127.0.0.1:{port}", "-t", str(public)],
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
                    assert key not in response.text
                    assert client.post("/loader.php", data={"action": "install", "key": "wrong"}).status_code == 403
                    index = public / "index.php"
                    index.write_text("KEEP EXISTING WEBSITE")
                    response = client.post("/loader.php", data={"action": "install", "key": key})
                    assert "existing index.php" in response.text, response.text
                    assert index.read_text() == "KEEP EXISTING WEBSITE"
                    index.unlink()
                    response = client.post("/loader.php", data={"action": "install", "key": key})
                    assert "Installed " in response.text, response.text
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
                    response = client.post("/loader.php", data={"action": "install", "key": key})
                    assert "Installed " in response.text, response.text
                    assert json.loads(catalog_path.read_text()) == catalog
                    html = client.get("/index.php").text
                    assert "&lt;script&gt;test&lt;/script&gt;" in html
                    assert "PRIVATE_RECORD" not in html
                    assert client.get("/state.json").status_code == 404
                    assert client.get("/data/catalog.json").status_code == 404
                    response = client.post("/loader.php", data={"action": "check", "key": key})
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
