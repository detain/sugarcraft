# PHASE 3 CLOSE REVIEW — cycle 2 — FINDINGS (reviewer: review-1)

Tree position reviewed: `/home/sites/prompt-step-P3.CLOSE-r2`, branch `prompt/P3.CLOSE-r2`, HEAD `470e43569`.
Verdict: NOT CLEAN — 7 findings. Full-suite run 2 reproduced 10547/163710/1 exactly; run 1 came
back 10547/163707/1 + 1 FAILURE (F1, load flake). All five derived-roster figures, both golden md5s, the
5/3/0 git-subprocess primitive, and audit-fix-2 src purity (elementwise token identity) re-measured exact.
F2 HIGH: 13th scanner defeat — anon/named-class extends-SplFileObject construction truncates unscanned.
F4 MED: roster classifier has 3 undeclared silent alias escapes (use-function glob, use RDI, SplFileInfo).
F6 MED: P3.S2 hard-constraint absence test still vacuous on a dead renderer (RR4 F2 recurrence).
F5 MED: --root orients Runtime <env> but not the live Agent assembler (cross-step seam, no file list could see it).
F1 MED: flooding-stderr shutdown test readies on pid-file before stderr write; bounded single drain = flake.
F3 LOW: close-review brief claimed 7 transmit (6 + Echo exempt) and 5,099 B (now 5,176) — brief-text defect.
F7 LOW: 8 audit-fix-2-lane commits authored detain@gmail.com vs mandated detain@interserver.net (un-rewritable).

---

# Phase 3 CLOSE review — cycle 2 — findings (reviewer: review-1)
Tree under review: /home/sites/prompt-step-P3.CLOSE-r2 @ 470e4356907cf8d79ce34ec4d14b0a9000c6ca20 (branch prompt/P3.CLOSE-r2)
Started: 2026-09-01. git status --porcelain EMPTY at start (verified).


## F1 — FULL-SUITE FIGURE FALSIFIED + one load-sensitive test (flaky red in a non-tty full run)
- Tree: 470e43569, sandbox /home/sites/prompt-step-P3.CLOSE-r2.
- MEASURED: full suite, cwd = checkout root, serial, </dev/null, box-quiet probe printed 0:
  `Tests: 10547, Assertions: 163707, Failures: 1, Skipped: 1` — brief claims 163710/0 failures.
- The failure is NEITHER of the two known tty-viewport artifacts (CompactModelSummary,
  MouseModalGuard); it is `ClaudeCodeMcpClientShutdownTest::
  testTheFloodingServersStderrIsRetainedUpToOneBufferAndNoMore`,
  sugar-crush/tests/ClaudeCodeMcpClientShutdownTest.php:262 — `stderrTail()` was `''`.
- OBSERVED: `--filter` on that one test, 3 consecutive isolated runs, cwd sugar-crush, </dev/null:
  all `OK (1 test, 5 assertions)`. So: red 1/1 in-suite, green 3/3 isolated.
- Mechanism (OBSERVED in source): fixture NOISY_STDERR_SERVER writes the pid file (the ONLY
  readiness handshake, :381) BEFORE `fwrite(STDERR, str_repeat('E',200000))` (:382). The test then
  calls `readMessages()` exactly once (:258); it does a single non-blocking bounded (16-read)
  `drainStderr()` pass (src/ClaudeCodeMcpClient.php:728, :885-916) and returns. Nothing ever waits
  for stderr BYTES — under CPU contention the child can be unscheduled through the parent's entire
  assert path, `stderrTail()===''`, and `assertNotSame('', $tail)` reds against CORRECT implementation
  code (drain+retain+cap all intact).
- WHAT WOULD MAKE IT NOT WRONG: a bounded readiness wait on stderr data (poll stderrTail until
  non-'' or deadline), exactly as callTool() polls readMessages(); or an ordering handshake that
  makes "pid file visible" imply "first stderr byte written". Neither exists.
