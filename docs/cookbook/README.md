# SugarCraft composability cookbook

SugarCraft asserts that its libraries compose, but that claim is mostly made
in prose. This cookbook is a small set of **runnable** PHP scripts that show
two or more libraries working together end-to-end.

Each example is non-interactive: it builds the model(s), feeds a scripted
message or two through the normal `update()` contract, renders one frame, and
exits. No event loop, no TTY required — so they run anywhere (including CI).

## Running

```sh
cd docs/cookbook
composer install        # symlinks the sibling libs via path repositories
php spinner-in-table.php
php sparkline-in-table.php
php program-within-program.php
```

`composer install` wires up the libraries through `path` repositories
(`../../<lib>`), so the examples always run against the current monorepo
checkout. `vendor/` is gitignored.

## Examples

| File | Composition | Shows |
| --- | --- | --- |
| [`spinner-in-table.php`](./spinner-in-table.php) | candy-forms `Spinner` + sugar-table `Table` | A live spinner frame embedded in a `StyledCell`, used as a per-row job status. |
| [`sparkline-in-table.php`](./sparkline-in-table.php) | sugar-charts `Sparkline` + sugar-table `Table` + candy-forms `Spinner` | One inline trend chart per table row, with a spinner as the panel's "refreshing" indicator. |
| [`program-within-program.php`](./program-within-program.php) | candy-core `Model` + candy-forms `Spinner` | The Elm-architecture "sub-program" pattern: a root model forwards messages to whichever child (a counter or a spinner) has focus. |

## The recurring trick

Most of these compositions rely on the same property: a SugarCraft component's
`view()` returns a plain string of ANSI bytes. That means any component drops
into any string slot — a `sugar-table` cell, a `sugar-toast` background, a
parent model's frame — without a dedicated adapter. The "program-within-a-program"
example shows the other half: candy-core's `Model::update()` contract lets one
model own and drive another as a sub-program.
