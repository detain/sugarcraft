# C-EXT-4.c — STOP: fix needs one out-of-list file (CommandSpec.php), else a 15-field copy

**Verdict: BLOCKED, zero edits, zero commits (branch cc/C-EXT-4.c == base cd87233d0, tree clean).**

## Root cause — confirmed on disk
`ForeignCommandDiscovery::bundleDirs()` (:144-155) yields `dir => [anchoredIn, tier, source]` (no bundle name); `walk()` (:217-229) keys rows by bare file name, so two bundles shipping `review.md` collapse last-write-wins. Confirmed exactly as briefed.

## Why the step's files alone cannot carry the fix (measured, this tree)
- Names/display/dispatch all read `$spec->name`, not the map key: `Chat::slashCommandRows()` :16731-16739 does `array_values()`, `App.php` :4809 runs `runCommand('/'.$spec->name)`, `PaletteCatalog` :98 prints it. So the qualified name MUST live on the spec — keying alone fabricates a broken palette success-path (laws forbid).
- `CommandSpec::NAME_PATTERN` rejects colons. Measured: `fromFile(..., "revkit:rev")` throws `InvalidArgumentException: Unsafe command name: revkit:rev` → the row is silently skipped in `loadFromDirectory` — WORSE than the collapse. `revkit/review` is ACCEPTED (slash is the existing subdir separator), but the design + plan §6 chose the **colon** form ("`/<bundle>:<cmd>` resolves directly"; C-EXT-5.c keybinds and opencode parity both speak colon), so slash-spelling deviates from spec.
- The ctor is promoted-only with empty body (no validation), so a keyless in-scope route exists — but stamping requires a `new CommandSpec(...)` 15-named-field copy of the parsed spec in CommandLoader (no with*/copy-ctor exists). That copy drifts silently when any field is added; against §1 "root-cause fixes" it is the wrong shape.
- `TurnController::resolveCustomCommand()` (:408-441) already tries whole-name before colon-argument → zero code change needed there; its docblock :399-403 "( : is not legal in a command file's name)" needs a truth-flip once colons exist (its own file, §1.8 pairs it with this step anyway).

## Ask (one word from the lead unblocks the whole lane)
Allow `src/Commands/CommandSpec.php` in this commit: widen NAME_PATTERN to admit ONE leading `[A-Za-z0-9][A-Za-z0-9_-]*:` segment (colon is no less path-safe than the already-allowed `/`; traversal shapes still fail) + 1-sentence docblock update. ~4 lines, root cause, no drift hazard. Then resume this lane.

## Prepared shape (ready to execute on unblock)
- `bundleDirs()`: 4th tuple element = `$bundle->name` (bundle dirs only).
- `walk()`: passes it to `loadFromDirectory(..., ?string $bundle = null)`; CommandLoader "name stamp": qualified key/spec-name = `sanitize($bundle).":".name`, `commandSources` keyed qualified; bundle segment sanitized to pattern charset, empty-after-sanitize disclosed-and-skipped.
- Flat alias in `walk()` post-merge: added only when exactly one bundle claimed that bare name and no plain-foreign row occupies it (pin both polarities: unclaimed alias survives; second claim kills it — mutation-prove the shadowing law).
- NEW `tests/Commands/BundleCommandNamespaceTest.php` (two bundles × review.md both survive; `/bundle:cmd` whole-name resolves; alias polarities). Existing `ForeignCommandDiscoveryTest` / `BundleSurfaceWiringTest` pins analyzed to hold unchanged (verify at run).
- Docs: PLUGINS.md (~:104 commands area) + COMMANDS.md (bundle row ~:28) naming rows. `tools/gen-command-docs.php` lists no file rows (grep: zero custom/bundle refs) → §1.8 regen is a no-op, verified.
- Carry for welder: durations.tsv row for the new test file (lane-forbidden file).

---

# C-EXT-4.c — LANDED (fresh incarnation, lead unblocked CommandSpec.php per ruling 2026-10-29)

The stop-record above is the predecessor's honest BLOCKED report; its "Prepared shape" is
what this commit ships, executed exactly on the unblock.

**Fix.** Bundle rows are keyed `<bundle>:<file>` end to end: `bundleDirs()` threads the
bundle name as a 4th tuple slot; `walk()` passes it to `loadFromDirectory()`, which folds
the name via `bundleSegment()` (non-charset runs → `-`, leading non-alnum and trailing `-`
trimmed), keys the row `<segment>:<name>` on BOTH the map key and `CommandSpec::$name`
(unique-name law of `CommandRegistry::filter()`), and STAGES the flat spelling in
`$bundleAliasRows`. `walk()`'s final pass admits a flat alias only when exactly one bundle
claimed it AND no plain foreign row of any tier occupies it — so two bundles shipping
`review.md` keep `revA:review` + `revB:review` distinct and the ambiguous flat name stands
for neither; PLG-1's "the operator's tree wins" becomes a property, not a band-order
accident. A bundle name that folds to nothing refuses each file with a disclosed skip
record rather than sneaking rows in unqualified. `CommandSpec::NAME_PATTERN` widened
(lead-approved deviation, ruling 2026-10-29): ONE leading `[A-Za-z0-9][A-Za-z0-9_-]*:`
segment before the existing tail — traversal shapes still fail, and the
docs/PERMISSIONS.md:294 "tool input fields are not shell commands" boundary is untouched
(docblock says so). `TurnController::resolveCustomCommand()` needed ZERO behavior change
(CONFIRMED at tip: whole-name try precedes the colon-invocation arm); its docblock lie
("`:` is not legal in a command file's name") flipped §1.8-style. Menu survival pinned by
source-scan: `slashMenuPrefix()` strips only the leading slash, so colon rows reach
dispatch whole.

