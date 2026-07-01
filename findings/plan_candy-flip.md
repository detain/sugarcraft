---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-flip

## Goal

Address all medium and low severity findings from the candy-flip code review: implement per-frame delay scheduling in Player, remove dead code branches in Decoder's downsampling methods, consolidate duplicate sampling logic, optimize snapshot allocation, and improve documentation clarity.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Per-frame delay should drive tick scheduling | Animated GIFs with variable frame timings lose intended rhythm when played at uniform rate | `findings/candy-flip.md` §Medium-1 |
| `$allTransparent` branches are unreachable dead code | When `count === 0`, `$allTransparent` is always `false` because line 242 sets it to `false` for ANY opaque pixel | `findings/candy-flip.md` §Medium-2 |
| sampleCanvas/sample deduplication via helper | ~80 lines duplicated; DRY violation and maintenance hazard | `findings/candy-flip.md` §Low-3 |
| Lazy snapshot allocation | DISPOSAL_PREVIOUS is rare; creating GD image every frame is wasteful | `findings/candy-flip.md` §Low-4 |
| GIF89a GCE delay in centiseconds | GCE stores delay as 16-bit LE centisecond value; Frame::$delay stores same unit | `candy-flip/src/Frame.php:37`, CALIBER_LEARNINGS.md pattern:gce-delay-centiseconds |

## Phase 1: Per-Frame Delay in Player [PENDING]

- [ ] **1.1 Verify Frame::$delay propagation from Decoder to Player**
  - `Frame::$delay` is set from GCE in `Decoder::parseHeader()` at lines 316–318 and defaults to `10` centiseconds
  - Verified by reading `candy-flip/src/Decoder.php:293–319` and `candy-flip/src/Frame.php:35–40`

- [ ] **1.2 Modify Player::scheduleTick() to use per-frame delay**
  - Location: `candy-flip/src/Player.php:119–122`
  - Current: `return Cmd::tick($this->interval, static fn(): Msg => new TickMsg());`
  - Change to:
    ```php
    private function scheduleTick(): \Closure
    {
        $delay = $this->frames[$this->index]->delay;
        $interval = $delay > 0 ? $delay / 100.0 : $this->interval;
        return Cmd::tick($interval, static fn(): Msg => new TickMsg());
    }
    ```
  - `Frame::$delay` is centiseconds; `Cmd::tick()` takes seconds → divide by 100.0
  - When `$delay === 0` (no delay specified by GIF), fall back to `$this->interval`
  - Note: GIF spec default of 100ms is already stored as delay=10 in Frame, so non-zero delay is always available

- [ ] **1.3 Add test for per-frame delay scheduling**
  - File: `candy-flip/tests/PlayerTest.php` (or new `PerFrameDelayTest.php`)
  - Create Player with frames having distinct delays (e.g., Frame(delay: 20cs) and Frame(delay: 5cs))
  - After first TickMsg: assert the emitted Cmd uses interval ≈ 0.2s
  - After second TickMsg: assert the emitted Cmd uses interval ≈ 0.05s
  - Pattern: `testPerFrameDelaySchedulesAppropriateInterval()`

## Phase 2: Remove Dead `$allTransparent` Branches [PENDING]

- [ ] **2.1 Audit allTransparent logic in sampleCanvas()**
  - Location: `candy-flip/src/Decoder.php:229–261`
  - `$allTransparent` initialized `true` at line 229
  - Set `false` at line 242 whenever any opaque pixel is seen
  - At line 237–240: transparent pixels (`$a !== 0`) `continue` without setting `allTransparent`
  - When `count === 0`: means only transparent pixels were in the cell → `$allTransparent` is still `true`
  - But both `elseif ($allTransparent)` (line 258) and `else` (line 260) return `null`
  - The `elseif` branch is semantically unreachable (both branches identical) — dead code

- [ ] **2.2 Audit allTransparent logic in sample()**
  - Location: `candy-flip/src/Decoder.php:511–541`
  - Same pattern: `$allTransparent` initialized `true` at 511, set `false` at 522 for ANY opaque pixel
  - When `count === 0`, `$allTransparent` is always `false` → `elseif` at 536 unreachable
  - Both branches return `null`

