# sugar-reel Code Review

## Summary

sugar-reel is a terminal video player porting Charmbracelet's gum/reel-style video playback to PHP. It implements a TEA (The Elm Architecture) model via candy-core, decodes video via ffmpeg or candy-flip (for GIFs), and renders frames through multiple terminal protocols (ASCII, ANSI256, TrueColor, HalfBlock, QuarterBlock, Sixel, Kitty, iTerm2).

The code is generally well-structured with good documentation, comprehensive CALIBER_LEARNINGS, and solid patterns (immutable builders, factory seams for testability, graceful binary-degradation). However, several significant issues exist: a likely ineffective SIGSTOP/SIGCONT audio pause mechanism, widespread `/fake` test-path branching embedded in production code paths, duplicated audio-rebuild logic, and a 1223-line Player class that violates single-responsibility at its current size. The library is also fundamentally synchronous with no ReactPHP async integration, which limits its utility in an async ecosystem.

---

## Critical Issues (file:line format)

### 1. `AudioPlayer.php:122-128` — SIGSTOP pause mechanism likely ineffective
```php
public function pause(): void
{
    if (!is_resource($this->processHandle) || !\defined('SIGSTOP')) {
        return;
    }
    proc_terminate($this->processHandle, SIGSTOP);
}
```
**Issue**: `proc_terminate()` sends SIGSTOP to suspend the process, but ffmpeg/ffplay child processes may not respond correctly to SIGSTOP when they have active pipe I/O. More critically, SIGSTOP is a job-control signal that only works when the process is in the same process group as the PHP parent — which may not hold when running under a PTY or in certain terminal emulators. Additionally, `proc_terminate()` returns `true` even if the signal is sent to a process that is already in a stopped state, but never checks the return value. If SIGSTOP fails silently, audio continues playing while the video is paused, breaking A/V sync.

**Recommended fix**: Use a pause flag that prevents reading from the decoder pipe, or use SIGTTOU/SIGTTIN to gently suspend without the harsh SIGSTOP. Alternatively, kill and recreate the audio subprocess on pause/resume (like the seek path does) — though this adds latency.

### 2. `AudioPlayer.php:135-141` — SIGCONT resume same concerns
```php
public function resume(): void
{
    if (!is_resource($this->processHandle) || !\defined('SIGCONT')) {
        return;
    }
    proc_terminate($this->processHandle, SIGCONT);
}
```
Same issues as pause. SIGCONT may not reliably wake a process that was SIGSTOP'd under a PTY.

### 3. `AudioPlayer.php:95-104` — stop() calls proc_close without checking exit status
```php
public function stop(): void
{
    if (!is_resource($this->processHandle)) {
        return;
    }
    proc_terminate($this->processHandle, SIGTERM);
    proc_close($this->processHandle);
    $this->processHandle = null;
}
```
`proc_close()` returns the exit code but it is discarded. A non-zero exit code may indicate an abnormal termination (killed by signal). The exit code should be checked and potentially logged.

### 4. `FfmpegDecoder.php:355-362` — Same issue: exit code discarded in close()
```php
if ($this->process !== null && is_resource($this->process)) {
    $exitCode = proc_close($this->process);
    $this->process = null;
    // If ffmpeg exited non-zero (and we didn't already consume all frames),
    // that indicates an error. We don't throw here since next() returning
    // null will signal end of stream to the caller.
}
```
The comment acknowledges the issue. A non-zero exit from ffmpeg may indicate corruption or truncation. This should at minimum be logged or stored for later inspection.

---

## High Severity Issues

### 5. `Player.php:902-920` — `/fake` test path embedded in production rebuildDecoderAt()
```php
private function rebuildDecoderAt(int $cellsW, int $cellsH, Mode $mode, int $frameIndex): array
{
    if ($this->videoPath === '/fake') {
        $this->decoder->open($this->videoPath, $cellsW, $cellsH, $this->fps, $mode);
        $decoder = $this->decoder;
    } else {
        $this->decoder->close();
        $decoder = DecoderFactory::create(...);
    }
    // ...
}
```
Every call to `rebuildDecoderAt()` pays the cost of a string comparison `=== '/fake'`. This is a test seam forced into production code. The `/fake` path is also fragile — it calls `$this->decoder->open()` which is an implementation detail of FakeDecoder, and the cast `$this->decoder` to `$decoder` type hints as `Decoder` may mask bugs. This should be a proper test double or a sealed test-only subclass.

