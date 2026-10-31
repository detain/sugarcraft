# C-TUI-3.g weld (W28 lane)

Date: 2026-10-30. Welder: cc-cmp-welder (sole master writer this tick). Master tip at start: 8bc797e09 (C-WEB-2.e welded).

## Step 1 — cherry-picks (4 explicit shas, never a range)

Lane branch cc/C-TUI-3.g based off 364a52876 (merge-base verified).

| lane sha | master sha | subject |
|---|---|---|
| 56d082b04 | bc61748b4 | away recap wiring + /recap |
| 28df4f6db | 99347a0fe | docs roster + in-step guards |
| ca769cef4 | c34c94962 | review fixes r1 M1 — BlurMsg arm |
| 78b3b97b7 | 874324d35 | review fixes r1 m1+m2 — cap clause + failure voice pins |

en.php: AUTO-MERGED as append union (C-WEB-2.e's 4 host.budget.*/host.help.* keys intact AND this lane's cmd.recap.*/settings.sessions.awayRecap.*/chat.recap.* keys landed; grep-verified both families on the merged blob, 3336 entries). Docs picks applied clean — master moved no README/ARCHITECTURE/COMMANDS/SETTINGS region since base (git diff --stat 364a52876..8bc797e09 on those files: only en.php, +4).

Author law: all 4 picks %ae==%ce==detain@interserver.net (separate greps), zero Co-Authored-By trailers.

## HOLD t636 + RESUME t644 (disk-re-derived)

HOLD order arrived (r2 verdict was not yet on disk); stopped exactly after step 1 gates — no mark, no record, no prune; nothing unwound. KeyHelpTest was not run by me before the hold; closed by the 5.b welder's merged-tree measurement (63T/96505A/5F+1R, foreign baseline unchanged). Resume re-derived: master tip at receipt e6da50cb4 (5.b's five picks + record landed above my four; my picks all --is-ancestor yes). Review loop CLOSED TWICE OVER on disk: REVIEW-2.md 11199 B CLEAN 0C/0M/0MINOR/1NIT + REVIEW-3.md 8692 B CLEAN-weld-ready (lone NIT-1 confirmed, rider handling correct). The original brief's "NIT=2" was a miscount — both rounds carry exactly 1 NIT (the AwayTracker docblock), taken as rider.

## Step R — NIT-1 rider commit

2a38595d1 `C-TUI-3.g: AwayTracker docblock truth — focus-out arrives as BlurMsg` — candy-code/src/Tui/AwayTracker.php only, +3/-2 docblock (BlurMsg on loss / FocusGainedMsg on regain, FocusLostMsg never built by the decoder — re-derived live at sugar-core/src/InputReader.php:515/:518). No pin names the old sentence (RouteTraitParityTest:64 is an arms roster, untouched). %ae/%ce detain (separate greps), trailers 0. Post-rider re-run: own four-class gate EXACT 46T/303A.

## Step 2 — regenerate check (current merged tree, all four with --check)

gen-command-docs / gen-settings-doc / gen-tool-docs / gen-protocol-schema --check: ALL "up to date" rc0 → ZERO drift, NO `weld: W28 regenerate` commit (precedent C-WEB-2.e, 5.b). No npm/dist: neither lane touched candy-code-web; protocol trio byte-unchanged (confirmed by the schema check + file lists).

## Step 3 — §4 mark (pending landed-blob proof below)

plan_comparison.md: heading flipped to `#### ✅ bc61748b4,99347a0fe,c34c94962,874324d35 C-TUI-3.g — …` with dated parenthetical disclosing r1→r2→r3, the 4-pick shape, M1 BlurMsg cure, rider 2a38595d1, merged-tree en.php union + septet 150T/8448A (+6A drift fact for §7), §7 riders (2 DUR mint rows AwayRecapTest 13T→16T / AwayTrackerTest 9T/15A, staleness pair, m3/m4/m5/m6/n1), foreign reds NOT cured, WARN NEXT WELDER counter-re-derivation line. §3 W28 NOT flipped (wave loop's). NOT pushed.
