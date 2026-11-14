"""Exercise the single-file maintenance console without any network downloads."""
import json
import os
import re
import secrets
import shutil
import socket
import subprocess
import time
from pathlib import Path

import httpx
import pytest

ROOT = Path(__file__).resolve().parents[1]
PHP = os.getenv("PHP_BIN") or shutil.which("php")
pytestmark = pytest.mark.skipif(not PHP, reason="PHP CLI with sessions is required")


def csrf(html):
    return re.search(r'name="csrf" value="([^"]+)"', html)[1]


@pytest.fixture(params=["generated", "legacy", "environment"])
def site(tmp_path, request):
    public = tmp_path / "public"
    private = tmp_path / "private"
    public.mkdir()
    (private / "sessions").mkdir(parents=True)
    code = (ROOT / "loader.php").read_text()
    key = secrets.token_hex(32)
    if request.param == "legacy":
        code = code.replace("$setupKey = '';", "$setupKey = '" + key + "';")
    (public / "loader.php").write_text(code)
    environment = {k: v for k, v in os.environ.items() if k != "BIKERIDERS_SETUP_KEY"}
    environment["BIKERIDERS_PRIVATE_DIR"] = str(private)
    if request.param == "environment":
        environment["BIKERIDERS_SETUP_KEY"] = key
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    with (tmp_path / "server.log").open("w") as log:
        process = subprocess.Popen([PHP, "-d", f"session.save_path={private / 'sessions'}", "-S",
                                    f"127.0.0.1:{port}", "-t", str(public)],
                                   env=environment, stdout=log, stderr=log)
        try:
            with httpx.Client(base_url=f"http://127.0.0.1:{port}", trust_env=False, timeout=10) as client:
                for _ in range(100):
                    try:
                        response = client.get("/loader.php")
                        response.raise_for_status()
                        break
                    except httpx.ConnectError:
                        time.sleep(0.05)
                else:
                    raise RuntimeError("PHP test server did not start")
                if request.param == "generated":
                    guarded = (public / "var/setup-token.php").read_text()
                    key = json.loads(guarded.split("\n", 1)[1])["setup_token"]
                yield client, key, public, private, request.param
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()


def test_maintenance_authentication_and_preservation(site):
    client, key, public, private, mode = site
    html = client.get("/loader.php").text
    assert "Unlock maintenance" in html
    assert key not in html
    assert client.get("/loader.php?action=status").status_code == 403
    if mode == "generated":
        assert "var/setup-token.php" in html
        assert client.get("/var/setup-token.php").status_code == 404
    assert client.post("/loader.php", data={"csrf": "wrong", "action": "login", "key": key}).status_code == 403
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "install", "confirm": "yes"})
    assert response.status_code == 403
    assert not (public / "index.php").exists()
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "login", "key": "wrong"})
    assert response.status_code == 403
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "login", "key": key})
    assert response.status_code == 200
    html = response.text
    assert "Maintenance unlocked" in html
    assert "Install & set up" in html
    assert not (public / "var/setup-token.php").exists()
    assert client.get("/var/maintenance.php").status_code == 404
    assert key not in (public / "var/maintenance.php").read_text()
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "install"})
    assert "Confirm installation" in response.text
    assert not (public / "index.php").exists()
    assert client.get("/loader.php?action=status").json()["running"] is False

    # Replacing the loader with its distributed blank-key copy must retain login.
    (public / "loader.php").write_bytes((ROOT / "loader.php").read_bytes())
    html = client.get("/loader.php").text
    assert "Maintain your public site" in html or "Install & set up" in html
    assert "Unlock maintenance" not in html
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "logout"})
    html = response.text
    assert "Unlock maintenance" in html
    assert client.get("/loader.php?action=status").status_code == 403
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "login", "key": key})
    assert "Maintenance unlocked" in response.text


def test_key_rotation_and_session_invalidation(site):
    client, key, public, private, mode = site
    html = client.get("/loader.php").text
    html = client.post("/loader.php", data={"csrf": csrf(html), "action": "login", "key": key}).text
    new_key = secrets.token_hex(32)
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "rotate", "new_key": new_key})
    html = response.text
    if mode == "environment":
        assert "hosting settings" in html
        return
    assert "Maintenance key changed" in html
    html = client.post("/loader.php", data={"csrf": csrf(html), "action": "logout"}).text
    response = client.post("/loader.php", data={"csrf": csrf(html), "action": "login", "key": key})
    assert response.status_code == 403
    html = client.post("/loader.php", data={"csrf": csrf(html), "action": "login", "key": new_key}).text
    assert "Maintenance unlocked" in html
    # A key changed through cPanel invalidates an already logged-in browser.
    (public / "loader.php").write_bytes((ROOT / "loader.php").read_bytes())
    (public / "var/maintenance.php").unlink()
    assert client.get("/loader.php?action=status").status_code == 403
    assert (public / "var/setup-token.php").exists()