**Recommended fix**: Use a `DecoderInterface` that exposes a `reopen()` method only in the test implementation, or inject a `DecoderFactory` seam that returns a spy in tests.

### 6. `Player.php:1089-1099` — `frameAt()` creates orphaned decoder process
```php
public function frameAt(float $sec): ?RgbFrame
{
    if ($this->videoPath === '' || $this->videoPath === '/fake') {
        return null;
    }
    $decoder = DecoderFactory::create(...);
    $frame = $decoder->next();
    $decoder->close();
    return $frame;
}
```
This spawns a full ffmpeg process to grab ONE frame for a thumbnail, reads it, and closes immediately. This is correct but expensive. More critically, the frame is decoded at the player's current mode/resolution, not necessarily at a thumbnail-appropriate size. The decoder creation is not cached or shared.

### 7. `Player.php:926-1019` — `withSeek()` and `seekToSeconds()` duplicate identical audio rebuild logic

Both methods (lines 931-942 and 1058-1068) contain identical code:
```php
$newAudio = $this->audioPlayer;
if ($this->audioPlayer !== null) {
    $this->audioPlayer->stop();
    $startMs = (int)round(($targetIndex / $this->fps) * 1000);  // or ($sec * 1000)
    $factory = $this->audioFactory ?? static fn(string $path, ?int $ms): AudioPlayer => new AudioPlayer($path, $ms);
    $newAudio = $factory($this->videoPath, $startMs);
    if (!$this->paused) {
        $newAudio->start();
    }
}
```
Extract this to `private function rebuildAudio(?int $startMs): ?AudioPlayer`.

### 8. `Player.php:1189-1217` — `mutate()` accepts ALL 18 Player fields; silent `??` means `false`/`0` can't be passed through

```php
private function mutate(array $changes): self
{
    return new self(
        // ...
        ended: $changes['ended'] ?? $this->ended,  // passing ended=>false is IGNORED
        // ...
    );
}
```
The comment at line 1206-1207 acknowledges this: "?? is null-coalescing, so passing ended => false / frameIndex => 0 through mutate() is honourée (false/0 are not null)". This is a footgun — any caller who writes `$this->mutate(['ended' => false])` will be surprised. Use `array_key_exists()` or a dedicated builder instead.

### 9. `Player.php:664-693` — HalfBlock inline rendering duplicates MosaicHalfBlockRenderer path

The `frameToBuffer()` method (lines 664-693) has a dedicated inline HalfBlock rendering path that mirrors exactly what `HalfBlockRenderer` (which delegates to `candy-mosaic`) does. The test `testHalfBlockInlineMatchesMosaicRenderer` guards parity, but this is fragile — any change to the inline math (luma, color packing, glyph) won't automatically update the mosaic path and vice versa. The inline path is marked "NEVER reached by the Player runtime" per the comment at line 41.

**Refactor**: Consider removing the inline path and always routing through `RendererFactory::create(Mode::HalfBlock)`, or removing the `HalfBlockRenderer` class entirely and routing all HalfBlock through the inline Buffer path.

---

## Medium Severity Issues

### 10. `GifDecoder.php:57-77` — `open()` silently falls back to empty array on decode failure

```php
$this->frames = FlipDecoder::decode($source, $decodeW, $decodeH);
// Best-effort time seek: all GIF frames are already in memory...
if ($startSec > 0.0 && $fps > 0.0) {
    $this->frameIndex = min(count($this->frames), (int) round($startSec * $fps));
}
```
If `FlipDecoder::decode()` returns `[]`, the player will show a black screen with no error. At minimum this should be logged or a warning emitted. The `RgbFrame` returned will be 0×0 dimensions in some paths.

