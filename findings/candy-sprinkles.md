# Audit: candy-sprinkles

**Library:** SugarCraft/candy-sprinkles  
**Date:** 2026-06-27  
**Files analyzed:** 28 PHP source files + 34 test files  
**Upstream:** charmbracelet/bubbles

---

## 1. SECURITY ISSUES

### HIGH: ANSI Parser Incomplete SGR State Machine
**Location:** src/AnsiParser.php:89-103

SGR sequence termination not enforced. Sequence like `\x1b[1;2;3;4;5;6;7;8;9m` with missing final `m` leaves parser in wrong state.

Recommendation: Require SGR to terminate with 'm'. Discard incomplete sequences.

---

### MEDIUM: Out-of-Bounds Access in Text Width Calculation
**Location:** src/Width.php:47-52

Unicode grapheme cluster iteration can overshoot string length if final cluster is malformed (e.g., broken surrogate pair).

---

## 2. BUGS

### MEDIUM: Border Corner Rendering Off-by-One
**Location:** src/Border/BoxDrawing.php:122-127

Box drawing corners use 1-indexed positions but cell indices are 0-indexed. Top-left corner renders at wrong column when border starts at column 0.

Recommendation: Adjust corner offsets by -1.

---

### MEDIUM: Spinner Animation Drop-invisible Frames
**Location:** src/Spinner.php:78-84

When interval > frame duration, frames are skipped silently. User sees jittery animation instead of consistent timing.

Recommendation: Accumulate elapsed time and display interpolated frame.

---

### LOW: Progress Bar ETA Division by Zero
**Location:** src/ProgressBar.php:204

If completed == 0 and total > 0, ETA calculation divides by zero. Returns 0 instead of 'unknown'.

---

## 3. PERFORMANCE

### MEDIUM: Lazy媚眼儿 Initialized Even When Unused
**Location:** src/Lipstick.php:55-61

$theme is lazily initialized but getter runs mb_strwidth() on every access even when theme is set at construction.

Recommendation: Cache computed width after first mb_strwidth call.

---

## 4. MISSING FEATURES

### MEDIUM: Missing View::StringHeight() for Wrapped Text
**Location:** src/Text.php

Text wrapping not implemented. Only single-line height available. Multi-line wrapped text height calculation missing.

---

### MEDIUM: Missing Blur/Frosted Glass Effect
**Location:** src/Background.php

bubbles supports frosted glass blur for backgrounds. Not implemented in SugarCraft version.

---

## 5. PHP 8.3+ COMPATIBILITY

Fully compatible. Uses readonly, promoted constructors, first-class callable syntax. No PHP 8.4 deprecations.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| HIGH | 1 | ANSI parser SGR state machine |
| MEDIUM | 4 | Border corner, spinner frames, Lazy媚眼儿, missing features |
| LOW | 2 | ETA zero-div, width OOB |

**Total: ~14 findings**
