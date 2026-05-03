<img src=".assets/icon.png" alt="candy-kit" width="160" align="right">

# CandyKit

![demo](.vhs/cli-page.gif)

PHP port of [charmbracelet/fang](https://github.com/charmbracelet/fang) —
opinionated **CLI presentation helpers** that turn ordinary command-
line output into something that matches the rest of the CandyCore
stack. CandyKit is library-only — drop it into any Composer project,
no Symfony Console requirement.

```php
use CandyCore\Kit\StatusLine;
use CandyCore\Kit\Banner;
use CandyCore\Kit\Theme;

echo Banner::title('CandyApp', 'v0.1.0'), "\n\n";
echo StatusLine::info('connecting to https://example.com'), "\n";
echo StatusLine::success('done in 0.4s'), "\n";
echo StatusLine::warn('disk almost full'), "\n";
echo StatusLine::error('connection refused'), "\n";
```

## Components

- **`Theme`** — palette of `Sprinkles\Style` objects keyed by status
  level (success / error / warn / info / prompt / accent / muted).
  `Theme::ansi()` ships a sensible default; bring your own theme by
  passing styles to the constructor.
- **`StatusLine`** — `success` / `error` / `warn` / `info` static
  helpers returning a glyph + message string styled per the active
  theme.
- **`Banner`** — render a bordered title block with optional subtitle,
  rounded by default. Useful for app intros / `--version` output.

## Test

```sh
cd candy-kit && composer install && vendor/bin/phpunit
```
