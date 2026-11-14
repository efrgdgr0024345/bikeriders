import subprocess

import pytest


@pytest.fixture
def video(tmp_path):
    path = tmp_path / "sample.mp4"
    subprocess.run([
        "ffmpeg", "-nostdin", "-v", "error", "-f", "lavfi", "-i",
        "testsrc2=size=320x192:rate=10:duration=2", "-c:v", "mpeg4", "-threads", "1", str(path)
    ], check=True)
    return path

