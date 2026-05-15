# sugar-crumbs Research: Breadcrumb/Navigation Patterns

**Date:** 2026-05-13
**Library:** sugar-crumbs (PHP port of KevM/bubbleo)
**Upstream:** https://github.com/KevM/bubbleo

---

## 1. Current Implementation Analysis

### 1.1 Source Files

| File | Purpose |
|------|---------|
| `src/NavigationItem.php` | Value object: title + optional mixed data |
| `src/NavStack.php` | LIFO stack with push/pop/peek operations |
| `src/Breadcrumb.php` | Renders NavStack as separator-delimited string |
| `src/Shell.php` | Combines NavStack + Breadcrumb, immutable withPush/withPop |

**Source:** `/home/sites/sugarcraft/sugar-crumbs/src/`

### 1.2 Current Gaps (Critical)

The example file `examples/navigation.php` calls methods that **do not exist**:

```php
// Line 18-27: $nav->view() — NavStack has no view() method
$nav->view()

// Line 50: $shell->pushDirectory() — Shell has no pushDirectory() method
$shell->pushDirectory('/home/user/projects/sugarcraft/src')

// Line 45: $nav2->filter() — NavStack has no filter() method
$nav2->filter('dis')
```

This indicates either:
1. Stale examples from a different API design
2. Incomplete implementation

### 1.3 Existing Test Coverage

**Source:** `/home/sites/sugarcraft/sugar-crumbs/tests/NavStackTest.php`

Tests cover:
- Push/pop/peek operations
- Breadcrumb rendering with default/custom separator
- Max-width truncation
- Custom item renderer
- Shell withPush/withPop

**Missing tests:**
- Edge cases: max-width = 0, empty stack truncation
- Separator escaping for ANSI sequences
- Grapheme-aware width calculations

---

## 2. Upstream Comparison: KevM/bubbleo (Go)

### 2.1 Architecture

| Component | bubbleo (Go) | sugar-crumbs (PHP) |
|-----------|-------------|-------------------|
| NavigationItem | `Title string, Model tea.Model` | `title string, data mixed` |
| NavStack | `Model` (Tea-compatible) | `NavStack` class |
| Breadcrumb | Renders via lipgloss styles | Plain string output |
| Shell | Encapsulates NavStack + Breadcrumb + Window | Immutable Shell class |
| Closable interface | Yes (cleanup on pop) | No |

### 2.2 bubbleo NavStack Key Methods

```go
// Source: https://github.com/KevM/bubbleo/blob/main/navstack/model.go
func (m *Model) Push(item NavigationItem) tea.Cmd
func (m *Model) Pop() tea.Cmd
func (m Model) Top() *NavigationItem
func (m Model) StackSummary() []string  // Returns titles for breadcrumb
func (m *Model) Clear() error
```

**Notable:** bubbleo NavStack is Tea-compatible (implements `tea.Model` interface) and handles `tea.WindowSizeMsg` for proper rendering.

### 2.3 bubbleo Breadcrumb Styles

```go
// Source: https://github.com/KevM/bubbleo/blob/main/breadcrumb/model.go
type BreadcrumbStyles struct {
    Frame     lipgloss.Style
    Delimiter string
}

func DefaultStyles() BreadcrumbStyles {
    return BreadcrumbStyles{
        Frame:     styles.BreadCrumbFrameStyle,
        Delimiter: " > ",
    }
}
```

**Notable:** bubbleo breadcrumb uses lipgloss for styled frame rendering, not plain strings.

### 2.4 bubbleo Shell

```go
// Source: https://github.com/KevM/bubbleo/blob/main/shell/model.go
func New() Model {
    w := window.New(120, 30, 0, 0)
    ns := navstack.New(&w)
    bc := breadcrumb.New(&ns)
    return Model{Navstack: &ns, Breadcrumb: bc, window: &w}
}
```

**Notable:** bubbleo Shell creates a window model for proper sizing; sugar-crumbs Shell is purely logical.

