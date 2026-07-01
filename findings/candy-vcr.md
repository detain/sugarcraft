# Audit: candy-vcr

**Library:** SugarCraft/candy-vcr  
**Date:** 2026-06-30  
**Files audited:** 50+ PHP source files + test files  
**Upstream:** charmbracelet/vcr

---

## Overview

`candy-vcr` records and replays TUI program sessions. Generally well-structured with proper strict types and good PHPDoc coverage.

---

## Severity Summary

| Severity | Count |
|----------|-------|
| HIGH | 2 |
| MEDIUM | 9 |
| LOW | 8 |

---

## 1. HIGH: Undefined Variable $cassette in TapeToGif
**Location:** src/TapeToGif.php:70-71

```php
$fontSize = $cassette->header->fontSize ?? (int) ($options['fontSize'] ?? 14);
$fontFamily = $cassette->header->fontFamily ?? $options['fontFamily'] ?? 'JetBrainsMono';
```

$cassette used before assigned (only set at line 81). Will cause Undefined variable error.

Recommendation: Move lines 70-71 after line 81, or compute after $cassette is available.

---

## 2. HIGH: ImagickRasterizer Shared Tile Cache Between Clones
**Location:** src/ImagickRasterizer.php:58-66

```php
$clone->tileCache = $this->tileCache;  // SHARED REFERENCE
```

When withTheme() is called, clone shares same tileCache array. If either instance calls clearTileCache(), destroys tiles the other needs.

Recommendation: Deep-clone the cache array or implement copy-on-write.

---

## 3. MEDIUM: FrameStream Mutation During Iteration
**Location:** src/TapeToGif.php:104-106

FrameStream has public properties $pendingScreenshotPath and $captureCursor mutated by generator during iteration. External code reads these mid-iteration — data race.

Recommendation: Snapshot state before iteration or expose via generator method.

---

## 4. MEDIUM: Negative dt Validation Missing
**Location:** src/RelativeFormat.php:201

```php
$dt = (float) $data['dt'];
$absoluteT = round($cumulativeBase + $dt, self::T_PRECISION);
```

Negative dt values produce timestamps that go backwards. No validation.

Recommendation: Validate $dt >= 0.

---

## 5. MEDIUM: preg_replace Error Handling Wrong
**Location:** src/SanitizingHook.php:81

```php
$data[$key] = preg_replace($pattern, $replacement, $value) ?? $value;
```

preg_replace() returns false on error, not null. ?? won't catch failures.

Recommendation: === false check instead of ??.

---

## 6. MEDIUM: Double Iteration in InspectCommand
**Location:** src/InspectCommand.php:137-142

First iteration renders frames. Second iteration re-creates entire rendering pipeline just to count deduped frames. Doubles rendering cost.

Recommendation: Collect frames during first iteration and compute dedup count inline.

---

## 7. MEDIUM: PHP 8.3 Only mb_str_split()
**Location:** src/Compiler.php:190

```php
$chars = mb_str_split($node->text);
```

mb_str_split() added in PHP 8.3. composer.json requires ^8.3 so this is correct.

---

## 8. MEDIUM: Blocking fflush() on Every Event
**Location:** src/Recorder.php:306

```php
fwrite($this->fh, $json . "\n");
@fflush($this->fh);  // BLOCKING
```

Every recorded event flushes to disk. Causes timing jitter.

Recommendation: Batch N events before flush or use background writer with pipe.

---

## 9. MEDIUM: Blocking Process::run() in FfmpegGifEncoder
**Location:** src/FfmpegGifEncoder.php:90-92

100 sequential ffmpeg invocations for batch rendering.

Recommendation: Process::start() with async event loop integration, or parallel batch processing.

---

## 10. MEDIUM: Blocking stream_socket_pair() and fopen()
**Location:** src/Player.php:186-196

Synchronous blocking calls. Multiple Player::play() calls can't run concurrently.

Recommendation: Consider async Player::playAsync() using ReactPHP promises.

---

## 11. LOW: LZW Pixel Encoding Performance
**Location:** src/PhpGifEncoder.php:151-156

String concatenation per pixel in loop. 384,000 iterations for 800x480 GIF.

Recommendation: Preallocate string or use pack() with pre-built format.

---

## 12. LOW: FIFO Eviction O(n)
**Location:** src/Glyphs.php:146-158

array_key_first() is O(n) on associative arrays. Combined with MAX_CACHE_TILES=4096.

Recommendation: Use SplQueue or index-based FIFO for O(1) eviction.

---

## 13. LOW: ImagickRasterizer Clone Sharing
**Location:** src/ImagickRasterizer.php:58-66

Same as HIGH finding 2 — shared tileCache between clones.

---

## 14. LOW: Variable Shadowing in formatHunk
**Location:** src/DiffWriter.php:167-310

Parameter names $expCount and $actCount overwritten by local variables.

---

## 15. LOW: DiffWriter Complexity
**Location:** src/DiffWriter.php

formatHunk() has confusing variable reuse. Consider renaming to $expCountOut, $actCountOut.

---

## 16. LOW: V1ToV2Migrator Incomplete
**Location:** src/V1ToV2Migrator.php

migrateHeader() only sets numeric version to 2. Doesn't add documented v2 fields (formatVersion, migrationMeta).

---

## 17. LOW: Missing MouseScrollMsg and FocusMoveMsg Handlers
**Location:** src/BuiltinSerializer.php

Some candy-core Msg types fall through to JsonableSerializer. Should be documented.

---

## 18. LOW: Screenshot Path Confinement Race
**Location:** src/TapeToGif.php:166-196

realpath() returns false for non-existent paths. Check at line 179 throws. If path exists and is symlink, realpath() resolves it. Check at 181 catches this. Adequately secured.

---

## Memory: No leaks. imagedestroy called on evicted tiles. No stream resource leaks.

## Security: Adequately secured. Screenshot path confinement with realpath() check. preg_match errors silently skipped (not vulnerable).

## PHP 8.3+: Compatible. Uses readonly, promoted constructors, match expressions.

## Async: Several blocking operations identified (fflush, process::run, stream_socket_pair). Could benefit from ReactPHP integration for batch processing.

---

## Recommendations Priority

1. CRITICAL: Fix undefined $cassette in TapeToGif
2. HIGH: Fix ImagickRasterizer cache sharing
3. MEDIUM: Add negative dt validation, fix preg_replace error handling
4. MEDIUM: Eliminate double iteration in InspectCommand
5. MEDIUM: Consider async for batch rendering (ffmpeg, player replay)