### 11. `FfmpegDecoder.php:313-333` — PNG buffer grows unbounded; no size limit

```php
private function nextPng(): ?RgbFrame
{
    while (true) {
        $end = strpos($this->pngBuffer, self::PNG_IEND);
        if ($end !== false) {
            // ...
        }
        $chunk = $this->stdout !== null && is_resource($this->stdout)
            ? fread($this->stdout, 65536)
            : false;
        // ...
        $this->pngBuffer .= $chunk;  // grows until IEND found
    }
}
```
If a PNG frame is malformed (no IEND marker), `$this->pngBuffer` grows until memory is exhausted. Should cap buffer size and abort after a threshold.

### 12. `FfmpegDecoder.php:109-122` — `is_file()` probe before `proc_open` is racy

```php
if (!self::isNetworkSource($source) && !is_file($source)) {
    throw new \RuntimeException("video source not found: {$source}");
}
```
Between `is_file()` returning true and `proc_open()` executing, the file could be deleted. This is a TOCTOU race. Acceptable for this use case, but worth documenting.

### 13. `Player.php:535-572` — Mode cycle rebuilds decoder and renderer on every `m` keypress

```php
if ($msg->type === KeyType::Char && $msg->rune === 'm') {
    // ...
    [$decoder, $frame] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $nextMode, $this->frameIndex);
    $nextPlayer = $this->mutate([
        'mode' => $nextMode,
        'decoder' => $decoder,
        'currentFrame' => $frame ?? $this->currentFrame,
        'renderer' => RendererFactory::create($nextMode, $this->ramp, $this->cellPxW, $this->cellPxH),
    ]);
}
```
This closes and reopens a decoder (spawning a new ffmpeg process) just to switch rendering mode. For text modes (which decode to cell resolution, not full pixel resolution), this is particularly expensive. Consider lazy-rebuilding the decoder only when the new mode's resolution requirements differ.

### 14. `AsciiRenderer.php:52-58` — Potential undefined offset if bytes buffer is short

```php
$idx = $rowOffset + ($x * 3);
if ($idx + 2 >= $len) {
    $r = $g = $b = 0;
} else {
    $r = \ord($bytes[$idx]);
    $g = \ord($bytes[$idx + 1]);
    $b = \ord($bytes[$idx + 2]);
}
```
This guard exists but `Player::pixelRgb()` at line 819-826 has similar but not identical logic. Inconsistency between these two pixel-access patterns could lead to divergent behavior when frames are malformed.

### 15. `Player.php:866-875` — `renderPlaceholder()` creates new array each call

```php
private function renderPlaceholder(): string
{
    $lines = [];
    for ($i = 0; $i < $this->cellsH; $i++) {
        $lines[] = str_repeat(' ', $this->cellsW);
    }
    $body = implode("\r\n", $lines);
    $status = "loading...  space play  q quit";
    return $body . "\r\n" . $status;
}
```
This allocates a new array and does string operations on every call (which happens for the initial render before the first frame). Could be memoized.

### 16. `Reel.php:245-251` — Subtitle file silently ignored on error

```php
if ($this->subtitlePath !== null) {
    $raw = @file_get_contents($this->subtitlePath);
    if (is_string($raw) && $raw !== '') {
        $subtitles = WebVtt::parse($raw);
    }
}
```
`file_get_contents()` failure is silently ignored. If a user provides a subtitle path that doesn't exist or is unreadable, they get no feedback. Should log a warning or emit a user-visible notice.

### 17. `Sync.php:51-54` — Hardcoded skip limit of 2 frames

```php
public static function shouldSkip(int $currentFrame, int $targetFrame): bool
{
    return $targetFrame - $currentFrame > 2;
}
```
The skip limit of 2 frames is hardcoded with no configuration option. On very low-FPS sources (e.g., 8fps animation), a 2-frame skip represents a large time jump. This should be a constructor parameter or at minimum documented as a tuning constant.

