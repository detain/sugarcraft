### C-EXT-6.a — Hook `systemMessage` shown as a notice
- item: C-EXT-6 (sources: GAP-HK-08 systemMessage half)
- kind: gap · effort S
- approach:
  - **Root cause.** `ScriptHook.php:1633-1643` treats `systemMessage` as "HONORED BY REMOVAL": it is parsed and then dropped, because no human surface existed. `HOOKS.md:628` says "not read".
  - **Fix.** A string `systemMessage` is clipped and control-escaped, then recorded through C-EXT-1.a's hook-notice helper (`RuntimeNoticeSink::record`). It is human-only and never reaches model context. `suppressOutput` does not hide it, matching Claude Code.
  - **Why this sliver is phase normal.** It is S effort, needs no ruling, and is independent of the http and prompt kinds and of the log.
- files.create: none
- files.modify: `candy-code/src/Hooks/ScriptHook.php` (`fromEnvelope`), `candy-code/lang/en.php` (`hooks.notice.system_message`), `candy-code/docs/HOOKS.md` ("JSON on stdout" :628)
- tests: create `candy-code/tests/Hooks/ScriptHookSystemMessageNoticeTest.php`. Modify `candy-code/tests/Hooks/ScriptHookTest.php` (:651: still absent from `additionalContext`, now present as a notice).
- docs/guards: HOOKS.md
- serialized: [docs:HOOKS.md] [lang:hooks.notice.]
- deps: C-EXT-1.a
- phase: normal
- ruling: none
- risks: served sessions show it only after C-CTX-2's notice drain.

