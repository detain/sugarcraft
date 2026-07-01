# Implementation Plan: sugar-gallery Audit Findings

**Library:** SugarCraft/sugar-gallery  
**Date:** 2026-06-30  
**Status:** in-progress

---

## Goal

Create a detailed implementation plan addressing all 15 findings from `/home/sites/sugarcraft/findings/sugar-gallery.md`, investigating each in the actual codebase and providing concrete action items with citations.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| sugar-gallery is renderer-agnostic by design | PosterCard holds pre-rendered poster bytes; it never decodes images. Image rendering is delegated to candy-mosaic (ext-gd) | `findings/sugar-gallery.md:L10` |
| Gallery.php and FFIDecoder.php do not exist | Current library has PosterCard, Rail, PosterGrid only. No FFI decoder, no file-system navigation, no chdir() | `glob:sugar-gallery/src/**/*.php` |
| No 1:1 upstream port | MATCHUPS.md states this is an original SugarCraft component inspired by bubbles' list/viewport and phlix web media grid | `docs/MATCHUPS.md:L51` |
| FFI patterns exist in candy-pty | PosixTermios shows proper FFI allocation/try-finally pattern | `candy-pty/src/Posix/PosixTermios.php:L39-L44` |
| Image validation in candy-mosaic | ImageSource.fromFile() validates via getimagesize() before decode | `candy-mosaic/src/ImageSource.php:L53-L56` |

---

## Phase 1: Investigation — Finding Classification [COMPLETE]

### Current Source Files (what exists)

**Source:** `glob:sugar-gallery/src/**/*.php`

| File | Purpose | Lines |
|------|---------|-------|
| `src/PosterCard.php` | Immutable tile holding pre-rendered poster bytes | 247 |
| `src/Rail.php` | Horizontal carousel of PosterCards | 155 |
| `src/PosterGrid.php` | 2-D virtualized sparse grid | 413 |

### Finding Classification Matrix

| Finding | Description | Current State | Classification |
|---------|-------------|---------------|----------------|
| **1** | No file type validation | FFIDecoder.php does not exist | **N/A — by design** |
| **2** | Directory traversal (chdir) | Gallery.php does not exist | **N/A — by design** |
| **3** | FFI dimension validation | FFIDecoder.php does not exist | **N/A — by design** |
| **4** | Missing format fallback | No image loading | **N/A — by design** |
| **5** | Full dir scan on nav | Gallery.php does not exist | **N/A — by design** |
| **6** | No thumbnail caching | FFIDecoder does not exist | **N/A — by design** |
| **7** | FFI buffer not freed | FFIDecoder.php does not exist | **N/A — by design** |
| **8** | Image buffer in memory | No image decoder | **N/A — by design** |
| **9** | Path traversal security | Gallery.php does not exist | **N/A — by design** |
| **10** | Thin FFI abstraction | FFIDecoder.php does not exist | **N/A — by design** |
| **11** | No animated GIF support | FFIDecoder.php does not exist | **N/A — by design** |
| **12** | No metadata display | PosterCard shows title but not dimensions | **APPLIES** |
| **13** | FFI requires PHP 8.4+ | No FFI in current code | **N/A — by design** |
| **14** | Blocking image decode | No image decoder; candy-mosaic has async | **N/A — by design** |

**Investigation notes from 2026-06-30:**
The findings file references `src/Gallery.php:78-85` and `src/FFIDecoder.php:45` which do not exist in the current codebase. CALIBER_LEARNINGS.md explicitly states "Renderer-agnostic by design" and "it never decodes images". The library intentionally delegates image rendering to candy-mosaic (ext-gd based). Findings 1-11, 13-14 describe features of a planned-but-unbuilt FFI image decoder and file browser.

---

## Phase 2: Implement Applicable Findings [PENDING]

### 2.1 Finding 12 — No Metadata Display

**Source:** `findings/sugar-gallery.md:L125-L128`

#### What is Expected
PosterCard shows filename/title but not dimensions/file size/date.

#### Why the Change Should be Done
Users cannot see image metadata (dimensions, file size) without decoding the image, which is intentionally not done in sugar-gallery. However, optional metadata fields could be added for display purposes without coupling to an image decoder.

#### Severity: LOW

#### Conditions for Success
- PosterCard can optionally display dimensions (e.g., "1920×1080") and file size (e.g., "2.4MB")
- Metadata is purely decorative — does not affect rendering geometry
- Consumer is responsible for providing metadata

#### Related Code Locations
- `sugar-gallery/src/PosterCard.php:L38-47` — constructor
- `sugar-gallery/src/PosterCard.php:L106-143` — render() method
- `sugar-mosaic/src/ImageSource.php:L25-30` — has width/height fields that could supply metadata

#### Implementation Approach
Add optional metadata fields to PosterCard:

```php
// sugar-gallery/src/PosterCard.php — constructor modification
public function __construct(
    public string $id,
    public string $title,
    public ?string $posterUrl = null,
    public ?float $progress = null,
    public ?string $poster = null,
    public ?string $styledTitle = null,
    public ?string $posterImage = null,
    public ?int $imageId = null,
    // NEW: optional metadata
    public ?string $dimensions = null,  // e.g., "1920×1080"
    public ?string $fileSize = null,    // e.g., "2.4MB"
) {}
```

Add to `::new()` factory and update render() to optionally display metadata.

