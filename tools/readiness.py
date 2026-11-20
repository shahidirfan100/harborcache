"""WordPress.org readiness checks for the exact release ZIP.

Usage: python3 tools/readiness.py [path/to/plugin.zip]
"""
import hashlib
import json
import re
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SLUG = "harborcache-object-cache-with-redis"
TEXT_DOMAIN = SLUG
FORBIDDEN = (
    b"Object Cache Pro",
    b"Redis Object Cache",
    b"free plugin",
    b"free engine",
    b"commercial",
    b"competitor",
    b"objectcache.pro",
    b"RedisCachePro",
    b"Pro-lane",
)
DEV_DIRS = ("tests/", "tools/", "media/", "dist/", ".github/", ".git/")
DEV_DOCS = re.compile(r"docs/(VALIDATION-|COMPARISON-|RELEASE-|PERFORMANCE-|DESIGN-REVIEW-|ARCHITECTURE-|NAME-REVIEW|ACCEPTANCE|PUBLICATION)")
README_SECTIONS = ("== Description ==", "== Installation ==", "== Frequently Asked Questions ==", "== Screenshots ==", "== Changelog ==", "== Upgrade Notice ==")


def fail(message):
    print(f"FAIL {message}")
    return False


def ok(message):
    print(f"PASS {message}")
    return True


def main():
    header = (ROOT / "harborcache.php").read_text(encoding="utf-8")
    readme = (ROOT / "readme.txt").read_text(encoding="utf-8-sig")
    version = re.search(r"^ \* Version: ([0-9.]+)$", header, re.M).group(1)
    checks = []
    checks.append(("Plugin header and HARBORCACHE_VERSION agree", f"define( 'HARBORCACHE_VERSION', '{version}' );" in header))
    checks.append(("Stable tag matches the plugin version", f"Stable tag: {version}\n" in readme))
    checks.append(("Drop-in header matches the version", f"Version: {version}\n" in (ROOT / "includes/object-cache.php").read_text(encoding="utf-8")))
    loader = (ROOT / "deployment/harborcache-loader.php").read_text(encoding="utf-8")
    checks.append(("Loader header matches the version", f"Version: {version}\n" in loader))
    changelog = readme.split("== Changelog ==\n", 1)[1].split("\n== ", 1)[0]
    checks.append(("Exactly one current changelog entry", changelog.count(f"= {version} =") == 1))
    checks.append(("Version is a semantic release", re.fullmatch(r"\d+\.\d+\.\d+", version) is not None))
    checks.append(("Text Domain header matches the slug", f"Text Domain: {TEXT_DOMAIN}" in header))
    checks.append(("Plugin header is GPL-3.0-only", "License: GPL-3.0-only" in header and "License URI: http" in header))
    checks.append(("Requires PHP is declared", "Requires PHP: 7.4" in header and "Requires PHP: 7.4" in readme))
    checks.append(("Requires at least is declared", "Requires at least: 6.6" in header and "Requires at least: 6.6" in readme))
    checks.append(("Tested up to is present", re.search(r"Tested up to: \d+\.\d+", readme) is not None))
    for section in README_SECTIONS:
        checks.append((f"Readme section {section}", section in readme))
    checks.append(("Readme has no competitor wording", not any(token.decode().lower() in readme.lower() for token in FORBIDDEN)))

    screenshots = json.loads((ROOT / "media/screenshots.json").read_text(encoding="utf-8"))
    checks.append(("Screenshot metadata matches the version", screenshots.get("version") == version))
    for index in range(1, 8):
        checks.append((f"Screenshot {index} exists", (ROOT / f"media/screenshot-{index}.png").is_file()))
    checks.append(("Mobile screenshot exists", (ROOT / "media/screenshot-mobile.png").is_file()))
    checks.append(("Mobile widget screenshot exists", (ROOT / "media/screenshot-widget-mobile.png").is_file()))

    zip_path = ROOT / "dist" / f"harborcache-{version}.zip"
    if len(sys.argv) > 1:
        zip_path = Path(sys.argv[1])
    checks.append(("Release ZIP exists", zip_path.is_file()))
    if not zip_path.is_file():
        return report(checks)
    body = zip_path.read_bytes()
    checks.append(("Release ZIP is a valid archive", zipfile.ZipFile(zip_path).testzip() is None))
    sums = (ROOT / "dist/SHA256SUMS").read_text(encoding="utf-8").strip().splitlines()
    checks.append(("SHA256SUMS carries the exact ZIP hash", any(line.split() == [hashlib.sha256(body).hexdigest(), zip_path.name] for line in sums)))

    with zipfile.ZipFile(zip_path) as archive:
        names = archive.namelist()
        checks.append(("ZIP has one top-level folder", all(name.startswith(SLUG + "/") for name in names)))
        checks.append(("ZIP has no development directories", not any(re.search(r"(^|/)(tests|tools|media|dist|\.github|\.git)/", name) for name in names)))
        checks.append(("ZIP has no development docs", not any(DEV_DOCS.search(name) for name in names)))
        checks.append(("ZIP has no Markdown docs", not any(name.endswith(".markdown") for name in names)))
        for required in ("harborcache.php", "readme.txt", "LICENSE.md", "uninstall.php", "includes/object-cache.php", "includes/class-key-builder.php"):
            checks.append((f"ZIP contains {required}", f"{SLUG}/{required}" in names))
        checks.append(("ZIP has no shipped test files", not any("/tests/" in name for name in names)))

        bad = []
        for name in names:
            if name.endswith("/") or name.endswith("LICENSE.md") or "/vendor/" in name:
                continue
            data = archive.read(name)
            if any(token in data for token in FORBIDDEN):
                bad.append(name)
        checks.append(("No competitor wording in shipped files", not bad))
        if bad:
            print("  files:", ", ".join(bad[:10]))

        syntax = []
        php_files = [name for name in names if name.endswith(".php") and "/vendor/" not in name]
        with tempfile.TemporaryDirectory() as temp:
            for name in php_files:
                relative = name.split("/", 1)[1]
                target = Path(temp) / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(archive.read(name))
                result = subprocess.run(["php", "-l", str(target)], capture_output=True, text=True)
                if result.returncode != 0:
                    syntax.append(relative)
        checks.append((f"PHP lint on {len(php_files)} shipped plugin files", not syntax))
        if syntax:
            print("  files:", ", ".join(syntax[:10]))

        banned_code = []
        for name in names:
            if not name.endswith(".php") or "/vendor/" in name:
                continue
            text = archive.read(name).decode("utf-8", "ignore")
            if re.search(r"\b(var_dump|print_r|debug_print_backtrace|xdebug_break)\s*\(", text):
                banned_code.append(name)
        checks.append(("No debug dumps in shipped PHP", not banned_code))
        if banned_code:
            print("  files:", ", ".join(banned_code[:10]))

        checks.append(("Readme has a screenshot list", readme.count("\n1. ") >= 1 and "== Screenshots ==" in readme))

    return report(checks)


def report(checks):
    failures = [name for name, passed in checks if not passed]
    for name, passed in checks:
        print(("PASS " if passed else "FAIL ") + name)
    print(json.dumps({"checks": len(checks), "failed": len(failures), "readiness": "passed" if not failures else "failed"}))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
