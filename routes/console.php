<?php

use App\Jobs\AggregateProbeMetricsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('iicp:expire-nodes')->everyThirtySeconds();
Schedule::command('iicp:probe-nodes')->everyFiveMinutes(); // #373 Phase B — active per-node reachability probing
Schedule::command('iicp:node-lifecycle')->daily();
Schedule::command('iicp:reputation-decay')->hourly(); // spec §11.3: λ=0.005/hr idle decay
Schedule::command('iicp:rotate-reputation-window')->dailyAt('03:30'); // ADR-036 rolling window rotation
Schedule::command('iicp:rotate-replica-lifecycle')->dailyAt('03:45'); // ADR-039 §5.5 replica lifecycle
Schedule::command('iicp:prune-heartbeat-events', ['--days=1'])->dailyAt('04:00'); // db-D6prime (W-042): nightly 1-day retention (≤1440 heartbeat rows/node/day vs 10k+ weekly)
Schedule::command('iicp:founder-lockin-scan')->dailyAt('04:15'); // #310 founder recognition (§5.4): reserve #1, assign #2..N to genuine operators
Schedule::command('iicp:expire-credits')->dailyAt('02:00'); // ADR-035 / billing §11.3: 90d TTL credit sink (primary anti-inflation; complements live 2% burn)
Schedule::job(new AggregateProbeMetricsJob)->everyFiveMinutes();
Schedule::command('iicp:warm-stats-cache')->everyMinute(); // #508 — /v1/stats never pays the ~1.2s rebuild in-request
