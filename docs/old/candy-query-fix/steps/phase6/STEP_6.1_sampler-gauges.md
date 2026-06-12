# STEP 6.1 — Wire Sampler into sidebar gauges; fix gauge math

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §B (Sampler dead), §C (key-efficiency), §D (Server Status gauges).

## Why
`ServerStatusPage` builds `SidebarGaugeSet::new($context)` with no `Sampler` and never calls
`poll()`, so the per-second rate path is dead and gauges use the inaccurate cumulative-bytes
fallback (pinning traffic to ~100% on a long-running server). `computeKeyEfficiencyRatio` uses
two different denominators and is mislabeled. The "CPU" gauge actually computes the Connections
ratio (MySQL exposes no CPU status var).

## Goal
- A `Sampler` feeds `SidebarGaugeSet`; rates are delta-per-elapsed, not cumulative.
- Key-efficiency = `Key_reads / Key_read_requests` (cache-miss ratio), correctly.
- The CPU gauge is either honestly sourced or removed (not a mislabeled Connections duplicate).

## Files
- `src/Admin/ServerStatus/{ServerStatusPage,SidebarGaugeSet,SidebarGauge}.php`.
- `src/Admin/Sampler.php` (consumer side; reuse the existing rate math).
- `src/App.php` if the sampler must be threaded through the admin fetch.
- Tests under `tests/Admin/ServerStatus/`.

## Do
1. Construct `SidebarGaugeSet` with a `Sampler` and call `poll()` on each admin refresh so
   `previousRates`/`previousTs` advance; traffic/QPS/InnoDB rates use the delta path. (Restart
   detection unification is STEP 7.1 — here just feed the sampler.)
2. Fix `computeKeyEfficiencyRatio` to a single correct formula (`Key_reads /
   Key_read_requests`); remove the dead `$total`/`$keyWrites` branches.
3. Remove or honestly relabel the CPU gauge (drop the duplicate Connections-ratio compute). If a
   real source isn't available without OS access, omit it and `NOTE:` the decision.

## Acceptance criteria
- [ ] Gauges use sampled per-second rates (assert two-sample delta via fake snapshots, not
      cumulative-since-uptime).
- [ ] Key-efficiency uses one correct formula.
- [ ] No mislabeled CPU-as-connections gauge.
- [ ] Full suite green.

## Out of scope / defer
- PG status mapping → 6.2. Dashboard cells → 6.3. Replica/GTID/firewall → 6.4.
