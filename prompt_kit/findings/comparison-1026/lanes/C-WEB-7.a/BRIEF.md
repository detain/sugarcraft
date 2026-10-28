# BRIEF — C-WEB-7.a: Shell tail trailing-edge flush + `discard()` docblock truth
wave ? | phase normal | area web7 | item C-WEB-7 (sources: WEB-4-P1, WEB-6-P3) | kind fix · MINOR · effort S
deps: none
serialized: [hot:candy-code/src/Tools/BuiltIn/Bash.php]
ruling: none
source/design: 

## Design block (verbatim fields)
### item
C-WEB-7 (sources: WEB-4-P1, WEB-6-P3)
### approach

- **Root cause, the tail.** `ShellTailRing::onChunk()` (`candy-code/src/Tools/ShellTailRing.php:55-67`) only sets `dirty` for a chunk that arrives within `frameIntervalSeconds` (0.5 s, `Bash.php:708`) of the last frame. Only `onChunk()` and `finish()` (:70-76) push frames.
- Scenario: a build prints its last lines just after a frame, then goes quiet for minutes. The web ToolCard tail is missing those lines until exit.
- The class docblock (:19-24) promises "the next frame's tail carries them", which is false when no next chunk comes.
- **Key finding: no new loop seam is needed.** The review assumed "the onChunk seam must grow an idle callback". In fact the capture loops already call `$onWait` on every pass:
- `CapturesProcessOutput::runCaptured()` calls it once per ≤200 ms select slice (:243-245), and `awaitExitUntil()` (:351-365) calls it too;
- `runCapturedInteractive()` calls it every 50 ms read pass (:545-547).
- Bash simply passes `$heartbeat` there today (`Bash.php:590-592`), and that is null on a plain `execute()`.
- **Fix, the ring.** Add `ShellTailRing::tick()`. It pushes when `dirty` and the interval since the last frame has elapsed; otherwise it does nothing.
- **Fix, Bash.** `Bash::runCommand()` (:529-543) builds `$onWait`:
- when the ring exists: a closure that calls `$progress->tick()` and then `$heartbeat` if it is non-null;
- otherwise: `$heartbeat` unchanged.
- Both `runCaptured`/`runCapturedInteractive` calls receive `$onWait`. The trait is unchanged, and `finish()` stays the exit flush.
- Rewrite the class docblock's "not lost" sentence to name `tick()`.
- **WEB-6-P3.** `SessionFileStore::discard()`'s docblock (`candy-code/src/Server/Auth/SessionFileStore.php:191-198`) says live sessions "ride over the restart unsigned-out". The opposite is true: with no file, every live session is signed OUT at restart, as `CookieSessions.php:154-160` states. Reword it to "every live session is signed out at the restart — the conservative direction". Prose only.
- **What the user sees:** a quiet build's last lines appear in the web tool card within about 0.7 s instead of at exit.
### files
['candy-code/src/Server/Auth/SessionFileStore.php', 'candy-code/src/Tools/BuiltIn/Bash.php', 'candy-code/src/Tools/ShellTailRing.php', 'candy-code/tests/Tools/BashToolProgressTest.php', 'candy-code/tests/Tools/ShellTailRingTest.php']
### files.create
none
### files.modify
`candy-code/src/Tools/ShellTailRing.php` (new `tick()`, class docblock), `candy-code/src/Tools/BuiltIn/Bash.php` (`runCommand` — ring/`$onWait` composition and the two capture calls only), `candy-code/src/Server/Auth/SessionFileStore.php` (`discard()` docblock)
### tests

- Modify `candy-code/tests/Tools/ShellTailRingTest.php`, a cadence pin with the clock seam:
- a chunk at t=0 → frame 1;
- a chunk at t=0.1 → no frame;
- `tick()` at t=0.3 → no frame;
- `tick()` at t=0.6 → frame 2 carries both chunks;
- `tick()` at t=0.9 → nothing (not dirty);
- `finish()` afterwards → nothing.
- Modify `candy-code/tests/Tools/BashToolProgressTest.php`. Run `printf a; sleep 0.05; printf b; sleep 1.2` with a timestamping emitter, then assert:
- exactly 2 frames;
- the second carries `ab`;
- the second was emitted at least 0.4 s before `execute()` returned.
- Also pin that a run with no emitter still beats a supplied heartbeat (`executeWithHeartbeat`).
### docs/guards
none (no roster, no doc page); no child-lifetime row (no new spawn)
### risks

- `Bash.php` is also edited by design-tool steps (BackgroundShellRegistry rows, `sandboxRefusal()` extraction, sandbox types), so the scheduler serializes them by path. The edit here is confined to the `$progress`/`$onChunk` block and the two capture-call arguments.
- The Bash integration pin is timing-based. The margins are wide (a 1.2 s sleep against an emit at about 0.5–0.7 s).
