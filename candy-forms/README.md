# CandyForms

Foundation lib for form and input primitives — the raw component
implementations that `sugar-bits` and `sugar-prompt` re-export. Extracted
out of those two leaf libs so the form/prompt engine can ship as a single
standalone dependency.

## Install

```sh
composer require sugarcraft/candy-forms
```

## Role

CandyForms owns the full primitive + form surface:

- **Input primitives** — `TextInput`, `TextArea`, `ItemList`, `Viewport`,
  `FilePicker`, `Cursor`, `Scrollbar`, `Spinner`.
- **Form engine** — `Field` interface + `Field\{Input,Text,Confirm,Select,
  MultiSelect,Note,FilePicker}`, `Form`, `Group`, `KeyMap`, `Theme`,
  `Fuzzy\FuzzyMatcher`, and `Validator\{Required,Email,MinLength,MaxLength,Pattern}`.

`sugar-bits` (`SugarCraft\Bits\*`) and `sugar-prompt` (`SugarCraft\Prompt\*`)
now expose these classes as thin `class_alias` shims pointing back here, so
existing imports keep working while the canonical implementation lives in
`SugarCraft\Forms\*`.

## Quickstart

A single-field form:

```php
<?php

use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Validator\Required;

$form = Form::new(
    Input::new('name')
        ->withTitle('What is your name?')
        ->withValidator(new Required())
);

echo $form->view();
```

Standalone spinner (now lives here, was in sugar-bits):

```php
<?php

use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style;

$spinner = Spinner::new(Style::dot());
// in your model's init():   return $spinner->init();
// in your model's update():  [$spinner, $cmd] = $spinner->update($msg);
// in your model's view():    "loading {$spinner->view()}"
```

## Shared foundations

candy-forms is built on four shared packages:

- **[candy-buffer](/sugarcraft/candy-buffer)** — Cell-grid model + `Buffer::toAnsi()` for
  rendering glyphs and styles to ANSI bytes. TextInput / TextArea use the buffer
  path internally; their snapshot tests pin byte-for-byte output.
- **[candy-layout](/sugarcraft/candy-layout)** — `LayoutSolver` + constraint types
  (`Constraint::length`, `Constraint::fill`, etc.). `Form::withConstraints()` routes
  through the solver when an explicit constraint set is provided.
- **[candy-testing](/sugarcraft/candy-testing)** — `Assertions::assertGoldenAnsi` and
  `Assertions::assertCellGrid` snapshot helpers. Render fixtures live in
  `tests/fixtures/`; set `UPDATE_GOLDENS=1` to regenerate them.
- **[candy-fuzzy](/sugarcraft/candy-fuzzy)** — `SmithWatermanMatcher` scores.
  Select / MultiSelect filter delegates to it internally; the existing public
  `withFilter(callable)` injection point is preserved for custom filters.

The legacy `SugarCraft\Forms\Fuzzy\FuzzyMatcher` (step-07 back-compat shim)
remains as a deprecated alias — it delegates to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`
with no behavioural divergence.

## Dependencies

- `sugarcraft/candy-core` — Elm-architecture TUI runtime
- `sugarcraft/candy-sprinkles` — Declarative styling

## License

MIT
