#!/usr/bin/env python3
"""Measure a synthetic disposable discovery profile without retaining responses."""

from __future__ import annotations

import argparse
import json
import math
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from urllib.request import Request, urlopen


def percentile(values: list[float], fraction: float) -> float:
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, math.ceil(fraction * len(ordered)) - 1))
    return round(ordered[index], 3)


def request_once(url: str, timeout: float) -> tuple[float, bool]:
    started = time.perf_counter()
    try:
        with urlopen(Request(url, headers={"Accept": "application/json"}), timeout=timeout) as response:
            body = json.loads(response.read())
            ok = response.status == 200 and isinstance(body.get("nodes"), list) and len(body["nodes"]) > 0
    except Exception:
        ok = False
    return (time.perf_counter() - started) * 1000, ok


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--samples", type=int, default=40)
    parser.add_argument("--concurrency", default="1,8,32")
    parser.add_argument("--timeout", type=float, default=20)
    parser.add_argument("--fail-on-errors", action="store_true")
    args = parser.parse_args()
    if not args.base.startswith(("http://127.0.0.1:", "http://localhost:")):
        parser.error("--base must be loopback")
    levels = [int(value) for value in args.concurrency.split(",")]
    url = args.base.rstrip("/") + "/api/v1/discover?intent=urn%3Aiicp%3Aintent%3Allm%3Achat%3Av1"
    workloads = []
    for workers in levels:
        started = time.perf_counter()
        with ThreadPoolExecutor(max_workers=workers) as pool:
            results = list(pool.map(lambda _: request_once(url, args.timeout), range(args.samples)))
        elapsed = time.perf_counter() - started
        timings = [duration for duration, _ in results]
        workloads.append({
            "concurrency": workers,
            "requests": args.samples,
            "errors": sum(not ok for _, ok in results),
            "throughput_requests_per_second": round(args.samples / elapsed, 3),
            "latency_ms": {
                "p50": percentile(timings, 0.50),
                "p95": percentile(timings, 0.95),
                "p99": percentile(timings, 0.99),
            },
        })
    report = {
        "schema": "iicp.directory.operator-capacity.v1",
        "measured_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "content_free": True,
        "synthetic_nodes": 100,
        "production_database_used": False,
        "production_endpoint_used": False,
        "deployment_authorized": False,
        "not_an_sla": True,
        "workloads": workloads,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
    print(json.dumps(report, indent=2, sort_keys=True))
    return 1 if args.fail_on_errors and any(item["errors"] for item in workloads) else 0


if __name__ == "__main__":
    raise SystemExit(main())
