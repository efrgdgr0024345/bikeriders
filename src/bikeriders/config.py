import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Settings:
    data_dir: Path = Path(".data")
    sample_interval: float = 0.4
    max_duration: float = 120.0
    max_upload_bytes: int = 250 * 1024 * 1024
    max_dimension: int = 1280
    max_stills: int = 3
    # Use a local checkpoint only: model download must be an explicit action.
    yolo_weights: Path | None = None

    def __post_init__(self):
        if not 0.1 <= self.sample_interval <= 10:
            raise ValueError("sample_interval must be between 0.1 and 10 seconds")
        if not 1 <= self.max_stills <= 4:
            raise ValueError("max_stills must be between 1 and 4")
        if not 1 <= self.max_duration <= 600:
            raise ValueError("max_duration must be between 1 and 600 seconds")
        if not 64 <= self.max_dimension <= 2048:
            raise ValueError("max_dimension must be between 64 and 2048")
        if self.max_upload_bytes <= 0:
            raise ValueError("max_upload_bytes must be positive")

    @classmethod
    def from_env(cls):
        weights = os.getenv("BIKERIDERS_YOLO_WEIGHTS")
        return cls(
            data_dir=Path(os.getenv("BIKERIDERS_DATA_DIR", ".data")).resolve(),
            yolo_weights=Path(weights).resolve() if weights else None,
        )

