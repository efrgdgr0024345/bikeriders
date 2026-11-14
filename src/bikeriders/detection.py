"""Presence detection only; no face, body-shape or person identity features."""
from dataclasses import dataclass
from pathlib import Path
from typing import Protocol

import numpy as np


@dataclass(frozen=True)
class Presence:
    bike: float = 0.0
    rider: float = 0.0

    @property
    def score(self) -> float:
        # Require both, rather than letting a pedestrian dominate the score.
        return min(self.bike, self.rider)


class Detector(Protocol):
    def detect(self, image: np.ndarray) -> Presence: ...


class YoloDetector:
    def __init__(self, weights: Path):
        if not weights.is_file():
            raise ValueError(f"YOLO checkpoint does not exist: {weights}")
        if weights.suffix.lower() != ".pt":
            raise ValueError("Use a trusted local YOLO .pt checkpoint")
        try:
            from ultralytics import YOLO
        except ImportError as exc:
            raise ValueError("Install the vision extra: pip install -e '.[vision]'") from exc
        self.model = YOLO(str(weights))

    def detect(self, image: np.ndarray) -> Presence:
        result = self.model.predict(image, device="cpu", imgsz=640, verbose=False)[0]
        bike, rider = 0.0, 0.0
        for box in result.boxes:
            label = self.model.names[int(box.cls.item())]
            confidence = float(box.conf.item())
            if label == "bicycle":
                bike = max(bike, confidence)
            elif label == "person":
                rider = max(rider, confidence)
        return Presence(bike, rider)

