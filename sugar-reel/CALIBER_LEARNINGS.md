# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:reuse-rendering-stack]** sugar-reel is the video member of the family beside candy-flip (GIF) and candy-mosaic (images). Do NOT reimplement renderers, downsamplers, dithering, or color mapping — delegate: half-block/sixel/kitty/iterm2 via candy-mosaic `Renderer`/`Mosaic`, frame downscale + Floyd-Steinberg via candy-flip `Downsampler`/`Dither\FloydSteinberg`, RGB→256/truecolor SGR via candy-palette `Color`, and the playback Model + frame pacing (`Cmd::tick()`/`Cmd::every()`) via candy-core. The lib's own job is decode (ffmpeg/GIF) → `RgbFrame` → pick a reused renderer → pace.

- **[pattern:gate-external-binaries-in-tests]** ffmpeg/ffprobe/ffplay and the audio player are shelled out, so they cannot be assumed present in CI. Probe for the binary and skip live integration tests when it is absent; keep decoders pure and unit-testable by feeding canned `rgb24` byte buffers through a stream rather than spawning ffmpeg. Build every external-CLI invocation as an arg array for `proc_open`/`Cmd::exec` (no shell) — if a string is ever unavoidable, escape every field via `escapeshellarg()` so a hostile `$path` cannot inject. PHPUnit can hang on a stuck subprocess: use a backgrounded `pkill` watchdog, not `timeout`.

- **[pattern:which-late-static-binding]** `candy-core/src/Util/Editor.php:154` defines a `protected static function which()` that uses `static::` late static binding to let test subclasses override it without a DI container or traceable Command objects. Reuse this pattern verbatim: `protected static function which(string $cmd): ?string` with Windows `where` / Unix `command -v` split, `escapeshellarg()` on every argument, and `strtok` parsing the first line of output. `sugar-reel/src/Source/Probe.php` reuses it unchanged.

- **[pattern:ffprobe-json-edge-cases]** ffprobe JSON has three non-obvious shapes to handle: (1) `r_frame_rate` is a fraction string like `"30/1"` or `"30000/1001"` — parse with `explode('/', $rate, 2)` and divide; (2) `duration` is a string in the JSON even when numeric elsewhere; (3) `hasAudio` requires iterating all streams and checking `codec_type === 'audio'` — it is never a top-level boolean. Graceful degradation when ffprobe is absent: `probe()` returns `new self($path, 0, 0, 0.0, 0.0, false)` rather than throwing, so callers that depend on metadata still get a sane default object.

- **[pattern:chunk-framing-edge-case]** `fread()` can return fewer bytes than requested (short read), especially when the subprocess is flushing at end of stream. The loop `while ($bytesRead < $frameBytes) { $chunk = fread(...); ... }` accumulates until a full frame is available, and discards incomplete last frames.

- **[pattern:proc-open-array-form]** Passing `$cmdArray` directly to `proc_open` (not `implode(' ', $cmdArray)`) avoids the shell entirely — no element needs `escapeshellarg()`. Raw strings go directly as argv; the shell is bypassed. Use `implode(' ', $cmdArray)` + `escapeshellarg()` only when a shell string is required.

- **[pattern:gif-frame-size]** `FlipDecoder::decode()` already does area-average downsampling internally; `GifDecoder` receives frames at `cellsW × cellsH` (not `cellsH * 2` — that scaling is specific to the half-block ffmpeg path where ffmpeg scales to `cellsH * 2` rows so each terminal cell maps to 2 source rows).

- **[pattern:undersized-byte-buffer-warning-suppression]** `toGd()` clamps missing bytes to black rather than emitting `Uninitialized string offset` warnings when the byte buffer is shorter than `w*h*3`. This documents that `RgbFrame` is a trust-but-verify value object.

- **[pattern:foreach-on-iterable-objects]** `foreach ($decoder as $frame)` internally calls `getIterator()`, creating a fresh generator each iteration. To iterate an existing iterator, use `foreach ($iterator as $frame)` — not `foreach ($decoder as $frame)`.

- **[pattern:bt601-vs-bt709-luma]** The integer formula `(77*R + 150*G + 29*B) >> 8` used by tplay is BT.601 (SMPTE-C), not BT.709. Visually indistinguishable in practice but documented explicitly so future porters know why the spec formula differs.

- **[pattern:sgr-per-cell-reset]** Each cell in `AsciiRenderer` emits `\x1b[0m` after the char to prevent color bleed into adjacent cells — important for delta-repaint rendering.

