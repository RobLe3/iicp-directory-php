#!/usr/bin/env python3
"""Content-free evidence and exact-project cleanup for disposable rehearsals."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

PHASES = {"prepare", "build", "bootstrap", "readiness", "invalid_candidate", "database_recovery",
          "backup_restore", "previous_runtime", "upgrade", "rollback", "forward_recovery", "result"}


def safe_directory(path):
    path = Path(os.path.abspath(path))
    for part in (path, *path.parents):
        if part.is_symlink():
            raise ValueError("symlink directory refused")
    if not path.is_dir() or path.stat().st_uid != os.getuid():
        raise ValueError("directory ownership differs")
    return path


def write_json(path, value):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "w") as output:
        json.dump(value, output, sort_keys=True)
        output.write("\n")
        output.flush()
        os.fsync(output.fileno())


def command(args):
    # No raw command output, process environment or database contents enter evidence.
    try:
        result = subprocess.run(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
        return result.returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False


def resource_kind_absent(project, kind):
    with tempfile.TemporaryFile() as output:
        args = ["docker", kind, "ls"]
        if kind == "container":
            args.append("--all")
        args.extend(["--quiet", "--filter", f"label=com.docker.compose.project={project}"])
        try:
            result = subprocess.run(args, stdout=output, stderr=subprocess.DEVNULL, timeout=30)
            output.seek(0)
            return result.returncode == 0 and not output.read(1)
        except (OSError, subprocess.TimeoutExpired):
            return False


def resources_absent(project):
    return all(resource_kind_absent(project, kind) for kind in ("container", "volume", "network"))


def prepare(base, project, mode):
    if not re.fullmatch(r"iicp-operator-(?:rehearsal|upgrade)-[a-z0-9-]{1,64}", project):
        raise ValueError("unsafe rehearsal project")
    if not resources_absent(project):
        raise ValueError("project is occupied or Docker inventory unavailable")
    parent = safe_directory(base)
    work = Path(tempfile.mkdtemp(prefix="iicp-owned-", dir=parent))
    evidence = Path(str(work) + ".evidence")
    evidence.mkdir(mode=0o700)
    write_json(work / "owner.json", {"project": project, "mode": mode})
    write_json(evidence / "started.json", {"schema": "iicp.directory.operator-attempt.v1",
               "mode": mode, "run_id": work.name, "started_at": datetime.now(timezone.utc).isoformat(),
               "content_free": True, "deployment_authorized": False})
    return work


def read_small(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd) as stream:
        value = stream.read(65537)
    if len(value) > 65536:
        raise ValueError("oversized evidence input")
    return value


def checks_complete(checks, expected):
    if not isinstance(checks, dict) or set(checks) != expected:
        return False
    return all(v is True for v in checks.values())


def result_summary(work, mode):
    value = json.loads(read_small(work / "result.json"))
    schema = "iicp.directory.operator-" + ("upgrade-" if mode == "upgrade" else "") + "rehearsal.v1"
    expected = ({"clean_migration", "liveness", "readiness", "bad_candidate_rejected",
                 "database_failure_not_ready", "database_recovery", "backup_restore", "restored_migration_status"}
                if mode == "stack" else {"previous_clean_start", "pre_upgrade_backup", "next_one_shot_migration",
                 "next_readiness", "database_restore", "previous_image_rollback", "previous_migration_status", "next_forward_recovery"})
    checks = value.get("checks", {})
    if value.get("schema") != schema or not checks_complete(checks, expected):
        raise ValueError("incomplete workload result")
    digest = value.get("backup_sha256", "")
    if not isinstance(digest, str) or not re.fullmatch(r"[0-9a-f]{64}", digest):
        raise ValueError("invalid backup digest")
    return {"schema": schema, "checks": checks, "backup_sha256": digest}


def workload_record(work, mode, exit_code):
    phase = "prepare"
    try:
        observed = read_small(work / "phase").strip()
        if observed in PHASES:
            phase = observed
    except (OSError, ValueError):
        pass
    summary = None
    if exit_code == 0:
        try:
            summary = result_summary(work, mode)
        except (OSError, ValueError, TypeError, AttributeError):
            exit_code = 3
    return {"schema": "iicp.directory.operator-attempt.v1", "mode": mode, "run_id": work.name, "phase": phase,
            "workload_exit_code": exit_code, "content_free": True,
            "deployment_authorized": False, "qualification_credit": 0}, summary


def capture_workload(evidence, record, summary):
    try:
        if summary is not None:
            write_json(evidence / "checks.json", summary)
        write_json(evidence / "workload.json", record)
        return True
    except OSError:
        return False


def remove_worktrees(work, root):
    success = True
    for name in ("previous", "next"):
        checkout = work / name
        if checkout.is_symlink():
            success = False
        elif (checkout / ".git").exists():
            removed = command(["git", "-C", str(root), "worktree", "remove", "--force", str(checkout)])
            success = removed and success
    return success


def cleanup_resources(work, project, mode, root):
    cleanup = command(["docker", "compose", "-p", project, "-f", str(root / "compose.operator.yml"),
                       "down", "--volumes", "--remove-orphans"])
    cleanup = resources_absent(project) and cleanup
    if mode == "upgrade":
        cleanup = remove_worktrees(work, root) and cleanup
    return cleanup


def save_closure(evidence, work, record, clean):
    try:
        write_json(evidence / "resource-closure.json", record)
        if clean:
            shutil.rmtree(work)
    except OSError:
        clean = False
    record.update(status="PASS" if clean else "FAIL", workspace_returned=not work.exists())
    try:
        write_json(evidence / "closure.json", record)
    except OSError:
        clean = False
        record["status"] = "FAIL"
    return clean


def cleanup_status(keep, cleanup):
    if keep:
        return "RETAINED"
    return "PASS" if cleanup else "FAIL"


def finish(work, project, mode, root, exit_code, keep=False):
    work = safe_directory(work)
    if json.loads(read_small(work / "owner.json")) != {"project": project, "mode": mode}:
        raise ValueError("rehearsal ownership differs")
    evidence = safe_directory(Path(str(work) + ".evidence"))
    record, summary = workload_record(work, mode, exit_code)
    captured = capture_workload(evidence, record, summary)
    # Capture precedes teardown; export failure must not strand compute.
    cleanup = False if keep else cleanup_resources(work, project, mode, root)
    cleanup_state = cleanup_status(keep, cleanup)
    record.update(evidence_captured=captured, cleanup=cleanup_state)
    clean = record["workload_exit_code"] == 0 and cleanup and captured
    clean = save_closure(evidence, work, record, clean)
    print(json.dumps(record, sort_keys=True))
    print(f"rehearsal evidence: {evidence}", file=__import__('sys').stderr)
    return record["workload_exit_code"] or (0 if clean else 3)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["prepare", "finish", "export"])
    parser.add_argument("--work", type=Path)
    parser.add_argument("--base", type=Path, default=Path(tempfile.gettempdir()).resolve())
    parser.add_argument("--project", required=True)
    parser.add_argument("--mode", choices=["stack", "upgrade"], required=True)
    parser.add_argument("--root", type=Path)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--exit-code", type=int, default=0)
    parser.add_argument("--keep", action="store_true")
    args = parser.parse_args()
    if args.action == "prepare":
        print(prepare(args.base, args.project, args.mode))
        return 0
    if args.action == "export":
        result_summary(args.work, args.mode)
        parent = safe_directory(args.output.parent)
        write_json(parent / args.output.name, json.loads(read_small(args.work / "result.json")))
        return 0
    return finish(args.work, args.project, args.mode, args.root, args.exit_code, args.keep)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, OSError) as error:
        print(f"rehearsal evidence refused: {type(error).__name__}", file=__import__('sys').stderr)
        raise SystemExit(3)
