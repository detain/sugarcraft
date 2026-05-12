<?php

declare(strict_types=1);

/**
 * sugar-toast — all ToastType variants + position showcase.
 *
 * Run: php examples/types.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Toast\{Toast, ToastType, Position, SymbolSet};

// Background — simulate an app viewport
$bgLines = [];
for ($i = 0; $i < 20; $i++) {
    $bgLines[] = "  Application content line " . ($i + 1);
}
$bg = \implode("\n", $bgLines);

echo "=== All Toast types ===\n\n";

// Each type as a separate toast with a background
foreach (ToastType::cases() as $type) {
    $msg = match ($type) {
        ToastType::Info    => 'This is an informational message.',
        ToastType::Success => 'Deployment completed successfully!',
        ToastType::Warning => 'Disk usage at 85% — consider cleaning up.',
        ToastType::Error   => 'Build failed: composer install returned non-zero.',
    };
    $t = Toast::new(50)->withPosition(Position::TopLeft)->alert($type, $msg);
    echo "[{$type->value}] " . $t->View($bg, 80, 20) . "\n\n";
}

echo "=== Position variants ===\n\n";

$msg = 'Item saved!';
foreach (Position::cases() as $pos) {
    $t = Toast::new(50)->withPosition($pos)->success($msg);
    echo "[{$pos->name}] " . $t->View($bg, 80, 20) . "\n\n";
}

echo "=== Duration variants ===\n\n";

// Short (1s), Medium (3s), Long (10s), Persistent (null = manual dismiss)
foreach ([1.0, 3.0, 10.0, null] as $secs) {
    $label = $secs === null ? 'persistent' : "{$secs}s";
    $t = Toast::new(50)->withDuration($secs)->info("Duration: {$label}");
    echo "[{$label}] " . $t->View($bg, 80, 20) . "\n";
}
echo "\n";

echo "=== Symbol sets ===\n\n";

echo "NerdFont: " . Toast::new(50)->withSymbolSet(SymbolSet::NerdFont)->success('Success')->View($bg, 80, 20) . "\n";
echo "Unicode : " . Toast::new(50)->withSymbolSet(SymbolSet::Unicode)->success('Success')->View($bg, 80, 20) . "\n";
echo "Ascii   : " . Toast::new(50)->withSymbolSet(SymbolSet::Ascii)->success('Success')->View($bg, 80, 20) . "\n";
