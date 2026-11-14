import json
import subprocess
from dataclasses import replace

import cv2
import numpy as np
import pytest

from bikeriders.config import Settings
from bikeriders.detection import Presence
from bikeriders.frames import VideoError, extract, orient, probe, quality, rotate


def test_extract_real_video(video, tmp_path):
    output = tmp_path / "result"
    result = extract(video, output, Settings())
    assert result["sampled_frames"] == 5
    assert 1 <= len(result["stills"]) <= 3
    assert result["privacy"] == "private"
    assert not result["detection_enabled"]
    assert any("unverified" in warning for warning in result["warnings"])
    assert json.loads((output / "manifest.json").read_text()) == result
    for still in result["stills"]:
        image = cv2.imread(str(output / still["file"]))
        assert image.shape[:2] == (192, 320)
        assert 0 <= still["timestamp_seconds"] < 2


def test_metadata_rotation_applied_once(video, tmp_path):
    rotated = tmp_path / "rotated.mp4"
    subprocess.run(["ffmpeg", "-nostdin", "-v", "error", "-display_rotation:v:0", "90",
                    "-i", str(video), "-c", "copy", str(rotated)], check=True)
    metadata = subprocess.run(["ffprobe", "-v", "error", "-show_streams", "-of", "json", str(rotated)],
                              check=True, capture_output=True, text=True)
    assert json.loads(metadata.stdout)["streams"][0]["side_data_list"][0]["rotation"] == 90
    output = tmp_path / "result"
    manifest = extract(rotated, output, Settings())
    image = cv2.imread(str(output / manifest["stills"][0]["file"]))
    assert image.shape[:2] == (320, 192)
    assert manifest["stills"][0]["additional_rotation_clockwise"] == 0


@pytest.mark.parametrize("angle", [0, 90, 180, 270])
def test_semantic_orientation_all_rotations(angle):
    upright = np.zeros((40, 60, 3), dtype=np.uint8)
    upright[:10, :10] = 255

    class MarkerDetector:
        def detect(self, image):
            good = image.shape == upright.shape and np.array_equal(image, upright)
            return Presence(0.9, 0.85) if good else Presence(0.1, 0.1)

    image, correction, presence, margin = orient(rotate(upright, angle), MarkerDetector())
    assert np.array_equal(image, upright)
    assert correction == (360 - angle) % 360
    assert presence.score == 0.85
    assert margin > 0.05


def test_ambiguous_orientation_trusts_metadata():
    class AmbiguousDetector:
        def detect(self, image):
            return Presence(0.8, 0.8)

    image = np.zeros((40, 60, 3), dtype=np.uint8)
    result, angle, _, margin = orient(image, AmbiguousDetector())
    assert np.array_equal(result, image)
    assert angle == 0
    assert margin == 0


def test_no_detections_produces_no_output(video, tmp_path):
    class EmptyDetector:
        def detect(self, image):
            return Presence()

    output = tmp_path / "result"
    with pytest.raises(VideoError, match="both bicycle and rider"):
        extract(video, output, Settings(), EmptyDetector())
    assert not output.exists()


def test_bad_video_and_duration_rejected(video, tmp_path):
    bad = tmp_path / "bad.mp4"
    bad.write_text("not a video")
    with pytest.raises(VideoError):
        probe(bad, Settings())
    with pytest.raises(VideoError, match="duration"):
        probe(video, replace(Settings(), max_duration=1))
    with pytest.raises(VideoError, match="size"):
        probe(video, replace(Settings(), max_upload_bytes=1))


def test_existing_output_preserved(video, tmp_path):
    output = tmp_path / "existing"
    output.mkdir()
    marker = output / "keep.txt"
    marker.write_text("keep")
    with pytest.raises(VideoError, match="already exists"):
        extract(video, output, Settings())
    assert marker.read_text() == "keep"


def test_blur_scores_lower():
    rng = np.random.default_rng(42)
    image = rng.integers(0, 256, (120, 120, 3), dtype=np.uint8)
    assert quality(image)[0] > quality(cv2.GaussianBlur(image, (15, 15), 5))[0]


def test_identical_video_returns_one_still(tmp_path):
    video = tmp_path / "static.mp4"
    subprocess.run(["ffmpeg", "-nostdin", "-v", "error", "-f", "lavfi", "-i",
                    "color=c=green:size=160x120:rate=10:duration=2", "-c:v", "mpeg4",
                    "-threads", "1", str(video)], check=True)
    manifest = extract(video, tmp_path / "result", Settings())
    assert len(manifest["stills"]) == 1

