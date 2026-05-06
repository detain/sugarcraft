<?php
/**
 * sugar-toast — all ToastType variants + position showcase.
 *
 * Run: php examples/types.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Toast\{Toast, ToastType, Position, SymbolSet};

echo "=== All Toast types ===\n\n";

$messages = [
    ToastType::Info    => 'This is an informational message.',
    ToastType::Success => 'Deployment completed successfully!',
    ToastType::Warning => 'Disk usage at 85% — consider cleaning up.',
    ToastType::Error   => 'Build failed: composer install returned non-zero.',
];

foreach ($messages as $type => $msg) {
    $toast = Toast::new($msg, $type);
    echo "[{$type->value}] " . $toast->render() . "\n\n";
}

echo "=== Position variants ===\n\n";

$msg = 'Item saved!';
foreach (Position::cases() as $pos) {
    $toast = Toast::new($msg, ToastType::Success)->withPosition($pos);
    echo "[{$pos->value}] " . $toast->render() . "\n\n";
}

echo "=== Duration variants ===\n\n";

// Short (1s), Medium (3s), Long (10s), Persistent (null = manual dismiss)
foreach ([1.0, 3.0, 10.0, null] as $secs) {
    $label = $secs === null ? 'persistent' : "{$secs}s";
    $toast = Toast::new("Duration: {$label}", ToastType::Info)->withDuration($secs);
    echo "[{$label}] " . $toast->render() . "\n";
}
echo "\n";

echo "=== Custom symbol sets ===\n\n";

echo "Default : " . Toast::new('Default', ToastType::Success)->render() . "\n";
echo "Check   : " . Toast::new('Success', ToastType::Success)->withSymbolSet(SymbolSet::SuccessCheck)->render() . "\n";
echo "Triangle: " . Toast::new('Warning', ToastType::Warning)->withSymbolSet(SymbolSet::WarningTriangle)->render() . "\n";
echo "ErrorX  : " . Toast::new('Error', ToastType::Error)->withSymbolSet(SymbolSet::ErrorX)->render() . "\n";
