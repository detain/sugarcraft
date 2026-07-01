# Implementation Plan: sugar-reel Code Review Findings

**Status:** not-started  
**Phase:** 1  
**Updated:** 2026-06-30

## Goal

Address all 55 identified issues in sugar-reel (critical, high, medium, low severity), implement missing features, resolve duplication, fix compatibility issues, and add async patterns — organized into executable phases with clear verification criteria.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Extract audio rebuild to `rebuildAudio()` helper | DRY principle — identical code in withSeek() and seekToSeconds() | `ref:sugar-reel-src` lines 931-942, 1058-1068 |
| Replace `??` with `array_key_exists()` in mutate() | `??` ignores falsy values (false/0), breaking those updates | `ref:sugar-reel-src` Player.php:1206-1207 |
| Add buffer size cap in nextPng() | Malformed PNG with no IEND can exhaust memory | `ref:sugar-reel-src` FfmpegDecoder.php:313-333 |
| Add DecoderInterface::reopen() for test seam | Replace /fake path string comparison with proper interface | `ref:sugar-reel-src` Player.php:902-920 |
| Create FfmpegCommandBuilder utility | Shared command building between FfmpegDecoder and AudioPlayer | `ref:sugar-reel-src` FfmpegDecoder.php:199, AudioPlayer.php:174 |
| Add Color::pack() utility | Color packing `(($r & 0xFF) << 16) \| ...` duplicated in 2+ places | `ref:sugar-reel-src` Player.php:842, RgbFrame.php:78 |

---

## Phase 1: Critical Issues [PENDING]

### 1.1 AudioPlayer::pause() — SIGSTOP Mechanism Ineffective [CRITICAL]

**What:** AudioPlayer::pause() uses `proc_terminate($this->processHandle, SIGSTOP)` which fails silently under PTY environments.

**Why:** SIGSTOP only works in same process group; PTY runs children in different process group. Audio continues playing while video is paused, breaking A/V sync.

**Related Code:** `sugar-reel/src/AudioPlayer.php:122-128`

**Conditions for Success:**
- AudioPlayer::pause() kills the subprocess on POSIX (where SIGSTOP works unreliably)
- AudioPlayer stores position so resume() can restart from correct point
- Windows: documented as non-functional limitation

**Investigation Notes:**
CALIBER_LEARNINGS `[pattern:devnull-sinks]` confirms all subprocesses use file sinks (not pipes), so a "pause flag that prevents reading" won't help audio. The correct fix is kill+recreate on pause/resume, like the seek path does.

**Implementation:**
```php
public function pause(): void
{
    if (!is_resource($this->processHandle)) {
        return;
    }
    // Kill the subprocess — SIGSTOP fails under PTY
    proc_terminate($this->processHandle, SIGTERM);
    $exitCode = proc_close($this->processHandle);
    $this->processHandle = null;
    // Store exit code for diagnostics
    $this->exitCode = $exitCode;
}
```

---

### 1.2 AudioPlayer::resume() — Same PTY Concerns [CRITICAL]

**What:** AudioPlayer::resume() uses SIGCONT which has the same PTY issues.

**Related Code:** `sugar-reel/src/AudioPlayer.php:135-141`

**Conditions for Success:**
- resume() restarts audio from the correct timestamp position
- Audio and video stay in sync after pause/resume cycle

**Implementation:**
```php
public function resume(): void
{
    if (!is_resource($this->processHandle)) {
        // Was paused (killed) — restart from stored position
        $this->start(); // start() respects startMs
    } else {
        proc_terminate($this->processHandle, SIGCONT);
    }
}
```

---

### 1.3 AudioPlayer::stop() — Exit Code Discarded [CRITICAL]

**What:** `proc_close()` returns exit code but it's discarded.

**Related Code:** `sugar-reel/src/AudioPlayer.php:95-104`

**Conditions for Success:**
- Exit code captured and stored in `$this->exitCode`
- `getExitCode(): ?int` method exposed
- Non-zero exit codes logged via error_log

**Implementation:**
```php
private ?int $exitCode = null;

public function stop(): void
{
    if (!is_resource($this->processHandle)) {
        return;
    }
    proc_terminate($this->processHandle, SIGTERM);
    $this->exitCode = proc_close($this->processHandle);
    $this->processHandle = null;
}

public function getExitCode(): ?int
{
    return $this->exitCode;
}
```

