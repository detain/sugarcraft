
## cure-contrast — CLOSED 2026-10-27 (branch cc/cure-contrast @ 2ebf7d8ce, worktree /home/sites/cc-cmp-cure-contrast, never pushed)

Option taken: LEAD ruling (preferred), zero fallback. AgentColor::resolve() now returns
?\SugarCraft\Core\Util\Color (the class the file already imports — brief said Style\Color;
disk says Core\Util\Color, same round-trip) constructed at ONE site inside resolve();
legibleHex() narrowed to shape+lowercase validation, contrast floor moved to resolve
(literal path only, commented). Consumers WindowStrip.chip() and SessionsPane.agentLine()
take the Color directly; both single-use `use ...Color` imports stripped. Roster:
COLOUR_CONSTRUCTORS_ALLOWED gains 'AgentColor.php' with dated adjudication comment —
NOTE the guard compares getFilename() (basename), so the brief's 'Window/AgentColor.php'
form could never match; basename registered. String pins re-arm: AgentColorTest
->toHex()/assertInstanceOf comparisons, SessionsPaneTest:202 ?->toHex().

Byte-identity: Color::hex is ctype_xdigit/hexdec (case-insensitive) and toHex is
lowercase sprintf, so old(string->Color::hex at consumer) == new(Color at source) on
every path; word path constructs Color::hex($token->toHex()) exactly as consumers did.
Proven by: ShellContrast 14T/2408A EXACT baseline assertion count, green; Window|SessionsPane|AgentColor 299T/4451A green;
literal-probe: both old and new refuse #12ABcd (contrast 1.64 < 4.5).

GATES: --filter ShellContrast green; --filter 'Window|SessionsPane|AgentColor' green;
five-guard: staleness pair red AS ALLOWED (pinned 25,537 vs live 26,012 at tip 066d6e8ce —
figure pin predates the tip's C-EXT/P-MENU fleet, closeout re-pin duty), PLUS ONE
INHERITED RED NOT MINE: DuplicatedTestHelperDrift 'duplication CLIP:
Tui/MenuOverlayFrameTest.php vs Tui/VideoPlayer/VideoPlayerScreenTest.php' — proven
fires identically at base (6 files reverted-in-place run), roster-adjudication owed by
the fleet that introduced that pair, not this cure. php -l clean on all 6 touched files.
Commit: 2ebf7d8ce, %ae/%ce separately grepped detain@interserver.net, zero Co-Authored,
explicit-path staging, no caliber, no push.
