<img src=".assets/icon.png" alt="sugar-bits" width="160" align="right">

# SugarBits

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-bits)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-bits)
[![Packagist Version](https://img.shields.io/packagist/v/candycore/sugar-bits?label=packagist)](https://packagist.org/packages/candycore/sugar-bits)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/spinners.gif)

PHP port of [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) —
14 pre-built TUI components for CandyCore.

```sh
composer require candycore/sugar-bits
```

## Components

Upstream Bubbles ships 13 components; SugarBits ships those 13 plus
`AnimatedProgress` (the spring-physics variant lives in its own class
to keep the static `Progress` lean).

| Component | What it does | Notable msgs |
|---|---|---|
| `Cursor\Cursor` | Animated text cursor | `BlinkMsg` |
| `Help\Help` | Render short / full key-help footer from a `KeyMap`; `Help::updateWithBinding($msg, $toggle)` flips show-all in response to a key | — |
| `Key\Binding` | One key + label + help row; `Binding::new(...)`, `Binding::withDisabled(...)` factories | — |
| `Spinner\Spinner` | Animated loading glyph — 12 built-in styles | `Spinner\TickMsg` |
| `Progress\Progress` | Static progress bar (gradient fill optional, `withColors(...)` / `withColorFunc(...)`) | — |
| `Progress\AnimatedProgress` | Spring-physics-animated progress bar (HoneyBounce-driven) | `SpringTickMsg` |
| `Timer\Timer` | Countdown timer; `interval()`, `timeout()`, `withInterval(float)` | `Timer\TickMsg`, `TimeoutMsg` |
| `Stopwatch\Stopwatch` | Elapsed-time counter; `interval()`, `withInterval(float)` | `Stopwatch\TickMsg` |
| `TextInput\TextInput` | Single-line input with autocomplete + validators + `Styles` | — |
| `TextArea\TextArea` | Multi-line editor with line numbers / set-prompt-func / `focused()` / `cursor()` / `line()` / `column()` | — |
| `Viewport\Viewport` | Scrollable text region with mouse-wheel, scrollbar, horizontal scroll, `setWidth(int)` / `setHeight(int)` | — |
| `Paginator\Paginator` | Dot / arabic page indicator | — |
| `ItemList\ItemList` | Selectable / scrollable / filterable list with status messages | — |
| `Table\Table` | Selectable data table with `Column` struct + nav | — |
| `FilePicker\FilePicker` | Directory browser with icons / size / sort modes | — |

### Msg routing cheat-sheet

Forward these into your model's `update()` so the embedded component
can react: `BlinkMsg` (Cursor / TextInput), `Spinner\TickMsg`
(Spinner), `Timer\TickMsg` + `Timer\TimeoutMsg`, `Stopwatch\TickMsg`,
`SpringTickMsg` (AnimatedProgress), `StartStopMsg` (Timer / Stopwatch).
Each component's `update()` filters by its own `id()` so multiple
instances of the same component coexist on one event loop.

## Quickstart — TextInput with autocomplete

```php
use CandyCore\Bits\TextInput\TextInput;
use CandyCore\Core\{Cmd, Model, Msg, Program};
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\KeyType;

final class Search implements Model
{
    public function __construct(public readonly TextInput $ti) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Enter) {
            return [$this, Cmd::quit()];
        }
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Tab) {
            return [new self($this->ti->acceptSuggestion()), null];
        }
        [$ti, $cmd] = $this->ti->update($msg);
        return [new self($ti), $cmd];
    }

    public function view(): string
    {
        $body = $this->ti->view();
        if (($s = $this->ti->currentSuggestion()) !== null) {
            $body .= "\n  → $s";
        }
        return $body;
    }
}

[$ti, $cmd] = TextInput::new()
    ->withSuggestions(['apple', 'apricot', 'banana', 'cherry'])
    ->showSuggestions()
    ->withValidator(fn(string $v) => strlen($v) >= 2 ? null : 'too short')
    ->focus();

(new Program(new Search($ti)))->run();
```

## Quickstart — animated progress bar

```php
use CandyCore\Bits\Progress\AnimatedProgress;

$bar = AnimatedProgress::new()
    ->withWidth(40)
    ->withDefaultGradient();

[$bar, $cmd] = $bar->setPercent(0.75);
// dispatch $cmd via the Program — ticks re-fire from inside update()
// until the bar settles within 5e-4 of the target.
```

## Test

```sh
cd sugar-bits && composer install && vendor/bin/phpunit
```

## Demos

### Cursor

![cursor](.vhs/cursor.gif)

### File picker

![file-picker](.vhs/file-picker.gif)

### Help

![help](.vhs/help.gif)

### Item list

![item-list](.vhs/item-list.gif)

### Paginator

![paginator](.vhs/paginator.gif)

### Progress

![progress](.vhs/progress.gif)

### Spinners

![spinners](.vhs/spinners.gif)

### Stopwatch

![stopwatch](.vhs/stopwatch.gif)

### Table

![table](.vhs/table.gif)

### Text area

![text-area](.vhs/text-area.gif)

### Text input

![text-input](.vhs/text-input.gif)

### Timer

![timer](.vhs/timer.gif)

### Viewport

![viewport](.vhs/viewport.gif)