#### Investigation Notes
- candy-mosaic's `ImageSource` already has `$width`, `$height`, and access to file size via `filesize()` in `fromFile()`
- The CALIBER_LEARNINGS explicitly says NOT to add an image decoder dependency
- Metadata should be provided by the consumer that calls candy-mosaic to render

---

## Phase 3: Findings Requiring New Feature Development [PENDING]

The remaining 13 findings (1-11, 13-14) describe features that **do not exist** in the current codebase. These are **NOT RECOMMENDED** for sugar-gallery given its renderer-agnostic design principle.

### 3.1 All Non-Applicable Findings

**Source:** `docs/MATCHUPS.md:L51`

The MATCHUPS.md entry confirms:
> "No 1:1 upstream; original SugarCraft component inspired by bubbles' list/viewport and the phlix web media grid."

**Decision:** Do NOT implement FFI image decoder or Gallery file browser in sugar-gallery. This violates the renderer-agnostic design principle in CALIBER_LEARNINGS.md.

#### Finding-by-Finding Rationale

| Finding | Issue | Why Not Applicable | Recommendation |
|---------|-------|-------------------|----------------|
| **1** | No file type validation | FFIDecoder doesn't exist | Delegate to candy-mosaic's ImageSource validation (`candy-mosaic/src/ImageSource.php:L53-56`) |
| **2** | Path traversal (chdir) | Gallery.php doesn't exist | If file browsing needed, create separate `sugar-files` library |
| **3** | FFI dimension validation | FFIDecoder doesn't exist | N/A — no FFI decoder planned |
| **4** | Format error fallback | No image loading | Delegate to candy-mosaic error handling |
| **5** | Full directory scan | Gallery.php doesn't exist | N/A — no directory navigation in renderer-agnostic design |
| **6** | No thumbnail caching | FFIDecoder doesn't exist | candy-mosaic has `AdaptiveImage` with LRU cache (`candy-mosaic/src/AdaptiveImage.php:L21-26`) |
| **7** | FFI buffer not freed | FFIDecoder.php doesn't exist | N/A — no FFI decoder |
| **8** | Image buffer memory | No image decoder | Delegate to candy-mosaic which handles this |
| **9** | Path traversal risk | Gallery.php doesn't exist | N/A — no file browsing |
| **10** | Thin FFI abstraction | FFIDecoder.php doesn't exist | N/A — no FFI decoder |
| **11** | No animated GIF | FFIDecoder doesn't exist | candy-mosaic's `AnimationDriver` handles GIF animation |
| **13** | PHP 8.4 FFI constraint | No FFI in current code | N/A — no FFI usage |
| **14** | Blocking decode | No image decoder | candy-mosaic has `AsyncRenderer` for non-blocking (`candy-mosaic/src/AsyncRenderer.php`) |

---

## Phase 4: Documentation & Verification [PENDING]

### 4.1 Update CALIBER_LEARNINGS.md

Add clarification that the audit findings about FFI/Gallery are not applicable because the library is intentionally renderer-agnostic.

### 4.2 Add Note to composer.json

If FFI features are ever considered in the future, add constraint:
```json
"require": {
    "php": "^8.4"  // If FFI features added
}
```

---

## Summary Table

| Category | Count | Action |
|----------|-------|--------|
| Applicable findings | 1 | Finding 12 (metadata display) — implement optional fields |
| Not applicable (by design) | 13 | Findings 1-11, 13-14 — FFI/Gallery not part of renderer-agnostic design |
| Critical issues in current code | 0 | None — no HIGH severity issues apply |
| New features required | 0 | None recommended for sugar-gallery |

---

## Notes

- **2026-06-30**: The audit findings describe a `Gallery.php` and `FFIDecoder.php` that do not exist. The sugar-gallery library is intentionally renderer-agnostic per CALIBER_LEARNINGS.md.
- **2026-06-30**: MATCHUPS.md confirms this is an original SugarCraft component, not a 1:1 port of charmbracelet/bubbles/gallery.
- **2026-06-30**: Image rendering is handled by candy-mosaic (ext-gd based); file browsing would belong in a separate library.
- **2026-06-30**: FFI patterns are established in candy-pty (PosixTermios) and can be referenced if FFI decoding is ever implemented.

---

## Appendix: Reference Patterns

### FFI Best Practices (from candy-pty)

**Source:** `candy-pty/src/Posix/PosixTermios.php:L39-L44`

```php
public function __construct(int $fd)
{
    $this->fd = $fd;
    $this->libc = Libc::lib();
    $this->buf = $this->libc->new('char[' . self::BUFSIZE . ']');  // FFI allocation
}
```

### Magic Byte Validation Pattern (from candy-mosaic)

**Source:** `candy-mosaic/src/ImageSource.php:L53-56`

```php
$info = @getimagesize($path);
if ($info === false) {
    throw new \InvalidArgumentException(Lang::t('image_source.unsupported_format', ['path' => $path]));
}
```

### Async Rendering with Caching (from candy-mosaic)

**Source:** `candy-mosaic/src/AdaptiveImage.php:L59-73`

```php
public function render(int $cellWidth, int $cellHeight): string
{
    $key = "{$cellWidth}x{$cellHeight}";

    if (isset($this->cache[$key])) {
        $this->touchLru($key);
        return $this->cache[$key];
    }

    $bytes = $this->mosaic->render($this->image, $cellWidth, $cellHeight);
    $this->cache[$key] = $bytes;
    $this->touchLru($key);

    return $bytes;
}
```

---

**End of plan**
