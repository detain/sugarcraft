# W35 FIX-1 — MAJOR-1 cure: HookWebhooks positional-ctor break from 6.g canvas insert

- Finding: `lanes/W35/WAVE-REVIEW-1.md` MAJOR-1 (:59) + FINAL (:74): C-TOOL-6.g pick 9d1cf26ca inserted `?\Closure $canvas = null` between `$share` and `$webhooks` in the Server ctor/`new()` positional signature (src/Server/Server.php:151-161), updating only src/Cli/Serve.php:499; HookWebhooksTest.php:86 and HookWebhooksBodyCapStackTest.php pass `HookWebhooks::new(...)` positionally at slot #10 → binds `$canvas` → TypeError. 21T/125A/11E deterministic isolated.
- Scope lock honored: ONLY `candy-code/tests/Server/HookWebhooksTest.php` + `candy-code/tests/Server/HookWebhooksBodyCapStackTest.php`. Cure form = named arg `webhooks:` (the review's offered option; drift-proof against further positional inserts — both call sites end their positional run at slot #9, so the named arg appends cleanly).
- No src/ edits, no new tests/files, no figure files touched (suite-figure.json + durations.tsv byte-untouched — zero T delta).

## Step 1 — pre-cure red proof

```
$ env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter '(HookWebhooksTest|HookWebhooksBodyCapStackTest)'
TypeError: SugarCraft\Code\Server\Server::new(): Argument #10 ($canvas) must be of type
  ?Closure, SugarCraft\Code\Server\Http\HookWebhooks given
    called in tests/Server/HookWebhooksTest.php on line 86                x8
    called in tests/Server/HookWebhooksBodyCapStackTest.php on line 95    x3
ERRORS!
Tests: 21, Assertions: 125, Errors: 11, PHPUnit Deprecations: 2.
```

Reproduces MAJOR-1 exactly; all 11 frames from the two named call sites.

## Step 2 — cure (diff: +9/-2 across the two files)

- `HookWebhooksTest.php` `serve()` (:86): slot-#10 positional → `webhooks: HookWebhooks::new($hooks, $run ?? $this->run, $clock)` + 3-line why-comment.
- `HookWebhooksBodyCapStackTest.php` setUp (:95): slot-#10 positional → `webhooks: HookWebhooks::new(` + 2-line why-comment.
- Third `Server::new(` site checked: `HookWebhooksTest.php:111` (testTheOrdinaryServerNeverMountsTheRoute) passes only 5 positionals — no webhooks at all, never an error frame, correctly untouched (disclosed per the scope-lock's third-call-site clause).
- php -l both files: No syntax errors detected.

## Step 3 — gate (post-cure, targeted only)

```
$ env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter '(HookWebhooksTest|HookWebhooksBodyCapStackTest)'
Tests: 21, Assertions: 237, PHPUnit Deprecations: 2.   (Errors: 0 — OK)
```

FIGURE DISAGREEMENT (evidence-backed): FINAL :74 predicts "restores 21T/125A/0E". 125A is the RED run's assertion count — the 11 ctor-aborted tests never reach their assertions; the true GREEN floor is 21T/**237A**/0E (deprecations 2 pre-existing in both runs). The cure is correct and the errors/tests figures match the prediction exactly; only the A figure in the reviewer's expectation was a mid-broken-state measurement. No re-pin owed either way (tests count unchanged).

Drift guards touched by the comment additions, re-run: `--filter '(DuplicatedTestHelperDriftTest|TreeWideGuardRosterTest)'` → Tests: 31, Assertions: 4211 — byte-exact vs WAVE-REVIEW-1 duty-6 guards line.

## Step 4 — commit

- SHA: `7267e6f99` (git cat-file -t → commit; base tip 0fbd95456 = W36 fixer landed mid-run, normal sibling movement).
- Subject: `W35: cure HookWebhooks positional-ctor break from 6.g canvas insert (r1 MAJOR-1)`
- `git show --stat`: exactly the two scoped files, +9/-2. Foreign tree dirt (M .opencode/agents/*, lanes/*/WELD.md, candy-code/tests/Support/ReadPathCensusTest.php) NOT staged.
- Author verification (SEPARATE greps): `git log -1 --format=%ae | grep -c detain` = 1; `git log -1 --format=%ce | grep -c detain` = 1; `git log -1 --format=%B | grep -c 'Co-Authored'` = 0.
- NOT pushed (law).

## Keeper (per MAJOR-1)

Re-runnable after any future Server signature insert: `cd candy-code && env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter '(HookWebhooksTest|HookWebhooksBodyCapStackTest)'` → expect 21T/237A/0E (named-arg form makes the ctor calls immune to slot drift; the green A floor is 237, not the review's 125).
