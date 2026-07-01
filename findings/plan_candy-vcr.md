---
status: not-started
phase: 1
updated: 2026-06-30
goal: Fix all 19 candy-vcr audit findings (2 HIGH, 9 MEDIUM, 8 LOW) to eliminate runtime errors, correct logic bugs, and improve performance
---

# Implementation Plan: candy-vcr Audit Fixes

## Overview

This plan addresses all 19 findings from the candy-vcr audit:
- **2 HIGH severity** issues (blocking runtime errors)
- **9 MEDIUM severity** issues (logic bugs, performance, error handling)
- **8 LOW severity** issues (code clarity, incomplete features)

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Move $cassette-dependent code after compilation | `$cassette` is undefined at lines 70-71 but set at line 81 | `candy-vcr.md Finding #1` |
| Deep-clone tileCache array in ImagickRasterizer clones | Shallow array copy shares Imagick object references; calling clearTileCache() on one destroys tiles the other needs | `candy-vcr.md Finding #2` |
| Snapshot FrameStream state before iteration | $pendingScreenshotPath mutated mid-iteration creates data race with external reader | `candy-vcr.md Finding #3` |
| Add negative dt validation in RelativeFormat | Negative deltas produce backwards timestamps with no error | `candy-vcr.md Finding #4` |
| Use === false check for preg_replace error handling | ?? operator doesn't catch false (preg_replace returns false on error, not null) | `candy-vcr.md Finding #5` |
| Collect frames in first iteration to compute dedup inline | Double iteration doubles rendering cost unnecessarily | `candy-vcr.md Finding #6` |
| Collector-based approach for async ffmpeg batching | Process::start() with ReactPHP event loop enables parallel encoding | `candy-vcr.md Finding #9` |
| Use SplQueue or indexed FIFO for O(1) eviction | array_key_first() is O(n) on associative arrays | `candy-vcr.md Finding #12` |
| Complete migrateHeader() with v2 fields | formatVersion and migrationMeta not set despite doc claim | `candy-vcr.md Finding #16` |

---

## Phase 1: HIGH Severity Fixes

### 1.1 Fix undefined $cassette variable in TapeToGif

**File:** `src/Encode/TapeToGif.php`
**Lines:** 70-71, 81

#### What is Expected
Move the `$fontSize` and `$fontFamily` variable assignments from lines 70-71 to after line 81 where `$cassette` is properly defined. The cassette header values should be read after the compilation step so they are available.

#### Why the Change Should Be Done
This is a **critical blocking bug** — the `render()` method will produce an "Undefined variable $cassette" PHP fatal error at runtime because `$cassette` is accessed on lines 70-71 before being assigned on line 81.

#### Severity: CRITICAL

#### Conditions for Success
- [ ] `php -l src/Encode/TapeToGif.php` shows no errors
- [ ] `TapeToGif::render()` can be called without "Undefined variable" fatal
- [ ] Cassette header font settings are correctly preferred over CLI options

#### Related Code Locations
- **Source:** `src/Encode/TapeToGif.php:70-71` (undefined $cassette access)
- **Source:** `src/Encode/TapeToGif.php:81` (where $cassette is actually defined)
- **Source:** `src/Encode/TapeToGif.php:83-85` (where $cassette is used properly)
- **Tests:** `tests/Encode/TapeToGifTest.php`
- **Tests:** `tests/Encode/TapeToGifThemeTest.php`

#### Investigation Notes
```php
// Lines 70-71 - BUG: $cassette used before defined
$fontSize = $cassette->header->fontSize ?? (int) ($options['fontSize'] ?? 14);
$fontFamily = $cassette->header->fontFamily ?? $options['fontFamily'] ?? 'JetBrainsMono';

// Line 73-81 - proper tape reading and cassette compilation
$source = @file_get_contents($tapePath);
$tokens = $this->lexer->tokenize($source);
$ast = $this->parser->parse($tokens);
$strict = (bool) ($options['strict'] ?? false);
$cassette = $this->compiler->compile($ast, $tapePath, $strict);

// Line 83 onwards - $cassette properly used
$themeName = $cassette->header->theme ?? $cliTheme ?? 'TokyoNight';
$theme = $this->resolveTheme($themeName, $strict);
```

