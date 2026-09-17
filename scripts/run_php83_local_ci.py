#!/usr/bin/env python3
"""Run a caller-pinned CI argv in a disposable PHP 8.3 clone, not the host tree."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import stat
import subprocess
import threading
import time
import uuid

from operator_rehearsal_evidence import safe_directory, write_json

ROOT = Path(__file__).resolve().parents[1]
BUILDKIT = "moby/buildkit@sha256:28a898719c18a33f4e8000685287fa36fd0dd9560c6440227d3a732d79bb41d8"
LOG_LIMIT = 16 * 1024 * 1024
IMAGE_TIMEOUT = 1800
PROGRESS_INTERVAL = 30


def read_command(path, digest):
    path = Path(os.path.abspath(path))
    safe_directory(path.parent, allow_sticky=True)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        metadata = os.fstat(stream.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > 65536:
            raise ValueError("unsafe command file")
        raw = stream.read(65537)
    if len(raw) > 65536:
        raise ValueError("oversized command file")
    if hashlib.sha256(raw).hexdigest() != digest:
        raise ValueError("command digest differs")
    value = json.loads(raw)
    if not isinstance(value, list) or not value or any(not isinstance(x, str) or not x or "\0" in x for x in value):
        raise ValueError("command must be nonempty argv")
    return value


def progress_event(log, state, started, timeout, **fields):
    """Emit metadata only; log content and environment values stay private."""
    elapsed = round(time.monotonic() - started, 2)
    event = {"schema": "iicp.php-local-ci-progress.v1",
             "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
             "phase": log.stem, "state": state, "elapsed_seconds": elapsed,
             "timeout_seconds": timeout, **fields}
    try:
        metadata = log.stat()
        event.update(retained_log_bytes=metadata.st_size,
                     last_output_age_seconds=round(max(0, time.time() - metadata.st_mtime), 2))
    except OSError:
        pass
    print(json.dumps(event, sort_keys=True), flush=True)
    try:
        with (log.parent / "progress.jsonl").open("a") as stream:
            stream.write(json.dumps(event, sort_keys=True) + "\n")
    except OSError:
        print("Optional progress persistence unavailable", flush=True)


def wait_with_progress(process, log, timeout, interval):
    started = time.monotonic()
    progress_event(log, "started", started, timeout)
    while True:
        remaining = timeout - (time.monotonic() - started)
        if remaining <= 0:
            raise subprocess.TimeoutExpired(process.args, timeout)
        try:
            code = process.wait(timeout=min(remaining, interval))
            progress_event(log, "completed", started, timeout, exit_code=code)
            return code
        except subprocess.TimeoutExpired:
            progress_event(log, "heartbeat", started, timeout)


def capture(args, log, timeout, *, progress_interval=PROGRESS_INTERVAL):
    """Drain output after the retention limit; do not deadlock a noisy command."""
    process = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    def drain():
        remaining = LOG_LIMIT
        with log.open("xb") as output:
            while data := process.stdout.read1(8192):
                if remaining:
                    output.write(data[:remaining])
                    output.flush()
                    remaining = max(0, remaining - len(data))
    reader = threading.Thread(target=drain, daemon=True)
    reader.start()
    try:
        code = wait_with_progress(process, log, timeout, progress_interval)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait()
        code = 124
        progress_event(log, "timed-out", time.monotonic() - timeout, timeout, exit_code=code)
    except BaseException:
        process.kill()
        process.wait()
        reader.join(10)
        if not reader.is_alive():
            process.stdout.close()
        raise
    reader.join(10)
    if not reader.is_alive():
        process.stdout.close()
    return code if not reader.is_alive() else 125


def run(command, output, command_digest, *, image_timeout=IMAGE_TIMEOUT, progress_interval=PROGRESS_INTERVAL):
    if not 1 <= image_timeout <= 7200 or not 1 <= progress_interval <= 300:
        raise ValueError("image timeout must be 1..7200 and progress interval 1..300 seconds")
    if output.is_relative_to(ROOT):
        raise ValueError("output must be outside the source checkout")
    source = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
    if subprocess.check_output(["git", "-C", str(ROOT), "status", "--porcelain"], text=True).strip():
        raise ValueError("clean committed source required")
    parent = safe_directory(output.parent, allow_sticky=True)
    output = parent / output.name
    output.mkdir(mode=0o700)  # Refuse existing outputs, including incomplete attempts.
    name = "iicp-php-ci-" + uuid.uuid4().hex[:12]
    clone = output / "source"
    context = output / "image-context"
    context.mkdir()
    shutil.copyfile(ROOT / "Dockerfile.ci", context / "Dockerfile")
    receipt = {"schema": "iicp.php-local-ci.v1", "source_commit": source,
               "command_sha256": command_digest, "qualification_credit": 0,
               "dockerfile_sha256": hashlib.sha256((context / "Dockerfile").read_bytes()).hexdigest(),
               "buildkit": BUILDKIT, "image_timeout_seconds": image_timeout,
               "progress_interval_seconds": progress_interval, "phases": [], "status": "FAIL"}
    write_json(output / "started.json", receipt)
    def phase(label, args, timeout):
        start = time.monotonic()
        code = capture(args, output / (label + ".log"), timeout, progress_interval=progress_interval)
        receipt["phases"].append({"phase": label, "exit_code": code,
                                   "seconds": round(time.monotonic() - start, 2)})
        print(label, code, flush=True)
        if code:
            raise RuntimeError(label + " failed")
    try:
        phase("clone", ["git", "clone", "--no-hardlinks", "--no-checkout", str(ROOT), str(clone)], 120)
        phase("checkout", ["git", "-C", str(clone), "checkout", "--detach", source], 60)
        phase("builder", ["docker", "buildx", "create", "--name", name, "--driver", "docker-container",
                          "--driver-opt", "image=" + BUILDKIT], 120)
        phase("image", ["docker", "buildx", "build", "--builder", name, "--load", "--tag", name,
                        "--progress", "plain", str(context)], image_timeout)
        receipt["image_id"] = subprocess.check_output(["docker", "image", "inspect", "--format", "{{.Id}}", name], text=True).strip()
        receipt["platform"] = subprocess.check_output(["docker", "image", "inspect", "--format", "{{.Os}}/{{.Architecture}}", name], text=True).strip()
        base = ["docker", "run", "--name", name, "--init", "--memory", "3g", "--cpus", "2", "--pids-limit", "256",
                "--mount", "type=bind,src=" + str(clone) + ",dst=/work", "--workdir", "/work"]
        phase("dependencies", base + [name, "bash", "-euc",
              "php -v; composer --version; python3 --version; mkdir -p bootstrap/cache storage/framework/{cache,sessions,views} storage/logs; composer install --no-interaction --prefer-dist"], 600)
        phase("dependency-container-return", ["docker", "rm", name], 60)
        # The caller's canonical command includes the online advisory audit.
        # This is local source CI, not an offline-install qualification claim.
        phase("checks", base + [name] + command, 1800)
        receipt["status"] = "PASS"
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        receipt["failure_type"] = type(error).__name__
    finally:
        # Finish bounded cleanup even if the operator repeats the interrupt.
        if threading.current_thread() is threading.main_thread():
            previous_handlers = {sig: signal.signal(sig, signal.SIG_IGN) for sig in (signal.SIGINT, signal.SIGTERM)}
        else:
            previous_handlers = {}
        cleanup = []
        for label, args in [("container", ["docker", "rm", "--force", name]),
                            ("builder", ["docker", "buildx", "rm", "--force", name]),
                            ("image", ["docker", "image", "rm", name])]:
            code = capture(args, output / ("cleanup-" + label + ".log"), 120)
            cleanup.append({"resource": label, "exit_code": code})
        receipt["cleanup"] = cleanup
        try:
            absent = all(not subprocess.check_output(args, text=True, timeout=30).strip() for args in [
                ["docker", "container", "ls", "--all", "--quiet", "--filter", "name=^/" + name + "$"],
                ["docker", "image", "ls", "--quiet", name],
                ["docker", "volume", "ls", "--quiet", "--filter", "name=buildx_buildkit_" + name + "0_state"],
            ])
        except (OSError, subprocess.SubprocessError):
            absent = False
        receipt["resources_absent"] = absent
        # Removal of a never-created object may return nonzero. Independent
        # absence verification, not an error-message substring, closes cleanup.
        if absent:
            for row in cleanup:
                row["raw_exit_code"] = row["exit_code"]
                row["exit_code"] = 0
        if any(x["exit_code"] for x in cleanup) or not absent:
            receipt["status"] = "FAIL"
        if receipt["status"] == "PASS":
            shutil.rmtree(clone)
            shutil.rmtree(context)
        receipt["workspace_returned"] = not clone.exists()
        write_json(output / "result.json", receipt)
        for sig, handler in previous_handlers.items():
            signal.signal(sig, handler)
    return 0 if receipt["status"] == "PASS" else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--command-json", type=Path, required=True)
    parser.add_argument("--command-sha256", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--image-timeout-seconds", type=int, default=IMAGE_TIMEOUT)
    parser.add_argument("--progress-interval-seconds", type=int, default=PROGRESS_INTERVAL)
    args = parser.parse_args()
    os.umask(0o077)
    def interrupted(signum, frame):
        raise KeyboardInterrupt("local CI interrupted")
    signal.signal(signal.SIGTERM, interrupted)
    command = read_command(args.command_json, args.command_sha256)
    return run(command, Path(os.path.abspath(args.output)), args.command_sha256,
               image_timeout=args.image_timeout_seconds, progress_interval=args.progress_interval_seconds)


if __name__ == "__main__":
    raise SystemExit(main())