- Severity: MEDIUM (test-instrument soundness; phase-close figure non-reproducible; blocks the
  'suite green' claim at THIS tree position as-is).
- Status labels: suite figure MEASURED; isolated passes MEASURED; mechanism OBSERVED (source-read),
  deterministic reproduction attempt pending (F1-repro below).

### F1 addendum — deterministic mechanism reproduction (MEASURED)
Scratchpad synthetic f1-repro.php against UNMODIFIED worktree src/ClaudeCodeMcpClient.php,
two polarities (rule 18):
  delay_us=0      tail_len=65536  retained=yes   (x2)
  delay_us=300000 tail_len=0      retained=NO    (x2)   <-- exactly the suite failure
A 300ms child stall between the fixture's pid-file write and its fwrite(STDERR) — which the
scheduler under full-suite load supplies freely — deterministically reproduces the red against
correct library code. The test's only readiness sync (selfReportedPid, polled pid file) does not
imply any stderr byte exists; the single-shot readMessages() drain has no stderr-data bound.
Label upgrade: mechanism MEASURED (was OBSERVED).

## F2 — THE THIRTEENTH DEFEAT of the write-primitive scanner: `new class(...) extends \SplFileObject`
- Tree: 470e43569. File: sugar-crush/tests/RuntimeTest.php — scanner `writePrimitivesCalledIn()`
  (:3588); roster `WRITE_CONSTRUCTIONS = ['splfileobject','spltempfileobject']` (:3363); the miss
  path: `extends SplFileObject` token has `$next === '{'` so `:3723` `if ($next !== '(') continue;`
  drops it, and there is no T_NEW before the name (`:3719` afterNew=false). The `new` itself carries
  NO name (anon class), so no roster can key on it.
- MEASURED, through the SHIPPED method via ReflectionMethod (rule 13), both rows `php -l` clean,
  the write RUN for real (rule 16/18 known-positive control in the same script):
  * DEFEAT row: `new class($path,'w') extends \SplFileObject {};` → scan returned `array()` AND the
    6-byte target file was TRUNCATED TO 0 BYTES by construction alone. Silent pass on a real
    executed tree-mutation.
  * CONTROL row: `new \SplFileObject($path,'w');` → scan returned `['splfileobject' => [2]]`. The
    instrument is alive; the SHAPE is outside its alphabet.
- Novelty check (OBSERVED): not in the doc-block's twelve-defeat enumeration (RuntimeTest.php
  ~:3497-3520), not in the "WHAT THIS ALPHABET CANNOT EXPRESS" list (method calls on objects,
  indirection via strings, collaborators, ARGV, extension functions — an ANON-CLASS extends of a
  rostered construction-class is none of those), and not in the known-open do-not-report list (N1
  holds the `writesTree()` vs fingerprint DECISION, and the fail-open alias channel, not this
  spelling). The TreeWideGuardRosterTest.php:2627 `new class` note is about `declaredTypeNames`
  in a DIFFERENT guard, not a declaration of this hole.
- Consequence: the phase claim "the write-primitive scanner fails CLOSED; an unknown spelling costs
  a FALSE POSITIVE, never a silent miss" (brief claim 4) is FALSIFIED by a false NEGATIVE: the
  construction site never reaches the argument-walk fail-closed channel (no arguments are read at
  the new-site at all).
- Fix-shape (REPORTED not prescribed): treat `T_EXTENDS`/`T_IMPLEMENTS` + rostered construction
  name as a construction; same-file named subclasses `class W extends SplFileObject` + `new W(...)`
  are the same family (INFERRED — mechanism read from source, not run).
- Severity: HIGH (this is exactly the defeat class the claim says is closed; it is the 13th).
- Addendum MEASURED: same-file named subclass `class W extends \SplFileObject {}` + `new W($p,'w')`
  also scans `array()` and truncates the target to 0 BYTES on real execution — the family, not just
  the anon spelling.

