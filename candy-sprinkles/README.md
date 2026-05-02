# CandySprinkles

PHP port of [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) —
declarative styling and layout for terminal UIs.

```php
use CandyCore\Sprinkles\Style;
use CandyCore\Core\Util\Color;

$banner = Style::new()
    ->bold(true)
    ->foreground(Color::hex('#ff5f87'))
    ->padding(0, 2)
    ->render('hello, candy world');

echo $banner . "\n";
```

Status: **Phase 1, in progress.** Core `Style` (foreground/background, text
attributes, padding, prefix/suffix, render) is implemented. Borders, margins,
alignment, width/height, tables, lists, and trees are upcoming. See
[../CONVERSION.md](../CONVERSION.md).

## Test

```sh
cd candy-sprinkles && composer install && vendor/bin/phpunit
```
