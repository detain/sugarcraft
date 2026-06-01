# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:reuse-rendering-stack]** sugar-reel is the video member of the family beside candy-flip (GIF) and candy-mosaic (images). Do NOT reimplement renderers, downsamplers, dithering, or color mapping — delegate: half-block/sixel/kitty/iterm2 via candy-mosaic `Renderer`/`Mosaic`, frame downscale + Floyd-Steinberg via candy-flip `Downsampler`/`Dither\FloydSteinberg`, RGB→256/truecolor SGR via candy-palette `Color`, and the playback Model + frame pacing (`Cmd::tick()`/`Cmd::every()`) via candy-core. The lib's own job is decode (ffmpeg/GIF) → `RgbFrame` → pick a reused renderer → pace.

- **[pattern:gate-external-binaries-in-tests]** ffmpeg/ffprobe/ffplay and the audio player are shelled out, so they cannot be assumed present in CI. Probe for the binary and skip live integration tests when it is absent; keep decoders pure and unit-testable by feeding canned `rgb24` byte buffers through a stream rather than spawning ffmpeg. Build every external-CLI invocation as an arg array for `proc_open`/`Cmd::exec` (no shell) — if a string is ever unavoidable, escape every field via `escapeshellarg()` so a hostile `$path` cannot inject. PHPUnit can hang on a stuck subprocess: use a backgrounded `pkill` watchdog, not `timeout`.
