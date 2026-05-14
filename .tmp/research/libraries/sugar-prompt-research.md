# sugar-prompt Research: Terminal Form/Input Libraries Comparison

**Date:** 2026-05-13
**Status:** Research Complete
**Sources:** charmbracelet/huh (Go), inquire (Rust), prompt-toolkit (Python), enquirer/inquirer.js (Node), tty-prompt (Ruby), ratatui-form (Rust)

---

## Executive Summary

 sugar-prompt has a solid foundation mirroring charmbracelet/huh. Several patterns from other ecosystems can meaningfully improve UX: **fuzzy autocomplete**, **inline validation helpers**, **debounced async suggestions**, **multi-select vim-mode navigation**, and **cross-field validation**. Most improvements are medium-effort (1-3 days) and can be implemented incrementally.

---

## 1. Existing Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-prompt/src/`

### Current Strengths
- Immutable field design with `with*()` fluent API
- `HasHideFunc` and `HasDynamicLabels` traits for conditional visibility
- Theme system with 7 presets (ANSI, Charm, Dracula, Catppuccin, Base16, Base, Plain)
- `KeyMap` customization for navigation bindings
- Form-level error summary (`withErrorSummary()`)
- Group-based multi-page forms
- Accessibility mode (`withAccessible()`)

### Current Gaps
- No fuzzy/autocomplete matching (only exact prefix filter)
- No async/debounced suggestion providers
- No built-in validators (only custom closures)
- MultiSelect lacks vim keybindings (j/k/g/G)
- No inline error styling via Theme
- Confirm field has limited label customization
- No cross-field validation support

---

## 2. Cross-Language Comparison

### 2.1 Input Validation

| Library | Approach | Key Pattern |
|---------|----------|-------------|
| **huh (Go)** | `Validate(func(string) error)` — returns error on invalid | `Validate(func(s string) error { if len(s) < 3 { return fmt.Errorf("...") } return nil })` |
| **inquire (Rust)** | Validator trait + built-in macros | `with_validator(required!())`, `min_length!(3, "msg")` |
| **prompt-toolkit (Python)** | `Validator.from_callable()` with error message | `Validator.from_callable(fn, error_message="...", move_cursor_to_end=True)` |
| **inquirer.js (Node)** | `validate` function returning bool/string | `validate: (value) => /regex/.test(value) \|\| 'error message'` |
| **tty-prompt (Ruby)** | `:validate` option + custom messages hash | `q.validate(/\A\w+@\w+\.\w+\Z/, "Invalid email")` |
| **textual-wtf (Python)** | Validator class hierarchy with `ValidationError` | Cross-field via `clean_form()` override |

**Recommendation:** Add built-in validator classes (>Required, >Email, >MinLength, >MaxLength, >Pattern) mirroring ratatui-form's approach. Also support cross-field validation via a Form-level `validateAll(array<string,mixed> $values): ?string` callback.

```php
// Proposed sugar-prompt validator interface
interface Validator {
    public function validate(mixed $value): ?string; // null = valid, string = error
}

final class Required implements Validator {
    public function validate(mixed $value): ?string {
        return $value === null || $value === '' ? 'This field is required' : null;
    }
}

final class MinLength implements Validator {
    public function __construct(private int $min, private string $message = '') {}
    public function validate(mixed $value): ?string {
        $len = is_string($value) ? strlen($value) : 0;
        return $len >= $this->min ? null : ($this->message ?: "Must be at least {$this->min} characters");
    }
}

final class Pattern implements Validator {
    public function __construct(private string $regex, private string $message = '') {}
    public function validate(mixed $value): ?string {
        return is_string($value) && preg_match($this->regex, $value) === 1 ? null
            : ($this->message ?: 'Invalid format');
    }
}

// Usage would become:
Input::new('email')
    ->title('Email Address')
    ->validator(new Required())
    ->validator(new Pattern('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', 'Invalid email'))
```

**Source:** ratatui-form validators pattern at https://docs.rs/crate/ratatui-form/latest

### 2.2 Select/MultiSelect UX