---

### 1.4 FfmpegDecoder::close() — Exit Code Discarded [CRITICAL]

**What:** Same issue — `proc_close()` exit code is discarded with a comment acknowledging the problem.

**Related Code:** `sugar-reel/src/Decode/FfmpegDecoder.php:348-363`

**Conditions for Success:**
- Exit code stored in `$this->exitCode`
- `getExitCode(): ?int` method added
- Non-zero exit codes trigger warning via error_log

**Implementation:**
```php
private ?int $exitCode = null;

public function close(): void
{
    if ($this->stdout !== null && is_resource($this->stdout)) {
        \fclose($this->stdout);
        $this->stdout = null;
    }
    if ($this->process !== null && is_resource($this->process)) {
        $this->exitCode = proc_close($this->process);
        $this->process = null;
        if ($this->exitCode !== 0) {
            error_log("FfmpegDecoder: ffmpeg exited with code {$this->exitCode}");
        }
    }
}

public function getExitCode(): ?int
{
    return $this->exitCode;
}
```

---

## Phase 2: High Severity Issues [PENDING]

### 2.1 /fake Test Path in rebuildDecoderAt() [HIGH]

**What:** String comparison `=== '/fake'` in production code forces test seam into runtime.

**Related Code:** `sugar-reel/src/Player.php:902-920`

**Conditions for Success:**
- Remove `=== '/fake'` string comparison from production code
- Add `DecoderInterface::reopen()` method for test seam
- All existing tests still pass

**Investigation Notes:**
CALIBER_LEARNINGS `[pattern:fake-decoder-isolated-testing]` confirms FakeDecoder exists for unit-testing. CALIBER_LEARNINGS `[pattern:rebuilddecoderat-single-helper]` documents the /fake path as intentional but fragile.

**Implementation:**
```php
// Add to Decoder interface:
public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void;

// FakeDecoder implements reopen():
public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
{
    $this->index = 0;
    $this->opened = true;
    $this->everOpened = true;
}

// Player::rebuildDecoderAt() uses reopen():
private function rebuildDecoderAt(int $cellsW, int $cellsH, Mode $mode, int $frameIndex): array
{
    if ($this->decoder instanceof FakeDecoder) {
        $this->decoder->reopen($this->videoPath, $cellsW, $cellsH, $this->fps, $mode);
        $decoder = $this->decoder;
    } else {
        $this->decoder->close();
        $decoder = DecoderFactory::create(...);
    }
    // ...
}
```

---

### 2.2 frameAt() Creates Expensive Orphaned Decoder [HIGH]

**What:** Spawns full ffmpeg process to grab ONE frame at player's current mode/resolution.

**Related Code:** `sugar-reel/src/Player.php:1089-1099`

**Conditions for Success:**
- frameAt() accepts optional mode/cellsW/cellsH params for thumbnail-appropriate decoding
- Consider LRU cache for recent thumbnail decodes (future optimization)

**Implementation:**
```php
public function frameAt(float $sec, ?Mode $mode = null, int $cellsW = 80, int $cellsH = 24): ?RgbFrame
{
    if ($this->videoPath === '' || $this->videoPath === '/fake') {
        return null;
    }
    $decodeMode = $mode ?? $this->mode;
    $decoder = DecoderFactory::create(
        $this->videoPath, $cellsW, $cellsH,
        $this->fps, $decodeMode,
        max(0.0, $sec),
        $this->cellPxW, $this->cellPxH
    );
    $frame = $decoder->next();
    $decoder->close();
    return $frame;
}
```

---

### 2.3 withSeek() and seekToSeconds() Duplicate Audio Rebuild [HIGH]

**What:** Identical code block appears in both methods.

**Related Code:** `sugar-reel/src/Player.php:931-942` and `1058-1068`

**Conditions for Success:**
- Extract to `private function rebuildAudio(?int $startMs): ?AudioPlayer`
- Both methods call the extracted helper
- Unit tests verify behavior