---

## Low Severity Issues

### 18. `Player.php:79-103` — Constructor has 18 parameters (high coupling)

The `Player` constructor takes 18 parameters. This is very high coupling — adding or reordering a parameter requires updating all call sites. Consider a builder pattern or a configuration object.

### 19. `AudioPlayer.php:27` — `AudioPlayer` class is not `final`

Unlike most other classes in the codebase (`Player`, `Reel`, `FfmpegDecoder`, etc. are all `final`), `AudioPlayer` is a regular class. Since it has a `protected` method `buildCommand()` designed for subclassing (per the CALIBER_LEARNINGS: "fake-audio-player-test-double"), this is intentional, but conflicts with the project convention.

### 20. `Player.php:219-222` — openForTest creates renderer with Mode::HalfBlock regardless of actual mode

```php
$renderer = RendererFactory::create(Mode::HalfBlock, $ramp, $cellPxW, $cellPxH);
```
The test factory always creates a HalfBlock renderer, but the mode is also hardcoded to `Mode::HalfBlock`. This may mask mode-specific rendering bugs in tests.

### 21. `FfmpegDecoder.php:196-200` — `buildCommand()` is public but is it needed outside tests?

The method is public static and has extensive doc comments. If it's only used internally and for unit testing, it should be `private static` to reduce the public API surface.

### 22. `LumaRamp.php:69-72` — Static memoization cache not cleared between Player instances

```php
private static array $lutByName = [];
```
The LUT cache is process-global. If different `ramp` values are used across player instances in long-running processes, this is fine. But if the ramp definition itself changes (impossible currently since RAMPS is const), there is no cache-invalidation mechanism.

### 23. `WebVtt.php:168-189` — `parseTimestamp()` accepts `00:00:00.000` and `00:00:00,000` but not all variants

```php
$ts = trim(str_replace(',', '.', $ts));
```
Only handles comma decimal separator replacement. Other edge cases like trailing whitespace, extra dots, or negative times are not handled gracefully. Returns `null` which is appropriate, but could have a more informative error.

### 24. `Probe.php:81-103` — `which()` uses `shell_exec()` with string output parsing

```php
$out = @shell_exec($shell);
if (!is_string($out)) {
    return null;
}
$first = strtok(trim($out), "\r\n");
return $first ?: null;
```
`strtok()` with `"\r\n"` will treat `\r\n`, `\r`, and `\n` as tokens. On the first call, `strtok` returns the first token or `false` if empty. The `return $first ?: null` is problematic: if `$first === '0'` or any falsy-but-valid path, it returns `null`. Use explicit `false !== $first` check.

### 25. `Player.php:253-260` — `init()` returns `null` for tick when paused but tick command is recreated in update

```php
public function init(): ?\Closure
{
    if ($this->paused) {
        return null;
    }
    return Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg());
}
```
The init correctly returns no tick when paused, and `updateKey()` on Space-to-unpause correctly returns a tick command. But the `TickMsg` constructor is recreated on every tick. The `static fn(): Msg => new TickMsg()` closure is fine but the `new TickMsg()` each time is unnecessary — could use a singleton `TickMsg::instance()`.

### 26. `Reel.php:287` — `AutoMode` sentinel leaks to production

```php
$resolvedMode = $mode instanceof AutoMode ? null : ($mode ?? $this->mode);
```
`AutoMode` is a sentinel that converts to `null` in the `with()` helper. This is a minor leaky abstraction — the fact that `AutoMode` exists is an implementation detail of the fluent API. This pattern works but obscures the control flow.

---

## Missing Features

### 27. No seeking progress callback/UI
The player supports seeking (keyboard-driven and programmatic via `withSeek()`/`seekToSeconds()`) but there is no callback or event emitted when a seek occurs. A host application cannot display a progress indicator during seek without inspecting the Player's internals.

### 28. No frame callback (for screenshot/export)
There is no `frameAt()` equivalent that returns the current rendered output (as ANSI string) rather than the decoded `RgbFrame`. Applications that want to capture a screenshot of the current frame cannot do so without re-implementing the render path.