| Library | Filtering | Vim Keys | Enum Select | Pre-selection |
|---------|-----------|----------|-------------|---------------|
| **huh** | `.Filterable(true)` | No | No | `.Selected(true)` |
| **inquire** | Built-in filter | Yes (k/j) | No | `.with_default(&[0, 2])` |
| **tty-prompt (Ruby)** | `:filter => true` | No | `.enum ")"` | `menu.default 2` |
| **dialoguer (Rust)** | FuzzySelect variant | Optional | No | `.item_checked(item, true)` |
| **inquirer.js** | `search` prompt type | No | No | `checked: true` |
| **textual-wtf** | ChoiceField | No | No | `required=True` |

**Key Finding:** inquire (Rust) has the best MultiSelect UX: vim keybindings (j/k/g/G), left/right to select-all/unselect-all, `keep_filter` option, and custom formatters.

**Source:** https://context7.com/mikaelmello/inquire/llms.txt

**Recommendation:** Add vim-mode navigation to MultiSelect and optionally Select:

```php
// Add to MultiSelect update():
case $msg->type === KeyType::Char && $msg->rune === 'j'
    => [$this->moveCursor($this->cursor + 1), null],
case $msg->type === KeyType::Char && $msg->rune === 'k'
    => [$this->moveCursor($this->cursor - 1), null],
case $msg->type === KeyType::Char && $msg->rune === 'g'
    => [$this->moveCursor(0), null],
case $msg->type === KeyType::Char && $msg->rune === 'G'
    => [$this->moveCursor(count($this->options) - 1), null],
// Left arrow: unselect all, Right arrow: select all
case $msg->type === KeyType::Left
    => [$this->mutate(selected: []), null],
case $msg->type === KeyType::Right
    => [$this->selectAll(), null],
```

### 2.3 Fuzzy Autocomplete

| Library | Fuzzy Matching | Async/ Debounced | Nested |
|---------|----------------|------------------|--------|
| **prompt-toolkit** | `FuzzyWordCompleter` | Yes (custom source) | `NestedCompleter` |
| **dialoguer** | `fuzzy-select` feature | No | No |
| **inquirer.js** | No (search prompt) | Yes (AbortSignal) | No |
| **tty-prompt** | No | No | No |
| **huh** | No | No | No |

**Key Finding:** prompt-toolkit's `FuzzyWordCompleter` provides substring matching ("djm" → "django_migrations"). inquirer.js's `search` prompt provides live API-backed suggestions with `AbortSignal` for cancellation.

**Source:** https://context7.com/prompt-toolkit/python-prompt-toolkit/llms.txt

**Recommendation:** Add `withFuzzySuggestions(array $candidates)` and `withSuggestionsFunc(\Closure $input): array` with debounce support. Implement a `FuzzyMatcher` utility using PHP's built-in `levenshtein` or a custom scoring algorithm.

```php
// Proposed for Input field:
public function withFuzzySuggestions(array $candidates): self {
    $fuzzy = fn(string $input): list<string> => $this->fuzzyFilter($input, $candidates);
    return $this->withSuggestionsFunc($fuzzy);
}

private function fuzzyFilter(string $input, array $candidates): list<string> {
    if ($input === '') return [];
    $input = strtolower($input);
    $scored = [];
    foreach ($candidates as $candidate) {
        $lower = strtolower($candidate);
        // Substring match score
        $pos = strpos($lower, $input);
        if ($pos !== false) {
            $scored[] = [$pos, strlen($candidate), $candidate];
        }
    }
    usort($scored, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return array_map(fn($s) => $s[2], array_slice($scored, 0, 10));
}
```

For async/debounced suggestions (like inquirer.js search prompt), add:

```php
public function withAsyncSuggestions(
    \Closure $sourceFn, // fn(string $input, \Closure $cancel): array
    int $debounceMs = 300
): self {
    // Store source function + debounce config
    // In update(), schedule debounced calls
    // Pass AbortSignal-style cancellation token
}
```

### 2.4 Error Display

