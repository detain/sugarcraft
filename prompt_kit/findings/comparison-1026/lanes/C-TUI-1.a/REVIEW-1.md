# C-TUI-1.a — REVIEW-1 (round 1) — VERDICT: CLEAN

- Lane: cf5f0f925 on cc/C-TUI-1.a; parent b38baf0f8 (ancestor of current master 51812d155 — rolling cut, master moved +2 since spawn; lang/en.php gained +1 unrelated line between cut and master, weld cherry-pick context risk only).
- Diff = exactly the 7 files the brief names; +866/−86 matches claim. No fork/proc_open sites; sugar-forms untouched (5 diff mentions = imports/comments only).

## Focus checks (all PASS)
1. §6.1 R4: zero visual-line additions in the diff (grep over ^+ lines); docblock keeps "V aliases v — awaits R4 ruling". No C-TUI-1.b resurrection.
2. Regions: HandlesKeys hunks confined to the declared (b) arm (~L325-360) and claim arm (~L1035-1058) + their comments; ComposerVim lane-owned; lang one help line. VimState digit path verified wired through unchanged Chat::applyComposerVim (default `new ComposerVim()` arg-safe for new defaulted ctor params; mutate() carries by value).
3. Gates re-run by reviewer: --filter 'ComposerVim|LangParity' → OK 82T/3292A EXACT (7 per-class sums: 20+27+18+9 + LangParity 7). The 2 PHPUnit deprecations reproduce identically on the pristine base tree (LangParity filter alone) — ambient, not lane.
4. Non-vacuity (static — reviewer map denies src edit/cp/git-checkout; mutation probes not runnable): claimsKey count-keying is pinned both polarities (unarmed `0` false, armed roster true); count math pinned by exact values (20, 23, `3dd`→'a', `2yy` block, d20w→''); enterInsert pinned via vimState==Insert on ci"/cc/C/cw; HandlesKeys isArmed swap pinned chat-level (forgotten-count Tab, digits-in-NORMAL/Insert/menu). New tests assert real TextArea state — no fabricated paths. (src files are byte-clean: edit attempt was refused, porcelain empty.)
5. d0 risk: hand-traced both ways — bare `0` unclaimed → lib line-start; `d`+`0` via resolve → CHAR_MOTIONS delegation. Chat-level pin drives `0 c w`.
6. In-step guard law: guard family 391T → ONLY staleness pair red (expected; figure forbidden file). DocFigure/KeyBinding/Roster/Env/TrustKey/ConfigWriteProducer green. Nothing pins the old help sentence (grep tree); SETTINGS.md rows don't embed it; gen-settings-doc --check refused by reviewer map — §7.3 closeout duty.
7. Literals: GlobDialectDifferential|DuplicatedTestHelperDrift green (27T). NEW files tests/Tui/ComposerVimTextObjectTest.php (9T) + ComposerVimCountTest.php (18T) → weld owes 2 durations.tsv rows (listed in REPORT.md).
8. Laws: one commit; %ae and %ce separate greps = detain@interserver.net; zero Co-Authored/Signed-off trailers; suite-figure.json/durations/dist untouched.

Findings: none. NIT-level weld glances (not findings): en.php cherry-pick context; README Keys correctly did NOT move (mode, not bindings — brief-mandated).
