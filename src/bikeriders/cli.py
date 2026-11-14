import argparse
import json
from dataclasses import replace
from pathlib import Path
from uuid import uuid4

from .config import Settings
from .detection import YoloDetector
from .frames import VideoError, extract


def main():
    parser = argparse.ArgumentParser(description="Private, local bike video preparation")
    commands = parser.add_subparsers(dest="command", required=True)
    ingest = commands.add_parser("ingest", help="Select 1–4 private stills from a short video")
    ingest.add_argument("video", type=Path)
    ingest.add_argument("--output", type=Path)
    ingest.add_argument("--stills", type=int, default=3)
    ingest.add_argument("--interval", type=float, default=0.4)
    ingest.add_argument("--yolo-weights", type=Path, help="Optional trusted, local YOLO checkpoint")
    serve = commands.add_parser("serve", help="Start the private laptop upload interface")
    serve.add_argument("--port", type=int, default=8000)
    args = parser.parse_args()
    if args.command == "serve":
        import uvicorn
        uvicorn.run("bikeriders.api:create_app", factory=True, host="127.0.0.1", port=args.port)
        return
    try:
        settings = replace(Settings.from_env(), max_stills=args.stills, sample_interval=args.interval)
        weights = args.yolo_weights or settings.yolo_weights
        detector = YoloDetector(weights) if weights else None
        output = args.output or settings.data_dir / "private" / uuid4().hex
        manifest = extract(args.video, output, settings, detector)
        print(json.dumps({"output": str(output.resolve()), **manifest}, indent=2))
    except (ValueError, VideoError) as exc:
        parser.exit(1, f"Error: {exc}\n")


if __name__ == "__main__":
    main()

