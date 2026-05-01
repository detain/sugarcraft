# CandyCore

PHP port of [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) — the Elm-architecture TUI runtime at the heart of the Charmbracelet stack.

Status: **Phase 0** — foundation utilities (ANSI, color, width, TTY) under
`CandyCore\Core\Util`. The runtime (`Program`, `Model`, `Cmd`, `Msg`) lands
once Phase 0 stabilizes. See [../CONVERSION.md](../CONVERSION.md).

## Requirements

- PHP 8.1+
- `mbstring` extension
- `intl` extension (for grapheme width)
- `pcntl` extension (signal handling — POSIX only)

## Install (during monorepo development)

From the repo root:

```sh
composer install
```

## Test

```sh
cd candy-core && composer install && vendor/bin/phpunit
```