**Implementation:**
```php
private function rebuildAudio(?int $startMs): ?AudioPlayer
{
    if ($this->audioPlayer === null) {
        return null;
    }
    $this->audioPlayer->stop();
    $factory = $this->audioFactory ?? static fn(string $path, ?int $ms): AudioPlayer
        => new AudioPlayer($path, $ms);
    $newAudio = $factory($this->videoPath, $startMs);
    if (!$this->paused) {
        $newAudio->start();
    }
    return $newAudio;
}
```

---

### 2.4 mutate() Cannot Pass false/0 Values [HIGH]

**What:** `??` operator treats `false` and `0` as "not set".

**Related Code:** `sugar-reel/src/Player.php:1189-1217` (lines 1206-1207 acknowledge this)

**Conditions for Success:**
- `['ended' => false]` actually sets ended to false
- `['frameIndex' => 0]` actually sets frameIndex to 0

**Implementation:**
```php
private function mutate(array $changes): self
{
    return new self(
        decoder: array_key_exists('decoder', $changes) ? $changes['decoder'] : $this->decoder,
        mode: array_key_exists('mode', $changes) ? $changes['mode'] : $this->mode,
        speed: array_key_exists('speed', $changes) ? $changes['speed'] : $this->speed,
        paused: array_key_exists('paused', $changes) ? $changes['paused'] : $this->paused,
        videoTime: array_key_exists('videoTime', $changes) ? $changes['videoTime'] : $this->videoTime,
        frameIndex: array_key_exists('frameIndex', $changes) ? $changes['frameIndex'] : $this->frameIndex,
        currentFrame: array_key_exists('currentFrame', $changes) ? $changes['currentFrame'] : $this->currentFrame,
        lastTickTime: array_key_exists('lastTickTime', $changes) ? $changes['lastTickTime'] : $this->lastTickTime,
        fps: array_key_exists('fps', $changes) ? $changes['fps'] : $this->fps,
        totalFrames: array_key_exists('totalFrames', $changes) ? $changes['totalFrames'] : $this->totalFrames,
        cellsW: array_key_exists('cellsW', $changes) ? $changes['cellsW'] : $this->cellsW,
        cellsH: array_key_exists('cellsH', $changes) ? $changes['cellsH'] : $this->cellsH,
        videoPath: $this->videoPath,
        audioPlayer: array_key_exists('audioPlayer', $changes) ? $changes['audioPlayer'] : $this->audioPlayer,
        ended: array_key_exists('ended', $changes) ? $changes['ended'] : $this->ended,
        loop: array_key_exists('loop', $changes) ? $changes['loop'] : $this->loop,
        ramp: array_key_exists('ramp', $changes) ? $changes['ramp'] : $this->ramp,
        audioFactory: array_key_exists('audioFactory', $changes) ? $changes['audioFactory'] : $this->audioFactory,
        cellPxW: array_key_exists('cellPxW', $changes) ? $changes['cellPxW'] : $this->cellPxW,
        cellPxH: array_key_exists('cellPxH', $changes) ? $changes['cellPxH'] : $this->cellPxH,
        subtitles: array_key_exists('subtitles', $changes) ? $changes['subtitles'] : $this->subtitles,
        renderer: array_key_exists('renderer', $changes) ? $changes['renderer'] : $this->renderer,
    );
}
```

---

### 2.5 HalfBlock Inline Path Duplicates Mosaic Path [HIGH]

**What:** Player::frameToBuffer() has inline HalfBlock that mirrors HalfBlockRenderer exactly.

**Related Code:**
- `sugar-reel/src/Player.php:664-693` (inline path)
- `sugar-reel/src/Render/HalfBlockRenderer.php:40-45` (never used at runtime)

**Conditions for Success:**
- Remove inline HalfBlock path; always route through RendererFactory
- Update testHalfBlockInlineMatchesMosaicRenderer accordingly
- Verify all HalfBlock rendering uses the same code path

**Investigation Notes:**
Comment at HalfBlockRenderer.php:41-45 explicitly states "NEVER used by Player::view() at runtime". This duplication is fragile.

**Implementation:**
```php
// In Player::frameToBuffer(), remove the Mode::HalfBlock special case
// and let it fall through to the general buffer path:
// OR change Player::view() to always use RendererFactory for HalfBlock

// Recommended: Remove inline HalfBlock case, use RendererFactory consistently
if ($mode === Mode::HalfBlock) {
    // Use renderer factory path instead of inline
    return $this->renderDirect($frame); // Will use HalfBlockRenderer via cached renderer
}
```

