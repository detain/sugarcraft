# STEP 6.4 — Server Status: replica, GTID, firewall, stored-program detection

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §D (Server Status); `docs/mysql_workbench_dash.md` §5.4. **Phase 6 closeout.**

## Why
- `ReplicaStatusProvider` swallows every `PDOException`→null, so the 1227 "insufficient
  privileges" path is dead — the panel can't distinguish "not configured" from "permission
  denied"; it also drops multi-channel rows (`$rows[0]` only) and only switches SLAVE/REPLICA by
  major version.
- GTID-mode selector (`SET @@GLOBAL.GTID_MODE=…`) is absent.
- Firewall panel is an `Aurora_lwm` stub; `hasStoredPrograms` reads non-existent status vars;
  `hasFulltext`/`hasPartitioning` ignore their `$serverVars` param.

## Goal
- Replica panel distinguishes not-configured / denied / configured; shows multi-channel rows;
  flavor-aware (`SHOW REPLICA STATUS` 8.0 / `SHOW SLAVE STATUS` / MariaDB `SHOW ALL SLAVES
  STATUS`).
- A GTID-mode selector (≥5.7.6).
- Honest firewall + stored-program + fulltext/partitioning detection.

## Files
- `src/Admin/ServerStatus/{ReplicaStatusProvider,ServerStatusPage}.php`.
- Tests under `tests/Admin/ServerStatus/`.

## Do
1. Replica: catch errors granularly — surface 1227 as "insufficient privileges (REPLICATION
   CLIENT)", empty result as "not configured", rows as the status. Return all channels/rows, not
   just `[0]`. Choose the query by flavor (MariaDB `SHOW ALL SLAVES STATUS`).
2. GTID selector: a control that runs `SET @@GLOBAL.GTID_MODE = ?`-equivalent (validated enum, not
   user free-text — GTID_MODE values are a fixed whitelist), gated ≥5.7.6.
3. Replace the firewall stub with real detection (`mysql_firewall_mode` var / firewall plugin);
   `hasStoredPrograms` via `information_schema.ROUTINES`; make `hasFulltext`/`hasPartitioning`
   actually consult `$serverVars`/plugins.

## Acceptance criteria
- [ ] Replica panel shows distinct not-configured vs denied vs configured states; multi-channel
      rows preserved; flavor-correct query (assert via fakes).
- [ ] GTID selector emits a whitelisted GTID_MODE change, gated by version.
- [ ] Firewall/stored-program/fulltext/partitioning detection uses real sources.
- [ ] Full suite green.

## Out of scope / defer
- Live-server verification → `DEFERRED:` for Step 8.1.