### 29. No audio volume control
`AudioPlayer` has no volume API. The ffplay/mpv subprocesses inherit system volume with no way to adjust from the Player.

### 30. No playback rate independent of wall-clock sync
Speed changes (`[` and `]` keys) are implemented as speed multipliers on the wall-clock accumulation. This means actual playback rate is proportional to `delta * speed`. There's no option for a "frame-rate-locked" mode where speed changes adjust the effective FPS rather than the time multiplier.

### 31. No subtitle styling options
`WebVtt::parse()` strips all cue settings (alignment, position, etc.) and renders subtitles as plain single-line text at the bottom of the screen. There is no way to customize the subtitle appearance (color, position, font).

### 32. No support for external audio tracks
The player only plays audio embedded in the video file. There's no option to load a separate audio file (e.g., music + silent video) for music visualization use cases.

### 33. No API to query supported rendering modes
`RendererFactory::autoMode()` and `RendererFactory::auto()` probe at runtime to pick a mode, but there is no public API for a host application to query "what modes does this terminal support?" beyond the `Mosaic::diagnose()` call embedded in `updateKey()`.

### 34. No graceful degradation when graphics-mode decoder fails
If `GraphicsRenderer` fails (e.g., chafa not available and pure-PHP SixelRenderer throws), the Player has no fallback to a text mode. It should fall back to the best available text mode.

### 35. No frame timestamp metadata
`RgbFrame` carries no timestamp information. For subtitle sync and seeking accuracy, knowing the PTS (presentation time stamp) of each frame would be valuable. Currently subtitle sync relies solely on `videoTime` computed from wall clock + speed, not actual frame timestamps from the decoder.

---

## Duplicated Logic / Refactoring Opportunities

### 36. Audio rebuild in `withSeek()` and `seekToSeconds()` — DUPLICATED
Already noted in High Severity #7. Extract to `private function makeAudioAt(?int $startMs): ?AudioPlayer`.

### 37. Luma computation `(77*R + 150*G + 29*B) >> 8` — DUPLICATED in 4 places
The BT.601 luma formula appears in:
- `Player.php:724` — `LumaRamp::char()` call uses it internally via `LumaRamp::compute()`
- `Player.php:763` — inline in `quarterCell()` `$luma = static fn (array $c): int => (($c[0] * 77) + ($c[1] * 150) + ($c[2] * 29)) >> 8`
- `AsciiRenderer.php:59` — `LumaRamp::compute()` called via `LumaRamp::char()`
- `LumaRamp.php:106-109` — definition of `compute()`

The `quarterCell()` lambda at line 763 inlines the formula rather than calling `LumaRamp::compute()`. This should call `LumaRamp::compute()` directly.

### 38. Player constructor call repeated across `withSeek()`, `withNewFrame()`, `mutate()`
All three create a new `Player` instance with 18 fields. The `withNewFrame()` at line 1150-1182 is nearly identical to `mutate()` but cannot use `mutate()` because it needs to pass a new `decoder`. Consider a builder or a named constructor.

### 39. `pixelRgb()` and `rgbToStyleColor()` in Player could be static utilities
Both are instance methods but use only their parameters and `$this->cellsW` (for `pixelRgb`, indirectly). They could be static helpers, reducing confusion about whether they depend on mutable state.

### 40. `rebuildDecoderAt()` and `rebuildDecoderAtSeconds()` — nearly identical structure
Both: close old decoder, create new one via factory (or reopen for fake), advance to frame/index, return `[decoder, frame]`. Could be one method with a strategy parameter, or a `DecoderRunner` abstraction.

### 41. `AsciiRenderer` and `GraphicsRenderer` don't implement `cellDimensions()` consistently
`AsciiRenderer::cellDimensions()` returns `[1, 1]` for all modes. `GraphicsRenderer::cellDimensions()` returns `[1, 1]`. Only `HalfBlockRenderer` and `QuarterBlockRenderer` return meaningful values. The interface contract is unclear — `cellDimensions(Mode $mode)` takes a Mode but the renderer already knows its mode via constructor. This is redundant.