---

## Phase 3: Medium Severity Issues [PENDING]

### 3.1 GifDecoder Silently Falls Back to Empty [MEDIUM]

**What:** If FlipDecoder::decode() returns [], player shows black screen silently.

**Related Code:** `sugar-reel/src/Decode/GifDecoder.php:57-77`

**Conditions for Success:** Log warning when frames array is empty

**Implementation:**
```php
$this->frames = FlipDecoder::decode($source, $decodeW, $decodeH);
if ($this->frames === []) {
    error_log("GifDecoder: FlipDecoder::decode('{$source}', {$decodeW}, {$decodeH}) returned empty array");
}
```

---

### 3.2 PNG Buffer Grows Unbounded [MEDIUM]

**What:** No IEND marker causes unbounded buffer growth.

**Related Code:** `sugar-reel/src/Decode/FfmpegDecoder.php:313-333`

**Conditions for Success:** Buffer capped at MAX_PNG_BUFFER (100MB)

**Implementation:**
```php
private const MAX_PNG_BUFFER = 100 * 1024 * 1024;

private function nextPng(): ?RgbFrame
{
    while (true) {
        $end = strpos($this->pngBuffer, self::PNG_IEND);
        if ($end !== false) {
            $cut = $end + strlen(self::PNG_IEND);
            $png = substr($this->pngBuffer, 0, $cut);
            $this->pngBuffer = substr($this->pngBuffer, $cut);
            return new RgbFrame('', $this->frameW, $this->frameH, $png);
        }
        $chunk = $this->stdout !== null && is_resource($this->stdout)
            ? fread($this->stdout, 65536) : false;
        if ($chunk === false || $chunk === '') {
            return null;
        }
        if (strlen($this->pngBuffer) + strlen($chunk) > self::MAX_PNG_BUFFER) {
            error_log("FfmpegDecoder: PNG buffer exceeded limit, aborting");
            return null;
        }
        $this->pngBuffer .= $chunk;
    }
}
```

---

### 3.3 TOCTOU Race on is_file() [MEDIUM]

**What:** File could be deleted between is_file() and proc_open().

**Related Code:** `sugar-reel/src/Decode/FfmpegDecoder.php:120-122`

**Conditions for Success:** Document as acceptable trade-off in code comment

**Implementation:**
```php
// TOCTOU note: is_file() returning true doesn't guarantee the file still
// exists when proc_open() runs. Acceptable for video playback use case.
if (!self::isNetworkSource($source) && !is_file($source)) {
    throw new \RuntimeException("video source not found: {$source}");
}
```

---

### 3.4 Mode Switch Unnecessarily Rebuilds Decoder [MEDIUM]

**What:** Pressing 'm' closes/reopens decoder even when resolution unchanged.

**Related Code:** `sugar-reel/src/Player.php:535-572`

**Conditions for Success:** Decoder only rebuilt when mode's resolution requirements differ

**Implementation:**
```php
// In updateKey() for mode cycling:
$nextMode = $allModes[($currentIdx + 1) % count($allModes)];

$currentResMatches = (
    $this->mode->colsPerCell() === $nextMode->colsPerCell()
    && $this->mode->rowsPerCell() === $nextMode->rowsPerCell()
    && $this->mode->isGraphics() === $nextMode->isGraphics()
);

if (!$currentResMatches) {
    [$decoder, $frame] = $this->rebuildDecoderAt(...);
} else {
    $decoder = $this->decoder;
    $frame = $this->currentFrame;
}
```

---

### 3.5 Inconsistent Pixel Access Guards [MEDIUM]

**What:** AsciiRenderer and Player have similar but not identical pixel access logic.

**Related Code:**
- `sugar-reel/src/Render/AsciiRenderer.php:52-58`
- `sugar-reel/src/Player.php:819-826`

**Conditions for Success:** Both use identical RgbFrame::pixelRgb() static method