**Fix:** Move lines 70-71 to after line 81, inside the `try` block before line 83:
```php
try {
    $source = @file_get_contents($tapePath);
    // ... existing code ...
    $cassette = $this->compiler->compile($ast, $tapePath, $strict);

    // NEW: Read font settings AFTER cassette is available
    $fontSize = $cassette->header->fontSize ?? (int) ($options['fontSize'] ?? 14);
    $fontFamily = $cassette->header->fontFamily ?? $options['fontFamily'] ?? 'JetBrainsMono';

    $themeName = $cassette->header->theme ?? $cliTheme ?? 'TokyoNight';
    // ... rest of code ...
```

---

### 1.2 Fix ImagickRasterizer shared tileCache between clones

**File:** `src/Raster/ImagickRasterizer.php`
**Lines:** 58-66 (withTheme), 69-82 (withFont)

#### What is Expected
The `withTheme()` and `withFont()` methods perform shallow copy of `$this->tileCache`, sharing the same `\Imagick` object references between original and clone. When `clearTileCache()` is called on either instance, it destroys Imagick tiles that the other instance may still need.

The fix should deep-clone the tileCache array so each clone has independent Imagick objects.

#### Why the Change Should Be Done
This is a **HIGH severity bug** — calling `clearTileCache()` on a cloned rasterizer (e.g., via `maybeInvalidateCache()` when cell dimensions change) will destroy cached tiles that the original rasterizer is still using, causing use-after-free and undefined behavior in subsequent rasterization calls.

#### Severity: HIGH

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Raster/ImagickRasterizerCacheTest.php` passes
- [ ] Cloned rasterizer has independent tileCache (not shared by reference)
- [ ] `clearTileCache()` on clone does not affect original's cached tiles
- [ ] Theme/font changes trigger cache rebuild without affecting the other instance

#### Related Code Locations
- **Source:** `src/Raster/ImagickRasterizer.php:35` (tileCache declaration)
- **Source:** `src/Raster/ImagickRasterizer.php:58-67` (withTheme - shared tileCache)
- **Source:** `src/Raster/ImagickRasterizer.php:69-82` (withFont - shared tileCache)
- **Source:** `src/Raster/ImagickRasterizer.php:155-165` (clearTileCache)
- **Tests:** `tests/Raster/ImagickRasterizerCacheTest.php`

#### Investigation Notes
```php
// Lines 58-67 - withTheme shares tileCache
public function withTheme(Theme $theme): self
{
    $clone = new self($this->fontSize, $this->fontFamily, $theme);
    $clone->cacheDisabled = $this->cacheDisabled;
    $clone->tileCache = $this->tileCache;  // BUG: shallow copy, shared references
    // ... rest
}

// Lines 69-82 - withFont shares tileCache
public function withFont(string $fontFamily, ?int $fontSize = null): self
{
    $clone = new self($fontSize ?? $this->fontSize, $fontFamily, $this->theme);
    $clone->cacheDisabled = $this->cacheDisabled;
    $clone->tileCache = $this->tileCache;  // BUG: shallow copy, shared references
    // ... rest
}
```

**Comparison with GdRasterizer:** GdRasterizer does NOT share Glyphs cache between clones:
```php
// GdRasterizer.php:55-60 - withTheme does NOT share glyphs
public function withTheme(Theme $theme): self
{
    $clone = new self($this->fontSize, $this->fontFamily, $theme);
    $clone->cacheDisabled = $this->cacheDisabled;
    return $clone;  // glyphs is null, will be rebuilt on first rasterize
}
```

**Fix for ImagickRasterizer:** Deep-clone the Imagick objects in tileCache:
```php
public function withTheme(Theme $theme): self
{
    $clone = new self($this->fontSize, $this->fontFamily, $theme);
    $clone->cacheDisabled = $this->cacheDisabled;
    // Deep-clone tileCache so each instance has independent tiles
    foreach ($this->tileCache as $key => $tile) {
        $clone->tileCache[$key] = clone $tile;
    }
    // ... rest
}
```

---

## Phase 2: MEDIUM Severity Fixes

### 2.1 Fix FrameStream mutation during iteration

**File:** `src/Encode/TapeToGif.php:104-106`, `src/Render/FrameStream.php:26`

#### What is Expected
The `FrameStream` class has public properties `$pendingScreenshotPath` and `$captureCursor` that are mutated during generator iteration. External code reads `$frameStream->captureCursor` mid-iteration on line 105 of TapeToGif, creating a data race.

#### Why the Change Should Be Done
This is a **MEDIUM severity logic bug** — the external reader may see partially-updated state as the generator yields frames, potentially reading an incorrect cursor visibility state.

#### Severity: MEDIUM

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Render/FrameStreamTest.php` passes
- [ ] No mutation of FrameStream public properties during iteration by external code
- [ ] `captureCursor` snapshot is taken before iteration, not during

