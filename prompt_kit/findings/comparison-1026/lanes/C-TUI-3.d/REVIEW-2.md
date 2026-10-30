# C-TUI-3.d — round 2 review (idle Esc Esc message-picker wiring)

VERDICT: FINDINGS — 1 CRITICAL (process: the round-2 brief's premise is phantom vs disk; the LANE's own work is CLEAN and already WELDED to master; no fix round was ever needed or committed).

## Disk truth (cat-file + reflog + grep proven, Oct 30)
- Branch cc/C-TUI-3.d @ 2593d9294, tree CLEAN, EXACTLY 3 commits atop 6fb38eef4: fa3487bb2 (Chat half: HandlesKeys idle two-beat +17/-1, Chat.php +21/-3, EscapeSoftCancelTest +97/-1) → 8e1ed751d (shell half: App/Renderer/KeyBindingRegistry/en.php/README + MessagePickerWiringTest 299L NEW 11T + drift/registry cures) → 2593d9294 (REPORT.md only, +28L). Parents from `git log --format='%H %P'`; reflog shows only these (+1 amend of the report).
- The brief's claimed chain is FICTION: 7024b8571, 3b328a227, 5b3936855, a9cf14166, 7d3f49437 all FAIL cat-file in the shared object DB. FIX-1.md does not exist on-branch or in the main-tree lane dir (ls: REPORT.md 4926B + REVIEW-1.md 6818B only). Symbols "freshAtBottomLeft", "isIdleForPicker", "provider name observed" = ZERO hits in candy-code src/ + tests/. IdleEscapePickerTest does not exist (glob **/IdleEscape* empty) — the new file is MessagePickerWiringTest.php.
- REVIEW-1.md (6818B, on disk) says VERDICT: CLEAN — 0C/0M/0 step-local MINOR. There is NO r1 with "0C/2M/3m/2n" on disk; the brief's enumerated "fixer claims" match nothing (same brief-relay distortion class r1 itself documented for this lane).

## Step already CLOSED (Weld 1030W, plan_comparison.md:3457 ✅ f11a0b019)
- Picks fa3487bb2/8e1ed751d/2593d9294 → e6e701c82/77199e554/f11a0b019 onto ad60f7a3f; f11a0b019 IS an ancestor of master (merge-base --is-ancestor OK); master tip fc608096f = the evidence commit.
- `git cherry master cc/C-TUI-3.d` = "- - -" (all 3 absorbed by patch-id). Range law satisfied by the weld: 6fb38eef4..2593d9294 = exactly 3 EXCL-start.
- DUR already minted/re-timed at weld: MessagePickerWiringTest 2.402/11; EscapeSoftCancelTest 0.012/9 → 0.093/15. §7 carry +17T recorded. Nothing owed by this lane.

## Round-2 re-verification of the real work (lane worktree, plain pipe, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS)
- MessagePickerWiringTest|EscapeSoftCancelTest = 26T/148A OK (r1 union figure exact).
- KeyBindingDriftTest|KeyBindingRegistryTest|ReadmeRosterDriftTest = 281T/3418A OK (= r1's 271T/3340A + ReadmeRoster 10T/78A, exact).
- CommandKeybindingDispatchTest = 7T, 1F = tests/App/CommandKeybindingDispatchTest.php:222 KeyHelp overlay-scroll foreign standing family (rostered; unchanged by lane — file absent from the 3-commit diff).
- Hand-trace: idle normal pair (now + lastEscapeAt ≤ window, empty trim(draft), negative stamp → OpenMessagePickerMsg + stamp cleared, HandlesKeys.php:410-417) ✓; straddle edge (idle −t1 met by in-flight press: :425-426 `now − (−t1)` ≈ 2×epoch > window → never pairs; in-flight branch re-arms positive) ✓ — sign law structural, pinned by committed testADoubleTapStraddlingATurnEndNeverOpens + testAnIdleStampNeverArmsTheInFlightCancelWindow.
- Chat.php hot-file change add-only in the declared region (rewindToCheckpoint beside handleRewindCommand + lastEscapeAt docblock flip) = matches W29 claim [chat:rewindToCheckpoint (new method)]; zero forbidden files (no suite-figure.json/durations.tsv/dist) in any commit.

## Carry for the lead
1. Do NOT pick 6fb38eef4..7024b8571 — objects nonexistent; the step is welded. Prune cc/C-TUI-3.d + /home/sites/cc-cmp-C-TUI-3.d after confirming the weld's own collect (evidence fc608096f already carries REVIEW-1.md + REPORT.md).
2. Collect THIS REVIEW-2.md into the run ledger; investigate the spawn-brief generator for the phantom r2 narrative (fabricated SHA list + fix claims + invented test/gate names) before the next C-* step dispatch — same failure class as the owner-stopped phantom 1030W first dispatch.
