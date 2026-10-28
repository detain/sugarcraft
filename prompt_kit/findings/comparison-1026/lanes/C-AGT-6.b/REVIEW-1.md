# C-AGT-6.b — REVIEW-1 (round 1)

VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 1 MINOR step-local / 2 NIT)

Verified on disk (worktree /home/sites/cc-cmp-C-AGT-6.b, tip 3abfb69ef, master..HEAD = 1, parent bb8d741c2):
- diff == exactly the 6 declared files; no suite-figure/durations/dist; porcelain 0 after gates; %ae and %ce separate greps 1/1 detain; zero trailers.
- Gates reproduced (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml): ContextMentionsTest OK 19T/172A (2 pre-existing deprecations); ReviewCommandTest OK 17T/122A; CommandDocsDrift|LangParity|LangLaunchLocale OK 26T/2889A; DocFigure|ReadmeSuiteFigure 95T with ONLY the staleness pair red (25,537 vs live 26,518 — fleet carry, weld re-pin; lane may not touch the pair per §1.5).
- Range legs match design: A..B → `git diff --no-ext-diff --no-textconv --no-color -M --patch A B`; A...B → `diff --merge-base` with exit-129 merge-base+diff fallback, ancestor validated against OBJECT_ID_PATTERN (never fed an unparsed stdout back to git); all through bounded GitRunner (no new proc_open site). isDiffRef re-admits only the two shapes (sides cut at first `...`/`..`, per-side brace check, dash/dot/separator sides refused); refError pre-resolves each side fail-closed.
- Non-vacuity re-reasoned: dropping the diff() range arm reddens the two-dot, three-dot, self-range, hostile-diff.external and empty-leg CM tests (5); dropping the isDiffRef range arm reddens the flipped attach + isDiffRef unit block in RCT. The RCT flip is design-mandated ("refusal arms flip back to attached") and replaced by two stronger refuse arms asserting no billed turn — not accept-bleed. Trim casualties ('@diff:base..' → attaches '@diff:base', '@diff:....' → bare) pinned honestly as pre-existing trim-law behavior; unit paths still refuse.

MINOR (step-local): src/Attachments/ContextMentions.php:410,433,436 — the range leg is porcelain without path disambiguation. A range side that is a tracked PATH instead of a rev (e.g. `@diff:HEAD..src/foo.php` — a plausible user spelling) makes `git diff HEAD src/foo.php` succeed (measured rc=0) and attach the WORKING-TREE-vs-HEAD path diff under the header "committed changes from HEAD to …(the working tree … not included)" — the docblock's "the working tree can neither enter nor race the snapshot" is false for that form, and a billed review reads mislabeled content. (/review itself is safe: the side fails `rev-parse <path>^{tree}`.) Fix: append `'--'` as the final argv in all three diff captures (measured: `git diff HEAD f --` exits 128 while `git diff A B --` runs the full rev pair), plus one pin asserting `@diff:HEAD..f.txt` refuses with 'could not diff'.

NIT: REPORT.md says CM "+6 tests" (file census: 14→19 = +5) and "live 26,519" (measured 26,518); lead-brief's "36T/189A" combined is 36T/294A (38T/306A with the filter's 2 extra matches). Prose counts, not gates.
NIT: gen-command-docs --check not runnable here (shell permission map); COMMANDS.md regen is nevertheless green through CommandDocsDriftTest at the claimed 26T/2889A.

Fix the one MINOR in-step; re-review with a fresh reviewer.
