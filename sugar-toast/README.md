<img src=".assets/icon.png" alt="sugar-toast" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-toast)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-toast)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-toast?label=packagist)](https://packagist.org/packages/sugarcore/sugar-toast)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarToast

PHP port of [DaltonSW/bubbleup](https://github.com/daltonsw/bubbleup) — floating alert notification component for terminal UIs. Alerts float to the top of your TUI like bubbles in soda.

## Features

- **6 positions**: TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight
- **4 alert types**: Error, Warning, Info, Success — each with distinct styling
- **Dynamic width**: fixed or auto-sizing between minWidth and maxWidth
- **Symbol sets**: NerdFont (icons), Unicode (boxed), ASCII (plain text)
- **Auto-dismiss**: duration-based expiry support
- **Multiple alerts**: queue of toasts rendered in order
- **Pure renderer**: outputs ANSI strings; works with any TUI framework

## Install

```bash
composer require sugarcraft/sugar-toast
```

## Quick Start

```php
use SugarCraft\Toast\{Position, Toast, ToastType};

$toast = Toast::new(50)  // max width 50
    ->withPosition(Position::TopRight)
    ->withDuration(10.0);  // seconds

// Add alerts
$toast = $toast->alert(ToastType::Success, 'File saved!');
$toast = $toast->alert(ToastType::Error, 'Connection failed');

// Render into a viewport
$bg = str_repeat("background content\n", 20);
echo $toast->View($bg);
```

## Alert Types

```php
ToastType::Error
ToastType::Warning
ToastType::Info
ToastType::Success
```

## Positions

```php
Position::TopLeft
Position::TopCenter
Position::TopRight
Position::BottomLeft
Position::BottomCenter
Position::BottomRight
```

## License

[MIT](LICENSE)
