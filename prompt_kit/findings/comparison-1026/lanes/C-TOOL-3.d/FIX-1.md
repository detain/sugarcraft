# C-TOOL-3.d — FIX-1 (round-1 cures)

Date 2026-10-28. Fixed lane: cc-cmp fixer. Amended IN PLACE (single-commit law, master..branch stays 1):
tip `ab80609d77ff5b478f1007152a40a7a845610fd0` → **`1de8e9266c09c7ed0c8ed98d9b138594d5f7f200`** (same 12 files, +576/−4).
Author+committer `detain@interserver.net` (separate greps = 1 each), zero Co-Authored trailers, porcelain clean, NEVER pushed.

## MAJOR — DuplicatedTestHelperDrift red — FIXED
`candy-code/tests/Host/Commands/ShellsCommandTest.php`: helper `registry()` (:235) renamed to `shellsRegistry()` — definition + all 7 `$this->registry()` call sites. Distinct session label `cc-cmp3d-launch` kept (rename preferred over licensing a fifth byte-identical twin). Blob-verify at tip: `grep -c 'function shellsRegistry'` = 1, `grep -c 'function registry('` = 0.

## MINOR — DocFigure dated comment provenance — FIXED
`candy-code/tests/Config/DocFigureProseDriftTest.php`: the +5-line cure's dated comment reworded to attribute the 36→37 growth and the both-pages thirty-seven re-spell to **C-AGT-5.a `5cd68af7e`** (ScheduleTool landing; sole author per `git log -S 'thirty-seven' -- candy-code/docs` re-run in the worktree). The false 'C-TOOL-3.c era / Shell-family directory grew' claim deleted. No 'forty-six' history claim existed in the lane's comment (the file's single 'forty-six' at :6365 is a pre-existing legit map entry in another block — untouched). Map entry `$wordNumbers['thirty-seven'] = 37;` byte-identical (W13 collision plan: content-identity); nothing else in the guard file touched (lane diff vs base = exactly 5 lines).

## NIT — report arithmetic (record only, no code)
en.php is **19** rows (2 `cmd.shells.*` + 17 `host.shells.*`), not 20/18+2 as written; live `--filter ShellsCommand` shape is **10T/51A** (9 lane tests + derived BinCandyCodeWiring census row), not 9T/45A file-only. Corrections appended to REPORT.md.

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml)
- `--filter 'DocFigureProseDriftTest|GlobDialect|DuplicatedTestHelperDrift|ChildWallClock'` → **OK: Tests: 130, Assertions: 16141, PHPUnit Deprecations: 2** (was 1F at old tip; 0F now, counts unchanged — rename adds no methods).
- `--filter ShellsCommand` → **OK: Tests: 10, Assertions: 51** +2 deprecations.
- `--filter 'MenuBarLabelDerivation|PaletteSupersetDispatch'` → **OK: Tests: 17, Assertions: 368** +2 deprecations.
- durations.tsv / suite-figure.json / dist untouched; no git stash; no git checkout of repo files.