- [ ] **2.3 Simplify sampleCanvas() conditional**
  - File: `candy-flip/src/Decoder.php:252–262`
  - Change from:
    ```php
    if ($count > 0) {
        $row[] = [...];
    } elseif ($allTransparent) {
        $row[] = null;
    } else {
        $row[] = null;
    }
    ```
  - To:
    ```php
    if ($count > 0) {
        $row[] = [...];
    } else {
        $row[] = null;
    }
    ```

- [ ] **2.4 Simplify sample() conditional**
  - File: `candy-flip/src/Decoder.php:530–542`
  - Same simplification as 2.3

## Phase 3: Consolidate sampleCanvas() and sample() [PENDING]

- [ ] **3.1 Extract shared downsampleRegion() helper**
  - Location: `candy-flip/src/Decoder.php` (new method after line 577)
  - Both methods share nested-loop structure: cell rows × cell cols × pixel rows × pixel cols
  - Key difference: `sampleCanvas()` uses truecolor pixel unpacking; `sample()` uses palette index resolution
  - Proposed helper:
    ```php
    private static function downsampleRegion(
        \GdImage $img,
        bool $isPalette,
        int $x0, int $x1, int $y0, int $y1,
    ): ?array{0:int, 1:int, 2:int} {
        $sumR = $sumG = $sumB = 0;
        $count = 0;
        for ($sy = $y0; $sy <= $y1; $sy++) {
            for ($sx = $x0; $sx <= $x1; $sx++) {
                $pixel = imagecolorat($img, $sx, $sy);
                if ($isPalette) {
                    $rgb = imagecolorsforindex($img, $pixel);
                    if ($rgb === false) continue;
                    $sumR += $rgb['red'];
                    $sumG += $rgb['green'];
                    $sumB += $rgb['blue'];
                    $count++;
                } else {
                    $a = ($pixel >> 24) & 0xFF;
                    if ($a !== 0) continue;
                    $sumR += ($pixel >> 16) & 0xFF;
                    $sumG += ($pixel >> 8) & 0xFF;
                    $sumB += $pixel & 0xFF;
                    $count++;
                }
            }
        }
        return $count > 0 ? [
            (int) round($sumR / $count),
            (int) round($sumG / $count),
            (int) round($sumB / $count),
        ] : null;
    }
    ```

- [ ] **3.2 Refactor sampleCanvas() to use downsampleRegion()**
  - File: `candy-flip/src/Decoder.php:209–267`
  - Replace lines 225–251 (nested loops) with: `$rgb = self::downsampleRegion($canvas, false, $x0, $x1, $y0, $y1);`
  - Then: `$row[] = $rgb;` (helper returns null for empty cells)

- [ ] **3.3 Refactor sample() to use downsampleRegion()**
  - File: `candy-flip/src/Decoder.php:473–548`
  - Replace lines 507–529 (nested loops) with: `$rgb = self::downsampleRegion($img, true, $x0, $x1, $y0, $y1);`
  - Note: `sample()` also handles transparent pixels via `transparentColor` index check — this must be preserved or moved into the helper
  - The transparent check at line 519 (`if ($transparent && $index === $transparentColor)`) needs to remain — either keep it in sample() before calling helper, or pass `$transparentColor` to helper

- [ ] **3.4 Run full test suite to verify refactor**
  - `cd candy-flip && composer install && vendor/bin/phpunit`
  - All 10 test files must pass
  - Golden file snapshots must match (GoldenRenderTest.php)

## Phase 4: Lazy Snapshot Allocation [PENDING]

- [ ] **4.1 Audit current snapshot allocation**
  - Location: `candy-flip/src/Decoder.php:84–85` (init) and `107–111` (allocation)
  - `$snapshot = null` initialized at line 85
  - Lines 107–111: snapshot created on EVERY frame iteration unconditionally
  - DISPOSAL_PREVIOUS (method 3) is rare in practice; most GIFs use disposal 0, 1, or 2

