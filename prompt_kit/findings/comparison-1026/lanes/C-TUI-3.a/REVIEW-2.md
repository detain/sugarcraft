# C-TUI-3.a review round 2 — `/copy [n]` sanitizer cure (reviewer: fresh round-2, read-only)

VERDICT: CLEAN — 0 CRITICAL, 0 MAJOR, 1 MINOR (step-local, order-pin; weld-ready, fix optional). Review target ca3896ad7 (fix commit, 3 files +78/−2) atop 33c15af11. Commit header: `%ae`/`%ce` each 1× detain, zero trailers (git show verified). Tree clean. WRITE-LOCATION NOTE: this reviewer's write map refused the lane worktree (/home/sites/cc-cmp-C-TUI-3.a/...) — the file lives in the MAIN TREE only; the welder must copy it onto the branch (same shape as REVIEW-1.md).

## r1 MAJOR — CURED, verified on disk
- `candy-code/src/Chat.php:12562` (handleCopyCommand): `$text = Sanitize::untrustedForDisplay(Sanitize::stripPrivateUse($selection['text']));` — exactly the claimed cure, the `/export` pair in the same order (`ExportCommand.php:189` verified side by side), reusing the existing import at `Chat.php:28` (`use SugarCraft\Core\Util\Sanitize;` — grep line-verified).
- **Placement — single pass, upstream of every egress.** Read the method end to end (:12534-12580): the refusal branch returns at :12547-12551 before the sweep and carries NO transcript content (Lang rows with int `blocks` + user-typed `arg` — same class as every other refusal, painted through the renderer's untrusted path, never clipboard). After the sweep, exactly three egresses read `$text`: the chars notice `mb_strlen($text)` (:12567), the OSC-52 leg `relayWidgetCmd(Cmd::setClipboard($text))` (:17420/:17442 chain verified — cappedOsc52 only clips, no sanitize), and the closure `SystemClipboard::copy($text)` (:12574 — copy() passes text straight to the tool's stdin, no sanitize). `Ansi::setClipboard` base64-encodes only (Ansi.php:761). The fence path and the bare path both flow through the single `CopyCommand::select()` return consumed at :12562 — CopyCommand is pure (no egress in it). grep `Sanitize::` over Chat.php: only :12562 on this path (:16054 is the separate stored-row boundary, :17207 a paste docblock). **No double-sanitize anywhere** — the fixer comment "neither leg sanitizes on its own" is code-true.

## r1 NIT — fixed
- Docblock cite now `trackTextSelection()` (:12518); the batch(relay+SystemClipboard) precedent verifiably lives at :6568-6574 inside that method (read both bodies). SymbolCitationDriftTest green in my battery.

## Order correctness (check 2)
Reasoned from the Sanitize implementation (`sugar-core/src/Util/Sanitize.php`), not executed. `untrustedForDisplay` = `markInvisibleFormatting(CRLF-fold(untrusted(s)))` and `untrusted()` explicitly KEEPS U+E000–U+F8FF (docblock :230-234) — so `stripPrivateUse` is NOT redundant: outer alone leaves the forged-sentinel vector; inner alone leaves the OSC-8/C0 vector. Both halves load-bearing (M2/M3 mutation claims map to exactly this). The ORDER itself is behavior-neutral here: the two sweeps target disjoint character classes and never splice multi-byte sequences, so `A(B(x)) == B(A(x))` for all x. **No pin catches an order swap and none is required for correctness — but per the round-2 brief rule I record it MINOR (step-local):** a future refactor could silently change the pair-order convention away from `/export` unnoticed. Cure if taken (optional, does not gate weld): add a structural substr-count pin mirroring ExportCommand's exact call nesting. The current spelling already mirrors /export.

## Non-vacuity (check 3) — LINE-REASONED, I did NOT run mutations (read-only)
- M1 (sanitizer deleted): hostile pin reds on leg 1 (base64 of raw ≠ base64 of `$clean`), legs 2-3 confirm; fence pin reds likewise; the other 20 tests use clean fixtures → unaffected. Claimed 22T/78A/2F is arithmetically coherent: each red test dies at its first assert, −3A ×2 → 84−6=78.
- M2 (stripPrivateUse dropped): sentinels survive `untrusted` → both pins red (both carry U+E000 legs) = 2F. M3 (untrustedForDisplay dropped): OSC-8 survives only in the bare-hostile pin (fence pin is PUA-only, stays green) = 1F. Claims consistent with the code.
- Expectations are HARDCODED literals (`$clean`, `'first inside'`, `chars 12`); no Sanitize call appears in any expectation — pins cannot self-derive. `rawWrites()` EXECUTES the batch closures, so the asserted bytes are the real post-clip payload and the real argv the tool would receive; SystemClipboard discovery+runner are stubbed in setUp (:55-63), hermetic on headless CI. No `assertTrue(true)` shapes.