**Implementation:**
```php
// Add to RgbFrame:
public static function pixelRgb(string $bytes, int $w, int $byteLen, int $px, int $py): array
{
    $idx = ($py * $w + $px) * 3;
    if ($idx + 2 >= $byteLen) {
        return [0, 0, 0];
    }
    return [ord($bytes[$idx]), ord($bytes[$idx + 1]), ord($bytes[$idx + 2])];
}

// Player uses it:
private function pixelRgb(string $bytes, int $w, int $byteLen, int $px, int $py): array
{
    return RgbFrame::pixelRgb($bytes, $w, $byteLen, $px, $py);
}
```

---

### 3.6 renderPlaceholder() Not Memoized [MEDIUM]

**What:** Allocates new array on every call.

**Related Code:** `sugar-reel/src/Player.php:866-875`

**Conditions for Success:** Result cached after first computation

**Implementation:**
```php
private ?string $cachedPlaceholder = null;

private function renderPlaceholder(): string
{
    if ($this->cachedPlaceholder !== null) {
        return $this->cachedPlaceholder;
    }
    $lines = [];
    for ($i = 0; $i < $this->cellsH; $i++) {
        $lines[] = str_repeat(' ', $this->cellsW);
    }
    $body = implode("\r\n", $lines);
    $status = "loading...  space play  q quit";
    $this->cachedPlaceholder = $body . "\r\n" . $status;
    return $this->cachedPlaceholder;
}
```

---

### 3.7 Subtitle File Not Found Silently Ignored [MEDIUM]

**What:** file_get_contents() failure is silent.

**Related Code:** `sugar-reel/src/Reel.php:245-251`

**Conditions for Success:** Log warning when file not found/unreadable

**Implementation:**
```php
if ($this->subtitlePath !== null) {
    $raw = @file_get_contents($this->subtitlePath);
    if ($raw === false) {
        error_log("Reel: subtitle file not found or unreadable: {$this->subtitlePath}");
    } elseif ($raw === '') {
        error_log("Reel: subtitle file is empty: {$this->subtitlePath}");
    } elseif (is_string($raw) && $raw !== '') {
        $subtitles = WebVtt::parse($raw);
    }
}
```

---

### 3.8 Hardcoded Skip Limit of 2 Frames [MEDIUM]

**What:** Sync::shouldSkip() hardcodes skip limit with no configuration.

**Related Code:** `sugar-reel/src/Sync.php:51-54`

**Conditions for Success:** Skip limit configurable via method parameter

**Implementation:**
```php
private const DEFAULT_SKIP_LIMIT = 2;

public static function shouldSkip(int $currentFrame, int $targetFrame, int $skipLimit = self::DEFAULT_SKIP_LIMIT): bool
{
    return $targetFrame - $currentFrame > $skipLimit;
}
```

---

## Phase 4: Low Severity Issues [PENDING]

### 4.1 Player Constructor Has 18 Parameters [LOW]

**What:** High coupling — consider PlayerConfig value object.

**Related Code:** `sugar-reel/src/Player.php:79-103`

**Conditions for Success:** Consider PlayerConfig for grouping related params (future refactor)

---

### 4.2 AudioPlayer Not final [LOW]

**What:** AudioPlayer not final unlike other classes.

**Related Code:** `sugar-reel/src/AudioPlayer.php:27`

**Conditions for Success:**
- Mark `final` AND change `buildCommand()` to `protected` to allow test subclassing
- Update FakeAudioPlayer test pattern

**Implementation:**
```php
final class AudioPlayer
{
    protected function buildCommand(): ?array
    {
        // ...
    }
}
```

---

### 4.3 openForTest Hardcodes HalfBlock [LOW]

**What:** Test factory ignores mode parameter.

**Related Code:** `sugar-reel/src/Player.php:219-222`

**Conditions for Success:** Use passed mode parameter

**Implementation:**
```php
// Change from:
$renderer = RendererFactory::create(Mode::HalfBlock, $ramp, $cellPxW, $cellPxH);
return new self(
    // ...
    mode: Mode::HalfBlock,

// To:
$effectiveMode = $mode ?? Mode::HalfBlock;
$renderer = RendererFactory::create($effectiveMode, $ramp, $cellPxW, $cellPxH);
return new self(
    // ...
    mode: $effectiveMode,
```

---

### 4.4 buildCommand() Unnecessarily Public [LOW]

**What:** FfmpegDecoder::buildCommand() is public but only used internally.

