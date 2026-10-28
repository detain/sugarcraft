# C-AGT-5.e — FIX-1 (fixer rounds)

## Round 2 — MINOR-1 (step-local): constructor bounds

VERDICT: FIXED. Round-2 tip 291da3a554a563ebb9708876f7c155d749aeb0e8 (amended in place; still ONE commit on base 2a6cd3c1f; %ae/%ce both detain@interserver.net, zero trailers; tree clean).

Finding (REVIEW-1 MINOR-1): `WebhookEvent::__construct` was public with an
unvalidated `$data` — the 128/64 clip law lived only in the four factory
signatures, so `new WebhookEvent($type, $t, ['note' => $fragment])` could
smuggle an unbounded, off-schema payload through the signer/queue.

Cure (`candy-code/src/Automation/WebhookEvent.php`):
- New `private const SCHEMA` — per-type exact key set with per-field bound
  kinds (`BOUND_ID` → clip to MAX_ID_CHARS=128, `BOUND_OUTCOME` → clip to
  MAX_OUTCOME_CHARS=64, `BOUND_NUMBER` → clamp at zero, float stays float /
  int stays int so the JSON bytes are untouched).
- `private static function boundedData(string $type, array $data): array`
  (single shared helper; `fieldRefused()` builds the type-mismatch error):
  refuses extra keys and missing keys fail-closed by name, refuses
  off-kind value types, then clips/trims/clamps through the SAME `clip()`
  the factories use. Constructor calls it after the unknown-type check;
  `$data` is no longer a promoted property (declared readonly, assigned
  once, external contract unchanged — only `json()` reads it).
- Factories keep their event-name/shape checks; their clip/clamp calls are
  now the idempotent front of the same normalization. Class docblock
  truth-flipped ("bound where it clips" paragraph now states the bounds are
  constructor-enforced, off-schema refuses).

Byte-parity: proved in-suite — the new pin re-normalizes each factory
payload (`new WebhookEvent($minted->type, $minted->timestamp, $minted->data)`)
and asserts `json()` is identical for ALL FOUR event types.

Pin (`candy-code/tests/Automation/WebhookDispatcherTest.php::testTheConstructorEnforcesTheSchemaAndBoundsOnEveryPath`,
one method): direct ctor with 500-char session_id + 200-char outcome →
clipped to 128/64; '  nightly  ' trimmed; −3.0/−7 clamped to 0; extra key
`note` throws naming it; missing `tool_calls` throws naming it; the 4-type
parity loop above.

Red-before: pin run against the round-1 `WebhookEvent.php` (cp-harness from
`git show HEAD:` copy, /tmp backups, no stash) → FAIL
"Failed asserting that 500 is identical to 128". Mutation-reason: neuter the
ctor call to `$this->data = $data` → exactly this pin reddens (1F), restore
cp+md5 clean.

Gates (cwd worktree candy-code, -c phpunit.xml, plain pipe, env -u LINES -u
COLUMNS):
- `--filter 'OutboundWebhook|WebhookSigner|WebhookDispatcher'` → OK 55T/177A (54+1 as brief predicted).
- `--filter 'Automation'` → OK 56T/183A.
- `--filter 'StderrEmitterCensus|GlobDialectDifferential|DuplicatedTestHelperDrift'` → OK 122T/21738A (zero emitters touched; no glob-shaped literals; no test-helper duplication — the new pin is inline, and the src helpers are shared, not copied).
- "PHPUnit Deprecations: 2" in every run = base-wide `@dataProvider` docblock idiom in OutboundWebhookConfigTest, pre-existing per REVIEW-1.

Untouched (lane boundary): suite-figure.json, durations.tsv, dist, master.
MINOR-2 (react/http `withTimeout` floor glance + the 3 durations rows + the
foreign `thirty-seven` DocFigure arm) stays welder duty.