#### Related Code Locations
- **Source:** `src/Render/FrameStream.php:26-27` (public properties)
- **Source:** `src/Encode/TapeToGif.php:104-106` (reads captureCursor mid-iteration)

#### Investigation Notes
```php
// FrameStream.php:26-27
public ?string $pendingScreenshotPath = null;
public bool $captureCursor = true;

// TapeToGif.php:104-106 - reading during iteration
foreach ($this->buildFramesWithHolds($frameStream, 1.0 / $fps) as $index => $frameInfo) {
    $renderCursor = $frameStream->captureCursor;  // DATA RACE: reading mid-iteration
```

**Fix:** Snapshot `captureCursor` before the iteration:
```php
// In TapeToGif::render(), before line 104:
$captureCursor = $frameStream->captureCursor;  // Take snapshot BEFORE iteration

foreach ($this->buildFramesWithHolds($frameStream, 1.0 / $fps) as $index => $frameInfo) {
    // Use the snapshot instead of reading mid-iteration:
    // $renderCursor = $frameStream->captureCursor;  // REMOVE THIS
    $renderCursor = $captureCursor;  // Use snapshot
```

---

### 2.2 Add negative dt validation in RelativeFormat

**File:** `src/Format/RelativeFormat.php`
**Lines:** 200-201

#### What is Expected
Add validation in `decodeEvent()` to reject negative `dt` values. Currently, a negative `dt` silently produces timestamps that go backwards in time, corrupting the timeline.

#### Why the Change Should Be Done
This is a **MEDIUM severity logic bug** — negative deltas produce timestamps that go backwards, which can cause various issues in replay including inverted timing, frame sequence corruption, and unexpected behavior in time-sensitive operations.

#### Severity: MEDIUM

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Format/RelativeFormatTest.php` passes
- [ ] RelativeFormat rejects cassettes with negative dt values
- [ ] Existing valid cassettes (with dt >= 0) continue to work

#### Related Code Locations
- **Source:** `src/Format/RelativeFormat.php:200-201` (dt processing without validation)
- **Tests:** `tests/Format/RelativeFormatTest.php`

#### Investigation Notes
```php
// Lines 200-201 in decodeEvent():
$dt = (float) $data['dt'];
$absoluteT = round($cumulativeBase + $dt, self::T_PRECISION);
// BUG: No validation that $dt >= 0
```

**Fix:** Add validation after line 200:
```php
$dt = (float) $data['dt'];
if ($dt < 0) {
    throw new \RuntimeException("candy-vcr: negative dt on line {$lineNo}");
}
$absoluteT = round($cumulativeBase + $dt, self::T_PRECISION);
```

---

### 2.3 Fix preg_replace error handling in SanitizingHook

**File:** `src/Hook/SanitizingHook.php`
**Lines:** 80-81

#### What is Expected
Replace the null coalescing operator (`??`) with an explicit `=== false` check. `preg_replace()` returns `false` on error, not `null`. The `??` operator only handles `null`, so errors silently pass through unchanged.

#### Why the Change Should Be Done
This is a **MEDIUM severity bug** — invalid regex patterns will silently pass through un-replaced, potentially leaving sensitive data unsanitized without any warning.

#### Severity: MEDIUM

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Hook/SanitizingHookTest.php` passes
- [ ] Invalid regex produces warning or exception, not silent pass-through

#### Related Code Locations
- **Source:** `src/Hook/SanitizingHook.php:80-81`
- **Tests:** `tests/Hook/SanitizingHookTest.php`

#### Investigation Notes
```php
// Lines 80-81:
if (is_string($value)) {
    $data[$key] = preg_replace($pattern, $replacement, $value) ?? $value;
    // BUG: preg_replace returns false on error, not null. ?? doesn't catch false.
}
```