---

## 3. Web Breadcrumb Patterns (W3C/WAI/APG)

### 3.1 Semantic Structure

**Source:** [WAI Breadcrumb Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/breadcrumb)

```html
<nav aria-label="Breadcrumbs">
  <ol>
    <li><a href="/">Home</a></li>
    <li aria-hidden="true">/</li>
    <li><a href="/galleries">Galleries</a></li>
    <li aria-hidden="true">/</li>
    <li aria-current="page">Current Page</li>
  </ol>
</nav>
```

**Key requirements:**
- `<nav>` with `aria-label="Breadcrumbs"`
- Ordered list (`<ol>`) for semantic structure
- Separators are `aria-hidden`
- Current page has `aria-current="page"` and is NOT a link
- All ancestor items ARE links

### 3.2 Separator Best Practices

**Source:** [W3C G65 Technique](https://www.w3.org/WAI/WCAG21/Techniques/general/G65)

| Separator | Unicode | Notes |
|-----------|---------|-------|
| `›` (U+203A) | Single right-pointing angle | Recommended by NN/g |
| `>` | ASCII | Common, less visual distinction |
| `/` | ASCII | Path-like, familiar |
| `→` (U+2192) | Right arrow | Accessible, directional |

### 3.3 Truncation Strategies

**Source:** [Smashing Magazine](https://www.smashingmagazine.com/2022/04/designing-better-breadcrumbs), [NN/g](https://www.nngroup.com/articles/breadcrumbs/)

1. **Overflow menu:** Show first 2 + last 2, collapse middle into "..."
2. **Horizontal scroll:** Fade edges, allow swipe (mobile)
3. **Left truncation:** Drop oldest items first (current sugar-crumbs approach)
4. **Responsive:** Desktop shows full, mobile shows last 1-2 levels

**Current sugar-crumbs uses left truncation** — oldest items dropped first with `…` prefix.

### 3.4 Accessibility Checklist

- [ ] `aria-label="Breadcrumbs"` on `<nav>`
- [ ] `aria-current="page"` on current location
- [ ] All ancestors are links
- [ ] Current location is NOT a link
- [ ] Separators are `aria-hidden`
- [ ] Keyboard navigable (Tab/Shift+Tab)
- [ ] Visible without scrolling

---

## 4. TUI Navigation Patterns (Charmbracelet Ecosystem)

### 4.1 navigator bubble (charmbracelet/bubbles)

**Source:** [charmbracelet/bubbles#591f8eb](https://github.com/charmbracelet/bubbles/commit/591f8eb5b9969d126e7ad52e779ca17a55be2cd0)

New addition to bubbles as of July 2025 — `navigator.Model` with push/pop lifecycle hooks:

```go
type Model struct {
    pages Stack  // []tea.Model
}

type splash struct {
    Index int
}

func (s splash) Init() tea.Cmd { /* ... */ }
func (s splash) OnEnter() tea.Cmd { /* ... */ }  // Called on push
func (s splash) OnLeaving() tea.Cmd { /* ... */ }  // Called on pop
```

**Key insight:** `Init()` runs once on creation; `OnEnter()`/`OnLeaving()` are lifecycle hooks for navigation events. sugar-crumbs has NO such lifecycle mechanism.

### 4.2 bubbleo vs sugar-crumbs Navigation Flow

```
bubbleo (Go):
  PushNavigation msg → NavStack.Push() → tea.Sequence(Pop Close, New Init, WindowSize)
  PopNavigation msg → NavStack.Pop() → Close() if Closable → tea.Quit if empty

sugar-crumbs (PHP):
  NavStack.push() → append to array (no lifecycle)
  NavStack.pop() → array_pop (no cleanup)
```

**Missing in sugar-crumbs:**
1. No `Closable` interface for cleanup on pop
2. No lifecycle hooks (OnEnter/OnLeaving)
3. No WindowSize handling
4. No Tea integration (not a `tea.Model` equivalent)

---

## 5. Path Representation Approaches

### 5.1 Current sugar-crumbs Approach

```php
// NavStack holds list<NavigationItem>
$stack->push('Home', '/');
$stack->push('Settings', '/settings');
$stack->push('Display', '/settings/display');

// Breadcrumb renders: "Home › Settings › Display"
```

**Pros:** Simple, title-only rendering
**Cons:** Path data is not queryable; no URL generation from stack

### 5.2 Alternative: Full URL Derivation

```php
// Each item stores a URL fragment
$stack->push('Home', '/');
$stack->push('Settings', '/settings');
$stack->push('Display', '/settings/display');

// Derive current path: /settings/display
$currentPath = implode('/', array_column($stack->items(), 'data'));

// Or build URL: /settings/display?ref=breadcrumb
```

### 5.3 Web-idiomatic: Path-based vs Location-based

**Source:** [Carbon Design System](https://v10.carbondesignsystem.com/components/breadcrumb/usage)

| Type | Description | Use Case |
|------|-------------|----------|
| **Location-based** | Reflects site hierarchy | Most apps |
| **Path-based** | Shows steps user actually took | Wizard/multi-step flows |

Current sugar-crumbs is **location-based** (hierarchy), not path-based (actual traversal).

---

## 6. Separator Handling

### 6.1 Current Implementation

```php
// Breadcrumb.php:22
private string $separator  = ' › ';  // U+2039 single right-pointing angle
private string $truncator  = '… ';   // U+2026 ellipsis
```

**Assessment:** Default separator is accessible and visually clear. However:

1. **No escaping:** If title contains `›` or `…`, no escaping is done
2. **No ANSI-awareness:** Separators applied raw; ANSI sequences in titles could break width calculation
3. **No per-item separator override**

### 6.2 Improvement: Separator Customization Options

```php
// Suggested API additions
$bc->setSeparator(' / ');           // ASCII-friendly
$bc->setSeparator(' › ');           // Current default
$bc->setSeparator(' → ');           // Arrow Unicode
$bc->setTruncator('…');             // Without trailing space
$bc->setSeparatorRenderer(fn($index, $total) => $index < $total - 1 ? '|' : '');  // Custom
```

---

## 7. Click Navigation Support

### 7.1 Current State

Current `Breadcrumb::render()` returns a **plain string** — no click targets:

```php
// Returns: "Home › Settings › Display"
// No URL/click information encoded
```

### 7.2 Web Pattern: Links + aria-current

```php
// Desired output for web rendering:
$html = '<nav aria-label="Breadcrumbs"><ol>';
foreach ($items as $i => $item) {
    if ($i > 0) $html .= '<li aria-hidden="true">›</li>';
    if ($i === count($items) - 1) {
        $html .= '<li aria-current="page">' . htmlspecialchars($item->title) . '</li>';
    } else {
        $html .= '<li><a href="' . htmlspecialchars($item->data ?? '#') . '">' . htmlspecialchars($item->title) . '</a></li>';
    }
}
$html .= '</ol></nav>';
```

### 7.3 TUI Pattern: Click Regions

For TUI rendering, breadcrumb needs **click region support**:

```php
// Conceptual TUI click handling
$regions = $breadcrumb->renderWithClickRegions($stack);
// Returns: [
//   ['title' => 'Home', 'start' => 0, 'end' => 4],
//   ['title' => 'Settings', 'start' => 6, 'end' => 14],
//   ...
// ]
// User clicks position 7 → index 1 (Settings) is the target
```

**Current gap:** sugar-crumbs has no click region/URL mapping.

---

## 8. Prioritized Recommendations

### Priority 1: Fix Broken Examples (Low Effort, High Impact)

The `examples/navigation.php` uses non-existent methods. Either:
1. Remove the example and keep only `basic.php`
2. Implement the missing methods (`pushDirectory`, `view`, `filter`)

**Recommendation:** Option 2 — implement a path-based extension.

### Priority 2: Add Closable Interface + Lifecycle Hooks (Medium Effort)

```php
interface Navigable {
    public function onEnter(): void;
    public function onLeave(): void;
}

interface Closable {
    public function close(): void;
}
```

This matches bubbleo's pattern and enables cleanup on pop.

### Priority 3: Add URL/Path Derivation (Medium Effort)

```php
// On NavStack
public function currentPath(): string  // "/settings/display"
public function buildUrl(array $query = []): string  // "/settings/display?ref=breadcrumb"
public function parentAt(int $depth): ?NavigationItem  // Navigate to ancestor
```

### Priority 4: Add Click Region Rendering (Medium Effort)

```php
// On Breadcrumb
public function renderWithClickRegions(NavStack $stack): array
// Returns region coordinates for TUI click detection

public function renderHtml(NavStack $stack): string
// Returns accessible HTML for web contexts
```

### Priority 5: Add Separator Escaping (Low Effort)

```php
// Prevent separator characters in titles from breaking rendering
private function escapeTitle(string $title): string {
    return str_replace($this->separator, ' ', $title);
}
```

### Priority 6: Add aria-current + Semantic HTML Rendering (Low Effort)

```php
public function renderAria(NavStack $stack): array
// Returns structured array with aria-current flags for custom rendering
```

---

## 9. Effort Estimates

| Improvement | Effort | Impact | Risk |
|-------------|--------|--------|------|
| Fix broken examples | 1hr | High | Low |
| Add Closable interface | 2hr | Medium | Low |
| Add lifecycle hooks (onEnter/onLeave) | 3hr | Medium | Medium |
| URL/path derivation | 3hr | High | Low |
| Click region rendering | 4hr | Medium | Medium |
| Separator escaping | 1hr | Low | Low |
| aria-current support | 2hr | Medium | Low |
| ANSI-aware width (separators in titles) | 2hr | Low | Medium |

**Total estimated:** 18 hours across all improvements

---

## 10. Implementation Plan

### Phase 1: Stabilization (2-3 hours)
- [ ] Fix or remove `examples/navigation.php`
- [ ] Add `view()` method to NavStack (returns formatted stack state)
- [ ] Add `filter()` method to NavStack (filter items by title substring)
- [ ] Add `pushDirectory()` to Shell (parse path segments into stack)

### Phase 2: Feature Parity with bubbleo (5-6 hours)
- [ ] Add `Closable` interface
- [ ] Add `Navigable` interface with `onEnter()`/`onLeave()`
- [ ] Update NavStack to call hooks on push/pop
- [ ] Add `currentPath()` derivation

### Phase 3: Rendering Extensions (4-5 hours)
- [ ] Add `renderWithClickRegions()`
- [ ] Add `renderHtml()` for web contexts
- [ ] Add separator escaping
- [ ] Add aria-current structured output

### Phase 4: Polish (2-3 hours)
- [ ] Add missing test cases for edge conditions
- [ ] Document separator customization
- [ ] Add `SeparatorRenderer` callback support

---

## 11. Key Sources

- [KevM/bubbleo GitHub](https://github.com/KevM/bubbleo)
- [WAI ARIA Breadcrumb Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/breadcrumb)
- [W3C G65 Breadcrumb Technique](https://www.w3.org/WAI/WCAG21/Techniques/general/G65)
- [Smashing Magazine: Designing Effective Breadcrumbs](https://www.smashingmagazine.com/2022/04/designing-better-breadcrumbs)
- [NN/g Breadcrumbs UX Guidelines](https://www.nngroup.com/articles/breadcrumbs/)
- [Carbon Design System: Breadcrumb](https://v10.carbondesignsystem.com/components/breadcrumb/usage)
- [Open UI Breadcrumb Component](https://open-ui.org/components/breadcrumb)
- [charmbracelet/bubbles navigator](https://github.com/charmbracelet/bubbles/commit/591f8eb5b9969d126e7ad52e779ca17a55be2cd0)
