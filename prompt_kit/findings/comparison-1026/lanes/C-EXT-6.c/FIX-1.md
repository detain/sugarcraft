# C-EXT-6.c — FIX-1 (round-1 fixer)

DONE. Commit `f895fc2ed` on `cc/C-EXT-6.c` (follow-up atop ac7ce47a7 + 969ee98b3; the two picks unamended). Tree clean at close.

## M1 (MAJOR) — Host pin absent from the wire, vacuously claimed

- `src/Hooks/HttpHook.php::pinnedPost()`: added the guard-parallel construction
  `$name = (string) $request['host']; $defaultPort = $scheme === 'https' ? 443 : 80;
  $hostHeader = $port === $defaultPort ? $name : "$name:$port";` (mirrors
  `PublicUrlGuard::transferPinned` :444/:453 exactly) and prepended
  `"Host: $hostHeader\r\n"` to the `'header'` stream-context string.
- New structural source-scan pin `HttpHookTest::testTheRealWireCarriesTheHostPinTheClaimSitesPromise`
  (house precedent `PublicUrlGuardTest::testTheInstructionBudgetIsAPerReadTransportBoundNotAKillTimer`
  :354-368): slices pinnedPost by declaration + first `\n    }` terminator and asserts
  the `$hostHeader` assignment spelling, `'header' => "Host: ` lead-in, the literal
  `"Host: $hostHeader\r\n"` (escape spelling included), `'max_redirects' => 0`, and
  `'peer_name' => (string) $request['host']`. No new test file (extended HttpHookTest).
- Discrimination proven: cp backup (md5 `orig.md5`) → python needle-replace dropping
  the Host prefix ("MUTATION APPLIED" verified) → the new pin reds at the
  `'header' => "Host: ` arm (1F, tests:288) → cp restore → md5 -c OK → class green.
  (Harness note: the first restore attempt was short-circuited by `&&` after the
  expected pre-restore md5 FAILED; restored unconditionally and re-verified. Never
  `git checkout --`.)

### Claim sites: zero prose edits, deliberately

REVIEW-1 M1 fix line: "Truth-flip the three claims only AFTER the wire carries it."
All three (`HttpHook.php:37-39`, `:633-636` docblock, `ReadPathCensusTest:708` fopen-row
sentence) are AFFIRMATIVE claims ("the same Host pin", "Host: carrying the name",
"the Host header carries the virtual name"). The wire fix makes them TRUE as written;
flipping their text would manufacture the opposite falsehood. Prose and code now
agree — that is the truth-flip. The dispatch paraphrase "truth-flip the three claim
sites" was read against this evidence and NOT applied as text edits.

## n1 (MINOR) — redirect comment overstatement

`:355-360`: replaced "and a fallthrough would let a 302 with an empty body look like
an allow" with the truth — the adjacent `<200||>=300` deny already answers nothing,
so the arm's job is the distinct reason word the message table pins. Comment-only,
zero behavior change.

## NOT touched (per disposition)

No Hooks::emit() compat door (premise disproved, Q3). No foreign-red cures
(ApplySettingsTest/KeyHelpTest/HelpTest tier/BinCandyCodeDispatch --worktree/
AttachPagingTest/ReadmeSuiteFigureDrift/MenuBarLabelDerivation).
DuplicatedTestHelperDrift + TreeWideGuardRoster not run-cured (master e78d5dc14;
branch predates it). No stash touched. PathGlob.php untouched.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, no full suite)

| run | result |
|---|---|
| HttpHookTest pre-edit | 23T/164A OK (baseline) |
| HttpHookTest post-fix | 24T/171A OK (+1T/+7A) |
| mutation (Host emission neutralised) | new pin 1F at the header lead-in arm |
| restore + green | md5 OK, 24T/171A |
| `SugarCraft\\Code\\Tests\\Hooks\\` namespace | 1099T/3724A OK (= reviewer's 1098/3717 + exactly my delta) |
| guard battery (DocFigure\|GlobDialect\|ReadPathCensus\|StderrEmitterCensus\|SymbolCitation\|SuiteSkipRoster) | 256T/46913A 0F |

In-step guard law: nothing derived moved — ReadPathCensus row became true not stale;
no emitters/sprintfs added; HOOKS.md untouched; Glob corpus unmoved (new literals all
carry spaces/quotes/$, corpus-exempt — GlobDialect green); `M`-only porcelain pre-commit,
two tracked files, no `add -N` residue.

## §7 list

- New test FILES: NONE. New test METHOD: `HttpHookTest::testTheRealWireCarriesTheHostPinTheClaimSitesPromise` (+1T — the closeout re-pin of suite-figure/README owes this delta on top of the lane's existing +2 files).
- Files touched: `candy-code/src/Hooks/HttpHook.php`, `candy-code/tests/Hooks/HttpHookTest.php`.
- Tip: f895fc2ed (cat-file'd commit in same shell as cite). Author/committer %ae/%ce grep counts 1/1 detain; body trailer grep 0 co-authored.
