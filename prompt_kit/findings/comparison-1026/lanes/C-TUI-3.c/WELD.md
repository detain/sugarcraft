# C-TUI-3.c — WELD (2026-10-29, welder: cc-cmp welder subagent)

Base at weld: master f3236300a (weld: C-SES-8.f). Lane cc/C-TUI-3.c (base 878047472, already an ancestor — batch-1 landed), 1 commit. BOTH r1 reviewers CLEAN 0C/0M (twin verdicts agree; reviewer#1 duties came via lead relay — canonical dir holds the twin's REVIEW-1.md).

## Pick map
- 4ccfb9ce4 → c8e8a1f42 — Message-picker core: src/Tui/MessagePicker/{MessagePicker,MessagePickerAction,OpenMessagePickerMsg}.php + tests/Tui/MessagePicker/MessagePickerTest.php (all NEW) + lang/en.php 7 keys tui.msgpicker.* soft-appended adjacent to tui.history (all 7 requested in view(), same commit). Zero conflicts. ✅ mark cites c8e8a1f42.

## Absorption proof
- `git cherry master cc/C-TUI-3.c` → `- 4ccfb9ce4…` (absorbed).
- patch-id --stable SAME.
- author %ae=%ce=detain@interserver.net, zero Co-Authored-By.

## Merged-tree gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml) — ALL EXACT vs REVIEW-1 duty list
- `--filter 'MessagePickerTest'`: **OK 9T/90A**.
- `--filter 'LangParity|TuiLang'`: **OK 16T/4412A** (7T+9T classes; 2998A+1414A=4412A exact).
- 2 PHPUnit Deprecations = pre-existing foreign AccessorDeprecationTest shape.

## KeyHelpTest standing-red check (lead relay duty)
- `--filter 'KeyHelpTest'` at merged tree: **63T/5F** — exactly 5 failures, names all renderer/key-help family (SlashHelpNoLongerOpens…, FooterSaysThatTheSecondQuestionMark…, FooterFitsTheBox…, ScrollingThroughItShowsEveryLiveBinding…, AClippedKeyHelpFieldEndsWithAnEllipsis). ZERO msgpicker-shaped names.
- Structural backing: `git diff 878047472..HEAD` over KeyHelpTest.php + KeyBindingRegistry.php + Renderer.php + Tui/Renderer.php + Cli/Chat.php is EMPTY — the scan subjects are byte-identical since the base where the lead tracked these 5 reds. The pick adds no names to that set.

## Bookkeeping
- durations.tsv row ADDED: `tests/Tui/MessagePicker/MessagePickerTest.php 0.187 9` (after SettingsEditorNarrowTest 0.187). Rows 1549→1550, descending-order violations 4==4.
- §4 mark ✅ c8e8a1f42 at plan_comparison.md:2211.

## Carries to closeout (§7)
- +9T/+90A figure carry (closeout re-pin; batch-2 cumulative ≈ +20T new-file + 9T + 21T + … — re-pin is the authority).
- OpenMessagePickerMsg has ZERO callers repo-wide — dormant-by-design wiring door for C-TUI-3.d (r1 ruling: NOT a reachability finding at this step).