**Related Code:** `sugar-reel/src/Decode/FfmpegDecoder.php:199`

**Conditions for Success:** Change to `protected static`

---

### 4.5 Static LUT Cache No Invalidation [LOW]

**What:** LumaRamp::$lutByName has no cache clearing.

**Related Code:** `sugar-reel/src/Render/LumaRamp.php:58-72`

**Conditions for Success:** Add `LumaRamp::clearCache()` for testing

**Implementation:**
```php
public static function clearCache(): void
{
    self::$lutByName = [];
}
```

---

### 4.6 parseTimestamp() Edge Cases [LOW]

**What:** Doesn't handle trailing whitespace, extra dots, negative times.

**Related Code:** `sugar-reel/src/Subtitle/WebVtt.php:168-189`

**Conditions for Success:** More robust input validation

**Implementation:**
```php
private static function parseTimestamp(string $ts): ?float
{
    $ts = trim(str_replace(',', '.', $ts));
    if ($ts === '') {
        return null;
    }
    if (preg_match('/[^0-9:.]/', $ts)) {
        return null;
    }
    // ... existing parsing
}
```

---

### 4.7 strtok Falsy Path [LOW]

**What:** `return $first ?: null` converts '0' to null.

**Related Code:** `sugar-reel/src/Source/Probe.php:100-103`

**Conditions for Success:** Use explicit false check

**Implementation:**
```php
$first = strtok(trim($out), "\r\n");
return $first !== false ? $first : null;
```

---

### 4.8 TickMsg Instantiated Each Tick [LOW]

**What:** `new TickMsg()` created on every tick.

**Related Code:** `sugar-reel/src/Player.php:259`

**Conditions for Success:** Add singleton instance

**Implementation:**
```php
// In TickMsg:
private static ?TickMsg $instance = null;
public static function instance(): self
{
    return self::$instance ??= new self();
}

// In Player:
return Cmd::tick(1.0 / $this->fps, static fn(): Msg => TickMsg::instance());
```

---

### 4.9 AutoMode Sentinel Leaky Abstraction [LOW]

**What:** AutoMode converts to null in with() helper.

**Related Code:** `sugar-reel/src/Reel.php:287`

**Conditions for Success:** Use null directly; remove AutoMode sentinel

**Implementation:**
```php
public function withAutoMode(): self
{
    return $this->with(mode: null);
}
```

---

## Phase 5: Missing Features [PENDING]

### 5.1 No Seeking Progress Callback [MEDIUM]

**What:** No callback when seek occurs.

**Conditions for Success:** Add `withSeekProgressCallback(?callable)` to Player

**Implementation:**
```php
private ?\Closure $seekProgressCallback = null;

// New method:
public function withSeekProgressCallback(?callable $callback): self
{
    return $this->mutate(['seekProgressCallback' => $callback]);
}

// Called during seek:
if ($this->seekProgressCallback !== null) {
    ($this->seekProgressCallback)($this->frameIndex, $targetIndex, $newVideoTime);
}
```

---

### 5.2 No Frame Screenshot Export API [MEDIUM]

**What:** Cannot capture current rendered frame.

**Conditions for Success:** Add `captureFrame(): string` method

**Implementation:**
```php
public function captureFrame(): string
{
    return $this->view();
}
```

---

### 5.3 No Audio Volume Control [MEDIUM]

**What:** AudioPlayer has no volume API.

**Conditions for Success:** Add `setVolume(float)` method

**Implementation:**
```php
private float $volume = 1.0;

public function setVolume(float $volume): void
{
    $this->volume = max(0.0, min(1.0, $volume));
}

// In buildCommand(), add volume flag before path
if ($this->volume < 1.0) {
    $cmd[] = '-volume';
    $cmd[] = (string)round($this->volume * 100);
}
```

---

### 5.4 No Frame-Rate-Locked Speed Mode [MEDIUM]

**What:** Speed changes adjust wall-clock not effective FPS.

**Conditions for Success:** Document current behavior; optionally add mode flag

---

### 5.5 No Subtitle Styling Options [MEDIUM]

**What:** WebVtt strips all cue settings.

**Conditions for Success:** Document stripping in class docblock

---

### 5.6 No External Audio Track Support [HIGH]

**What:** Only embedded audio works.