## F3 — Brief claim 2, as written, is FALSE of the tree (check 14: the brief is falsifiable)
- Brief (phase-review-brief-r2.md:112-114): "all seven providers transmit it" and "assembled == golden
  == wire bytes (measured 5,099 B)".
- MEASURED at 470e43569: ProviderInterface has exactly SEVEN implementers (derived via the shipped
  ProviderRequestResponseTest::providerImplementers(): Bedrock, ClaudeCode, Custom, Echo, OpenAI,
  Sglang, Vertex) but SIX transmit; EchoProvider is exempted by the tree's OWN assertion
  (SystemPromptTransmissionMatrixTest.php:348-353, `assertSame(['EchoProvider'], diff)`, named
  reason: test double, never serializes a payload). "All seven transmit" is the cardinality conflated
  with "all seven implement". The underlying property — every WIRED provider transmits, derived
  roster, green — is TRUE and re-verified (matrix tests ran in the full suite).
- MEASURED: the golden is 5,176 bytes at HEAD (`cat-file -s`: 5099 at 924c71a0d/379ecc7d6/dabcd27f7,
  5192 at 74cabae7f [P3.S3 caption +93], 5176 from 33df838d0 on [hermeticity −16]). The brief pairs
  the figure "5,099 B" with "on the golden fixture context assembled == golden == wire" AT HEAD; that
  figure's domain is `397a6983a`-era pre-P3.S3 (worklog RETRO-RR5 :3436). The EQUALITY survives —
  reproduced at 5,176 by this review below — the LITERAL does not. Rule 1/6.
- Severity: LOW (brief-prose accuracy; no code defect). Filed because the brief invites falsification
  and a downstream agent re-deriving "5,099" at HEAD would chase a phantom regression.

