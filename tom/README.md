# Directory target-operating-model contract

This directory records the portability-first bridge between the current Laravel
seed directory and the long-term federated control plane. The current runtime
remains one directory application. The contract assigns bounded-context and
event ownership so a future extraction can preserve public routes, event
integrity, backup/restore discipline and client compatibility.

`context-ownership-v1.json` is an executable architecture guard, not a service
deployment manifest. It must cover every signed `NodeEvent` type exactly once.
It deliberately marks `service_id` as planned: a service-origin field cannot be
emitted until the migration, signature fixtures and an independently operated
service/replica pilot exist.

Run `python3 scripts/check_tom_portability_contract.py` after changing event
types or ownership boundaries.
