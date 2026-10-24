# Getting started with SugarCraft

Three ways to get something on your screen in about five minutes — run a
finished binary, scaffold an app, or taste one library. Then a router table
for "I want to X, which package is that?", and the install-path truth stated
once. New to the vocabulary? [docs/GLOSSARY.md](GLOSSARY.md) defines every
term below.

## Route A — run a finished app (no Composer needed)

Signed single-file phars ship on the [releases
page](https://github.com/detain/sugarcraft/releases). Every release carries a
SHA-256 sidecar; `candy-code`/`candy-top` builds from `v0.1.1`/`v0.1.0`
onward also carry an Ed25519 `minisig` verified against the pinned key
([SECURITY.md](../SECURITY.md)). Requirements: PHP 8.3+.

```bash
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-code/v0.1.1/candy-code.phar
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-code/v0.1.1/candy-code.phar.sha256
sha256sum -c candy-code.phar.sha256
php candy-code.phar doctor        # checks your PHP extensions, then:
php candy-code.phar               # the AI coding-agent TUI
```

```bash
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-top/v0.1.0/candy-top.phar
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-top/v0.1.0/candy-top.phar.sha256
sha256sum -c candy-top.phar.sha256
php candy-top.phar                # the btop-style system monitor
```

A tagged phar updates itself: `php candy-top.phar self-update --check`, then
`self-update` (and `--rollback` once if you ever want the previous file
back). `candy-code/v0.1.0` predates the signing key — install `v0.1.1`
instead; see [candy-code/README.md](../candy-code/README.md#install) for the
full story.

## Route B — build your first TUI

The starter is the shortest path to a running program: `bin/start` hands a
Model to sugar-core's `Program`, which owns the event loop, raw mode, and the
alt-screen. You only write the Model.

```bash
composer create-project sugarcraft/candy-starter my-app
cd my-app
./bin/start                       # a counter demo; q / Esc / Ctrl+C quits
```

A Model is the [four-method contract](../candy-starter/README.md) — this
compiles as-is:

```php
<?php
declare(strict_types=1);

namespace App;

use SugarCraft\Core\{Model, Msg, Subscriptions};

final class Greeter implements Model
{
    public function init(): ?\Closure { return null; }

    /** @return array{0: Model, 1: ?\Closure} */
    public function update(Msg $msg): array { return [$this, null]; }

    public function view(): string { return 'hello'; }

    public function subscriptions(): ?Subscriptions { return null; }
}
```

Keys arrive as `Msg`s, timers and streams ride `subscriptions()`, and every
effect is a `Cmd` closure — [GLOSSARY.md](GLOSSARY.md#runtime-vocabulary) and the [sugar-core
README](../sugar-core/README.md) walk each one.

## Route C — taste one library

Charts are the smallest full dependency: add sugar-charts to any project and
one class gives you a braille-cell sparkline on stdout.

```bash
composer require sugarcraft/sugar-charts
```

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use SugarCraft\Charts\Sparkline\Sparkline;

echo Sparkline::new([1, 3, 2, 8, 5, 4, 7, 6], 8)->view();
// ▂▁█▅▃▇▆
```

## I want to … → which package?

| Goal | Reach for |
|---|---|
| Run an AI coding agent in the terminal | [candy-code](../candy-code/) |
| Monitor CPU/memory/disks/network like btop | [candy-top](../candy-top/) |
| View an image in the terminal | [candy-image](../candy-image/) (CLI) / [sugar-image](../sugar-image/) (library) |
| Play a GIF or `.ansi` art file | [candy-gifview](../candy-gifview/) |
| Browse/edit files | [candy-files](../candy-files/) |
| Query databases | [candy-sql](../candy-sql/) |
| Drive git with keys | [candy-git](../candy-git/) |
| Read markdown in the terminal | [candy-mdview](../candy-mdview/) |
| Start your own app | [candy-starter](../candy-starter/) + [sugar-core](../sugar-core/) |
| Sparklines, bars, line charts | [sugar-charts](../sugar-charts/) |
| Forms and fields | [sugar-forms](../sugar-forms/) |
| Sortable/filterable tables | [sugar-table](../sugar-table/) |
| Parse raw terminal byte-streams | [sugar-ansi-parser](../sugar-ansi-parser/) / [sugar-input](../sugar-input/) |
| Sprites, physics, animation | [sugar-spring](../sugar-spring/), [sugar-animation](../sugar-animation/) |
| Record/replay a TUI session in tests | [candy-vcr](../candy-vcr/), [sugar-testing](../sugar-testing/) |

The full matrix with upstream comparisons is [docs/MATCHUPS.md](MATCHUPS.md);
the site mirror is [sugarcraft.github.io](https://sugarcraft.github.io/).

## Why PHP?

Because one language can cover the whole stack: the same `final class` that
computes your business logic also returns the exact SGR bytes that paint it,
with no daemon, FFI boundary, or second runtime between the two. Packages
stay composer-installable like any other PHP library, and finished apps
still ship to people who never touch Composer — as one signed, self-updating
`.phar` file (Route A). PHP 8.3's strict types plus ReactPHP cover the async
event loop the interactive stack runs on.

## The binary-path truth (once, for everything below)

Depending on how you got a package, its launcher lives in one of three
places — READMEs use `vendor/bin/…` and `./bin/…` for different ones:

- **You `composer require`d it into your project** → the command is
  `vendor/bin/candy-code` (any `candy-*` package with a binary).
- **You are inside a checkout of the package itself** (monorepo or clone) →
  it is `./bin/candy-code`, no `vendor/` prefix.
- **You are a release user** → there is no bin at all: `php candy-code.phar`.
- **You want it globally** → `composer global require sugarcraft/candy-code`
  and put `~/.config/composer/vendor/bin` on your `PATH`.

## Accessibility & reduced motion

- **`REDUCE_MOTION`** (or `PREFERS_REDUCED_MOTION`) set to any truthy value
  is read by [`SugarCraft\ColorProfile\Probe::reducedMotion()`](../sugar-colorprofile/src/Probe.php)
  and honoured by [`sugar-spring`](../sugar-spring/src/Spring.php): springs
  snap straight to their target instead of animating.
- **`NO_COLOR`**: color depth is detected fleet-wide by
  [`sugar-core`'s `ColorProfile::detect()`](../sugar-core/src/Util/ColorProfile.php)
  (TERM / `COLORTERM` / `NO_COLOR` / CI probes) and announced to every
  program as a `ColorProfileMsg`; the full precedence ladder is documented
  in the [sugar-colorprofile
  README](../sugar-colorprofile/README.md). Applying it is app-by-app:
  sugar-style renderers honour the profile, while some apps pick their depth
  from their own config first — candy-top, for example, derives it from its
  `truecolor`/terminal-mode options and does not consume the startup message.
  If an app ignores `NO_COLOR`, that is a bug worth a report.
- Keyboard-only operation and per-app key tables are indexed in
  [docs/KEYMAPS.md](KEYMAPS.md).

## Where to read more

- [docs/GLOSSARY.md](GLOSSARY.md) — every term, defined with its source file.
- [docs/KEYMAPS.md](KEYMAPS.md) — fleet keymap index (pointers).
- [sugar-core README](../sugar-core/README.md) — the runtime, plus the
  shared environment-variable table.
- [docs/TRUST-BOUNDARY.md](TRUST-BOUNDARY.md) — what untrusted input may and
  may not do; read before rendering anything you didn't author.
- [SECURITY.md](../SECURITY.md) — releases, signing, supported versions.
- [candy-code/docs/TROUBLESHOOTING.md](../candy-code/docs/TROUBLESHOOTING.md)
  — the flagship app's failure-mode guide.
- [CONTRIBUTING.md](../CONTRIBUTING.md) + [AGENTS.md](../AGENTS.md) —
  conventions, the per-lib `composer install && vendor/bin/phpunit` loop,
  and how path repositories work.