### 42. `DecoderFactory::create()` creates decoder then immediately calls `open()`
```php
$decoder = new GifDecoder($cellPxW, $cellPxH);  // or FfmpegDecoder
$decoder->open($source, $cellsW, $cellsH, $fps, $mode, $startSec);
return $decoder;
```
This two-step pattern is repeated for both decoder types. A single `Decoder::open()` static factory method on each class would be cleaner (each decoder constructs and opens itself).

### 43. `Player::view()` dispatches to two different rendering pipelines

```php
if ($this->mode === Mode::Sixel || $this->mode === Mode::Kitty || ...) {
    $out = $this->renderDirect($frame);
} else {
    $out = $this->frameToBuffer($frame, $this->mode)->toAnsi();
}
```
This `renderDirect` vs `frameToBuffer` branch is a dispatch on mode. This could be a `RendererStrategy` pattern injected into the Player, reducing the `view()` method complexity.

### 44. Color packing `(($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF)` — appears in multiple places

In `Player::rgbToStyleColor()` and `RgbFrame::toGd()`. Extract to `Color::pack()` or a shared utility.

### 45. `FfmpegDecoder::buildCommand()` duplicated partially in `AudioPlayer::buildCommand()`
Both build ffmpeg-family command arrays. The ffplay path in `AudioPlayer` is similar to the ffmpeg path in `FfmpegDecoder`. The reconnect options, the `-ss` input-seek approach, and the array-based command building are all similar. A shared `FfmpegCommandBuilder` utility could reduce duplication.

---

## Compatibility Issues

### 46. `Player.php:122-123` — `Player::open()` calls `VideoSource::probe()` which runs ffprobe synchronously

On Windows, `where` is used via `shell_exec()` in `Probe::which()`. On some Windows configurations, `shell_exec()` may be disabled or restricted, causing the probe to fail. The graceful fallback (returning default VideoSource) works, but probe results may be wrong.

### 47. `AudioPlayer.php:71` — Windows NUL device handling
```php
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
```
This is correct, but on Windows the `NUL` device may be ambiguous if a file named `NUL` exists in the current directory (Windows device name resolution order can be confusing). Using `\\.\NUL` or proper handle configuration would be more robust.

### 48. `FfmpegDecoder.php:256-259` — HTTP source regex may not cover all URL forms
```php
return preg_match('#^https?://#i', $source) === 1;
```
This covers `http://` and `https://` but misses `rtsp://`, `rtmp://`, `mms://` streams that ffmpeg supports. The reconnect options would also not be applied to those protocols. At minimum, the reconnect options being skipped for non-HTTP sources may be intentional (not all protocols support reconnect), but the behavior should be documented.

### 49. `Player.php:361-362` — Window size clamp values are magic numbers
```php
$cols = max(10, min($cols, 200));
$rows = max(5, min($rows, 80));
```
The minimum rows/cols (5, 10) and maximum (80, 200) are hardcoded. These magic numbers appear nowhere else and could be documented constants. Some terminals may support larger sizes.

### 50. `Reel.php:229-262` — `play()` blocks synchronously

`Reel::play()` calls `(new Program($player, $options))->run()` which blocks the calling thread. In a ReactPHP context (this is an async ecosystem), blocking the event loop is problematic. There is no async-compatible entry point.

### 51. SIGSTOP/SIGCONT not available on Windows
`AudioPlayer::pause()` and `resume()` check `\defined('SIGSTOP')` and `\defined('SIGCONT')` and return silently on Windows. This means audio pause/resume is completely non-functional on Windows — audio continues playing when the video is paused. This should be documented explicitly.

---

## Async Pattern Improvements

### 52. Entire decode loop is blocking-synchronous

