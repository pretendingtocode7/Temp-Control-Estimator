"""Build a WordPress plugin ZIP using an explicit runtime-file allowlist."""
import argparse
import hashlib
import json
import re
import shutil
import zipfile
from pathlib import Path

SLUG = "temp-control-estimate-builder"
ROOT_FILES = [SLUG + ".php", "uninstall.php", "readme.txt"]
DIRECTORIES = ["admin", "includes", "endpoints", "public", "assets",
               "seed-templates", "app/dist", "vendor"]
EXTENSIONS = {".php", ".js", ".css", ".json", ".jpg", ".jpeg", ".png",
              ".gif", ".svg", ".webp", ".ico", ".woff", ".woff2", ".ttf",
              ".mustache", ".html", ".txt", ".md"}
REQUIRED = [SLUG + ".php", "includes/class-plugin.php",
            "app/dist/estimate-builder.js", "app/dist/estimate-builder.css"]

def build(source, output):
    source, output = Path(source).resolve(), Path(output).resolve()
    if output == source or source in output.parents or output in source.parents:
        raise ValueError("Output must be outside the source directory.")
    for name in REQUIRED:
        path = source / name
        if not path.is_file() or path.stat().st_size == 0:
            raise ValueError("Required file missing or empty: " + name)
    entry = (source / ROOT_FILES[0]).read_text()
    header = re.search(r"^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$", entry, re.M)
    constant = re.search(r"""define\(\s*['"]TC_ESTIMATE_VERSION['"]\s*,\s*['"]([^'"]+)['"]""", entry)
    if not header or not constant or header[1] != constant[1]:
        raise ValueError("Plugin header and constant versions disagree or are invalid.")
    files = [source / name for name in ROOT_FILES if (source / name).exists()]
    for directory in DIRECTORIES:
        folder = source / directory
        if folder.is_symlink():
            raise ValueError("Symlinks are not allowed: " + directory)
        if folder.is_dir():
            files.extend(folder.rglob("*"))
    selected = []
    for path in sorted(set(files)):
        rel = path.relative_to(source)
        if path.is_symlink():
            raise ValueError("Symlinks are not allowed: " + str(rel))
        if not path.is_file():
            continue
        if any(part.startswith(".") or part in ("node_modules", "__pycache__", "tests") for part in rel.parts):
            continue
        if path.suffix.lower() not in EXTENSIONS:
            continue
        selected.append((path, rel))
    output.mkdir(parents=True, exist_ok=True)
    stage = output / SLUG
    if stage.exists():
        shutil.rmtree(stage)
    stage.mkdir()
    archive_path = output / (SLUG + ".zip")
    with zipfile.ZipFile(archive_path, "w", zipfile.ZIP_DEFLATED) as archive:
        for path, rel in selected:
            target = stage / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(path, target)
            archive.write(target, str(Path(SLUG) / rel))
    result = {"version": header[1], "zip": str(archive_path),
              "sha256": hashlib.sha256(archive_path.read_bytes()).hexdigest(),
              "files": len(selected)}
    (output / "package.json").write_text(json.dumps(result, indent=2) + "\n")
    return result

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, default=Path("."))
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.source, args.output), indent=2))
