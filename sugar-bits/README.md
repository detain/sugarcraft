# SugarBits

PHP port of [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) —
pre-built TUI components for CandyCore.

## Status

Initial slice (round 1):

- `CandyCore\Bits\Key\Binding` + `Help` data class, `KeyMap` interface
- `CandyCore\Bits\Help\Help` — short and full-form help renderer
- `CandyCore\Bits\Spinner\Spinner` — animated spinner driven by `Cmd::tick`
- `CandyCore\Bits\Progress\Progress` — static progress bar

Coming up:

- `Timer`, `Stopwatch`, `Cursor`, `TextInput`, `TextArea`, `Viewport`,
  `Paginator`, `List`, `Table`, `FilePicker`

## Test

```sh
cd sugar-bits && composer install && vendor/bin/phpunit
```
