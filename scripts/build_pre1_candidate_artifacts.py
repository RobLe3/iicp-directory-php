#!/usr/bin/env python3
"""Build and prove one PHP Directory pre-stable artifact fragment."""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import sys
import tarfile
import tempfile
from pathlib import Path, PurePosixPath

import pre1_artifact_common as common


ROOT = Path(__file__).resolve().parents[1]
COMPONENT = "directory-php"
TARGETS = {"linux-x86_64", "linux-aarch64"}
COMPOSER_VALIDATE_ARGUMENTS = [
    "composer",
    "validate",
    "--no-interaction",
    "--no-check-publish",
]
LARAVEL_RUNTIME_DIRECTORIES = (
    Path("bootstrap/cache"),
    Path("storage/framework/cache/data"),
    Path("storage/framework/sessions"),
    Path("storage/framework/testing"),
    Path("storage/framework/views"),
    Path("storage/logs"),
)


def describe() -> dict:
    return {
        "schema": "iicp.pre1-artifact-builder-description.v1",
        "component": COMPONENT,
        "targets": sorted(TARGETS),
        "artifact_identities": [
            ["release-archive", "any"],
            ["release-manifest", "any"],
        ],
        "gates": sorted(common.GATES),
        "requires_clean_source": True,
        "non_authorizing": True,
    }


def safe_extract(archive_path: Path, destination: Path) -> Path:
    destination.mkdir()
    with tarfile.open(archive_path, "r:gz") as archive:
        members = archive.getmembers()
        roots: set[str] = set()
        for member in members:
            parsed = PurePosixPath(member.name)
            if (
                parsed.is_absolute()
                or ".." in parsed.parts
                or not parsed.parts
                or member.issym()
                or member.islnk()
            ):
                raise ValueError("PHP release archive contains an unsafe path or link")
            roots.add(parsed.parts[0])
        if len(roots) != 1:
            raise ValueError("PHP release archive does not contain one source root")
        archive.extractall(destination, members=members, filter="data")
    source = destination / next(iter(roots))
    if not source.is_dir() or source.is_symlink():
        raise ValueError("PHP release source root is unavailable or unsafe")
    return source


def composer_environment(cache: Path, *, offline: bool = False) -> dict[str, str]:
    value = dict(os.environ)
    value.update(
        {
            "COMPOSER_CACHE_DIR": str(cache),
            "COMPOSER_NO_INTERACTION": "1",
            "COMPOSER_PROCESS_TIMEOUT": "900",
        }
    )
    if offline:
        value["COMPOSER_DISABLE_NETWORK"] = "1"
    return value


def reject_runtime_symlink(path: Path, relative: Path) -> None:
    if path.is_symlink():
        raise ValueError(f"Laravel runtime path traverses a symlink: {relative}")


def reject_runtime_nondirectory(path: Path, relative: Path) -> None:
    if path.exists() and not path.is_dir():
        raise ValueError(f"Laravel runtime path is not a directory: {relative}")


def validate_laravel_runtime_path(source: Path, relative: Path) -> None:
    cursor = source
    for part in relative.parts:
        cursor = cursor / part
        reject_runtime_symlink(cursor, relative)
        reject_runtime_nondirectory(cursor, relative)


def materialize_laravel_runtime_directories(source: Path) -> None:
    """Create ignored Laravel runtime paths without following unsafe links."""
    for relative in LARAVEL_RUNTIME_DIRECTORIES:
        validate_laravel_runtime_path(source, relative)
        (source / relative).mkdir(parents=True, exist_ok=True)


def install(source: Path, cache: Path, *, offline: bool) -> str:
    materialize_laravel_runtime_directories(source)
    environment = composer_environment(cache, offline=offline)
    common.run(
        [
            "composer",
            "install",
            "--no-dev",
            "--prefer-dist",
            "--no-interaction",
            "--no-progress",
            "--no-ansi",
        ],
        source,
        environment,
    )
    common.run(["php", "artisan", "--version"], source, environment)
    return common.output(
        ["php", "-r", 'echo trim(file_get_contents("VERSION"));'],
        source,
        environment,
    )


