# C-SEC-8.f — `candy-code login --keychain` — LANDED

## Commits (branch cc/C-SEC-8.f, worktree /home/sites/cc-cmp-C-SEC-8.f, base 6d0c79851)
- `ba842a702` skeleton — declared test file LoginKeychainTest (cat-file: commit; %ae/%ce detain, zero Co-Authored)
- `f491d8187d26fb26979ab239fc90d6dd98b77c8f` full step (cat-file: commit; %ae=1 + %ce=1 detain greps; Co-Authored grep=0; tree clean at tip)

## Diffstat (base→tip)
7 files, +679/−24: `src/Support/Keychain.php` +150, `src/Cli/Subcommands.php` +55/−…, `src/Cli/ParsedArgs.php` +7, `tests/Cli/LoginKeychainTest.php` +482 (new), `tests/Support/DescriptorInheritanceGuardTest.php` +11, `README.md` +3, `lang/en.php` +6.

## Shape
- `Keychain::store(name, secret)`: Linux `secret-tool store service candy-code account <name>`, Darwin `security add-generic-password -U -s candy-code -a <name> -w`. SECRET TRAVELS ON STDIN via the widened `spawn(argv, ?stdinPayload)` — never argv; newline-terminated (secret-tool's line reader), stdin closed on every path. Verdict = child exit code (0→ok; non-zero→failed; missing binary→missing; 5 s budget→timeout), matching the class's read-side doctrine. Guards before any spawn: name regex + empty/oversized-secret refusal (`refused`), non-Linux/Darwin → `unsupported`. Success invalidates the stale lookup memo. `-U` makes Darwin idempotent (replace-in-place).
- `login <provider> --keychain`: flag scoped via `ParsedArgs::SUBCOMMAND_FLAGS['login']` (parser's table branch — no ArgvParser edit; `--keychain=x` → "takes no value" usage error at parser). Only the five storing arms accept it (passthrough/ambient → failUsage EXIT_CONFIG, refusal precedes prompt). On store-ok the config gets `"apiKey": "${keychain:<provider>}"` (ProviderFactory's legible-reference vocabulary); every failure/verification leg exits non-zero with the config BYTE-UNTOUCHED. Sibling-key preservation rides the pre-existing merge (`...$block` spread) — the C-PRV whole-block-replace defect (PRV-4-P1) is NOT touched by this step.
- Lang: 6 new keys `cli.login.keychain.*` ONLY (en.php — the sole locale file that exists in candy-code, parity trivially holds).
- README: login line + 3 continuation comment lines inside the Subcommands fenced block; LINE-DISJOINT from C-PRV-4.b's provider-docs regions (no collision).

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- `--filter 'LoginKeychain'`: 22T/92A OK (2 pre-existing PHPUnit deprecations — LoginSubcommandTest alone reports the same 2).
- `--filter 'LoginKeychain|LoginSubcommand|ReadmeRosterDrift|Subcommands|Completion|DescriptorInheritance'`: 222T/6583A/**1F** — the sole red is `BinCandyCodeDispatchTest::testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions` demanding `--worktree` in OPTIONS: **standing base-red, red at origin/master itself** (parser literal `ArgvParser.php:800` from the C-AGT-6.c weld with no OPTIONS row on master or origin). Discrimination: my diff is ArgvParser-byte-empty and the derived `$arg === '--*'` flag set is FLAG-SET-IDENTICAL base-vs-tip; no OPTIONS/--worktree line in my Subcommands hunks (all hunks in imports + login regions). NOT introduced, NOT fixable in-scope (the flag's help/dialect ripple is C-AGT-6.c's defect) — reported per rule, not edited.
- `--filter 'Keychain'`: 48T/178A OK. `--filter 'DuplicatedTestHelperDrift|GlobDialect'`: 28T/567A OK (byte-copied helpers drift-green; glob corpus figure un-moved).
- php -l: all 7 changed files PASS.
- Staleness pair (ReadmeSuiteFigureDrift) untouched by design (never in these filters' green set expectation; forbidden file).

## Deviations & disclosures
1. **No completion-script change.** The design note's "completion roster" phrase is refuted by live measurement: `BinCandyCodeDispatchTest:1676` parses every globally-offered flag, and `--keychain` is NOT a global flag (adding it to OPTIONS reddens that pin). Subcommand-scoped flags (--channel/--force/--url/--write) are offered by no completion dialect today; --keychain follows that existing, guarded convention. The completion roster pins went green in-step precisely by staying un-moved (the one red is the unrelated --worktree base-red above).
2. **No new proc_open site.** `store()` reuses the single `spawn()`; DescriptorInheritanceGuard roster row `Support/Keychain.php::spawn` keeps count=1, reason text extended in-step to cover the stdin-written path.
3. **Orphan-entry honesty:** if the keychain write succeeds but a LATER refusal fires (type_mismatch, corrupt config), the keychain entry exists while the config stays byte-identical — pinned by testTypeMismatchRefusalAfterStore (stores==1). -U idempotency makes a re-run harmless.
4. Keychain real-dial law: zero tests touch a real keychain — seam recorders + the PATH-shim fake `secret-tool` (SystemClipboardTest idiom: putenv PATH=temp bin, VARS snapshot/restore; shim builtins-only since the child's PATH is the bin dir alone). Shim legs prove stdin-delivery/exit-code verdicts/missing-binary AND that the read path shares the same spawn.
5. DUR carry for the gate: **+1 test file `candy-code/tests/Cli/LoginKeychainTest.php`** (gate mints the durations.tsv row; lane never touches it).
6. Deps: builds on C-SEC-8.e (cc3be741c + 6a1bbc540 — verified ancestors of base).