**In-step flips (§1.8).** `CommandLoader` DEBUG_REFUSALS_ENV docblock seven→eight report
sites (new per-file unnamed-bundle skip) + `docs/ENVIRONMENT.md` DEBUG_COMMANDS row
recount + `DocFigureProseDriftTest` arms (word map +eight, enum, 3-per-file split,
same-eight-sites, "Four of the eight"); `ProjectTierRefusalInventoryTest` 4→5 params;
docs/COMMANDS.md + docs/PLUGINS.md namespacing prose; `tools/gen-command-docs.php` regen
is a no-op (lists no custom-file rows — verified).

**Gate (targeted, this tree).** `vendor/bin/phpunit -c phpunit.xml --filter
'BundleCommandNamespace|ForeignCommand|BundleSurface|CommandSpec|CommandLoader|CustomCommand|GenCommandDocs|ReadmeRoster|DocFigure'`
→ **274 tests / 4879 assertions / 1 failure / 2 PHPUnit deprecations**. The lone failure
is FOREIGN base-red: `DocFigureProseDriftTest::testArchitecturePageCountersAreLiveOrDigitless`
— ARCHITECTURE.md at cd87233d0 itself says "dispatch arms for 59" while live
`slashCommands()` counts 60 (proven: `git show cd87233d0:...ARCHITECTURE.md` and my diff
touches zero builtin-commands/CommandRegistry files). Not fixed here (out of ownership);
welder/closeout owns it. The 2 deprecations are pre-existing private data providers in
`AccessorDeprecationTest`. `ForeignCommandDiscoveryTest` and `BundleSurfaceWiringTest`
hold UNCHANGED (flat aliases survive unclaimed; plain rows own their names) — pins did not
move, so those files were not touched.

**Mutation proofs.** M1 revert-the-keying (thread `null` bundle in `walk()` → last-write-wins
world): BundleCommandNamespaceTest 6/7 red. M2 neuter the unclaimed guard (admit flat
alias even when a plain row owns it): exactly 1 red (`testAPlainForeignRowOfAnyTierSuppressesTheFlatAlias`).
Both cp-restored, md5-verified.

**Welder duties.** NEW test file `tests/Commands/BundleCommandNamespaceTest.php` (7 tests)
needs a `scripts/parallel-tests-durations.tsv` row (lane forbidden from that file) and the
suite-figure re-pin (+7 tests) at gate.

---

## FIX ROUND 1 (review-1, 2026-10-28)

**MINOR (step-local) — sources staged for refused aliases.** Cured as the review
prescribes: the staging pass in `loadFromDirectory()` no longer writes
`commandSources[$flat]` (the line after the `bundleAliasRows` stage is deleted);
the flat attribution is copied at ADMISSION by the new public
`CommandLoader::admitBundleAliasSource($flat, $qualified)`, called from
`ForeignCommandDiscovery::walk()`'s admission leg inside
`isset($commands[$qualified])` — it copies the qualified row's path (same file)
and writes nothing without it, mirroring "an alias may not outlive its row".
The `bundleAliasRows` docblock claim that refused candidates' source entries
"sit unused, keyed by names nothing consults" is rewritten truthful (the
mixed-case consult was exactly the defect face).

**Pins.** `BundleCommandNamespaceTest` +2 methods (reflection reads the private
`commandSources` map — its live consumers are all loadAll-after-native-rewrite,
so the map contents are today's observable face):
`testRefusedFlatAliasesLeaveNoAttributionEntry` (two-bundle collision: both
qualified sources kept, no `review` key);
`testFlatAliasAttributionIsWrittenAtAdmissionNotStaging` (admitted alias shares
its row's path; the reviewer's mixed case — project-plain winner walked BEFORE
the user bundle — keeps its own source, with a positive control that the bundle
qualified row IS attributed).
Mutations: full revert of the cure (restore staged write + drop admit call) →
EXACTLY these 2 reds, 7 old tests green; admission-copy-only removal → exactly
1 red (`...WrittenAtAdmission...`). cp-backups + md5-verified restores.

**NITs (docs-only, taken).** `bundleSegment()` docblock now discloses the
non-injective fold (`a.b`/`a-b` share segment `a-b` → segment-level
last-write-wins for exotic pairs; disambiguation ruled out-of-scope); the
staging comment states the double-parse cost (qualified + flat spec) as
accepted.

**Gate (targeted, post-fix, this tree).** `BundleCommandNamespaceTest`
isolated: 9T/62A green (same 2 pre-existing `AccessorDeprecationTest`-style
deprecations family). Brief filter re-derived:
**276 tests / 4891 assertions / 1 failure / 2 deprecations** — exact +2T/+12A
on review-1's 274/4879 shape; sole red remains the FOREIGN-standing
`DocFigureProseDriftTest::testArchitecturePageCountersAreLiveOrDigitless`
(ARCHITECTURE 59-vs-60, cure a2d5d0e3f on master, welder absorbs — not
chased, per review).

**Welder duties updated.** durations.tsv row for `BundleCommandNamespaceTest`
is now 9 tests (was 7); suite-figure +9T re-pin.
