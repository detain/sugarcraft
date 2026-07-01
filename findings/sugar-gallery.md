# Audit: sugar-gallery

**Library:** SugarCraft/sugar-gallery  
**Date:** 2026-06-30  

---

## Overview

`sugar-gallery` is a port of `charmbracelet/bubbles/gallery` — an image gallery browser for terminals supporting various image formats via FFI and file system browsing.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — Image loading doesn't validate file type
**Severity:** HIGH  
**Location:** `src/Gallery.php:78-85`

The loader opens any file and attempts to decode it as an image. No magic-bytes validation. Passing a non-image file could cause the decoder to throw or behave unexpectedly.

**Recommendation:** Check magic bytes before attempting decode.

---

### Finding 2 — Directory traversal in path navigation
**Severity:** HIGH  
**Location:** `src/Gallery.php:95`

`chdir($path)` is used to navigate directories. No sandboxing. If a user navigates to `/etc` or similar, subsequent file operations could be restricted or expose system files.

**Recommendation:** Validate that `$path` is within an allowed root directory.

---

### Finding 3 — FFI image decoder doesn't validate dimensions
**Severity:** MEDIUM  
**Location:** `src/FFIDecoder.php:45`

If image dimensions are 0×0 or extremely large, no validation is performed. Large images could exhaust memory.

**Recommendation:** Validate width/height before allocating decode buffer.

---

### Finding 4 — Missing format support fallback
**Severity:** MEDIUM  
**Location:** `src/Gallery.php`

If a format is unsupported, the error is thrown but not caught. The gallery crashes instead of showing an error message and continuing.

**Recommendation:** Catch format errors, show user-friendly message, skip to next image.

---

## 2. Performance Problems

### Finding 5 — Full directory scan on every navigation
**Severity:** MEDIUM  
**Location:** `src/Gallery.php`

Every `chdir()` triggers a full filesystem scan of the new directory. For directories with many files, this causes perceptible lag.

**Recommendation:** Cache directory listings, invalidate on file system events.

---

### Finding 6 — No image thumbnail caching
**Severity:** MEDIUM  
**Location:** `src/FFIDecoder.php`

Same image revisited requires full decode. No thumbnails cached in memory.

---

## 3. Memory Leaks

### Finding 7 — FFI buffer not explicitly freed
**Severity:** HIGH  
**Location:** `src/FFIDecoder.php`

FFI C allocations must be explicitly `free()`'d. PHP GC can't manage C memory.

**Recommendation:** Wrap FFI allocations in explicit try/finally with free.

---

### Finding 8 — Image buffer held during viewing
**Severity:** MEDIUM  
**Location:** `src/Gallery.php`

Full decoded image held in memory while viewing. Large images could exhaust RAM.

---

## 4. Security

### Finding 9 — No security concerns beyond Finding 1 & 2
**Severity:** MEDIUM  

Path traversal risk (Finding 2) is the main concern.

---

## 5. Complexity

### Finding 10 — FFIDecoder abstraction is thin
**Severity:** LOW  
**Location:** `src/FFIDecoder.php`

The FFI wrapper is a thin passthrough. Consider whether abstraction adds value.

---

## 6. Missing Features / Incomplete Ports

### Finding 11 — No animated image support (GIF)
**Severity:** MEDIUM  
**Location:** `src/FFIDecoder.php`

Only first frame decoded. Animated GIFs don't animate.

---

### Finding 12 — No metadata display (EXIF, dimensions, size)
**Severity:** LOW  
**Location:** `src/Gallery.php`

Gallery shows filename but not dimensions/file size/date.

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 13 — FFI requires PHP 8.4+ on Windows
**Severity:** MEDIUM  
**Location:** `composer.json`

FFI only available reliably on PHP 8.4+ for Windows. Library should note this constraint.

---

## 8. Async/ReactPHP Improvements

### Finding 14 — Image decoding is blocking
**Severity:** MEDIUM  
**Location:** `src/FFIDecoder.php`

Decoding a large image blocks the event loop. For large images, this causes UI freeze.

**Recommendation:** Decode in background process, stream result back.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| HIGH | 3 | No file type validation, path traversal, FFI buffer not freed |
| MEDIUM | 6 | Missing format fallback, full dir scan, no thumbnail cache, large image memory, animated GIF not supported, PHP 8.4 FFI, blocking decode |
| LOW | 2 | Thin FFI abstraction, no metadata display |

**Total: 15 findings**
