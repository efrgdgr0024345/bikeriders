"""Bounded FFmpeg decoding, optional semantic orientation, and diverse selection.

Frames are private intermediate artifacts. They are never safe-to-publish images.
"""
import json
import math
import shutil
import subprocess
import tempfile
from dataclasses import asdict, dataclass
from pathlib import Path

import cv2
import numpy as np

from .config import Settings
from .detection import Detector, Presence


class VideoError(ValueError):
    pass


@dataclass
class Candidate:
    path: Path
    timestamp: float
    sharpness: float
    exposure: float
    rotation: int
    presence: Presence
    orientation_margin: float
    score: float
    fingerprint: np.ndarray


def _run(args: list[str], timeout: int) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(args, capture_output=True, text=True, timeout=timeout, check=True)
    except FileNotFoundError as exc:
        raise VideoError(f"Required executable unavailable: {args[0]}") from exc
    except subprocess.TimeoutExpired as exc:
        raise VideoError(f"{args[0]} exceeded its processing time limit") from exc
    except subprocess.CalledProcessError as exc:
        # Do not reflect arbitrary codec stderr into the public API.
        raise VideoError(f"{args[0]} could not read/process this video") from exc


def probe(path: Path, settings: Settings) -> dict:
    if not path.is_file():
        raise VideoError("Video file does not exist")
    if path.stat().st_size > settings.max_upload_bytes:
        raise VideoError("Video exceeds the upload size limit")
    result = _run([
        "ffprobe", "-v", "error", "-show_streams", "-show_format", "-of", "json", str(path.resolve())
    ], 20)
    try:
        metadata = json.loads(result.stdout)
        video = next(s for s in metadata["streams"] if s["codec_type"] == "video")
        duration = float(video.get("duration") or metadata["format"]["duration"])
        width, height = int(video["width"]), int(video["height"])
    except (KeyError, ValueError, StopIteration, TypeError) as exc:
        raise VideoError("Video must have a readable video track and known duration") from exc
    if not math.isfinite(duration) or not 0 < duration <= settings.max_duration:
        raise VideoError(f"Video duration must be between 0 and {settings.max_duration:g} seconds")
    if not 0 < width <= 7680 or not 0 < height <= 7680:
        raise VideoError("Unsupported video dimensions (maximum 7680 per side)")
    return {"duration": duration, "width": width, "height": height}


def rotate(image: np.ndarray, degrees: int) -> np.ndarray:
    """Clockwise rotation relative to FFmpeg's metadata-corrected image."""
    return np.ascontiguousarray(np.rot90(image, -(degrees // 90)))


def orient(image: np.ndarray, detector: Detector | None) -> tuple[np.ndarray, int, Presence, float]:
    if detector is None:
        return image, 0, Presence(), 0.0
    options = [(angle, detector.detect(rotate(image, angle))) for angle in (0, 90, 180, 270)]
    options.sort(key=lambda item: item[1].score, reverse=True)
    (angle, presence), (_, runner_up) = options[:2]
    margin = presence.score - runner_up.score
    # Ambiguous scores are not evidence of a rotation: trust container metadata.
    if margin < 0.05 or presence.score < 0.25:
        return image, 0, dict(options)[0], margin
    return rotate(image, angle), angle, presence, margin


def quality(image: np.ndarray) -> tuple[float, float, np.ndarray]:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    sharpness = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    exposure = float(np.mean((gray > 12) & (gray < 243)))
    # Used solely to remove visually duplicate frames, never for person matching.
    fingerprint = cv2.resize(gray, (32, 32)).astype(np.float32) / 255.0
    return sharpness, exposure, fingerprint


def choose(candidates: list[Candidate], count: int, interval: float) -> list[Candidate]:
    selected: list[Candidate] = []
    for candidate in sorted(candidates, key=lambda c: c.score, reverse=True):
        if any(abs(candidate.timestamp - other.timestamp) < interval * 1.5 for other in selected):
            continue
        if any(float(np.mean(np.abs(candidate.fingerprint - other.fingerprint))) < 0.025 for other in selected):
            continue
        selected.append(candidate)
        if len(selected) >= count:
            break
    return sorted(selected, key=lambda c: c.timestamp)


def extract(video: Path, output: Path, settings: Settings, detector: Detector | None = None) -> dict:
    metadata = probe(video, settings)
    if output.exists():
        raise VideoError("Output directory already exists; choose a new directory")
    output.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    warnings = ["Private source frames may contain faces. Do not publish them."]
    if detector is None:
        warnings.append("Detection disabled: bike/rider presence and semantic orientation are unverified; only container rotation metadata is applied.")
    with tempfile.TemporaryDirectory(prefix="bikeriders-", dir=output.parent) as scratch:
        scratch_path = Path(scratch)
        # FFmpeg automatically applies display-matrix / rotate metadata, once.
        # Start at t=0 and sample exact input instants so recorded timestamps are meaningful.
        fps = 1 / settings.sample_interval
        scale = f"scale='min({settings.max_dimension},iw)':'min({settings.max_dimension},ih)':force_original_aspect_ratio=decrease"
        _run([
            "ffmpeg", "-nostdin", "-v", "error", "-threads", "2", "-filter_threads", "2", "-i", str(video.resolve()),
            "-map", "0:v:0", "-t", str(settings.max_duration), "-an",
            "-vf", f"fps={fps}:round=up,{scale}", "-threads", "2", "-q:v", "2",
            "-frames:v", str(math.ceil(settings.max_duration * fps)), str(scratch_path / "%06d.jpg")
        ], 180)
        candidates = []
        sampled = 0
        for index, path in enumerate(sorted(scratch_path.glob("*.jpg"))):
            sampled += 1
            image = cv2.imread(str(path))
            if image is None:
                continue
            image, rotation, presence, margin = orient(image, detector)
            sharpness, exposure, fingerprint = quality(image)
            if detector is not None and presence.score < 0.25:
                continue
            score = math.log1p(sharpness) * exposure + 3 * presence.score
            if not cv2.imwrite(str(path), image):
                raise VideoError("Could not write oriented frame")
            candidates.append(Candidate(path, index * settings.sample_interval, sharpness, exposure,
                                        rotation, presence, margin, score, fingerprint))
        if not candidates:
            raise VideoError("No usable frames found" if detector is None else "No frames with both bicycle and rider detected")
        selected = choose(candidates, settings.max_stills, settings.sample_interval)
        if detector is not None and any(c.orientation_margin < 0.05 for c in selected):
            warnings.append("Orientation is ambiguous for at least one still; review locally.")
        if any(c.sharpness < 30 or c.exposure < 0.4 for c in selected):
            warnings.append("Source quality is low; selected stills may be blurry or poorly exposed.")
        manifest = {"schema_version": 1, "privacy": "private", "video": metadata,
                    "detection_enabled": detector is not None, "sampled_frames": sampled,
                    "eligible_frames": len(candidates), "warnings": warnings, "stills": []}
        output.mkdir(mode=0o700)
        try:
            for index, candidate in enumerate(selected, 1):
                name = f"still-{index:02d}.jpg"
                shutil.copyfile(candidate.path, output / name)
                (output / name).chmod(0o600)
                manifest["stills"].append({"file": name, "timestamp_seconds": candidate.timestamp,
                    "additional_rotation_clockwise": candidate.rotation, "sharpness": candidate.sharpness,
                    "exposure": candidate.exposure, "presence": asdict(candidate.presence),
                    "orientation_margin": candidate.orientation_margin, "score": candidate.score})
            (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
            (output / "manifest.json").chmod(0o600)
        except Exception:
            shutil.rmtree(output)
            raise
    return manifest
