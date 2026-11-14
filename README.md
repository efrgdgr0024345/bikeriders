# bikeriders
The Wheelers’ Appreciation Team Spotters

Bike + Kit Spotter: trainspotting for cyclists, built to run on a normal laptop.
No paid Linux server, cloud account, GPU, or API subscription is required.

## GitHub + cPanel

`loader.php` is a standalone installer/updater for the PHP public-site foundation.
It follows the maintenance workflow used by `efrgdgr0024345/map`: upload one file,
open it, read the generated `var/setup-token.php` through cPanel File Manager, and
unlock setup. No source editing is needed. It checks hosting support, shows progress,
downloads verified app files, and updates itself with a verified backup. Normal updates
reuse unchanged app files and preserve the catalogue and maintenance credentials.

Full instructions: [cPanel install and updates](deploy/cpanel/README.md).
Python video processing stays on your laptop. The cPanel site starts empty; automatic
sync, images, matching, histories and value/rarity statistics are not implemented yet.

## Implemented: milestone 1

- Python package, CLI, FastAPI service, and responsive local upload interface.
- FFmpeg video validation and sampling every 0.4 seconds (configurable).
- Phone rotation metadata applied by FFmpeg, once.
- Sharpness / exposure scoring, temporal spacing, and near-duplicate suppression.
- 1–3 selected JPEG stills by default, plus a JSON manifest of scores and warnings.
- Optional CPU YOLO adapter tests 0/90/180/270° for bike + rider presence.
- Private outputs, bounded video size/duration, temporary upload deletion, no media-serving endpoint.

This is a working video-preparation foundation, **not the finished public spotting system**.
Without YOLO, selection is based on image quality and container rotation metadata;
it cannot establish whether a cyclist is present or infer an untagged upside-down scene.
Semantic orientation uses a confidence margin and falls back to metadata when ambiguous.
Real-world detection accuracy has not yet been measured on cyclist footage.

## Run locally

Prerequisites: Python 3.11+ and FFmpeg / ffprobe on PATH. Integration tests require
FFmpeg 6+ (`-display_rotation`); this environment was tested with Python 3.12 and FFmpeg 7.1.
Install FFmpeg using your operating system's package manager (e.g. `brew install ffmpeg`
on macOS or `sudo apt-get install ffmpeg` on Debian/Ubuntu).

```sh
cd /path/to/bikeriders
python3 -m venv .venv
.venv/bin/python -m pip install -r requirements-dev.lock
.venv/bin/python -m pip install --no-deps -e .
.venv/bin/bikeriders serve
```

Open `http://127.0.0.1:8000` on your own laptop. The server binds only to loopback.
On Windows, use `.venv\Scripts\python.exe` and `.venv\Scripts\bikeriders.exe`.
The lock records the tested base/dev dependencies; the optional model stack is separate.

Or process a video directly:

```sh
.venv/bin/bikeriders ingest /path/to/ride.mp4 --output .data/private/ride-001
```

`--stills 1..4` and `--interval 0.4` control selection. Existing output directories
are never overwritten. Identical footage may produce just one still. Clips longer
than 120 seconds or larger than 250 MiB are rejected rather than silently truncated.
Sample timestamps are positions on the sampling grid, not source frame identifiers.

### Optional bike/rider detection and semantic orientation

```sh
# For a low-cost CPU installation, install the official CPU PyTorch wheels first:
.venv/bin/python -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu
.venv/bin/python -m pip install -e '.[vision]'
.venv/bin/bikeriders ingest /path/to/ride.mp4 --yolo-weights /path/to/yolo11n.pt
```

Obtain a trusted YOLOv8n or YOLO11n COCO checkpoint from Ultralytics separately.
Check the model/library licence before public distribution. Only load trusted checkpoints;
model files are executable artifacts. No model is downloaded automatically by this project.
For the web interface set `BIKERIDERS_YOLO_WEIGHTS` to that absolute checkpoint path
before starting. `BIKERIDERS_DATA_DIR` optionally changes the storage location.

Detection is deliberately optional so basic preparation remains inexpensive. It runs on
CPU and can be slower because it evaluates four rotations per candidate. It uses bicycle
and person class confidence only, with no face recognition, body-shape features or
person identity model. Multiple riders in the same frame are not paired yet; this is
not an identity/matching pipeline. The optional YOLO installation and real-model inference
have not been validated in the current environment.

## Privacy boundary

Source videos and selected frames can contain faces and other identifying content.
They are **private working inputs**, not publication-ready thumbnails. No face or
body-shape analysis is performed. The UI does not display or expose source media.
The web upload is deleted after processing; CLI source files remain where you put them.
Selected stills and manifests remain under `.data/private/` until you remove them.
Store this directory privately; do not put it on a public static host or commit it.

This service is for trusted local use, not Internet deployment. Future public views
must use separately generated and reviewed bike/kit-only assets, never these raw stills.
Overall-scene or full-person embeddings would capture more than bike and kit: future
matching must instead use isolated bike and garment pixels, with background/head/skin
excluded. Neither silhouette geometry nor person tracking belongs in that identity model.

## Validation

```sh
.venv/bin/python -m pytest -q
.venv/bin/python -m pip check
```

Tests create videos with FFmpeg and exercise decoding, rotation metadata, selection,
duplicate removal, quality ranking, invalid inputs, upload cleanup, private-file routing,
and cross-origin/host restrictions. Four-way semantic orientation uses a deterministic
test detector; passing these tests does not establish real YOLO accuracy.

## Next milestones

1. Validate YOLO on consented cyclist clips; bike/garment-only extraction and publication review.
2. Multi-embedding features with CLIP, colour features, and a small labelled matching benchmark.
3. SQLite sightings/setups, conservative confidence thresholds, and manual merge/split corrections.
4. Times seen / first-last seen / rarity statistics, with explicit unknown value estimates.
5. Public browse/search/history UI backed only by approved assets; watch-folder ingestion.
6. Consider Supabase/pgvector and static hosting only when local measurements justify them.

Keep ingestion and inference on the laptop. A future public database can receive approved
records without receiving source video. Free-tier hosting may be enough; a paid server
is a decision to revisit after measuring volume and latency, not a prerequisite.