- **[pattern:halfblock-cell-dimensions]** `cellDimensions(Mode::HalfBlock)` returns `[1, 2]` because each terminal cell maps to 2 source rows (the `▀` char uses top+bottom pixel). All other modes return `[1, 1]`.

- **[pattern:auto-probe-ci-warnings]** `Mosaic::diagnose()` and `Probe::colorProfile()` emit PHP warnings in non-TTY (CI) environments — tests for `auto()` may show warnings but still pass. These are environmental, not bugs.

- **[pattern:mosaic-delegation]** `HalfBlockRenderer` bridges `RgbFrame → ImageSource → MosaicHalfBlockRenderer`, reusing the full image rendering stack without reimplementing SGR output.

- **[pattern:backward-seek-requires-reopen]** Video decoders are forward-only streams. Seeking backward re-opens the decoder from the source file and skips forward to the target frame. This is O(n) but necessary — a frame cache would be a future optimization.

- **[pattern:view-must-be-pure]** The TEA `view()` function should only render, not update model state. In `sugar-reel`, delta repaint state (prevBuffer) is managed in `update()` via `withNewFrame()`, not in `view()`.

- **[pattern:sync-inline-math-after-speed-change]** After speed changes, the Sync object's internal speed was stale. Inlined the `targetFrame` computation directly in `updateTick()` using `$this->speed` so changes take effect immediately on the next tick.

- **[pattern:scripted-input-program-simulator-tea]** `ScriptedInput` (declares key sequence) and `ProgramSimulator` (runs a TEA Model through scripted input) from candy-testing enable behaviour-testing the Player without real video or a PTY.

- **[pattern:fake-decoder-isolated-testing]** A test helper implementing `Decoder` that yields synthetic `RgbFrame[]` allows unit-testing the Player without ffmpeg or live video files.

- **[pattern:audio-graceful-degradation]** `AudioPlayer.start()` is a silent no-op when no binary is found or when the video has no audio track. The `buildCommand() === null` pattern gates the early return — callers don't need to check `hasAudio` or binary presence separately.

- **[pattern:proc-open-pipe-fd-cleanup]** When `proc_open` returns `false` or `0` (binary missing), the three pipe handles may have been partially-created before failure. All must be `fclose()`d in the failure path to avoid FD leaks. Guard with `is_resource($pipe)` before each `fclose()`.

- **[pattern:fake-audio-player-test-double]** `FakeAudioPlayer` test double overrides `buildCommand()` to return a controlled command (or null), enabling testing of the start/stop/isPlaying lifecycle without live ffplay/mpv binaries.

- **[pattern:mosaic-kitty-no-static-factory]** candy-mosaic has `KittyRenderer` class and `Mosaic::iterm2()` but no `Mosaic::kitty()` static factory. For kitty mode, use `new Mosaic(new KittyRenderer(), Capability::universal(), null, null, null)->render(...)` directly. Always check the actual candy-mosaic API rather than assuming naming symmetry.

- **[pattern:kitty-uses-dcs-apc-not-osc]** The kitty graphics protocol uses DCS `\x1b_Ga=...` (not the OSC `\x1b]1337;` that iTerm2 uses). These are distinct protocols — kitty uses DCS APC sequences while iTerm2 uses OSC 1337.

- **[pattern:graphics-renderer-cell-dimensions-always-1x1]** The graphics protocols fill the terminal with the image; they don't use a cell grid. cellDimensions returns [1,1] meaning "one virtual cell = the whole image".

- **[pattern:synthetic-gif-for-example-without-binary-deps]** `examples/play.php` generates a rainbow-gradient GIF using ext-gd and saves it to `/tmp` when run without a video argument. This allows the full player to be demonstrated without any video files or external binaries in the example. The player shelled out to ffprobe during open() — if ffprobe is absent, `VideoSource::probe()` gracefully returns defaults and playback still works (audio just won't be detected).

- **[pattern:vhs-tape-shows-help-or-static-output]** For a TUI player with keyboard interaction, the VHS demo (`play.tape`) shows the `--help` output rather than a video playback — it produces clean static terminal output that's perfect for GIF encoding. The player is demonstrated to work via the examples/play.php, not necessarily via the VHS tape.

- **[pattern:player-open-vs-open-for-test]** `Player::open()` wraps `VideoSource::probe()` + `DecoderFactory::create()`, requiring an actual file path and optionally ffprobe. `Player::openForTest()` takes a Decoder directly and bypasses VideoSource::probe(), used in unit tests with FakeDecoder. The example (not tests) uses `Player::open()` with the generated synthetic GIF path — ffprobe is optional for the GIF path since `VideoSource::probe()` degrades gracefully.
