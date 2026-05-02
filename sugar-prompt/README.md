# SugarPrompt

PHP port of [charmbracelet/huh](https://github.com/charmbracelet/huh) —
interactive form library built on top of CandyCore + SugarBits.

```php
use CandyCore\Prompt\Form;
use CandyCore\Prompt\Field\{Input, Confirm, Select, Note};

$form = Form::new(
    Note::new('welcome')->withTitle('Onboarding')
        ->withDescription('A few quick questions.'),
    Input::new('name')->withTitle('Your name?')->withPlaceholder('Ada Lovelace'),
    Confirm::new('newsletter')->withTitle('Subscribe to the newsletter?'),
    Select::new('lang')
        ->withTitle('Favorite language?')
        ->withOptions('PHP', 'Go', 'Rust', 'Python'),
);
// $form is a CandyCore Model — drop it into a Program.
```

## Field types (round 1)

- `Input`  — single-line text (wraps `SugarBits\TextInput`).
- `Note`   — read-only paragraph; navigated past with Tab.
- `Confirm`— y/n boolean.
- `Select` — single-choice list (wraps `SugarBits\ItemList`).

Forms navigate with `Tab` / `Shift+Tab`. `Enter` on the last field
submits; `Esc` / `Ctrl+C` aborts.

## Test

```sh
cd sugar-prompt && composer install && vendor/bin/phpunit
```