## F4 — THREE UNDECLARED SILENT ESCAPES of the roster walker (the derivation is the thing to attack)
- Tree: 470e43569. Instrument: sugar-crush/tests/TreeWideGuardRosterTest.php, shipped
  `classifyWalkSites()` :2680; name test :2690-2696 matches the WRITTEN token text (lowercased,
  ltrim `\`) against WALKER_CLASSES :658 / WALKER_FUNCTIONS :661. No alias map.
- MEASURED through the SHIPPED method via ReflectionMethod, known-answer control first (rule 13),
  each row classified:
  * CONTROL direct `glob(dirname(__DIR__,2)...)` => ROOT (instrument alive).
  * FQ spellings `\scandir(`, `\RecursiveDirectoryIterator`, lowercase `new directoryiterator` => ROOT
    (already handled — not escapes).
  * ESCAPE 1 `use function glob as g;` + `g(dirname(__DIR__,2).'/src/*.php')` => SILENT (0/0).
  * ESCAPE 2 `use RecursiveDirectoryIterator as Walk;` + `new Walk(dirname(__DIR__,2).'/src')`
    (inside RecursiveCallbackFilterIterator) => SILENT (0/0).
  * ESCAPE 3 `new \SplFileInfo(dirname(__DIR__,2).'/src')` + `->getChildren()` iteration => SILENT (0/0).
  * chdir(dirname(__DIR__,2)) + relative `glob('src/*.php')` => REPORTED (fail-closed; NOT an escape).
- The blind-spot table `testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre` (:1218-1284) pins
  exactly SIX silent shapes (Finder, shell find, git ls-files, SplFileObject manifest, literal
  absolute path, string-indirection `$w='scandir'`). ESCAPES 1-3 are NONE of those six: an import
  FUNCTION alias, an import CLASS alias, and the SPL `getChildren()` directory spelling are silent
  shapes the file does NOT say are silent — so the table's claim to be the map of the alphabet's
  silence is false by three.
- Seam/precedent: ESCAPES 1-2 are the SAME defeat family the write-primitive scanner was patched for
  TWICE — "an import alias adds a spelling and never replaces one" (RuntimeTest.php, aliases
  channel) and "a use function import written in a COMMENT / alias SUBTRACTION fail-open closed at
  5cabca4a8". §16.8 rule 40 corrections-travel: the alias lesson travelled into one scanner and
  never reached the other classifier in the same tests/ tree. The GlobIterator row's own
  argument (:1043-1049) is the template: "a walk this alphabet cannot see produces no site at all -
  so the file is skipped in SILENCE rather than landing in the residue" — exactly the mechanism here.
- Population (OBSERVED): no live use of any of the three shapes under sugar-crush/tests/ today
  (grep for getChildren( / use function glob / use RecursiveDirectoryIterator as → nothing outside
  the roster test), so this is the same 'MEASURED LATENT, removes a future false' grade the T_ENUM
  row carries. It is a defect of the INSTRUMENT'S MAP, not of today's verdict.
- WHAT WOULD MAKE IT NOT WRONG: either the blind-spot table gains three silent rows for these shapes
  (and their escape expression would then need licensing at any future offender), or the classifier
  gains an additive import-alias map (mirror of the scanner's) + `splfileinfo`/`getchildren`/`chdir`
  handling. Not a prescription — reported shape of the gap.
- Severity: MEDIUM (the derivation is the phase's headline defence and its self-declared map of its
  own silence is incomplete in a family this phase already bled on twice).

## F5 — SEAM ACROSS STEPS: `--root` reaches Runtime's <env> but NOT the per-stage Agent prompts (live path)
- Tree: 470e43569.
- OBSERVED: `Runtime::projectRoot()` (src/Runtime.php:2716-2719) = `$app->root ?? getcwd()`, and its
  docblock states the invariant explicitly ("Captured at projectRoot(), not at the process directory:
  ... on a `--root <lib>` run they must name the directory the tools are jailed to"). The workflow
  path renders the AGENT assembler instead: WorkflowEngine.php builds 6 fresh `new Agent(...)`
  (:1013/:1124/:1226/:1268/:1379/:1407) and passes NO environment — `/usr/bin/grep -c
  withEnvironment( src/Workflows/WorkflowEngine.php` → 0 — so every per-stage render falls to
  `Agent::systemPrompt()`'s last resort `EnvironmentBlock::capture((string) getcwd(), ...)`
  (src/Agents/Agent.php:918). Bootstrap contains NO live `chdir()` call (3 hits, all prose comments);
  App.php:118-127 records that `--root` used to reach ONLY the tools and was fixed to reach the
  prompt — that fix is the Runtime half.
- CONSEQUENCE (INFERRED from the above; no live measurement of a mislabelled run): under
  `bin/sugarcrush --root <dir>` with the process started elsewhere, the live `/workflow run` path
  (Chat.php:7725 → WorkflowEngine, established live by P3.S6's hop-walk) tells every sub-agent a
  "Working directory:" and git block for a directory whose files its jailed tools are NOT confined
  to — precisely the orienting-line mismatch Runtime's capture site was created to prevent. With
  cwd == root (the default) the two agree and nothing diverges.
- WHY no single-step review could see it: P3.S1/S5 own the Runtime capture site; P3.S6 measured the
  Agent renders but dispositioned the WRITE-SIGNAL cost only; the DIRECTORY domain of the same env
  block was never re-walked on the second assembler.
- NOT in the known-open list (the §18 row and the two escalations concern the write signal, not the
  cwd line). Outside every step's declared scope → REPORTED, not prescribed.
- WHAT WOULD MAKE IT NOT WRONG: an attachment point that hands the WorkflowEngine-built agents the
  session root (the `withEnvironment()` seam exists and is used by Bootstrap:1462 for REGISTERED
  agents), or a `chdir()` to the jail root before stage dispatch, or a recorded decision that
  workflow sub-agents are cwd-anchored by design. None exists in the tree.
- Severity: MEDIUM.

## F6 — The P3.S2 HARD-CONSTRAINT absence test is STILL vacuous on a dead render() (RR4-F2 unfixed)
- Tree: 470e43569. File: sugar-crush/tests/Context/EnvironmentBlockTest.php:150-156.
- MEASURED (mutation M1, sandbox, FULLY RESTORED — `git status --porcelain` empty after restore and
  file re-verified `OK (43 tests, 170 assertions)`): inserting `return '';` as the first statement of
  `EnvironmentBlock::render()` (:673) leaves
  `--filter testNoAdditionalWorkingDirectoriesLineIsEmitted` at `OK (1 test, 2 assertions)`, while
  35 other tests in the same file RED on the same mutation — the class is not blind; THIS test is.
- The brief's step text calls this test out as P3.S2's "Hard constraint ... pins an ABSENCE as a
  decision (backlog E26). Do not make it pass by accident". It passes with the whole renderer dead:
  '' contains neither needle. §16.8 rule 16 — the known-positive control is required IN THE SAME
  TEST, through the same scanner; the file's other tests are separately deletable units.
- Status history (OBSERVED): RETRO-RR4 F2 flagged exactly this and scheduled it "for after P3.S3
  merges"; it is NOT on the close-review known-open do-not-report list (the only RR4-era F2 there is
  the AgentTest `gitSubprocessesDuring()` helper one), and EnvironmentBlockTest.php is not among
  P3.audit-fix-2's six files — it slipped between the lanes.
- WHAT WOULD MAKE IT NOT WRONG: the test additionally renders a block it KNOWS emits content
  (e.g. assertSame on the 'Working directory: ' line in the same $output, or asserts the git-repo
  line) so a dead render() reddens THIS test by itself.
- Severity: MODERATE (a guard the plan labels load-bearing is unproven against the deletion it
  exists to police).

### F4 addendum — same family reaches channel A's helper matcher (INFERRED, mechanism read at :2693-2695)
`use SugarCraft\Crush\Tests\Support\TestFileWalkTrait as TF;` + `use TF;` would miss
walkingHelperUsedIn()'s name matching exactly as the class-alias walk missed classifyWalkSites:
the helper alphabet is matched on WRITTEN last-segment text. Not separately re-driven (the skip of
the walker is inside the helper body — a consumer-channel analogue of ESCAPE 2); mechanism shares
its root cause with F4 ESCAPES 1-2: name-matched alphabets with no import-alias map, while the
sibling scanner in the same tests/ tree carries one since 5cabca4a8.

### F1 addendum — second full-suite sample at the SAME tree (470e43569, post-mutation, restored)
Run 2, cwd checkout root, serial, </dev/null, box-quiet probe 0: `Tests: 10547, Assertions: 163710,
Skipped: 1` — OK, 0 failures, EXACTLY the brief's figure. So F1 is INTERMITTENT (1 red / 2 full
samples in this session, 0/3 in isolation, deterministic synthetic reproduction), not a persistent
red; the headline figure is reproducible but NOT reliably so at this tree position. Grade unchanged
(MEDIUM): a load-dependent red in the phase-close suite is precisely what CI will re-discover.

## F7 — Identity: 8 of 200 in-window commits carry detain@gmail.com (P3.audit-fix-2 lane)
- MEASURED: `git log --format='%H %ae' 924c71a0d..470e43569 | awk uniq-c` → 192 detain@interserver.net,
  8 detain@gmail.com; all 8 subjects `prompt: P3.audit-fix-2 — …` (first enumerated: 067a18e0a), and
  they carry the sugar-crush/tests changes of that lane (RuntimeTest.php, TreeWideGuardRosterTest.php).
- The plan's non-negotiable identity is `Joe Huss <detain@interserver.net>` (name matches, address
  does not). Not fixable without rewriting merged history — prohibited — so reported for lane hygiene:
  the audit-fix-2 worktree's git config identity differed from every other lane's.
- Severity: LOW (metadata convention).
