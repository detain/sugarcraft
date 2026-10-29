# C-PRV-3.b FIX-1 — round-1 review fixes (2026-10-29)

Verdict: MAJOR cured — the single out-of-region byte in the /help docblock co-tenant line is restored to base hyphen; REPORT decomposition erratum applied. Gate green, counts untouched by the fix.

## MAJOR: hyphen restore in candy-code/src/Chat.php (/help docblock line)

Lane commit 3d49d2488 had changed base `- the command list` to em-dash `— the command list` inside the co-tenant [chat:] region (deletion census counted 6 lines, 5 legitimate).

Before (line 13050 at 3d49d2488) — od -c:
```
0000040   a   s   e       4       i   t   e   m       2   )     342 200
0000060 224       t   h   e       c   o   m   m   a   n   d       l   i
```
After (fix) == base 86f80fef8 line 12901 — od -c (diff of the two od dumps: BYTE-EXACT):
```
0000040   a   s   e       4       i   t   e   m       2   )       -
0000060       t   h   e       c   o   m   m   a   n   d       l   i
```
Raw dumps kept at /tmp/opencode/C-PRV-3.b-fix1/{before,after,base}.od; pre/post file copies Chat.php.{pre,post} md5 ba1101fdc9dfd76c90067c0c39a7566b (post). Working diff at fix time: exactly 1 file, 1 insertion, 1 deletion.

## REPORT nit: decomposition erratum

Main-tree REPORT.md lines 9-10: `16 tests` → `18 tests`, `9 tests` → `7 tests` (total 25T/143A was and stays correct; live `grep -c 'public function test'` = 18 + 7; date-stamped ERRATUM inline).

## Gates (cwd=worktree candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

- --filter 'FastCommand|FastHostCommand': OK — Tests: 27, Assertions: 151 (exit 0). Brief said 25T: the filter's name-match also catches 2 derived rows that pre-date the fix — BashTimeout::testAFastCommandIsUntouchedByTheDefaultBound and BinCandyCodeWiring provider data set "Host/Commands/FastHostCommand.php". Proven count-neutral: re-ran with pre-fix bytes swapped in (cp, md5-verified restore) → identical 27/151.
- /help docblock pinning: NONE exists — tests mentioning 'the command list' (KeyHelpTest, VhsTapeContractTest) carry it only in prose comments, no assertion needle reads the Chat.php docblock line.
- --filter Help (kept-green check): 6 FAILURES, ALL base-side drift, NOT from this fix and NOT from the lane: CANDY_CODE_SERVICE_TIER src-reader exists at base 86f80fef8 (LaunchContext/ModelProviderSettings) with no Help-screen row; /skills hint + footer row-count drift also live at base. Representative arm re-run pre-fix/post-fix → identical 1F. Welder/owning-lane domain; lane's own gates (REPORT: 'Fast|Commands|SlashDispatch', doc-drift families) stay green.