**Conditions for Success:** Add `withAudioTrack(string $path)` to Reel/Player

**Implementation:**
```php
// Add audioPath field to Reel
private ?string $audioPath = null;

// Player.open() creates audio from $audioPath ?? $videoPath
public static function open(..., ?string $audioPath = null): self
{
    $audioPlayer = $source->hasAudio ? $audioFactory($audioPath ?? $videoPath, null) : null;
}
```

---

### 5.7 No Terminal Capability Query API [MEDIUM]

**What:** No public API for supported modes.

**Conditions for Success:** Add `RendererFactory::supportedModes(): array<Mode>`

---

### 5.8 No Graphics-Mode Fallback [MEDIUM]

**What:** No fallback when graphics renderer fails.

**Conditions for Success:** Wrap renderDirect in try-catch; fall back to best text mode

**Implementation:**
```php
public function view(): string
{
    $frame = $this->currentFrame;
    if ($frame === null) {
        return $this->renderPlaceholder();
    }
    try {
        if ($this->mode === Mode::Sixel || ...) {
            $out = $this->renderDirect($frame);
        } else {
            $out = $this->frameToBuffer($frame, $this->mode)->toAnsi();
        }
    } catch (\Throwable $e) {
        // Fall back to ASCII mode
        $out = $this->frameToBuffer($frame, Mode::Ascii)->toAnsi();
        error_log("Renderer error, fell back to ASCII: " . $e->getMessage());
    }
    // ...
}
```

---

### 5.9 No Frame Timestamp/PTS Metadata [MEDIUM]

**What:** RgbFrame has no timestamp.

**Conditions for Success:** Add $pts field to RgbFrame

**Implementation:**
```php
public function __construct(
    public string $bytes,
    public int $w,
    public int $h,
    public ?string $png = null,
    public ?float $pts = null,  // NEW
) {}
```

---

## Phase 6: Duplicated Logic / Refactoring [PENDING]

### 6.1 quarterCell() Should Call LumaRamp::compute() [LOW]

**What:** Line 763 inlines formula.

**Related Code:** `sugar-reel/src/Player.php:763`

**Conditions for Success:** Replace inline with `LumaRamp::compute($c[0], $c[1], $c[2])`

---

### 6.2 Player Constructor Call Duplicated [HIGH]

**What:** withSeek/withNewFrame/mutate all create Player.

**Conditions for Success:** Consider builder or named constructor

---

### 6.3 pixelRgb/rgbToStyleColor Could Be Static [LOW]

**What:** Instance methods use only parameters.

**Conditions for Success:** Make static utilities

---

### 6.4 rebuildDecoderAt + rebuildDecoderAtSeconds Consolidation [MEDIUM]

**What:** Nearly identical structure.

**Conditions for Success:** Consider DecoderRunner abstraction

---

### 6.5 cellDimensions() Redundant Parameter [LOW]

**What:** Mode param redundant since renderer knows its mode.

**Conditions for Success:** Remove Mode parameter from interface

---

### 6.6 DecoderFactory Two-Step Pattern [LOW]

**What:** create() then open() could be static factory.

**Conditions for Success:** Add Decoder::open() static factory on each class

---

### 6.7 renderDirect vs frameToBuffer Dispatch [MEDIUM]

**What:** view() dispatches on mode.

**Conditions for Success:** Inject RendererStrategy into Player

---

### 6.8 Color Packing Utility [LOW]

**What:** `(($r & 0xFF) << 16) | ...` duplicated.

**Conditions for Success:** Create `Color::pack(int $r, int $g, int $b): int`

**Implementation:**
```php
// In a new Color utility class or as standalone function
final class ColorUtil
{
    public static function pack(int $r, int $g, int $b): int
    {
        return (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
    }
}
```

---

### 6.9 FfmpegCommandBuilder Shared Utility [MEDIUM]

**What:** FfmpegDecoder and AudioPlayer build similar commands.

**Conditions for Success:** Create shared utility class

---

## Phase 7: Compatibility Issues [PENDING]

### 7.1 Windows Audio Pause Non-Functional [HIGH]

**What:** SIGSTOP absent on Windows.

**Conditions for Success:** Document limitation explicitly

---

### 7.2 Windows NUL Device Ambiguity [LOW]

