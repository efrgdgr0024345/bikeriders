from fastapi.testclient import TestClient

from bikeriders.api import create_app
from bikeriders.config import Settings


def test_upload_real_video_is_private(video, tmp_path):
    data = tmp_path / "data"
    with TestClient(create_app(Settings(data_dir=data))) as client:
        assert client.get("/health").json()["mode"] == "private-local"
        assert "Every great spot" in client.get("/").text
        with video.open("rb") as source:
            response = client.post("/api/prepare", files={"video": ("../../bad.mp4", source, "video/mp4")})
        assert response.status_code == 200, response.text
        result = response.json()
        assert (data / "private" / result["id"] / "manifest.json").exists()
        assert not list(data.glob("upload-*"))
        assert client.get(f'/private/{result["id"]}/still-01.jpg').status_code == 404
        assert response.headers["cache-control"] == "no-store"


def test_invalid_upload_cleans_up(tmp_path):
    data = tmp_path / "data"
    with TestClient(create_app(Settings(data_dir=data))) as client:
        response = client.post("/api/prepare", files={"video": ("bad.mp4", b"broken", "video/mp4")})
        assert response.status_code == 422
        assert not list(data.glob("upload-*"))


def test_upload_size_rejected(tmp_path):
    with TestClient(create_app(Settings(data_dir=tmp_path, max_upload_bytes=10))) as client:
        response = client.post("/api/prepare", files={"video": ("x.mp4", b"x" * 11)})
        assert response.status_code == 413
        assert not list(tmp_path.glob("upload-*"))


def test_cross_origin_and_untrusted_hosts_rejected(tmp_path):
    with TestClient(create_app(Settings(data_dir=tmp_path))) as client:
        response = client.post("/api/prepare", headers={"Origin": "https://untrusted.example"},
                               files={"video": ("x.mp4", b"x")})
        assert response.status_code == 403
        assert client.get("/health", headers={"Host": "untrusted.example"}).status_code == 400

