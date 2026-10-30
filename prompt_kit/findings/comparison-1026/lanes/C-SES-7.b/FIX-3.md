# C-SES-7.b FIX-3 (round-3 dispatch) — VERDICT: already fixed at HEAD, no new commit

DISPOSITION: RE-DISPATCH OF LANDED WORK. Round-3 brief ordered F-1 (REVIEW-2):
add `import <path> [--format <fmt>]` into `cli.session.usage`. That cure is ALREADY
ON DISK AT THE BRIEFED TIP — commit d46c6ecbf is exactly the round-2 fix that landed
it (message: "cli.session.usage gains the import verb (r2 F-1)"). Brief carried
tip=d46c6ecbf; F-1 was written against its parent d4c60d561. Fabricating a new tip
over already-correct content is impossible (zero diff; empty commits forbidden).

## Finding -> state (file:line)
- F-1 `candy-code/lang/en.php:1104` `cli.session.usage` — FIXED at d46c6ecbf.
  before (07e49e872 / d4c60d561):
    'cli.session.usage' => 'Usage: candy-code session list [--all|--archived|--children] [--limit N] | show <target> | rename <target> <title…> | delete <target> [--with-children] | pin|unpin|archive|unarchive <target> — <target> is an id, a name or a unique id prefix.',
  after (HEAD d46c6ecbf):
    'cli.session.usage' => 'Usage: candy-code session list [--all|--archived|--children] [--limit N] | show <target> | import <path> [--format <fmt>] | rename <target> <title…> | delete <target> [--with-children] | pin|unpin|archive|unarchive <target> — <target> is an id, a name or a unique id prefix.',
- F-2 NOT mine (weld erratum, per brief). Code untouched.

## Completeness proof (this session)
- `Subcommands.php:1466-1468` sessionUsage() = bare `Lang::t('cli.session.usage')`;
  repo-wide grep shows no second hardcoded verb list — the one string is the whole menu,
  so the round-2 edit fully closes F-1.
- Live door: `HOME=$(mktemp -d) php bin/candy-code session` -> rc=2, printed usage
  contains `import <path> [--format <fmt>]` after `show <target>` (correct position,
  matches SUBCOMMAND_ACTIONS['session'] verb order).

## Gates (targeted, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml)
- `--filter 'StderrEmitterCensus|BinCandyCodeAutoloadGuard|CliLangCatalogue|TreeWideGuardRoster|LangParity|DocFigureProseDrift|SessionImportSubcommandTest|SessionSubcommandsTest'`
  -> Tests: 283, Assertions: 34116, OK (deprecations 2 = baseline shape) — byte-exact vs REVIEW-2/FIX-2.
- `--filter 'ReadmeRosterDrift|GlobDialect'` -> Tests: 24, Assertions: 447, OK — byte-exact vs FIX-2.
- No test added (+0T/+0A): reviewer cure was the one string and verified no guard pins it;
  a new pin would inflate the weld-carry figure delta beyond the brief.
- Staleness pair (ReadmeSuiteFigureDrift) standing-red fleet-wide, unchanged — §7 closeout duty.

## Commits
- New tip: NONE (tree clean at d46c6ecbf; `git status --porcelain` = 0 lines).
- Owning fix SHA remains d46c6ecbf (cat-file'd: commit; branch cc/C-SES-7.b tip; author
  Joe Huss <detain@interserver.net>, %ae+%ce verified in round 2).
- Weld carries unchanged from FIX-2.md items 1-4 (durations row, figure re-pin, §4 erratum, F-4 note).