**What:** `NUL` could be file in current directory.

**Conditions for Success:** Use `\\\\.\\NUL` for unambiguous device

**Implementation:**
```php
$devNull = DIRECTORY_SEPARATOR === '\\' ? '\\\\.\\NUL' : '/dev/null';
```

---

### 7.3 Non-HTTP Stream Protocols Not Covered [LOW]

**What:** RTSP/RTMP/MMS not covered by reconnect options.

**Conditions for Success:** Document limitation; consider extending protocol detection

---

### 7.4 Magic Numbers for Window Size [LOW]

**What:** Hardcoded clamp values.

**Related Code:** `sugar-reel/src/Player.php:361-362`

**Conditions for Success:** Extract to constants

**Implementation:**
```php
private const MIN_COLS = 10;
private const MAX_COLS = 200;
private const MIN_ROWS = 5;
private const MAX_ROWS = 80;

// In updateResize():
$cols = max(self::MIN_COLS, min($cols, self::MAX_COLS));
$rows = max(self::MIN_ROWS, min($rows, self::MAX_ROWS));
```

---

### 7.5 play() Blocks Event Loop [HIGH]

**What:** Reel::play() blocks synchronously.

**Conditions for Success:** Document blocking; add playAsync() for async ecosystem

---

## Phase 8: Async Pattern Improvements [PENDING]

### 8.1 No Timeout on ffmpeg Pipe fread [MEDIUM]

**What:** Blocking fread with no timeout.

**Related Code:** `sugar-reel/src/Decode/FfmpegDecoder.php:280`

**Conditions for Success:** Wrap in stream_select() with timeout

**Implementation:**
```php
private const READ_TIMEOUT_SEC = 5.0;

private function readWithTimeout(int $fd, int $len): string|false
{
    $read = [$fd];
    $write = null;
    $except = null;
    $tvSec = (int)self::READ_TIMEOUT_SEC;
    $tvUsec = (int)((self::READ_TIMEOUT_SEC - $tvSec) * 1_000_000);

    $result = @stream_select($read, $write, $except, $tvSec, $tvUsec);
    if ($result === false || $result === 0) {
        return false; // Timeout or error
    }
    return fread($fd, $len);
}
```

---

### 8.2 No ReactPHP Loop Integration [HIGH]

**What:** Entire decode loop is blocking-synchronous.

**Conditions for Success:** Add `Reel::playAsync(Loop $loop)` entry point

---

### 8.3 No Async Generator for Frame Delivery [HIGH]

**What:** Synchronous generator only.

**Conditions for Success:** Consider AsyncIterator interface (future work)

---

### 8.4 No Backpressure Mechanism [MEDIUM]

**What:** Fixed tick interval regardless of processing time.

**Conditions for Success:** Add adaptive frame-skip when processing lags

**Implementation:**
```php
// In updateTick(), measure processing time and adjust:
$processStart = microtime(true);
// ... frame processing ...
$processTime = microtime(true) - $processStart;
$expectedFrameTime = 1.0 / $this->fps;
if ($processTime > $expectedFrameTime * 2) {
    // Skip extra frames to catch up
    $skipCount = (int)ceil(($processTime - $expectedFrameTime) / $expectedFrameTime);
    // ... skip frames ...
}
```

---

### 8.5 No Promise-Based API Surface [HIGH]

**What:** No async API for host applications.

**Conditions for Success:** Add Promise-based methods (future work)

---

### 8.6 No stream_select() for Pipe I/O [MEDIUM]

**What:** Pipe reads don't integrate with event loop.

**Conditions for Success:** Use stream_select() for non-blocking I/O

---

## Verification

After implementing each phase:
1. Run `cd sugar-reel && composer install && vendor/bin/phpunit` — all tests must pass
2. For visual libs: verify VHS demos still render correctly
3. Check `composer validate` passes
4. Review no new PHPStan/Psalm errors introduced

## Notes

- **2026-06-30**: Plan created from `findings/sugar-reel.md` code review
- All implementations maintain backward compatibility unless explicitly breaking
- Tests pass after each change before proceeding
- Bundle related changes into single PRs per sugar-reel workflow
- Critical (Phase 1) and High (Phase 2) items addressed first for maximum impact
