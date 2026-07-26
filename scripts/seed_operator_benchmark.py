#!/usr/bin/env python3
"""Emit deterministic, synthetic SQL for a disposable capacity rehearsal."""

from __future__ import annotations

import argparse


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--nodes", type=int, default=100)
    args = parser.parse_args()
    if not 1 <= args.nodes <= 10000:
        parser.error("--nodes must be between 1 and 10000")

    print("START TRANSACTION;")
    print("DELETE FROM capabilities; DELETE FROM nodes;")
    for number in range(1, args.nodes + 1):
        node_id = f"00000000-0000-4000-8000-{number:012d}"
        endpoint = f"https://synthetic-{number:05d}.invalid"
        print(
            "INSERT INTO nodes "
            "(id,endpoint,region,`load`,active_jobs,available,last_seen,"
            "node_token_hash,max_concurrent,tokens_per_min,status,"
            "public_reachable,created_at,updated_at) VALUES "
            f"('{node_id}','{endpoint}','synthetic',0.1,0,1,"
            f"UTC_TIMESTAMP(),'synthetic-not-a-secret',8,100000,'active',1,"
            "UTC_TIMESTAMP(),UTC_TIMESTAMP());"
        )
        print(
            "INSERT INTO capabilities "
            "(node_id,intent,models,max_tokens,created_at,updated_at) VALUES "
            f"('{node_id}','urn:iicp:intent:llm:chat:v1',"
            "'[\"synthetic-model\"]',4096,UTC_TIMESTAMP(),UTC_TIMESTAMP());"
        )
    print("COMMIT;")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