**Fix:**
```php
if (is_string($value)) {
    $result = preg_replace($pattern, $replacement, $value);
    if ($result === false) {
        // Log warning or handle error appropriately
        // For now, keep original value (don't modify on error)
    } else {
        $data[$key] = $result;
    }
}
```

---

### 2.4 Eliminate double iteration in InspectCommand

**File:** `src/Cli/InspectCommand.php`
**Lines:** 137-142

#### What is Expected
Remove the second iteration (lines 141-142) that re-creates the entire rendering pipeline just to count deduped frames. Instead, compute the dedup count inline during the first iteration.

#### Why the Change Should Be Done
This is a **MEDIUM severity performance issue** — double iteration doubles the rendering cost unnecessarily.

#### Severity: MEDIUM

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Cli/InspectFramesTest.php` passes
- [ ] Output of `candy-vcr inspect --frames` is unchanged
- [ ] Single iteration rather than double

#### Related Code Locations
- **Source:** `src/Cli/InspectCommand.php:107-151` (renderFrames method)
- **Source:** `src/Render/FrameDedup.php` (dedup logic to inline)
- **Tests:** `tests/Cli/InspectFramesTest.php`

#### Investigation Notes
```php
// Lines 121-135 - First iteration renders all frames
foreach ($stream as $snapshot) {
    $total++;
    $hash = $this->hashGrid($snapshot);
    // ... output frame ...
    if ($prev === null || !$snapshot->equals($prev)) {
        $unique++;
    }
    $prev = $snapshot;
}

// Lines 141-142 - Second iteration just to count deduped
$stream2 = (new Renderer())->render(new Player($cassette), $terminal, $fps);
$dedupCount = iterator_count(FrameDedup::dedup($stream2));
```

**Fix Approach:** Instead of using `FrameDedup`, track the dedup count during the first iteration using the same logic that `FrameDedup::dedup()` uses internally. The key is that `FrameDedup` groups consecutive identical snapshots and only yields when the snapshot changes. We can track this by checking if the current snapshot equals the last non-deduped snapshot.

---

### 2.5 Document mb_str_split PHP 8.3 requirement

**File:** `src/Tape/Compiler.php`
**Lines:** 190

#### What is Expected
This is an informational finding — no bug to fix. `mb_str_split()` was added in PHP 8.3, and the `composer.json` correctly requires `^8.3`.

#### Why the Change Should Be Done
This is **informational** — the code is correctly using a PHP 8.3+ only function.

#### Severity: MEDIUM (informational)

#### Conditions for Success
- [ ] Consider adding a code comment noting PHP 8.3+ requirement
- [ ] No actual code change required

#### Investigation Notes
```php
// Line 190:
$chars = mb_str_split($node->text);
```

---

### 2.6 Consider async fflush batching in Recorder

**File:** `src/Recorder.php`
**Lines:** 305-306

#### What is Expected
This is a design decision - the current behavior (flush on every event) is intentional for crash-safety. Consider adding an optional batch-flush mode for performance testing.

#### Why the Change Should Be Done
This is a **MEDIUM severity performance consideration** — `fflush()` on every event causes timing jitter during recording.

#### Severity: MEDIUM (performance consideration)

#### Conditions for Success
- [ ] No immediate change required
- [ ] Consider adding a constructor option or fluent method for batch-flush mode

#### Investigation Notes
```php
// Lines 305-306 in writeLine():
fwrite($this->fh, $json . "\n");
@fflush($this->fh);  // BLOCKING - intentional for crash safety
```

---

### 2.7 Consider async batch processing for FfmpegGifEncoder

**File:** `src/Encode/FfmpegGifEncoder.php`
**Lines:** 90-92

#### What is Expected
Consider implementing `encodeBatchAsync()` using ReactPHP promises for parallel ffmpeg encoding.

#### Why the Change Should Be Done
This is a **MEDIUM severity performance consideration** — sequential `Process::run()` for 100+ frames is slow.

#### Severity: MEDIUM (performance consideration)

#### Conditions for Success
- [ ] No immediate change required
- [ ] Consider future implementation of async batch encoding

#### Investigation Notes
```php
// Lines 90-92:
$process = new Process($args);
$process->setTimeout(300);
$exitCode = $process->run();  // BLOCKING - sequential
```

---

### 2.8 Consider async Player::playAsync() using ReactPHP

**File:** `src/Player.php`
**Lines:** 186-196

#### What is Expected
Consider implementing `Player::playAsync()` for concurrent replay.

#### Why the Change Should Be Done
This is a **MEDIUM severity performance consideration** — `stream_socket_pair()` and `fopen()` are blocking calls.

#### Severity: MEDIUM (performance consideration)

#### Conditions for Success
- [ ] No immediate change required
- [ ] Consider future implementation of async play

#### Investigation Notes
```php
// Lines 186-196:
$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$output = fopen('php://memory', 'w+b');
```

---

### 2.9 Performance: LZW Pixel Encoding in PhpGifEncoder

**File:** `src/Encode/PhpGifEncoder.php`
**Lines:** 151-156

#### What is Expected
Consider preallocating string or using `pack()` for pixel data.

#### Why the Change Should Be Done
This is a **MEDIUM severity performance issue** — string concatenation per pixel for 384,000 iterations is inefficient.

#### Severity: MEDIUM (performance)

#### Conditions for Success
- [ ] No immediate change required
- [ ] Consider future optimization using `pack()` or preallocation

#### Investigation Notes
```php
// Lines 151-156:
$pixels = '';
for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        $idx = imagecolorat($image, $x, $y);
        $pixels .= chr($idx & 0xff);  // Inefficient concatenation
    }
}
```

---

## Phase 3: LOW Severity Fixes

### 3.1 Use SplQueue or indexed FIFO for O(1) eviction in Glyphs

**File:** `src/Raster/Glyphs.php`
**Lines:** 146-158

#### What is Expected
Replace `array_key_first()` with an O(1) eviction strategy using `SplQueue` or indexed array.

#### Why the Change Should Be Done
This is a **LOW severity performance issue** — `array_key_first()` is O(n) on associative arrays, which is inefficient for the LRU cache eviction.

#### Severity: LOW

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Raster/GlyphsTest.php` passes
- [ ] Eviction uses O(1) algorithm

