# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:reuse-rendering-stack]** sugar-reel is the video member of the family beside candy-flip (GIF) and candy-mosaic (images). Do NOT reimplement renderers, downsamplers, dithering, or color mapping — delegate: half-block/sixel/kitty/iterm2 via candy-mosaic `Renderer`/`Mosaic`, frame downscale + Floyd-Steinberg via candy-flip `Downsampler`/`Dither\FloydSteinberg`, RGB→256/truecolor SGR via candy-palette `Color`, and the playback Model + frame pacing (`Cmd::tick()`/`Cmd::every()`) via candy-core. The lib's own job is decode (ffmpeg/GIF) → `RgbFrame` → pick a reused renderer → pace.

- **[pattern:gate-external-binaries-in-tests]** ffmpeg/ffprobe/ffplay and the audio player are shelled out, so they cannot be assumed present in CI. Probe for the binary and skip live integration tests when it is absent; keep decoders pure and unit-testable by feeding canned `rgb24` byte buffers through a stream rather than spawning ffmpeg. Build every external-CLI invocation as an arg array for `proc_open`/`Cmd::exec` (no shell) — if a string is ever unavoidable, escape every field via `escapeshellarg()` so a hostile `$path` cannot inject. PHPUnit can hang on a stuck subprocess: use a backgrounded `pkill` watchdog, not `timeout`.

- **[pattern:which-late-static-binding]** `candy-core/src/Util/Editor.php:154` defines a `protected static function which()` that uses `static::` late static binding to let test subclasses override it without a DI container or traceable Command objects. Reuse this pattern verbatim: `protected static function which(string $cmd): ?string` with Windows `where` / Unix `command -v` split, `escapeshellarg()` on every argument, and `strtok` parsing the first line of output. `sugar-reel/src/Source/Probe.php` reuses it unchanged.

- **[pattern:ffprobe-json-edge-cases]** ffprobe JSON has three non-obvious shapes to handle: (1) `r_frame_rate` is a fraction string like `"30/1"` or `"30000/1001"` — parse with `explode('/', $rate, 2)` and divide; (2) `duration` is a string in the JSON even when numeric elsewhere; (3) `hasAudio` requires iterating all streams and checking `codec_type === 'audio'` — it is never a top-level boolean. Graceful degradation when ffprobe is absent: `probe()` returns `new self($path, 0, 0, 0.0, 0.0, false)` rather than throwing, so callers that depend on metadata still get a sane default object.

- **[pattern:chunk-framing-edge-case]** `fread()` can return fewer bytes than requested (short read), especially when the subprocess is flushing at end of stream. The loop `while ($bytesRead < $frameBytes) { $chunk = fread(...); ... }` accumulates until a full frame is available, and discards incomplete last frames.

- **[pattern:proc-open-array-form]** Passing `$cmdArray` directly to `proc_open` (not `implode(' ', $cmdArray)`) avoids shell injection and is the secure pattern per video_plan.md. Each element is individually escaped via `escapeshellarg()`.

- **[pattern:gif-frame-size]** `FlipDecoder::decode()` already does area-average downsampling internally; `GifDecoder` receives frames at `cellsW × cellsH` (not `cellsH * 2` — that scaling is specific to the half-block ffmpeg path where ffmpeg scales to `cellsH * 2` rows so each terminal cell maps to 2 source rows).

- **[pattern:undersized-byte-buffer-warning-suppression]** `toGd()` clamps missing bytes to black rather than emitting `Uninitialized string offset` warnings when the byte buffer is shorter than `w*h*3`. This documents that `RgbFrame` is a trust-but-verify value object.

- **[pattern:foreach-on-iterable-objects]** `foreach ($decoder as $frame)` internally calls `getIterator()`, creating a fresh generator each iteration. To iterate an existing iterator, use `foreach ($iterator as $frame)` — not `foreach ($decoder as $frame)`.
