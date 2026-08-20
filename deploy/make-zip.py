"""
Pack the deployable files into store-shift-deploy.zip.

Written in Python rather than PowerShell for one reason: Compress-Archive writes
BACKSLASHES into zip entry names on Windows. Linux does not treat those as path
separators, so unzipping on the server produces files literally called
"assets\\index-abc.js" sitting in the top directory — and every /app/assets/*
request 404s while the zip looks perfectly fine locally.

zipfile always writes forward slashes, which is what the format actually says.

What goes in (matches DEPLOYMENT.md section 1):

    index.html  site.css  favicon.*  icons.svg     landing page
    assets/                                        landing page screenshots/logo
    privacy/ terms/ refund/ contact/ developers/   public pages
    app/index.php  app/.htaccess                   front controller
    app/assets/*                                   built JS/CSS
    app/backend-htaccess.txt                       rename to backend/.htaccess

The Laravel app itself is NOT in here — it is uploaded separately (and mostly
does not change between releases). See DEPLOYMENT.md section 9 for which files a
given change actually needs.
"""

from __future__ import annotations

import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "store-shift-deploy.zip"

# (path relative to ROOT, required?)
FILES = [
    ("index.html", True),
    ("site.css", True),
    ("app/index.php", True),
    ("app/.htaccess", False),
    ("app/backend-htaccess.txt", True),
    ("app/favicon.svg", False),
    ("app/favicon.ico", False),
    ("app/icons.svg", False),
    ("app/robots.txt", False),
    ("favicon.svg", False),
    ("favicon.ico", False),
    ("icons.svg", False),
]

DIRS = [
    ("app/assets", True),
    ("assets", True),
    ("privacy", False),
    ("terms", False),
    ("refund", False),
    ("contact", False),
    ("developers", False),
]


def main() -> int:
    missing: list[str] = []
    written = 0

    if OUT.exists():
        OUT.unlink()

    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as zf:
        for rel, required in FILES:
            path = ROOT / rel
            if not path.is_file():
                if required:
                    missing.append(rel)
                continue
            # as_posix() is the whole point of this script.
            zf.write(path, Path(rel).as_posix())
            written += 1

        for rel, required in DIRS:
            directory = ROOT / rel
            if not directory.is_dir():
                if required:
                    missing.append(rel + "/")
                continue

            found = False
            for path in sorted(directory.rglob("*")):
                if path.is_file():
                    zf.write(path, path.relative_to(ROOT).as_posix())
                    written += 1
                    found = True

            if required and not found:
                missing.append(rel + "/ (empty)")

    if missing:
        OUT.unlink(missing_ok=True)
        print("ZIP FAILED - required files missing:", file=sys.stderr)
        for name in missing:
            print(f"  {name}", file=sys.stderr)
        return 1

    size_kb = OUT.stat().st_size / 1024
    print(f"Wrote {OUT.name}: {written} files, {size_kb:.0f} KB")

    # Cheap guard against the exact bug this script exists to prevent.
    with zipfile.ZipFile(OUT) as zf:
        bad = [n for n in zf.namelist() if "\\" in n]
        if bad:
            print(f"ZIP FAILED - backslashes in entries: {bad[:3]}", file=sys.stderr)
            return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
