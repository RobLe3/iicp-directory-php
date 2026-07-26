# Hardened operator upgrade rehearsal — v1.10.78.1 to v1.10.79.1

**Date:** 2026-07-26  
**Scope:** disposable local Docker infrastructure only  
**Production database used:** no  
**Deployment authorized:** no

Both annotated release tags were built in detached worktrees. The rehearsal:

1. started `v1.10.78.1` from a clean database and verified fixed readiness;
2. created a pre-upgrade database backup;
3. ran the `v1.10.79.1` one-shot migration and recreated the hardened
   application, scheduler and proxy images;
4. verified the running application reported the next immutable version;
5. stopped the application, restored the pre-upgrade database and recreated
   the previous hardened images;
6. verified previous-version readiness and current migration status; and
7. moved forward to `v1.10.79.1` again and verified readiness.

All eight checks passed. The content-free result used schema
`iicp.directory.operator-upgrade-rehearsal.v1`; the temporary SQL backup,
credentials, containers, volumes and detached worktrees were destroyed. This
is release-rehearsal evidence, not authorization to deploy or a claim about
production behavior.
