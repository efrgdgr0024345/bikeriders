"""Loopback-only preparation UI. No public media or identity endpoints."""
import tempfile
import threading
from pathlib import Path
from uuid import uuid4

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import HTMLResponse
from starlette.concurrency import run_in_threadpool
from starlette.middleware.trustedhost import TrustedHostMiddleware

from .config import Settings
from .detection import YoloDetector
from .frames import VideoError, extract


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings.from_env()
    app = FastAPI(title="Bike + Kit Spotter · Local preparation", docs_url="/docs")
    app.add_middleware(TrustedHostMiddleware, allowed_hosts=["127.0.0.1", "localhost", "testserver"])
    gate = threading.Lock()
    detector = YoloDetector(settings.yolo_weights) if settings.yolo_weights else None

    @app.middleware("http")
    async def private_headers(request, call_next):
        # Prevent browser cross-origin form posts to the local upload endpoint.
        origin = request.headers.get("origin")
        if request.method not in {"GET", "HEAD", "OPTIONS"} and origin:
            if origin != str(request.base_url).rstrip("/"):
                return HTMLResponse("Cross-origin uploads are disabled", status_code=403)
        response = await call_next(request)
        response.headers["Cache-Control"] = "no-store"
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["Content-Security-Policy"] = "default-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'"
        return response

    @app.get("/", response_class=HTMLResponse)
    def home():
        return (Path(__file__).parent / "templates" / "index.html").read_text()

    @app.get("/health")
    def health():
        return {"status": "ok", "mode": "private-local", "detection_enabled": detector is not None}

    @app.post("/api/prepare")
    async def prepare(video: UploadFile = File(...)):
        if not gate.acquire(blocking=False):
            await video.close()
            raise HTTPException(409, "Another video is processing. Try again when it finishes.")
        try:
            settings.data_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
            job_id = uuid4().hex
            output = settings.data_dir / "private" / job_id
            with tempfile.TemporaryDirectory(prefix="upload-", dir=settings.data_dir) as temporary:
                source = Path(temporary) / "source.video"
                size = 0
                with source.open("wb") as handle:
                    while chunk := await video.read(1024 * 1024):
                        size += len(chunk)
                        if size > settings.max_upload_bytes:
                            raise HTTPException(413, "Video exceeds the upload size limit")
                        handle.write(chunk)
                try:
                    manifest = await run_in_threadpool(extract, source, output, settings, detector)
                except VideoError as exc:
                    raise HTTPException(422, str(exc)) from exc
            return {"id": job_id, "manifest": manifest}
        finally:
            await video.close()
            gate.release()

    return app

