# CandyTemplate

Skeleton repo for bootstrapping a CandyCore TUI app. Run

```bash
composer create-project candycore/candy-template my-app
cd my-app
./bin/start
```

and you'll see a working counter. Replace `src/Counter.php` with your own `Model`, keep editing.

## What you get

```
my-app/
├── composer.json     # requires candy-core + candy-sprinkles
├── phpunit.xml
├── bin/start         # entry point — runs Program(new Counter())
├── src/
│   └── Counter.php   # demo Model with up/down/quit, styled border
└── tests/
    └── CounterTest.php
```

`bin/start` is just three meaningful lines: load the autoloader, instantiate your `Model`, hand it to `Program::run()`. The Program harness owns the event loop, render tick, signal handling, raw-mode setup, and alt-screen lifecycle — you only write Models.

## Anatomy of a CandyCore Model

A `Model` is three pure methods:

```php
public function init(): ?\Closure;            // optional startup Cmd (timers, fetch...)
public function update(Msg $msg): array;       // [nextModel, ?Cmd]
public function view(): string;                // current frame
```

The shape is borrowed verbatim from Bubble Tea / The Elm Architecture. State lives on the value object, transitions are pure functions, side effects (timers, HTTP, file I/O) get *scheduled* as Cmds rather than executed inline.

`update()` always returns a new `Model` rather than mutating `$this`. That's why the demo declares `public readonly int $n` — the only way to "change" the count is to construct a fresh Counter with the new value.

## Common next steps

| Want to…                             | Reach for…                                 |
|--------------------------------------|---------------------------------------------|
| Add a text input                     | `candycore/sugar-bits` — `TextInput`        |
| Show a spinner while loading         | `candycore/sugar-bits` — `Spinner`          |
| Render Markdown help text            | `candycore/candy-shine` — `Renderer`        |
| Tail a log into a scrollable pane    | `candycore/sugar-bits` — `Viewport`         |
| Build a multi-page wizard            | `candycore/sugar-prompt` — `Group`          |
| Plot a sparkline                     | `candycore/sugar-charts` — `Sparkline`      |
| Make it `ssh`-accessible             | `candycore/candy-wish`                      |

Add the dep, import its classes, return them from `view()`. They're all pure renderers on the same `Style`-based vocabulary.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The included `tests/CounterTest.php` shows how to test `update()` deterministically by constructing `Msg` objects directly. No event loop, no terminal, no mocking — just call methods and assert the returned tuple.

## License

MIT.
