# BRIEF — C-TUI-7.b: Mac Option-as-Meta notice: once per version, tmux-aware
wave ? | phase normal | area tui | item C-TUI-7 (sources: TUI-5-P1) | kind fix · NIT · effort S
deps: none
serialized: [bootstrap:macAltNotice, terminalSpeaksKittyKeyboard, announceMacAltFallback] [lang:cli.notice.mac_alt] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
ruling: none
source/design: 

## Design block (verbatim fields)
### item
C-TUI-7 (sources: TUI-5-P1)
### approach
`Bootstrap::terminalSpeaksKittyKeyboard()` (`Bootstrap.php:10001-10015`) checks only `TERM`/`TERM_PROGRAM`. So tmux-on-kitty (`TERM_PROGRAM=tmux`), and a Terminal.app user who already set Option-as-Meta, see the row on **every** launch.
- A pure `MacAltNotice` also checks the kitty-family variables that survive tmux: `KITTY_WINDOW_ID`, `GHOSTTY_RESOURCES_DIR`, `WEZTERM_PANE`.
- It shows the row once per candy-code version, using a stamp in `~/.candy-code/cache/mac-alt-notice`.
- The Bootstrap methods delegate and keep their public signatures for `MacAltNoticeTest`.
- The COMPARISON §6.6 wording flip belongs to the COMPARISON owner (see Notes).
### files
['candy-code/lang/en.php', 'candy-code/src/Cli/Bootstrap.php', 'candy-code/src/Support/MacAltNotice.php', 'candy-code/tests/Commands/MacAltNoticeTest.php', 'candy-code/tests/Support/MacAltNoticeStampTest.php']
### files.create
`candy-code/src/Support/MacAltNotice.php`
### files.modify
`candy-code/src/Cli/Bootstrap.php` (`macAltNotice()` L9992, `terminalSpeaksKittyKeyboard()` L10001, `announceMacAltFallback()` L10024); `candy-code/lang/en.php` (only if the row wording changes)
### tests

- Modify `candy-code/tests/Commands/MacAltNoticeTest.php`: its L46-81 source-scan pins follow the move.
- Create `candy-code/tests/Support/MacAltNoticeStampTest.php`: the same version is silent; a new version shows once; tmux-on-kitty is silent.
### docs/guards
a ReadPathCensusTest row (the stamp read)
### risks
none notable.