| Library | Inline Error | Error Summary | Styled |
|---------|--------------|---------------|--------|
| **huh** | `! error` line | `WithErrorSummary()` | Yes (theme) |
| **sugar-prompt** | `! error` line | `withErrorSummary()` | Partial |
| **textual-wtf** | Below input | Tab red indicator | CSS classes |
| **prompt-toolkit** | `move_cursor_to_end` option | No | Via validator |
| **tty-prompt** | Custom messages hash | No | No |

**Key Finding:** huh uses a dedicated `Theme::$error` style, and textual-wtf applies CSS classes (`-invalid`) for styling.

**Recommendation:** Add a `Theme::$error` specifically for inline errors (already exists) but also add `Theme::$errorSummary` for the error summary block. Currently only `Theme::$error` is used for both inline and summary.

```php
// In Theme, split error styling:
public readonly Style $error,          // inline error below field
public readonly Style $errorSummary,   // error summary block header
public readonly Style $errorSummaryItem; // individual error in summary

// Form::renderErrorSummary() would use $errorSummaryItem:
private function renderErrorSummary(Theme $theme): string {
    $lines = [];
    $lines[] = $theme->errorSummary->render('Error Summary:');
    foreach ($this->errors() as $key => $error) {
        $field = $this->findFieldByKey($key);
        $title = $field !== null ? $field->getTitle() : $key;
        $lines[] = $theme->errorSummaryItem->render("• {$title}: {$error}");
    }
    return implode("\n", $lines);
}
```

### 2.5 Keyboard Navigation

| Library | Next/Prev | Submit | Abort | Custom |
|---------|-----------|--------|-------|--------|
| **huh** | Tab/Shift+Tab, ↑↓ | Enter | Esc, Ctrl+C | No |
| **sugar-prompt** | Same | Enter | Esc, Ctrl+C | `withKeyMap()` |
| **inquire** | ↑↓/jk, Tab | Enter | Esc | Limited |
| **tty-prompt** | ↑↓, Enter | Enter | Esc | No |
| **inquirer.js** | ↑↓, Tab | Enter | Esc | No |

**Key Finding:** sugar-prompt's `KeyMap` is already more flexible than most. Consider adding commonly-requested bindings: `j`/`k` for vim-style navigation.

```php
// Enhanced default KeyMap with vim hints:
public static function default(): self {
    return new self(
        next: [
            ['type' => KeyType::Tab],
            ['type' => KeyType::Down],
            ['type' => KeyType::Char, 'rune' => 'j'],   // vim down
        ],
        prev: [
            ['type' => KeyType::Tab, 'alt' => true],
            ['type' => KeyType::Up],
            ['type' => KeyType::Char, 'rune' => 'k'],   // vim up
        ],
        submit: [['type' => KeyType::Enter]],
        abort: [
            ['type' => KeyType::Escape],
            ['type' => KeyType::Char, 'rune' => 'c', 'ctrl' => true],
        ],
    );
}
```

---

## 3. Specific Improvements with Code Examples

### 3.1 Built-in Validators

**Source:** Inspired by ratatui-form and inquire validator macros

```php
// src/Validator/Validator.php
namespace SugarCraft\Prompt\Validator;

interface Validator {
    /** Returns null if valid, error message string if invalid */
    public function validate(mixed $value): ?string;
}

// src/Validator/Required.php
final class Required implements Validator {
    public function validate(mixed $value): ?string {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return 'This field is required';
        }
        return null;
    }
}

// src/Validator/MinLength.php
final class MinLength implements Validator {
    public function __construct(
        private int $min,
        private string $message = ''
    ) {}

    public function validate(mixed $value): ?string {
        $len = is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);
        if ($len >= $this->min) {
            return null;
        }
        return $this->message ?: "Must be at least {$this->min} characters";
    }
}

// src/Validator/MaxLength.php
final class MaxLength implements Validator {
    public function __construct(
        private int $max,
        private string $message = ''
    ) {}

    public function validate(mixed $value): ?string {
        $len = is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);
        if ($len <= $this->max) {
            return null;
        }
        return $this->message ?: "Must be at most {$this->max} characters";
    }
}

// src/Validator/Pattern.php
final class Pattern implements Validator {
    public function __construct(
        private string $regex,
        private string $message = ''
    ) {}

    public function validate(mixed $value): ?string {
        if (!is_string($value)) {
            return $this->message ?: 'Invalid type';
        }
        if (preg_match($this->regex, $value) === 1) {
            return null;
        }
        return $this->message ?: 'Invalid format';
    }
}

// src/Validator/Email.php
final class Email implements Validator {
    public function validate(mixed $value): ?string {
        if (!is_string($value)) {
            return 'Invalid email';
        }
        return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value) === 1
            ? null : 'Invalid email address';
    }
}
```

