# iicp-directory-php

**IICP Directory Service — PHP Reference Implementation**  
Apache 2.0 · [Protocol: iicp.network](https://iicp.network) · Private beta

> The Control Plane for the Intent-based Inter-agent Communication Protocol.  
> Handles node registration, liveness heartbeats, intent-based discovery, reputation, credits, and peer gossip.

---

## Overview

This is the **PHP/Laravel reference implementation** of the IICP directory service — the same codebase that powers [iicp.network](https://iicp.network) in production.

IICP's three-plane architecture:

```
Control Plane   →  iicp-directory-php  ← you are here
Execution Plane →  iicp-adapter (Python FastAPI + vLLM/llama.cpp/Ollama)
Client Plane    →  iicp-proxy (Python → Rust)
```

The directory is **discovery-only** — no task traffic routes through it. Nodes register, heartbeat, and are discovered here; actual inference runs adapter-to-adapter.

---

## Status

| Feature | Status |
|---------|--------|
| Node registration + JWT issuance | ✅ Production (v1.10.17) |
| Liveness heartbeat + 90s expiry | ✅ Production |
| Intent-based discovery + scoring | ✅ Production |
| Reputation tracking (proxy-observed) | ✅ Production |
| Credits system (balance/award/tx) | ✅ Production |
| Peer gossip (Phase 2 mesh) | ✅ Production |
| Node lifecycle (DORMANT/ARCHIVED/PURGED) | ✅ Production |
| Ed25519 event signing | ✅ Production |
| Reputation time-decay (λ=0.005/hr) | ⚠ Spec-validated — implementation pending |
| Two-sided feedback (commit-reveal) | ⚠ Research design — not yet implemented |

**Live instance**: [https://iicp.network](https://iicp.network)  
**API docs**: [https://iicp.network/docs](https://iicp.network/docs)  
**Browse nodes**: [https://iicp.network/nodes](https://iicp.network/nodes)

---

## API Surface

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/v1/register` | — | Register a new node, receive JWT |
| POST | `/api/v1/heartbeat` | Bearer JWT | Update node liveness (every 30s) |
| GET | `/api/v1/discover` | — | Scored, available nodes by intent |
| GET | `/api/v1/registry/nodes` | — | Browse the public node directory |
| GET | `/api/v1/stats` | — | Network-wide status and probe health |
| GET | `/api/v1/bootstrap` | — | Seed peer list for mesh bootstrap |
| POST | `/api/v1/peers` | Bearer JWT | Gossip peer exchange (HMAC-SHA256) |
| GET | `/api/v1/credits/balance` | Bearer JWT | Node credit balance |
| POST | `/api/v1/deregister` | Bearer JWT | Graceful node removal |

Full error codes: `IICP-E001` through `IICP-E032`. See [error reference](https://iicp.network/docs/error-reference).

---

## Tech Stack

- **PHP 8.3** + **Laravel 13**
- **MySQL 8.0** — node registry, reputation records, credits ledger, peer table
- **JWT HS256** — node authentication (issued on registration)
- **bcrypt** — node_token storage (plaintext never stored)
- **Ed25519** — node event signing (via libsodium)
- Deployed on **PHP-FPM + nginx**

---

## Running Locally

```bash
git clone https://github.com/RobLe3/iicp-directory-php.git
cd iicp-directory-php

composer install
cp .env.example .env
php artisan key:generate

# Configure .env: DB_DATABASE, DB_USERNAME, DB_PASSWORD, IICP_JWT_SECRET

php artisan migrate
php artisan serve
```

Tests (215 tests, PHPUnit):

```bash
php artisan test
```

---

## Protocol Context

This implementation targets **IICP spec S.12 v0.6.2-draft** (Cooperative Inference Profile).

- Spec: `spec/iicp-core.md` in the main monorepo
- Architecture: `project/ARCHITECTURE.md`
- Live API: [https://iicp.network/api/v1/discover](https://iicp.network/api/v1/discover)

---

## Relationship to iicp.network

This repo is a distribution of the `directory/` subtree from `RobLe3/iicp.network` (the monorepo).  
The monorepo is the development home; this repo tracks clean stable cuts for operators who want to run their own directory node.

> **Private beta**: Repository and protocol are under active development. Public release planned once Phase 5 CIP is ratified and the federation layer (Phase 6) is stable enough for multi-directory deployments.

---

## See Also

- [`iicp-directory-rust`](https://github.com/RobLe3/iicp-directory-rust) — Rust reimplementation (planned, higher-performance)
- [`iicp.network`](https://github.com/RobLe3/iicp.network) — Monorepo: directory + adapter + proxy + website
- [iicp.network](https://iicp.network) — Live network and documentation

---

## License

Apache 2.0 — see `LICENSE`. Protocol is vendor-neutral; this implementation is the reference, not the standard.
