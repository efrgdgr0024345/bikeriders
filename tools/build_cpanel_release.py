"""Regenerate the fixed public release manifest after changing cPanel app files."""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = ("index.php", "style.css", "app.js")


def build(version="0.1.0"):
    return {
        "schema": 1,
        "version": version,
        "files": {name: hashlib.sha256((ROOT / "deploy/cpanel/app" / name).read_bytes()).hexdigest() for name in FILES},
        "loader_sha256": hashlib.sha256((ROOT / "loader.php").read_bytes()).hexdigest(),
    }


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", default="0.1.0")
    args = parser.parse_args()
    (ROOT / "deploy/cpanel/manifest.json").write_text(json.dumps(build(args.version), indent=2) + "\n")