#### Investigation Notes
```php
// Lines 147-158 - evictIfNeeded():
private function evictIfNeeded(): void
{
    if (count($this->cache) >= self::MAX_CACHE_TILES) {
        $oldestKey = array_key_first($this->cache);  // O(n) - inefficient
        if ($oldestKey !== null) {
            $oldImage = $this->cache[$oldestKey];
            unset($this->cache[$oldestKey]);
            imagedestroy($oldImage);
            $this->evictions++;
        }
    }
}
```

**Fix:** Use SplQueue or maintain a separate eviction order array.

---

### 3.2 Document ImagickRasterizer clone sharing issue

**File:** `src/Raster/ImagickRasterizer.php`
**Lines:** 58-66, 69-81

#### What is Expected
Same as HIGH finding #2 — document that `tileCache` is shared between clones.

#### Severity: LOW (same as #2, already covered)

---

### 3.3 Rename shadowed variables in formatHunk

**File:** `src/Diff/DiffWriter.php`
**Lines:** 268-296

#### What is Expected
Rename local variables that shadow function parameters (`$expCount`, `$actCount`).

#### Why the Change Should Be Done
This is a **LOW severity code clarity issue** — variable shadowing makes code harder to understand.

#### Severity: LOW

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Diff/DiffWriterTest.php` passes
- [ ] Variables renamed to avoid shadowing (e.g., `$expCountOut`, `$actCountOut`)

#### Investigation Notes
```php
// Lines 268-296 - formatHunk():
private function formatHunk(int $start, array $contents, int $expCount, int $actCount): string
{
    // Parameters: $expCount, $actCount

    // Local variables SHADOW the parameters:
    $expStart = null;
    $expCount = 0;  // SHADOWS parameter $expCount
    $actStart = null;
    $actCount = 0;  // SHADOWS parameter $actCount
```

---

### 3.4 Improve DiffWriter formatHunk readability

**File:** `src/Diff/DiffWriter.php`
**Lines:** 268-310

#### What is Expected
Related to finding #3.3 — improve variable naming and reduce confusing variable reuse.

#### Severity: LOW

---

### 3.5 Complete V1ToV2Migrator migrateHeader()

**File:** `src/Migration/V1ToV2Migrator.php`
**Lines:** 82-93

#### What is Expected
The `migrateHeader()` method should add `formatVersion: "2.0"` and `migrationMeta` fields as documented in the class PHPDoc, not just set the numeric version.

#### Why the Change Should Be Done
This is a **LOW severity incomplete feature** — the migrator doesn't fully implement what its own documentation promises.

#### Severity: LOW

#### Conditions for Success
- [ ] `vendor/bin/phpunit tests/Migration/V1ToV2MigratorTest.php` passes
- [ ] Migrated v2 header includes `formatVersion` and `migrationMeta` fields

#### Investigation Notes
```php
// Lines 82-93 - migrateHeader():
private function migrateHeader(CassetteHeader $header): CassetteHeader
{
    // BUG: Only sets version, doesn't add formatVersion or migrationMeta
    return new CassetteHeader(
        version: self::TARGET_VERSION,
        createdAt: $header->createdAt,
        cols: $header->cols,
        rows: $header->rows,
        runtime: $header->runtime,
    );
    // Missing: formatVersion => "2.0", migrationMeta => buildMigrationMeta()
}
```

The PHPDoc at lines 14-29 documents:
```
* v2 format adds:
*   - Header gains `formatVersion` field (string "2.0") alongside numeric `v`
*   - Header gains `migrationMeta` object tracking source version, migratedAt timestamp,
*     and migrator identifier
```

---

### 3.6 Document missing MouseScrollMsg and FocusMoveMsg handlers

**File:** `src/Msg/BuiltinSerializer.php`

#### What is Expected
Document which candy-core Msg types fall through to `JsonableSerializer`.

#### Why the Change Should Be Done
This is a **LOW severity documentation issue** — clarity for developers extending the serializer.

#### Severity: LOW

#### Conditions for Success
- [ ] PHPDoc or README notes which msg types fall through
- [ ] No behavior change

---

### 3.7 Screenshot path confinement - adequately secured

**File:** `src/TapeToGif.php`
**Lines:** 166-196

#### What is Expected
No action needed — the audit found path confinement to be "adequately secured."

#### Severity: LOW (informational)

---

## Verification Commands

```bash
# Run all tests for candy-vcr
cd candy-vcr && composer install && vendor/bin/phpunit

# Run specific test files
vendor/bin/phpunit tests/Encode/TapeToGifTest.php
vendor/bin/phpunit tests/Raster/ImagickRasterizerCacheTest.php
vendor/bin/phpunit tests/Render/FrameStreamTest.php
vendor/bin/phpunit tests/Format/RelativeFormatTest.php
vendor/bin/phpunit tests/Hook/SanitizingHookTest.php
vendor/bin/phpunit tests/Cli/InspectFramesTest.php
vendor/bin/phpunit tests/Raster/GlyphsTest.php
vendor/bin/phpunit tests/Migration/V1ToV2MigratorTest.php
vendor/bin/phpunit tests/Diff/DiffWriterTest.php

# Syntax check
php -l src/Encode/TapeToGif.php
php -l src/Raster/ImagickRasterizer.php
php -l src/Format/RelativeFormat.php
php -l src/Hook/SanitizingHook.php
php -l src/Cli/InspectCommand.php
php -l src/Diff/DiffWriter.php
php -l src/Migration/V1ToV2Migrator.php
php -l src/Raster/Glyphs.php
```

---

## Priority Order

1. **CRITICAL:** Fix undefined $cassette in TapeToGif (1.1)
2. **HIGH:** Fix ImagickRasterizer cache sharing (1.2)
3. **MEDIUM:** Add negative dt validation (2.2)
4. **MEDIUM:** Fix preg_replace error handling (2.3)
5. **MEDIUM:** Eliminate double iteration in InspectCommand (2.4)
6. **MEDIUM:** Fix FrameStream mutation during iteration (2.1)
7. **LOW:** Complete V1ToV2Migrator (3.5)
8. **LOW:** Rename shadowed variables in formatHunk (3.3)
9. **LOW:** O(1) eviction in Glyphs (3.1)
10. **INFO:** mb_str_split PHP 8.3 (2.5)
11. **INFO:** Screenshot path confinement (3.7)
12. **PERF:** Consider async options (2.6, 2.7, 2.8, 2.9)
