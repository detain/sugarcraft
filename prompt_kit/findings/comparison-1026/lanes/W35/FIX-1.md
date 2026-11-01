# W35 FIX-1 — MAJOR-1 (HookWebhooks positional-ctor break from 6.g canvas insert)

Date: 2026-11-01. Fixer lane: W35 wave-loop (round 1). Task anchor: `0fbd954563547a56ef3f82c0d91069098ab1c031`.

## Verdict: FIXED (cure landed on master as `7267e6f99`; this lane reproduced the defect, verified the cure, and holds NO new commit — see Race record)

## Root cause (confirmed live, verbatim finding text)
C-TOOL-6.g pick `9d1cf26ca` inserted `?\Closure $canvas = null` into `Server::new()`/ctor positional signature between `$share` and `$webhooks` (`candy-code/src/Server/Server.php:151-161`) and updated only `src/Cli/Serve.php:499`; the two webhook test call sites still passed `HookWebhooks::new(...)` as positional arg #10 → bound `$canvas` → TypeError. Reproduction at the pre-cure tree (this session, filter `(HookWebhooksTest|HookWebhooksBodyCapStackTest)`):

```
TypeError: SugarCraft\Code\Server\Server::new(): Argument #10 ($canvas) must be of type ?Closure, SugarCraft\Code\Server\Http\HookWebhooks given, called in /home/sites/sugarcraft/candy-code/tests/Server/HookWebhooksTest.php on line 86
...
ERRORS!
Tests: 21, Assertions: 125, Errors: 11, PHPUnit Deprecations: 2.
```

21T/125A/11E deterministic — EXACTLY the finding's figures.

## Landed cure
`7267e6f99` — subject `W35: cure HookWebhooks positional-ctor break from 6.g canvas insert (r1 MAJOR-1)` (03:10:24 -0500). Diff = the finding's prescribed option-2 shape in BOTH files (`null,` fills the `$canvas` slot AND the call switches to named `webhooks:` — belt-and-suspenders, drift-proof):

- `candy-code/tests/Server/HookWebhooksTest.php`: `HookWebhooks::new($hooks, $run ?? $this->run, $clock),` → `null,` + `webhooks: HookWebhooks::new(...)`.
- `candy-code/tests/Server/HookWebhooksBodyCapStackTest.php`: `null,` + `webhooks: HookWebhooks::new(...)`.
- `--stat`: 2 files, +9/−2. Production `Server.php`/`Serve.php` untouched (verified on the diff).
- Author law: `%ae` grep = detain@interserver.net, `%ce` grep = detain@interserver.net (separate fields, both pass); `grep -c Co-Authored-By` on the body = 0.

## Race record (honesty ledger)
This lane independently applied the finding's option-1 cure (one extra `null,` before the HookWebhooks arg, both files) and its targeted run went green; mid-session `git status` showed candy-code CLEAN with the named-arg version on disk — the parallel cure `7267e6f99` had landed and the tree moved (tip → `57d92a8f3`, in-flight C-WEB-5.f welds + run-state stamps). Per standing "re-read master and adapt" rule, my duplicate edit was dropped; NO second commit made (curing an already-absorbed finding twice = twin double-commit hazard). Net state: finding cured once, on master, verified below.

## Gates at tip 57d92a8f3 (with cure) — verbatim
Targeted (keeper gate, `candy-code` dir, plain pipe, `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml`):

```
$ --filter '(HookWebhooksTest|HookWebhooksBodyCapStackTest)'   TARGETED_EXIT=0
OK, but there were issues!
Tests: 21, Assertions: 238, PHPUnit Deprecations: 2.
```

21T / 0F / 0E — GREEN. Note on the figure: the finding predicted "restores 21T/125A/0E"; 125A was the BROKEN run's artifact (the 11 TypeErrors aborted those tests mid-flight, so their later assertions never ran). A completed run executes 237–238 assertions (±1 timing-dependent rate-window legs observed across two runs). Tests/Errors/Failures match; Assertions strictly greater — disclosed, not forced. The two PHPUnit Deprecations are environmental noise (present in the red baseline run identically; exit 0).

## Broader family — verbatim
```
$ --filter 'Canvas|Serve|Hook'   BROAD_EXIT=1
FAILURES!
Tests: 3306, Assertions: 25761, Failures: 5, PHPUnit Deprecations: 2.
```
Both HookWebhooks classes are GREEN inside this family (absent from the failure list). The 5 failures are FOREIGN to this finding and pre-date/are independent of the cure — proof: with the two hook files reverted to HEAD, `--filter '(CommandFilterFullQueryTest|MemoryPromptWiringTest|LspLauncherAutoDetectTest|AttachPagingTest)'` → `Tests: 50, Assertions: 213, Failures: 5` with the byte-identical failure set (`/tmp/opencode/w35fix-baseline-5.log`). Disposition (NOT touched here — fix-only-the-listed-findings law):
- `AttachPagingTest` x2 ("the server has no session …"): §7 accepted-foreign roster (base-proven).
- `CommandFilterFullQueryTest` ('recap' row-set), `MemoryPromptWiringTest` (`withClientCallSink`/`withClientCaller` builder roster), `LspLauncherAutoDetectTest` (`'sandbox' => true` key): test-roster drift from vocab not introduced within `0fbd95456..57d92a8f3` (git log -S over `candy-code/src` finds no introducer in-range) → pre-existing, routed to lead §7 for owner-lane re-pins.
- (Test count grew 3292→3306 between my two broad runs because in-flight C-WEB-5.f welds landed mid-session; failure SET identical.)

## Ledger
- §3 W35 close row: hook gate now green on master → MAJOR-1 unblocked for wave close (lead duty).
- No suite-figure.json / durations.tsv / dist edits by this lane (none needed: no test file added; 7267e6f99 changed only 2 existing files' call sites).