def build(destination: Path, requested_target: str | None) -> dict:
    common.safe_output(destination)
    target = common.require_target(requested_target, TARGETS)
    commit = common.require_clean_source(ROOT)
    version = (ROOT / "VERSION").read_text().strip()
    composer = json.loads((ROOT / "composer.json").read_text())
    if composer.get("require", {}).get("php") != "~8.3.0":
        raise ValueError("PHP package support boundary differs from the qualification policy")
    php_version = common.output(["php", "-r", "echo PHP_VERSION;"], ROOT)
    if re.fullmatch(r"8\.3\.\d+(?:[-+].*)?", php_version) is None:
        raise ValueError("artifact build requires the declared PHP 8.3 runtime")

    run_root = Path(tempfile.mkdtemp(prefix="iicp-pre1-php-directory-", dir=destination.parent))
    staging = run_root / "fragment"
    staging.mkdir()
    try:
        root_env = composer_environment(run_root / "root-composer-cache")
        materialize_laravel_runtime_directories(ROOT)
        # The exact truschery/kanon compatibility pin is intentional and emits
        # a Composer publishing warning, so validation must not use --strict.
        common.run(COMPOSER_VALIDATE_ARGUMENTS, ROOT, root_env)
        common.run(["composer", "install", "--no-interaction", "--no-progress"], ROOT, root_env)
        common.run(["composer", "test"], ROOT, root_env)

        built = run_root / "built"
        environment = dict(os.environ)
        environment["IICP_RELEASE_ALLOW_UNTAGGED"] = "1"
        common.run(
            [str(ROOT / "scripts/build_release_artifacts.sh"), version, str(built)],
            ROOT,
            environment,
        )
        archive = built / f"iicp-directory-php-v{version}.tar.gz"
        manifest = built / "RELEASE-MANIFEST.json"
        if not archive.is_file() or not manifest.is_file():
            raise ValueError("PHP release builder did not produce its archive and manifest")

        cache = run_root / "composer-cache"
        online_source = safe_extract(archive, run_root / "online")
        online_version = install(online_source, cache, offline=False)
        offline_source = safe_extract(archive, run_root / "offline")
        offline_version = install(offline_source, cache, offline=True)
        if online_version != version or offline_version != version:
            raise ValueError("PHP release package self-report differs")

        copied_archive = staging / archive.name
        copied_manifest = staging / f"iicp-directory-php-v{version}-release-manifest.json"
        shutil.copyfile(archive, copied_archive)
        shutil.copyfile(manifest, copied_manifest)
        fragment = common.emit_fragment(
            staging,
            component=COMPONENT,
            source_commit=commit,
            source_version=version,
            build_target=target,
            artifacts=[
                common.artifact("release-archive", "any", copied_archive),
                common.artifact("release-manifest", "any", copied_manifest),
            ],
            lock_inputs_sha256=common.files_sha256(
                ROOT, [ROOT / "composer.json", ROOT / "composer.lock", ROOT / "VERSION"]
            ),
            dependency_cache_sha256=common.tree_sha256(cache),
            toolchains={
                "composer": common.output(["composer", "--version", "--no-ansi"], ROOT),
                "php": php_version,
            },
        )
        common.publish_staging(staging, destination)
        return fragment
    finally:
        common.clean_failed_staging(run_root)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--describe", action="store_true")
    parser.add_argument("--output", type=Path)
    parser.add_argument("--target")
    args = parser.parse_args()
    if args.describe:
        print(json.dumps(describe(), indent=2, sort_keys=True))
        return 0
    if args.output is None:
        parser.error("--output is required unless --describe is used")
    try:
        value = build(args.output.resolve(), args.target)
    except (OSError, ValueError, RuntimeError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(value, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