- [ ] **4.2 Implement lazy snapshot allocation**
  - File: `candy-flip/src/Decoder.php:107–111`
  - Change from:
    ```php
    $snapshot = imagecreatetruecolor($screenW, $screenH);
    imagesavealpha($snapshot, true);
    imagefill($snapshot, 0, 0, $transparentBg);
    imagecopy($snapshot, $canvas, 0, 0, 0, 0, $screenW, $screenH);
    ```
  - To:
    ```php
    if ($snapshot === null) {
        $snapshot = imagecreatetruecolor($screenW, $screenH);
        imagesavealpha($snapshot, true);
        imagefill($snapshot, 0, 0, $transparentBg);
    }
    imagecopy($snapshot, $canvas, 0, 0, 0, 0, $screenW, $screenH);
    ```
  - Behavior preserved: snapshot is taken before painting each frame (needed for next frame's DISPOSAL_PREVIOUS)
  - Optimization: GD image is allocated only once instead of every frame

- [ ] **4.3 Add DISPOSAL_PREVIOUS compositing test (test gap)**
  - File: `candy-flip/tests/DecoderCompositingTest.php`
  - Add `testDisposalPreviousRestoresFromSnapshot()`
  - Build 2-frame GIF where frame 0 has DISPOSAL_PREVIOUS and frame 1 is a different color
  - Verify the decoder correctly stores and applies DISPOSAL_PREVIOUS
  - Note: Frame.php line 22 says DISPOSAL_PREVIOUS is "unsupported; treated as NONE" — this needs verification

## Phase 5: Clarify LCT Comment in renderSingleFrame() [PENDING]

- [ ] **5.1 Fix misleading comment in renderSingleFrame()**
  - Location: `candy-flip/src/Decoder.php:444`
  - Current: `"Local color table when present (after the Image Descriptor header)"`
  - Problem: Comment is ambiguous — "after the Image Descriptor header" could mean "after the 10-byte header structure" (which is correct: LCT IS at offset+10) or "after reading the header in the reassembled payload" (also correct)
  - The code at lines 445–449 is correct: `$lctOffset = $offset + 10` — this is after the Image Descriptor
  - Fix comment:
    ```php
    // Local color table when present — follows immediately after the
    // 10-byte Image Descriptor in the original GIF byte stream.
    if ($hasLct) {
        $lctOffset = $offset + 10;
        $gifData .= substr($bytes, $lctOffset, $lctBytes);
    }
    ```

## Phase 6: Transparent Pixel Alpha Detection [PENDING]

- [ ] **6.1 Document alpha detection behavior in sampleCanvas()**
  - Location: `candy-flip/src/Decoder.php:236–240`
  - Current: `if ($a !== 0) { continue; }` where `$a = ($pixel >> 24) & 0xFF`
  - PHP GD convention: alpha 0 = fully opaque, alpha 127 = fully transparent
  - Non-zero alpha treated as transparent and excluded from average
  - Semi-transparent pixels (0 < alpha < 127) skipped — may cause slight color shifts for transparent overlays
  - Add inline comment:
    ```php
    // PHP GD alpha: 0 = fully opaque, 127 = fully transparent.
    // Any non-zero alpha is treated as transparent and excluded from the average.
    $a = ($pixel >> 24) & 0xFF;
    if ($a !== 0) {
        continue;
    }
    ```

## Phase 7: Renderer::render() Deprecation Evaluation [PENDING]

- [ ] **7.1 Check if Renderer::render() is used externally**
  - Location: `candy-flip/src/Renderer.php:81–84`
  - Search: `grep -r "Renderer::render" /home/sites/sugarcraft --include="*.php"`
  - If no external usage found: add `@deprecated` docblock and keep for backward compatibility
  - If used: keep with deprecation notice; new code should use `Renderer::new()->renderFrame()`

## Verification

All changes must satisfy:

1. `cd candy-flip && composer install && vendor/bin/phpunit` — all tests pass
2. `php -l src/Player.php src/Decoder.php src/Renderer.php` — no syntax errors
3. `composer validate --no-check-publish` — passes (skip strict due to sugarcraft/@dev deps)
4. Any golden-file snapshots that change must be verified visually

## Notes

- 2026-06-30: Plan created from findings in `findings/candy-flip.md`. Source locations verified by reading the actual files.
- The plan preserves backward compatibility — no API changes to public interfaces.
- Phase ordering: deduplication (Phase 3) should precede lazy allocation (Phase 4) to minimize diff complexity.
- DISPOSAL_PREVIOUS test gap (Phase 4.3) was explicitly called out in the review's Test Coverage Assessment section.