**Usage:**
```php
Input::new('email')
    ->title('Email')
    ->validator(new Required())
    ->validator(new Email())
    ->validator(new MaxLength(255));
```

### 3.2 Fuzzy Autocomplete for Input

**Source:** prompt-toolkit's FuzzyWordCompleter pattern

```php
// src/Util/FuzzyMatcher.php
namespace SugarCraft\Prompt\Util;

final class FuzzyMatcher {
    /**
     * Filter candidates by fuzzy substring match.
     * Returns sorted by match position, then by string length.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    public static function filter(string $input, array $candidates, int $limit = 10): array {
        if ($input === '') {
            return [];
        }
        $input = strtolower($input);
        $scored = [];

        foreach ($candidates as $candidate) {
            $lower = strtolower($candidate);
            $pos = strpos($lower, $input);
            if ($pos !== false) {
                // Score: first by position, then by length (shorter = better)
                $scored[] = [$pos, strlen($candidate), $candidate];
            }
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(fn($s) => $s[2], array_slice($scored, 0, $limit));
    }
}
```

**Usage in Input:**
```php
Input::new('city')
    ->title('City')
    ->withFuzzySuggestions(['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'San Jose']);
// User types "da" → suggests Dallas
```

### 3.3 Async Debounced Suggestions

**Source:** inquirer.js search prompt with AbortSignal

```php
// In Input field, add:
private ?\Closure $asyncSourceFn = null;
private float $lastKeypress = 0;
private int $debounceMs = 300;

// New method:
public function withAsyncSuggestionsFunc(
    \Closure $sourceFn, // fn(string $input): \Generator<mixed, array, mixed>
    int $debounceMs = 300
): self {
    return new self(
        $this->key,
        $this->input,
        $this->title,
        $this->description,
        $this->error,
        $this->validator,
        null, // static suggestions disabled when async
        $sourceFn,
        $debounceMs,
    );
}

// In update(), handle debouncing:
public function update(Msg $msg): array {
    // ... existing handling ...

    if ($this->asyncSourceFn !== null && $msg instanceof KeyMsg) {
        $now = microtime(true);
        if ($now - $this->lastKeypress > $this->debounceMs / 1000) {
            $candidates = ($this->asyncSourceFn)($this->input->value);
            $this->input = $this->input
                ->withSuggestions($candidates)
                ->showSuggestions($candidates !== []);
        }
        $this->lastKeypress = $now;
    }

    return [$next, $cmd];
}
```

### 3.4 MultiSelect with Vim Keys

**Source:** inquire (Rust) key bindings

```php
// Add to MultiSelect::update():
case $msg->type === KeyType::Char && $msg->rune === 'j'
    => [$this->moveCursor($this->cursor + 1), null],
case $msg->type === KeyType::Char && $msg->rune === 'k'
    => [$this->moveCursor($this->cursor - 1), null],
case $msg->type === KeyType::Char && $msg->rune === 'g'
    => [$this->moveCursor(0), null],
case $msg->type === KeyType::Char && $msg->rune === 'G'
    => [$this->moveCursor(count($this->options) - 1), null],
case $msg->type === KeyType::Left
    => [$this->mutate(selected: []), null],           // clear all
case $msg->type === KeyType::Right
    => [$this->selectAll(), null],                    // select all
```

### 3.5 Form-Level Cross-Field Validation

**Source:** textual-wtf's `clean_form()` pattern

