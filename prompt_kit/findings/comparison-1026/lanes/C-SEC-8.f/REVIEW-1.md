# C-SEC-8.f review 1 — `candy-code login --keychain` @ f491d8187

VERDICT: FINDINGS

1. MAJOR — `candy-code/tests/Cli/StderrEmitterCensusTest.php:575` — the lane's new `candy-code: `
   prefixed refusal (`Subcommands.php:1019`, the not_key door) moves the src scan 40→41 and the
   roster was not updated in-step: the required filter is RED with a LANE-INTRODUCED failure
   (verified on the blobs: `git show master:.../Subcommands.php | grep -c "'candy-code: "` = 40,
   tip = 41). Fix: `'src/Cli/Subcommands.php' => 41,` plus a `// +1 in C-SEC-8.f: the
   login --keychain not-a-key-arm refusal ... same stderr-only decision` note in that comment
   family (NUMBER_WORDS already has `forty-one`, no widening). Builder's gate claim is wrong on
   disk: the required filter is 327T/28629A/**2F**, not 222T/6583A/1F "sole red = base-red".
2. MINOR (step-local) — `candy-code/src/Support/Keychain.php:370-378` — the store arm's blocking
   `fwrite($pipes[0], $secret . "\n")` runs before the drain loop, so the deadline at :383 gates
   nothing around it; the only thing keeping the write off a full pipe is
   `MAX_STDOUT_BYTES = 65536`, i.e. exactly Linux's default pipe capacity. A 65535-byte accepted
   secret against a backend that stops draining (wedged secret-service) hangs `login` past the
   5 s wall the class documents. Fix: mint a write bound `private const MAX_SECRET_BYTES = 8192;`
   and guard `strlen($secret) >= self::MAX_SECRET_BYTES` at :128 (reusing the read side's
   output bound for an input bound is the semantic mismatch), keeping the write blocking so a
   partial write can never store a truncated secret.
3. NIT — `candy-code/src/Cli/Subcommands.php:1136` — verdict `refused` (runaway paste) prints the
   generic `cli.login.keychain.failed` sentence whose "try again" advice cannot help; name the
   size bound in the wording.
4. NIT — report-vs-disk: `/tmp/opencode/sec8f-argv-probe.txt` does not exist (no C-SEC-8.f
   evidence dir either); the claim that `array_splice` does the apiKey-only write is also a
   wording error — the mechanism is the pre-existing spread merge at `Subcommands.php:1127`
   (identical line at base). No code impact; the secret-never-in-argv law IS pinned in-repo by
   `tests/Cli/LoginKeychainTest.php:377-398` (child shim records its own `$*` argv + stdin).

Adjudications (all confirmed): completion refutation UPHELD — `--keychain` is dispatched only
through `ParsedArgs::SUBCOMMAND_FLAGS` (`ArgvParser.php:820-836`), so the OPTIONS census leg
(scans `$arg === '--x'` literals in ArgvParser.php) needs no row, and the standalone-parse leg
(`BinCandyCodeDispatchTest.php:1695-1699`) would fail if one were added; subcommand-flag
precedent `--url`/`--children` confirms it. The `--worktree` red is a genuine standing base-red
(master has zero `'--worktree' =>` OPTIONS rows, master `ArgvParser.php:800` branches on it).
`${keychain:<provider>}` resolution is reachable and attribute-symmetric (store
`secret-tool store service candy-code account openai` vs read `secret-tool lookup …` at
`Keychain.php:172`, resolved at `ProviderFactory.php:486-489`, miss → `''` + launch notice).
Passthrough/ambient refusals precede any prompt or spawn; failed store leaves config byte-identical;
orphan-entry-on-later-refusal disclosed and pinned (:255). E646 clean (no new timers). Scope clean:
7 files = brief's declared set + the in-step guard row; no suite-figure.json/durations/dist touches.
`ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` is red in the tree —
6 chat.*/host.* keys, zero relation to this lane (out-of-scope, lead glance).

Gates run in this worktree: required filter `LoginKeychain|LoginSubcommand|Keychain|ReadmeRosterDrift|
BinCandyCodeDispatch|StderrEmitterCensus|DescriptorInheritance` → Tests: 327, Assertions: 28629,
Failures: 2 (items 1 and the disclosed base-red), 2 deprecations; `--filter 'LoginKeychain|Keychain'`
→ 48T/178A green; `php -l` clean on all six touched PHP files.
