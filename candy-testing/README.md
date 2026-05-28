# candy-testing

Test harness for TEA (The Elm Architecture) programs — pioneering what [bubble-tea issue #1654](https://github.com/charmbracelet/bubbletea/issues/1654) never shipped.

> **TEA background:** The Elm Architecture (Model / Update / View) is the foundation of [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea). Testing TEA programs deterministically has been a long-standing gap — `candy-testing` closes it for the PHP ecosystem.

## Overview

`candy-testing` provides the infrastructure SugarCraft pioneers for deterministic TEA program testing:

- **`ProgramSimulator`** — drives a `Program` with scripted input, captures model/view/cmds
- **`ScriptedInput`** — fluent builder for message sequences (`->key('q')->enter()`)
- **Snapshot assertions** — `assertGoldenAnsi`, `assertCellGrid`, `assertAnsiEquals`
- **`GoldenFile`** — load/save helper for `.golden` fixture files
- **`TapeRecorder`** — emits VHS-compatible `.tape` files for demo rendering

## Quickstart

```php
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\Snapshot\Assertions;

// Build a scripted session and run the simulator.
$sim = ProgramSimulator::for($program)
    ->send(new KeyMsg(KeyType::Char, 'a'))
    ->send(new KeyMsg(KeyType::Enter))
    ->run();

// Assert the view output matches the golden file.
Assertions::assertGoldenAnsi(__DIR__ . '/fixtures/counter.golden', $sim->view);

// Inspect the final model state.
$counter = $sim->model; // CounterModel with updated count
```

## Requirements

- PHP 8.3+
- `sugarcraft/candy-core` (Program, Msg, Model, Cmd)
- `sugarcraft/candy-buffer` (Buffer for cell-grid assertions)

## Install

```sh
composer require sugarcraft/candy-testing:@dev
```

## API

### ProgramSimulator

```php
// Wrap a Program for testing.
$sim = ProgramSimulator::for($program);

// Enqueue messages (fluent).
$sim->send(new KeyMsg(...))->send(new KeyMsg(...));

// Override the cmd runner to capture instead of executing side effects.
$sim->withFakeCmdRunner(fn($cmd) => null);

// Run the session and get the result.
$result = $sim->run();
echo $result->view;   // Last view() output
echo $result->model;  // Final model state
echo $result->output; // Raw accumulated ANSI bytes
```

### Assertions

```php
// Golden ANSI snapshot (auto-creates on first run if UPDATE_GOLDENS=1).
Assertions::assertGoldenAnsi('tests/fixtures/view.golden', $actual);

// Cell-grid diff (for buffer-based renderers).
Assertions::assertCellGrid($expected2DArray, $buffer);

// Byte-exact ANSI comparison with readable diff on failure.
Assertions::assertAnsiEquals("\x1b[1;32mHello\x1b[0m", $actual);
```

### ScriptedInput

```php
$input = ScriptedInput::new()
    ->key('h', ctrl: true)  // Ctrl+h
    ->arrow('down')
    ->enter()
    ->ticks(5)             // 5 tick events
    ->resize(120, 40)
    ->key('q')
    ->build();
```

## License

MIT
