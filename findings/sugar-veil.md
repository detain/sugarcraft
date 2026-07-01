# Sugar-Veil Library Audit Findings

**Library:** sugarcraft/sugar-veil (port of rmhubbert/bubbletea-overlay)  
**Audit Date:** 2026-06-30  
**PHP Version Target:** ^8.3

---

## Executive Summary

A well-structured, immutable+fluent PHP library. No critical bugs or security issues. Several medium-severity issues relate to edge cases and incomplete animation implementations.

---

## Severity Summary

| Severity | Count |
|----------|-------|
| HIGH | 0 |
| MEDIUM | 3 |
| LOW | 8 |

---

## 1. MEDIUM: Duplicate Docblock on dimLine()
**Location:** src/Veil.php:539-561

Two PHP docblocks on dimLine() describing different implementations. Duplicate creates confusion.

Recommendation: Remove the first docblock, keep only the truecolor description.

---

## 2. MEDIUM: Fade Animation is Functionally a No-Op
**Location:** src/Animation/Fade.php:43-48

Fade::apply() returns foreground unchanged due to "terminal limitations". The opacity() method exists but is never called. Users expecting visual fade get nothing.

Recommendation: Document prominently that FADE is a visual placeholder, or implement alternative using SGR codes that terminals support.

---

## 3. MEDIUM: isClickOutside() Returns False for Unscanned State
**Location:** src/Veil.php:326-336

When lastRendered === null (no scan data), isClickOutside() returns false. This hides indeterminate state — outside clicks appear as inside clicks.

Recommendation: Throw exception or return sentinel when scan() hasn't been called.

---

## 4. LOW: RenderSession Accumulates State Forever
**Location:** src/RenderSession.php:23-26

previousFrame Buffer and previousOutput string persist for lifetime of RenderSession. In long-running apps, memory could grow.

Recommendation: Add maximum history limit or provide release() method.

---

## 5. LOW: Scanner State Persists Across Frames
**Location:** src/Veil.php:348-352

Scanner mutated in-place and passed to new Veil via mutate(). Old zone data persists in long-running apps.

Recommendation: Document that resetPreviousFrame() does not reset scanner state.

---

## 6. LOW: Deprecated Manager Parameter Serves No Function
**Location:** src/Veil.php:62-63, 284-298

$manager property stored but never used for hit-testing. Deprecated BC path.

Recommendation: Remove if BC not actually needed, or document preserved functionality.

---

## 7. LOW: VeilStack::compositeAll() Comment Describes Non-Behavior
**Location:** src/VeilStack.php:95-106

Documentation describes what is NOT happening rather than the actual use case.

---

## 8. LOW: isClickOutside() Returns False for Unscanned State
**Location:** src/Veil.php:326-336

Same as Finding 3 above.

---

## 9. LOW: isset() on String Offset Unusual
**Location:** src/Veil.php:573

Using isset($line[0]) to check string boundaries is valid but unusual.

Recommendation: Simplify to $line[0] === "\e" after confirming non-empty.

---

## Memory: No leaks — immutable value objects. RenderSession holds previousFrame intentionally for diff.

## Security: No security issues found. No injection vectors, no file/network access.

## PHP 8.3+: Fully compatible. readonly classes, promoted params used correctly.

## Async: Synchronous by design (TUI component). No async improvements needed.

---

## Positive Findings

1. Excellent immutability patterns — readonly classes with with*() methods
2. Comprehensive test coverage — 994+ lines with edge cases
3. No security issues
4. Proper diff algorithm using Buffer-based comparison
5. Well-documented known limitations (Fade no-op)

---

## Recommendations Priority

1. MEDIUM: Fade animation — document as placeholder or implement alternative feedback
2. MEDIUM: isClickOutside() — throw on unscanned state instead of silent false
3. LOW: RenderSession — add max history limit
4. LOW: Remove duplicate docblock on dimLine()