```php
// Add to Form:
private ?\Closure $formValidator = null;

public function withFormValidator(\Closure $fn): self {
    // $fn receives array<string, mixed> $values, returns ?string error message
    return $this->mutate(formValidator: $fn, formValidatorSet: true);
}

// In update(), after all fields validate:
private function validateAll(): array {
    $errors = $this->errors();
    if ($errors !== [] || $this->formValidator === null) {
        return $errors;
    }
    $formError = ($this->formValidator)($this->values());
    if ($formError !== null) {
        // Need to associate with a field or show as general error
        return ['__form' => $formError];
    }
    return [];
}

// Usage:
Form::groups($group)
    ->withFormValidator(fn($v) => $v['confirm'] !== $v['password']
        ? 'Passwords do not match' : null);
```

### 3.6 Select with Enum/Index Mode

**Source:** tty-prompt Ruby enum_select

```php
// Add to Select field:
private bool $showIndex = false;
private string $indexDelimiter = ')';

public function withShowIndex(bool $show = true, string $delimiter = ')'): self {
    return $this->mutate(showIndex: $show, indexDelimiter: $delimiter);
}

// In view(), render index:
public function view(): string {
    // ...
    foreach ($this->list->items() as $i => $item) {
        $prefix = $this->showIndex ? ($i + 1) . $this->indexDelimiter . ' ' : '';
        $line = $prefix . $marker . ' ' . $box . ' ' . $opt;
    }
}

// Allow typing number to select:
public function update(Msg $msg): array {
    if ($this->showIndex && $msg instanceof KeyMsg && $msg->type === KeyType::Char) {
        $num = (int) $msg->rune;
        if ($num >= 1 && $num <= count($this->list->items())) {
            return [$this->mutate(list: $this->list->select($num - 1)), null];
        }
    }
    // ... existing handling
}
```

---

## 4. Prioritized Recommendations

### Priority 1: Quick Wins (1 day each)

| Improvement | Impact | Effort | Notes |
|-------------|--------|--------|-------|
| **Add vim keys to MultiSelect** | High | 1 day | j/k/g/G navigation, left/right select-all |
| **Add built-in validators** | High | 1 day | Required, MinLength, MaxLength, Pattern, Email |
| **Enhance KeyMap defaults** | Medium | 0.5 day | Add j/k vim bindings to form navigation |

### Priority 2: Medium Effort (2-3 days each)

| Improvement | Impact | Effort | Notes |
|-------------|--------|--------|-------|
| **Fuzzy autocomplete** | High | 2 days | FuzzyMatcher utility + withFuzzySuggestions() |
| **Async/debounced suggestions** | Medium | 2 days | WithAsyncSuggestionsFunc with debounce |
| **Split Theme error styling** | Low | 1 day | Add errorSummary/errorSummaryItem styles |
| **Select enum/index mode** | Medium | 2 days | Number keys to select directly |

### Priority 3: Larger Features (3-5 days each)

| Improvement | Impact | Effort | Notes |
|-------------|--------|--------|-------|
| **Cross-field validation** | High | 3 days | Form-level validator callback |
| **Search prompt type** | Medium | 4 days | Dedicated async search field |
| **Nested autocomplete** | Low | 3 days | For CLI-like interfaces |

---

## 5. Effort Estimates Summary

| Category | Items | Total Effort |
|----------|-------|--------------|
| Quick Wins | 3 | ~2.5 days |
| Medium | 4 | ~10 days |
| Larger | 3 | ~12 days |
| **Total** | **10** | **~24 days** |

---

## 6. References

- **charmbracelet/huh:** https://github.com/charmbracelet/huh
- **inquire (Rust):** https://github.com/mikaelmello/inquire
- **prompt-toolkit:** https://github.com/prompt-toolkit/python-prompt-toolkit
- **inquirer.js:** https://github.com/sboudrias/Inquirer.js
- **tty-prompt:** https://github.com/piotrmurach/tty-prompt
- **ratatui-form:** https://crates.io/crates/ratatui-form
- **dialoguer:** https://github.com/console-rs/dialoguer
- **textual-wtf:** https://github.com/holdenweb/textual-wtf
