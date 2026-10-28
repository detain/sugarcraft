# C-SEC-8.e REVIEW-1 (r1, reviewer: step-loop)

VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 2 MINOR / 2 NIT)

Re-derived at tip 851b6614a (parent c8e8a1f42 ancestor of master, detain A/C, zero trailers, clean tree, 7 files = declared set, +5/−0 en.php pure-append):
- KeychainTest+ProviderFactoryTest: `OK 132 tests, 360 assertions` EXACT; KeychainTest alone 18T/56A EXACT (2 PHPUnit Deprecations are house-wide — DescriptorInheritanceGuardTest alone shows the same 2).
- Guard set (DescriptorInheritanceGuard, StderrEmitterCensus, EnvRosterDrift, DocFigureProseDrift, CliLangCatalogue): 247T/69882A/F1 — sole red = CliLangCatalogue sorted-run; `sort -c` on the W11-a cli block proves the FIRST disorder at index 21 (`cli.argv.hint.output_formats`, foreign) at BOTH base and tip = base-red byte-identical; failure diffs contain zero keychain lines.
- ContainedPathInventory: 2F standing (names routed/hand-spelled inventories; no Keychain mention) ✓.

Security core — sound:
- argv pin NOT vacuous: seam receives the same $argv resolve() hands to production spawn(); secret absent from every element; real-spawn pins drive private spawn() directly. No stdout on any miss/timeout path (discard proven; timeout test pins stdout=''); stderr read-and-dropped; zero fwrite/echo/error_log in Keychain.php → StderrEmitterCensus genuinely untouched, not evaded.
- `${keychain:${HOME}}` captures literal `${HOME` (stops at first `}`), reaches argv RAW; env pass never sees the placeholder (no recursion; ProviderFactoryTest pins adjacency + OPENAI_KEY non-double-resolve).
- Timeout path: pipes[0] closed pre-loop, [1]/[2] closed before ProcessReaper::terminateAndClose; groupPid recorded while handle alive; spawnSpec `setsid -w` propagates status, group kill reaches grandchild; bounded ladder proven. E646-clean per brief. Guard row = per-site SHORT_VIA_HELPER receipt (not a licence), green.

Deviations JUDGED OK: LaunchNotices::record is the house shelf (Bootstrap:7856 same call); providers build synchronously via chat()→workspace()→backend()→create()→resolveEnvVars BEFORE chat()'s final `withLaunchNotices(launchNotices())` read (:1680) → TUI transcript + /notices show the row; `-p` non-fatal ('' + loud provider auth), E78c design match.

- MINOR-1 (step-local) src/Support/Keychain.php:102-109/156: entry name unvalidated — leading `-` is option-parseable by secret-tool's GLib parser (`-u` absorbed, `--x`→error→miss; macOS `-a <val>` safe), and a JSON-`\u0000`-poisoned name sends a NUL into proc_open argv → PHP 8 ValueError inside the resolveEnv callback = fatal launch, contradicting "never fatal". Fix: in resolve(), after empty-check, treat names matching `/^[-\x00-\x1f\x7f]/` or containing control bytes as a miss-notice without spawning (reuse cli.keychain.miss shape — no new lang key).
- MINOR-2 (step-local) src/Providers/ProviderFactory.php:476-481: REPORT deviation #2 claims the secret-containing-`${VAR}`-expands-downstream tradeoff is "documented in code comment" — no such comment exists. Fix: one sentence on the keychain-pass comment (or ENVIRONMENT.md).
- NIT (out-of-scope) docs/ENVIRONMENT.md: "raised as a launch notice" — true interactively only; headless -p never drains the shelf. Consider "on interactive launches".
- NIT: memo staleness in serve/long-lived hosts is documented ("once per launch per name") — fine; note for C-SEC-8.f (store+lookup same launch will show stale).

Welder carries: durations row candy-code/tests/Support/KeychainTest.php (~0.7s claim; re-measure — two timeout-ladder tests, pair run 5.4s wall is mostly ProviderFactoryTest); +18T/≈+296A figure re-pin from green K-run.
