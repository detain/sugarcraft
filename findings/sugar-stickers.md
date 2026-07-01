# Sugar-Stickers Audit Findings

**Library:** sugar-stickers (PHP port of 76creates/stickers)  
**Audit Date:** 2026-06-30  

---

## Severity Summary

| Severity | Count |
|----------|-------|
| HIGH | 2 |
| MEDIUM | 2 |
| LOW | 4 |

---

## 1. HIGH: Column::sanitize() Destroys Valid UTF-8 Multibyte Characters
**Location:** src/Table/Column.php:112-128

The sanitize() method strips bytes 0x80-0x9F claiming they are C1 controls, but these bytes are valid UTF-8 continuation bytes. CJK characters like `東` = `\xe6\x9d\xb1` (bytes 0x9D, 0xB1) would be corrupted.

Recommendation: Remove `\x80-\x9F` from the regex pattern. C1 controls in UTF-8 are two-byte sequences starting with 0xC2.

---

## 2. HIGH: FlexBox::sanitize() Has Same Multibyte Destruction Bug
**Location:** src/Flex/FlexBox.php:291-303

Same issue. Line 302: `\x7F\x80-\x9F` corrupts CJK and other multibyte text.

Recommendation: Same fix — remove `\x80-\x9F` from the regex.

---

## 3. MEDIUM: Non-Existent Justify Enum Imported in Example
**Location:** examples/flexbox.php:13

`use SugarCraft\Stickers\Flex\Justify;` — Justify enum does not exist. Would cause fatal error at runtime.

Recommendation: Remove the unused import.

---

## 4. MEDIUM: Cursor Resets to 0 on Every rebuildView()
**Location:** src/Table/Table.php:274

Every sort/filter triggers rebuildView() which unconditionally resets cursorRow to 0. Users may find this unexpected.

Recommendation: Document this behavior or add a parameter to control it.

---

## 5. LOW: scrollLeft/scrollRight Default to 0 (No-Op)
**Location:** src/Viewport.php:352-359

With default 0, scrollLeft() with no args is a no-op. Other nav methods default to 1.

Recommendation: Change defaults from 0 to 1.

---

## 6. LOW: Repeated Array Allocation in FlexBox::renderRow/renderColumn
**Location:** src/Flex/FlexBox.php:114-120, 195-201

$measured array with closures created on every render. Adds GC pressure.

---

## 7. LOW: Large Per-Line Array in TableRenderer
**Location:** src/Table/TableRenderer.php:119-150

$strippedPosToStyle associative array created per line. ~5000 entries for 100×50 buffer.

---

## 8. LOW: Example Would Cause Fatal Error
**Location:** examples/flexbox.php:13

Same as Finding 3.

---

## Memory: No leaks detected.

## Security: Sanitization approach is sound but multibyte bug (Finding 1,2) corrupts text rather than protecting it.

## PHP 8.3+: Fully compatible.

---

## Recommendations Priority

1. FIX: Column::sanitize() and FlexBox::sanitize() multibyte corruption
2. FIX: examples/flexbox.php Justify import
3. CONSIDER: Document cursor reset behavior
4. CONSIDER: scrollLeft/scrollRight default 0→1