`Player::updateTick()` (lines 292-351) calls `$this->decoder->next()` synchronously, which in `FfmpegDecoder` does a blocking `fread()` on the ffmpeg stdout pipe. If ffmpeg stalls (network stream dropout, slow disk), the entire PHP process blocks. There is no timeout on the `fread()` call.

**Improvement**: Wrap the pipe read in a non-blocking `stream_select()` with a timeout, or use ReactPHP's `Loop::addReadStream()` for async I/O on the pipe.

### 53. AudioPlayer uses blocking `proc_open()`

`AudioPlayer::start()` calls `proc_open()` and immediately returns. But the `isPlaying()` check uses `proc_get_status()` which can also block briefly. More importantly, if the audio subprocess becomes unresponsive, there is no async recovery mechanism.

**Improvement**: Consider using ReactPHP's `Process` component or `Loop::addChildProcess()` for proper async process management.

### 54. No streaming/generator-based frame delivery for large files

`FfmpegDecoder::getIterator()` is a simple generator:
```php
public function getIterator(): \Generator
{
    while (($frame = $this->next()) !== null) {
        yield $frame;
    }
}
```
This is a synchronous generator. For very large video files, the entire decode can't be streamed to a consumer without blocking. An async generator (`AsyncIterator`) would allow downstream consumers to process frames without blocking the event loop between frames.

### 55. No backpressure mechanism

The tick-driven playback (`Cmd::tick()`) fires at a fixed interval regardless of how long `updateTick()` takes to process. If frame processing (especially `frameToBuffer()` for large frames in HalfBlock mode) takes longer than the tick interval, the event loop will fall behind. No backpressure or adaptive frame-skip is implemented.

### 56. No ReactPHP Promise-based API surface

The library exposes only a synchronous `Reel::play()` method. There is no:
- `Player::playAsync()` returning a `React\Promise\PromiseInterface`
- `Decoder::nextAsync()` returning a promise for the next frame
- `Player::positionFuture()` returning a promise for the position at a future time

**Recommendation**: Consider adding an async entry point `Reel::playAsync(Loop $loop)` that runs the TEA model in the ReactPHP event loop, using `Loop::addPeriodicTimer()` for tick dispatch and `Loop::addReadStream()` for decoder pipe management.

### 57. FfmpegDecoder pipe management could use async I/O

```php
$this->stdout = $pipes[1];
// ...
$chunk = fread($this->stdout, 65536);
```
This is a blocking read. Using `Loop::addReadStream($this->stdout, fn($sock) => ...)` would integrate with ReactPHP's event loop and allow other timers/events to fire while waiting for frame data.

---

## Recommendations Summary (priority table)

