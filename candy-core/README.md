<img src=".assets/icon.png" alt="candy-core" width="160" align="right">

# CandyCore

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-core)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-core)
[![Packagist Version](https://img.shields.io/packagist/v/candycore/candy-core?label=packagist)](https://packagist.org/packages/candycore/candy-core)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/counter.gif)

PHP port of [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) —
the Elm-architecture TUI runtime at the heart of the Charmbracelet stack.

```php
use CandyCore\Core\{Cmd, KeyType, Model, Msg, Program};
use CandyCore\Core\Msg\{KeyMsg, WindowSizeMsg};

final class Counter implements Model
{
    public function __construct(public readonly int $count = 0) {}
    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            return match (true) {
                $msg->type === KeyType::Char && $msg->rune === 'q' => [$this, Cmd::quit()],
                $msg->type === KeyType::Up    => [new self($this->count + 1), null],
                $msg->type === KeyType::Down  => [new self($this->count - 1), null],
                default => [$this, null],
            };
        }
        return [$this, null];
    }

    public function view(): string { return "count: $this->count\n(↑/↓ to change, q to quit)"; }
}

(new Program(new Counter()))->run();
```

## Requirements

- PHP 8.1+
- `mbstring`, `intl` (for grapheme width)
- `pcntl` (signal handling — POSIX only)
- `react/event-loop` ^1.6 (Composer)

## Architecture

- **`Model`** — your app implements `init()`, `update(Msg)`, `view()`.
- **`Msg`** — marker interface for events. Built-ins: `KeyMsg`, `WindowSizeMsg`, `QuitMsg`.
- **`Cmd`** — `Closure(): ?Msg`. Async work whose result is dispatched as a Msg. Helpers in `Cmd::quit()`, `Cmd::batch()`, `Cmd::send()`.
- **`Program`** — orchestrator. Sets up TTY, runs the ReactPHP event loop, dispatches Msgs, drives renders at the configured framerate.
- **`InputReader`** — stateful byte-stream parser; handles split escape sequences across reads.
- **`Renderer`** — minimal cursor-home + erase + write. Diff-based renderer is a follow-up.
- **`Util/`** — `Ansi`, `Color`, `ColorProfile`, `Width`, `Tty` foundation utilities, shared with CandySprinkles.

## Demos

### Counter Model

![counter](.vhs/counter.gif)

### Timer

![timer](.vhs/timer.gif)


## Status

- **Phase 0** (foundation utilities): 🟢 complete.
- **Phase 3** (runtime): 🟢 v1 — Program loop, mouse (cell-motion + all-motion + SGR 1006), focus / blur, bracketed paste, full function-key set including F13–F63 and the Kitty PUA range, the cell-diff "cursed" renderer (synchronized output 2026 + unicode mode 2027), inline-mode rendering, declarative `View` struct, plus the v2 Cmd surface (`Suspend` / `Interrupt` / `Resume` / `Exec` / `Sequence` / `Every` / `Printf` / `Raw` / `wait` / `kill` / `releaseTerminal` / `restoreTerminal`).

See [../CONVERSION.md](../CONVERSION.md) for the full roadmap and the
[v2 parity sweep](../CONVERSION.md#phase-11--v2-parity-sweep-bubble-tea--lipgloss--bubbles)
table tracking each Bubble Tea v2 / Lipgloss v2 / Bubbles v2
feature.

## Companion libraries

CandyCore is the foundation — the rest of the SugarCraft stack
builds on it. From the same monorepo:

- **CandySprinkles** (← lipgloss) — declarative styling + layout.
- **SugarBits** (← bubbles) — 14 prebuilt components.
- **SugarPrompt** (← huh) — multi-page form library.
- **SugarCharts** (← ntcharts) — sparkline / bar / line / heatmap / OHLC.
- **CandyShell** (← gum) — composer-installable CLI of 13 subcommands.
- **CandyShine** (← glamour) — Markdown → ANSI renderer.
- **CandyZone** (← bubblezone) — mouse-zone tracker.
- **HoneyBounce** (← harmonica) — spring physics + Newtonian projectile sim.
- **CandyKit** (← fang) — opinionated CLI presentation helpers.
- **CandyFreeze** (← freeze) — code → SVG screenshot.
- **CandyWish** (← wish) — SSH server middleware framework.
- **SugarSpark** (← sequin) — ANSI escape-sequence inspector.

See the matchup table in [../MATCHUPS.md](../MATCHUPS.md) for status,
package names, and namespace mappings.

## Test

```sh
cd candy-core && composer install && vendor/bin/phpunit
```
