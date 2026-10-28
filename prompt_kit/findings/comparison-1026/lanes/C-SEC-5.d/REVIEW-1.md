# C-SEC-5.d REVIEW-1 — verdict: FINDINGS (work content sound; the commit does not exist)

Reviewed at: /home/sites/cc-cmp-C-SEC-5.d, branch cc/C-SEC-5.d, base e7e22823a.

## CRITICAL-1 — phantom commit: the branch has ZERO commits over base
- Claimed tip 021e3d4a4 is not a valid object (`git cat-file -t` fatal); `git log e7e22823a..HEAD` empty; HEAD == base.
- ALL lane work is uncommitted: 7 modified tracked files (App.php, KeyboardHandler.php, LaunchContext.php, Renderer.php, en.php, PERMISSIONS.md, KeyboardHandlerTest.php) + 1 untracked (tests/App/LaunchTrustDialogTest.php).
- This is the 5th-round phantom pattern with HONEST content on disk (src burst mtime 04:02:18 — lane was still writing when my first gate run scanned; my review-start porcelain already showed the full change set).
- Fix: explicit-path stage all 8 declared files, ONE commit, author-law verify %ae/%ce detain@interserver.net separately + zero trailers (cannot be verified now — nothing exists to check).

## Work-content assessment (pre-merge review of the uncommitted diff)
- File set == brief's declared set exactly; lang adds ONLY tui.trust.granted|failed; docs touches ONLY PERMISSIONS.md paragraph; no scope creep.
- Grant path CORRECT: resolveTrustDialog routes every granted row through Bootstrap::grantProjectTrust(target, root, $this->settingsWriter) — the same landed 5.a/5.c door (:8096), not a parallel writer; MCP rows ride the MCP door inside it. Write failure -> tui.trust.failed StatusMsg (loud); skip/dismiss -> zero writes, zero notice; trusted/bare root -> no dialog; -p/serve never seed (structural scan: withTrustDialog only App.php+LaunchContext.php; NonInteractive.php names none of TrustDialog/trustDialog/launchTrustDialog/grantProjectTrust).
- Modal law: App::handleKey arm placed first among full-band modals, yields = settingsEditorYields verbatim; KeyboardHandler::shellOwnsKeyboard gains the trustDialog leg; the KH test's positive+negative pair is mutation-honest by trace (live mutation edit denied to this reviewer — reasoned only).
- Latch: once-per-root-per-process, reset seam test-only — per Approach.
- Renderer.php conditional claim: touched; content follows the renderMediaForm/settings full-band precedent (compositor genuinely lives in Tui\Renderer) — substantively justified, but REPORT.md (the required justification artifact) does not exist; ride it in the fix round's commit message/REPORT.

## Gates (all plain-pipe, cwd candy-code, env -u LINES -u COLUMNS; PtyRun never in filter)
- php -l 7/7 OK.
- 'LaunchTrustDialog|KeyboardHandler': 93T/12754A green 3/3 (initial 1F was the write-race artifact; that failure also served as a natural red-before proving the structural pin fails on unwired src).
- 'TrustKeyDocumentationDrift|LangParity|MouseModalGuard': 41T/3638A green.
- 'LaunchContext|AppModelTest': 152T green. 'DocFigure|TuiLang': 100T/5372A green.
- Isolated 'LaunchTrustDialog': 7T — 6 methods + provider leg; brief expected 5 cases (+1 modal leg = +6T shape) — lane ships +7T shape; disclose at weld.

## Minor (defer)
- NIT: App.php use-import inserts Tui\TrustDialog before the Tui\Settings\ block (ordering only, no gate).

## Weld roster
- New test file: candy-code/tests/App/LaunchTrustDialogTest.php (durations row required).

VERDICT: FINDINGS — 1 CRITICAL (no commit; phantom SHA) / 0 MAJOR content / minors deferred. Re-review after the commit exists: content is otherwise mergeable.
