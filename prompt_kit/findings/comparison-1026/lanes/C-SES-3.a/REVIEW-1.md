# C-SES-3.a round 1 — VERDICT: CLEAN (0C/0M/0 MINOR; 1 NIT no-action)
Scope: exactly the 6 brief-named files; no weld-only files (suite-figure/durations/dist untouched); one cohesive commit 6c5d10486.
Author law: %ae and %ce verified separately = detain@interserver.net; zero Co-Authored-By.
Transaction: revoke rides deleteSessionTrees()'s same per-chunk loop; both entry points wrap it in immediateTransaction (SessionStore.php:995, :1353) — no partial state on rollback; guarded by hasTable('shares'); bind order matches revokeForSessionsSql placeholders; SQL single-home law held.
liveAll(): LEFT JOIN orphan-flagging, shares-only DB tolerated (all-orphan honest), never throws, ordering mirrors liveForSession; §6 ruling honored — revoke-not-delete, rows listable until sweepInertRows, live-token lookup semantics unchanged, detached branch-children keep pages.
Redaction: Server.php keyed on ShareStore::URL_PREFIX, `/s/<first4>…`, non-share paths verbatim; docblock chain item 2 moved same commit (§1.8).
Non-vacuity: pre-fix reds reconcile statically (4F revoke pins + structural slice; 2E undefined liveAll; 1F log line); the one green-pre-fix tolerance pin is disclosed and legit (over-correction guard, reddens under unconditional UPDATE); ageSessions proven byte-identical to SessionIndexAndRetentionTest:505; §1.9 literal hazards clean.
New file SessionDeleteRevokesSharesTest.php listed for gate durations (README/suite-figure re-pin 25537→ owed by weld: builder reported +19T? see REPORT).
Reviewer blockers: phpunit re-run + REVIEW write denied by reviewer shell policy; all builder claims checked statically against signatures (Server::new/9-arg, port(), createSession name param, SessionKind values) — consistent. Weld must re-run `--filter 'SessionDeleteRevokesShares|SessionIndexAndRetention|ShareStore'` + guards on the merged tree.
NIT (no action): revokeForSessionsSql(0) would emit `IN ()`, unreachable from chunk loop (ShareStore.php:81).
