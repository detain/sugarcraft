# C-AGT-5.e — REVIEW-2 (round-2 cure: constructor-enforced schema/bounds)

VERDICT: CLEAN 0C/0M

## 1. Phantom-first — PASS
- `cat-file` confirms tip 291da3a554a563ebb9708876f7c155d749aeb0e8 exists; branch `cc/C-AGT-5.e`; `master..HEAD` = 1 commit; `git status --porcelain` = 0.
- `%ae` and `%ce` separate greps: both `detain@interserver.net`; `%b` grep -ci co-authored = 0.
- Full commit stat vs base 2a6cd3c1f: 8 files, +1579/−3 — the same declared set r1 vetted (4 src/Automation + 3 tests/Automation + StderrEmitterCensusTest in-step roster flip).
- Amend delta `git diff 2ebd53542 tip`: exactly `WebhookEvent.php` (+116) and `WebhookDispatcherTest.php` (+66) — matches the fixer's claimed scope; no other file touched, no doc lines, lane boundary clean (no suite-figure/durations/dist/composer touches).

## 2. Cure tokens on disk (blob-grep of git show 291da3a55) — CONFIRMED
- `private const SCHEMA` with per-type exact key sets and per-field bound kinds (BOUND_ID 128 / BOUND_OUTCOME 64 / BOUND_NUMBER clamp); ctor line 125 routes `$data` through `self::boundedData($type, $data)` after the unknown-type check; `fieldRefused()` builds the type-mismatch error; pin method `testTheConstructorEnforcesTheSchemaAndBoundsOnEveryPath` present.
- `$data` is now a declared `public readonly array` assigned once (external contract unchanged); class docblock truth-flipped to the constructor-enforced law.

## 3. Gates — REPRODUCED EXACT (plain pipe, cwd=worktree candy-code, -c phpunit.xml, env -u LINES -u COLUMNS; PtyRunToolTest never run)
- `--filter 'OutboundWebhook|WebhookSigner|WebhookDispatcher'`: **OK 55T/177A** (== claim; 54 + the 1 new pin, +11A).
- `--filter 'Automation'`: **OK 56T/183A** (== claim).
- `--filter 'StderrEmitterCensus|GlobDialectDifferential|DuplicatedTestHelperDrift'`: **OK 122T/21738A** (== claim). Census green ⇒ the roster row (WebhookDispatcher 2 warn sites) is unchanged-and-still-true bidirectionally — the cure added zero emitters; no glob-shaped literals; no test-helper duplication (pin is one inline method; src helpers shared, not copied).
- "PHPUnit Deprecations: 2" = the base-wide `@dataProvider` docblock idiom in OutboundWebhookConfigTest (r1-established, not lane-attributable).

## 4. New attack surface adjudicated
- **Throw shape vs async containment:** grep `new WebhookEvent(` in candy-code/src = 0 hits; the only construction sites are the four factories (`new self(` ×4) whose arguments are typed `string|int|float` and pre-normalized, and the dispatcher never builds events — it consumes them. So the ctor's fail-closed refusals fire synchronously at authoring/construction call sites (a 5.f integration bug surfaces at the caller before the queue), NEVER inside the async send/retry path. Containment property holds; no worker can die on a constructor throw.
- **Factory payloads always pass the schema:** hand-traced turnCompleted: keys match SCHEMA exactly; match arms hit the float/int legs before default; clip of an already-clipped id is identity; `max(0.0,round)` idempotent. Extra/missing/off-kind all refuse naming the field.
- **Byte-parity leg non-vacuity:** under the claimed mutation (`$this->data = $data`) the parity loop stays green (identity witness) while the direct 500→128 leg reddens with exactly the claimed "500 is identical to 128" shape — so the pin method as a whole is a faithful red-before/green-after discriminator; the parity legs are future-drift tripwires (a SCHEMA-vs-factory key rename throws inside the minting call → loud red). Honest composition, disclosed here, not a finding.
- **Clamp type preservation:** `−3.0 → max(0.0,−3.0)` stays float 0.0; `−7 → max(0,−7)` stays int 0; pinned via assertSame through a JSON roundtrip with `JSON_PRESERVE_ZERO_FRACTION` (0.0 encodes "0.0", 0 encodes "0") — wire bytes of factory-shaped data untouched.
- **E646:** WebhookEvent is a pure value type — zero timers introduced anywhere in the cure. Connect 2.0s / POST 10.0s caps unchanged and E646-legal (webhook boundary, not an LLM request timeout).
- **Conventions:** declare(strict_types=1) first line; one class per file (each src/Automation file has exactly one `final class` declaration at grep census); PSR-12 shape; refusal messages are runtime English consistent with the fleet idiom (no Lang::t needed for these dev-facing InvalidArgumentException texts); no denial-prefix-shaped literals.

## 5. r1 MINOR disposition + welder duty list
- **MINOR-1 (step-local): CLOSED by this cure** — the constructor now enforces the type-level bound on every path in, exactly option (b) of the r1 prescription, with a mutation-discriminating pin.
- **MINOR-2 (out-of-scope, welder): RESOLVES IN THE WELD GLANCE, no action owed** — `candy-code/composer.json:41` requires `react/http: "^1.11"`; `Browser::withTimeout()` landed in react/http 1.10, so the committed floor already satisfies the two `withTimeout` sites. Welder may stamp it satisfied.
- **Durations rows:** the commit contains exactly the three new phpunit files `tests/Automation/{OutboundWebhookConfigTest,WebhookSignerTest,WebhookDispatcherTest}.php` — matches the welder duty list; no fourth test file exists (the census flip is to an existing file).
- **Foreign carry (report-only, not lane-attributable):** ReadmeSuiteFigureDrift staleness pair and the `thirty-seven` DocFigure arm (cure `9fbb73173` not in this base) ride the lane as before; DreamPassTest name-collide not hit by these filters.

## Verdict
The cure removes the root cause it names (bounds lived only in factory signatures; now they live in the type), changes no wire bytes, adds no emitters/timers/spawn sites, reproduces every gate figure byte-exact, and its pin is a proven red-before/green-after discriminator. Round-1 CLEAN stands; step loop exit condition (0 critical / 0 major / step-local minors fixed) is met.

VERDICT: CLEAN 0C/0M
