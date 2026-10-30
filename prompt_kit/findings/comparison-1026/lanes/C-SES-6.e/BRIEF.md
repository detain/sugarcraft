# C-SES-6.e — Update-available notice (W26, gap, effort M)

Authoritative spec: `prompt_kit/findings/comparison-1026/design-ses.md` lines 622-648 (read it yourself; it wins over this file). Plan §1, §2, §6 — §6.3 owner rulings are ALL answered (default on for phar installs, env opt-out mirroring the price-DB refresh).

Dep: C-SES-5.a (already welded — the release-list pagination + `SelfUpdate::selectRelease()`/`selectDevRelease()` you must reuse; do NOT write a second fetcher).

## Lead addenda (things this run has already been bitten by)

1. **Help-screen roster law (NEW, durable).** This lane introduces the env var `CANDY_CODE_NO_UPDATE_CHECK`. A lane adding a `getenv`/env var MUST flip the Help roster in BOTH `candy-code/src/Cli/Help.php` and the `lang/en.php` roster, or `EnvRosterDriftTest` / `testEveryRosterVariableIsDocumentedOnTheHelpScreenOrExcludedByName[...]` goes red at the weld (the C-PRV-3.d r1 reviewer missed exactly this and the welder had to cure it). Include that arm in your own gate filter.
2. **Second non-LLM network call = disclosure duty.** `docs/ENVIRONMENT.md` gets the variable row AND the `README.md` telemetry/network paragraph must name this call beside the LiteLLM price refresh. A privacy/network claim that the code contradicts is reviewed as a MAJOR.
3. **Money/trust laws in force:** the cache file `~/.candy-code/cache/update-check.json` is written **0600** through the repo's existing owned-home + atomic-write helpers (no bare `file_put_contents`); the fetched release list is UNTRUSTED content — never interpolate a server-supplied string into anything executed, and keep the painted notice bounded/`Sanitize`-consistent with how `StartupSummary` already renders other rows.
4. **No child, no fork, no timer budget misuse.** The design says async on the TUI loop with `React\Http\Browser` — keep it that way (no `proc_open`, so no child-lifetime roster row). E646 forbids a blanket wall-clock timeout on an LLM call; this is NOT an LLM call, but the check must still never block `view()` — the whole point is `cachedNotice()` reads the PREVIOUS check's cache.
5. **By-design staleness must be PINNED, not hidden:** the first launch after a stale cache only refreshes the cache; the notice appears on the NEXT launch. Pin that two-launch behaviour explicitly so a later "fix" cannot silently make `view()` wait on the network.
6. **Tests must be hermetic** — loopback/localhost server or a transport double, never a real GitHub dial; never write the real `~/.candy-code` (use the sandboxed-HOME idiom the sibling tests already use).
7. **GlobDialect law**: no glob-shaped string literals in test files (they drift the forbidden `src/Util/PathGlob.php` figure).
8. Any NEW `*Test.php` (`tests/Cli/UpdateCheckTest.php`) must be listed in your report — the welder mints its `scripts/parallel-tests-durations.tsv` row; you must NOT edit that file or `suite-figure.json`.

## Regions (serialized)

`[bootstrap:chat] [lang:startup.update.*] [docs:ENVIRONMENT.md] [docs:README.md] [hot:candy-code/src/Support/StartupSummary.php]`

Collision notice: `Bootstrap.php`, `lang/en.php`, `README.md` and `ENVIRONMENT.md` are shared with live lanes (C-TUI-3.a `/copy`, C-SES-8.e `usage`, C-SEC-7.a docs). Stay inside your declared edits, keep hunks minimal, and REPORT a collision rather than widening.
