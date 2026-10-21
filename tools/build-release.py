"""Build the install ZIP using the same exclusions documented for wp dist-archive."""
import argparse
import fnmatch
import hashlib
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = "harborcache-object-cache-with-redis"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Audit inputs without writing a release")
    parser.add_argument("--verify", action="store_true", help="Verify the existing ZIP against current runtime source")
    args = parser.parse_args()
    header = (ROOT / "harborcache.php").read_text(encoding="utf-8")
    readme = (ROOT / "readme.txt").read_text(encoding="utf-8")
    version = re.search(r"^ \* Version: ([0-9.]+)$", header, re.M).group(1)
    assert f"Stable tag: {version}\n" in readme
    changelog = readme.split("== Changelog ==\n", 1)[1].split("\n== ", 1)[0]
    assert changelog.count(f"= {version} =") == 1, "Duplicate or missing release changelog"
    assert f"Text Domain: {SLUG}" in header
    dropin = (ROOT / "includes/object-cache.php").read_text(encoding="utf-8")
    assert f"Version: {version}\n" in dropin
    patterns = [line.strip().lstrip("/") for line in (ROOT / ".distignore").read_text().splitlines() if line.strip() and not line.startswith("#")]
    runtime_roots = {"assets", "docs", "includes", "languages", "vendor"}
    root_files = {"harborcache.php", "uninstall.php", "readme.txt", "LICENSE.md", "composer.json"}
    user_guides = {"ADVANCED.md", "CONFIGURATION.md", "DEPLOYMENT.md", "FEATURES.md", "SOURCE.md"}
    included = []
    for path in sorted(ROOT.rglob("*")):
        rel = path.relative_to(ROOT).as_posix()
        if any(fnmatch.fnmatchcase(rel, pattern) or rel.startswith(pattern.rstrip("/") + "/") for pattern in patterns):
            continue
        if not path.is_file():
            continue
        assert path.parts[len(ROOT.parts)] in runtime_roots or rel in root_files, f"Unexpected distribution location: {rel}"
        assert not rel.startswith("docs/") or path.name in user_guides, f"Unexpected user documentation: {rel}"
        assert not path.is_symlink(), f"Symlink in distribution: {rel}"
        assert path.suffix.lower() in {".php", ".js", ".css", ".txt", ".md", ".json", ".xml", ".svg", ".png", ".jpg", ".jpeg", ".gif", ".webp", ".pot", ".po", ".mo", ".map"} or path.name in {"LICENSE", "COPYING"}, f"Unexpected distribution file: {rel}"
        included.append((path, rel))
    names = {rel for _, rel in included}
    for required in ["harborcache.php", "readme.txt", "LICENSE.md", "vendor/predis/predis/LICENSE", "vendor/colinmollenhour/credis/LICENSE", "includes/ch-paths.php", "includes/ch-namespace.php"]:
        assert required in names, f"Missing runtime or license file: {required}"
    assert not any(rel.endswith(".markdown") or rel.startswith(("tests/", "tools/", "media/", "dist/", ".git")) or re.search(r"docs/(NAME-REVIEW|RELEASE-|VALIDATION-|COMPARISON-)", rel) for rel in names)
    # Prevent silent deletion of the readable source corresponding to runtime libraries.
    assert "vendor/predis/predis/src/Client.php" in names
    if args.check:
        print(json.dumps({"version": version, "files": len(included), "distribution_checks": "passed"}))
        return
    output = ROOT / "dist" / f"harborcache-{version}.zip"
    output.parent.mkdir(exist_ok=True)
    if not args.verify:
        with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for path, rel in included:
                archive.write(path, f"{SLUG}/{rel}")
    with zipfile.ZipFile(output) as archive:
        assert archive.testzip() is None
        expected = {f"{SLUG}/{rel}" for _, rel in included}
        assert set(archive.namelist()) == expected, f"ZIP file list mismatch: {set(archive.namelist()) ^ expected}"
        for path, rel in included:
            assert archive.read(f"{SLUG}/{rel}") == path.read_bytes(), f"ZIP/source mismatch: {rel}"
    print(json.dumps({"archive": str(output), "version": version, "files": len(included), "sha256": hashlib.sha256(output.read_bytes()).hexdigest(), "distribution_checks": "passed"}))


if __name__ == "__main__":
    main()