## Legit-text safety (check 4)
`testBareCopyCopiesTheWholeLastReply`/`testNumberCopiesTheNewestBlockOnly` assert the WHOLE unmodified `blockyReply()` bytes (`Ansi::setClipboard($reply)`, argv `$reply`, `chars mb_strlen($reply)`) still green at 22T/84A → ordinary copy is byte-for-byte unchanged; LF preserved, TAB/CR policy inert on the fixtures, no entity encoding in either sweep. Fence/citation pins inside CopyCommandTest all green.

## r1 PASS list — re-confirmed by the 17-class battery, not re-litigated
Registration/spec/read-only/dispatch all moved in-step; guard-roster edits GUARD-FORCED (both rosters walk `CommandRegistry::all()`); README fence + COMMANDS.md regenerated and drift-guard green; READ_ONLY pin reflects the production constant; `/copy 0` → BAD_ARG pinned (:92); tilde-fence exclusion = MarkupScanner parity (both ```). Battery: **496T/27430A/2F** — the two F are the foreign `output-style` pair by NAME (`PaletteSupersetDispatchTest::testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet`, `MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable` @:106); the failure diffs show ONLY `'output-style'` index-shift, every `copy` row absorbed. `gen-command-docs --check` not run directly (`php tools/...` denied by my permission map — same limit r1 hit; CommandDocsDriftTest green is the same comparison; fixer's own run rc=0). `php -l` clean on both src + test.

## Numerals (check 6) — landed-blob form, dirty-tree gates not consulted
- LANE: `git show ca3896ad7:candy-code/docs/ARCHITECTURE.md | sed -n '155p'` → **65**. ✓ carried as briefed.
- MASTER (re-read twice as it moved): `git -C /home/sites/sugarcraft show master:candy-code/docs/ARCHITECTURE.md | sed -n '155p'` → **64** at 85463f3fd and at 5f8717874 (C-TOOL-2.c weld-marks landed between my reads; its blob did NOT flip → no pending count move; master ls-tree builtin-commands = 72 spec files both reads, unchanged).
- Sibling weld_pending check `git diff --name-status master..<lane> -- candy-code/builtin-commands/ candy-code/docs/ARCHITECTURE.md`: C-TUI-2.g = ARCHITECTURE only; C-TUI-4.b = ARCHITECTURE only; C-SEC-7.a = ARCHITECTURE only + two D entries (`1405-output-style.php`, `2215-insights.php` = stale-base artifacts, both already live on master); C-TOOL-2.c branch itself vanished mid-review — welded, which is why the re-read happened. None adds a new slash row. **MERGED tree must land 65** (master 64 + copy); plain pick lands it. If any count-moving lane welds ahead of this pick, DocFigureProseDriftTest:712-718 live-count reddens at the merged tree → re-derive, never hand-bump.

## §7 figure delta
The +20T/+76A from r1 is superseded by the fix commit: the Copy pair now measures **22T/84A** (reproduced, tail line quoted: `Tests: 22, Assertions: 84, ... PHPUnit Deprecations: 2` — baseline EchoBackend shape). Closeout re-pin: **+22T/+84A**.

## Gates reproduced (cwd candy-code, env -u LINES -u COLUMNS, plain pipe; PtyRun never matched)
- `--filter 'CopyCommandTest|CopySlashCommandTest'` → **22T/84A OK (2 deprecations)** — exact claim match.
- `--filter 'MenuBarLabelDerivationTest|PaletteSupersetDispatchTest'` → **18T/443A/2F**, names verified = the two standing foreign output-style reds (green-equivalent per the standing roster; NOT lane defects).
- Battery (ReadmeRosterDrift|CommandDocsDrift|SlashDispatch|BuiltInCommands|RegistryTranslation|KeyBindingDrift|DocFigureProseDrift|SymbolCitation|NoRawAnsiInTranscript|both Copy|both roster guards|Hooks|Insights|CommandSpecSanitization|Generate|CommandRegistry|CommandsTableTakesColumnDrift) → 496T/27430A/2F, same pair only.
- ReadmeSuiteFigureDrift staleness pair NOT run — standing red by design until the §7 re-pin.

## Sweep (check 7)
Whole-lane diff = 13 files, exactly the design-block surfaces + the two guard-forced roster edits + evidence files (REPORT.md, FIX-1.md); no out-of-region Chat.php hunks (both edits inside handleCopyCommand's region), no lang/README changes in the fix commit (lang sort stays as r1 verified), new test literals contain no glob/wildcard shapes (`;`/space-bearing strings are GlobDialect-exempt anyway) and no `**Refused:**`-shaped tokens. Nothing else found.

## §4 weld carries (updated)
1. NEW durations.tsv rows: `candy-code/tests/Commands/CopyCommandTest.php`, `candy-code/tests/Chat/CopySlashCommandTest.php`.
2. Suite-figure/README re-pin: +22T/+84A.
3. ARCHITECTURE :155 merged value **65** (lane blob already says it; master still 64).
4. trio/dist/candy-code-web/suite-figure.json/durations.tsv: untouched (diff-verified).
5. The one MINOR (order-swap pin) is optional and does not gate weld.
