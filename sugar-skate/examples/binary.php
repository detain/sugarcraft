<?php

declare(strict_types=1);

/**
 * SugarSkate binary data demo — store and retrieve binary files.
 *
 * Run: php examples/binary.php
 *
 * Exercises:
 * - Entry::binary() to create a binary entry
 * - Entry::rawValue() to decode it
 * - Store::setFile() / getFile()
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Skate\Store;
use CandyCore\Skate\Entry;

$tmp = sys_get_temp_dir() . '/skate-binary-demo-' . uniqid();
$store = new Store($tmp);
register_shutdown_function(fn() => @array_map('unlink', glob("{$tmp}/*.db") ?: []));

// Create a test "binary" file (normally you'd use a real image/file)
$imgPath = $tmp . '/test.bin';
$imgData = "\x89PNG\r\n\x1a\n" . str_repeat("\x00\x01\x02\x03", 100);
file_put_contents($imgPath, $imgData);

// Store via setFile
$store->setFile('image:avatar', $imgPath);

echo "File stored, size: " . strlen($imgData) . " bytes\n";

// Retrieve
$outPath = $tmp . '/retrieved.bin';
$store->getFile('image:avatar', $outPath);

$retrieved = file_get_contents($outPath);
echo "Retrieved size: " . strlen($retrieved) . " bytes\n";
echo "Integrity: " . ($retrieved === $imgData ? "OK ✓" : "FAILED ✗") . "\n";

// Manual binary entry via Entry::binary()
$entry = Entry::binary('document', "PDF-like\x00\xff\xfe\xfd data here");
$store->set('document', $entry->value, true);

$restored = $store->entry('document');
echo "\nManual binary entry:\n";
echo "  binary flag: " . ($restored->binary ? 'yes' : 'no') . "\n";
echo "  raw length:  " . strlen($restored->rawValue()) . " bytes\n";

echo "\nDone.\n";