| Priority | ID | Issue | File:Line | Effort |
|----------|----|-------|-----------|--------|
| Critical | 1 | SIGSTOP audio pause mechanism likely ineffective | AudioPlayer.php:122-128 | High |
| Critical | 2 | SIGCONT audio resume same concerns | AudioPlayer.php:135-141 | High |
| Critical | 3 | AudioPlayer::stop() discards exit code | AudioPlayer.php:95-104 | Low |
| Critical | 4 | FfmpegDecoder::close() discards exit code | FfmpegDecoder.php:355-362 | Low |
| High | 5 | /fake test path embedded in rebuildDecoderAt() | Player.php:902-920 | High |
| High | 6 | frameAt() creates expensive orphaned decoder | Player.php:1089-1099 | Medium |
| High | 7 | withSeek() and seekToSeconds() duplicate audio rebuild | Player.php:931-942, 1058-1068 | Low |
| High | 8 | mutate() can't pass false/0 values | Player.php:1189-1217 | Medium |
| High | 9 | HalfBlock inline path duplicates Mosaic path | Player.php:664-693 | Medium |
| Medium | 10 | GifDecoder silently falls back to empty on decode failure | GifDecoder.php:57-77 | Low |
| Medium | 11 | PNG buffer grows unbounded on malformed frame | FfmpegDecoder.php:313-333 | Medium |
| Medium | 12 | TOCTOU race on is_file() before proc_open | FfmpegDecoder.php:120-122 | Low |
| Medium | 13 | Mode switch closes/reopens decoder unnecessarily | Player.php:563 | Medium |
| Medium | 14 | Inconsistent pixel access guards in AsciiRenderer vs Player | Player.php:819-826, AsciiRenderer.php:52-58 | Low |
| Medium | 15 | renderPlaceholder() not memoized | Player.php:866-875 | Low |
| Medium | 16 | Subtitle file not found silently ignored | Reel.php:245-251 | Low |
| Medium | 17 | Hardcoded skip limit of 2 frames | Sync.php:51-54 | Low |
| Low | 18 | 18-parameter constructor | Player.php:79-103 | High |
| Low | 19 | AudioPlayer not final | AudioPlayer.php:27 | Low |
| Low | 20 | openForTest hardcodes HalfBlock renderer | Player.php:219-222 | Low |
| Low | 21 | buildCommand() unnecessarily public | FfmpegDecoder.php:199 | Low |
| Low | 22 | Static LUT cache has no invalidation | LumaRamp.php:58-72 | Low |
| Low | 23 | parseTimestamp() edge cases | WebVtt.php:168-189 | Low |
| Low | 24 | strtok falsy path in Probe::which() | Probe.php:100-103 | Low |
| Low | 25 | TickMsg instantiated each tick | Player.php:259 | Low |
| Low | 26 | AutoMode sentinel leaky abstraction | Reel.php:287 | Low |
| Missing | 27 | No seeking progress callback | — | Medium |
| Missing | 28 | No frame screenshot export API | — | Medium |
| Missing | 29 | No audio volume control | — | Medium |
| Missing | 30 | No frame-rate-locked speed mode | — | Medium |
| Missing | 31 | No subtitle styling options | — | Medium |
| Missing | 32 | No external audio track support | — | High |
| Missing | 33 | No terminal capability query API | — | Medium |
| Missing | 34 | No graphics-mode fallback to text | — | Medium |
| Missing | 35 | No frame timestamp/PTS metadata | — | Medium |
| Refactor | 36 | quarterCell() should call LumaRamp::compute() | Player.php:763 | Low |
| Refactor | 37 | Player constructor call in withSeek/withNewFrame/mutate | Player.php | High |
| Refactor | 38 | pixelRgb/rgbToStyleColor could be static | Player.php | Low |
| Refactor | 39 | rebuildDecoderAt + rebuildDecoderAtSeconds consolidation | Player.php | Medium |
| Refactor | 40 | cellDimensions() redundant on FrameRenderer | Render/FrameRenderer.php | Low |
| Refactor | 41 | DecoderFactory create+open two-step | DecoderFactory.php | Low |
| Refactor | 42 | renderDirect vs frameToBuffer dispatch | Player.php:604-608 | Medium |
| Refactor | 43 | Color packing utility | — | Low |
| Refactor | 44 | FfmpegCommandBuilder shared utility | — | Medium |
| Async | 45 | No timeout on ffmpeg pipe fread | FfmpegDecoder.php:280 | Medium |
| Async | 46 | No ReactPHP Loop integration for decoder | FfmpegDecoder.php | High |
| Async | 47 | No async generator for frame delivery | FfmpegDecoder.php:338-343 | High |
| Async | 48 | No backpressure / adaptive frame-skip | Player.php:292-351 | Medium |
| Async | 49 | No Promise-based API surface | Reel.php:229-262 | High |
| Async | 50 | No stream_select() for pipe I/O | FfmpegDecoder.php:280 | Medium |
| Compat | 51 | Windows audio pause non-functional (SIGSTOP absent) | AudioPlayer.php | High (document) |
| Compat | 52 | Windows NUL device ambiguity | AudioPlayer.php:71 | Low |
| Compat | 53 | Non-HTTP stream protocols not covered by reconnect logic | FfmpegDecoder.php:205-213 | Low |
| Compat | 54 | Magic numbers for window size clamp | Player.php:361-362 | Low |
| Compat | 55 | play() blocks event loop | Reel.php:229-262 | High |
