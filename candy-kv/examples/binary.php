<?php

declare(strict_types=1);

/**
 * CandyKv binary data demo — store and retrieve binary files.
 *
 * Run: php examples/binary.php
 *
 * Exercises:
 * - Store::set(binary: true) to store raw bytes
 * - Entry::rawValue() to decode it
 * - Store::setFile() / saveTo()
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Kv\Store;

$tmp = sys_get_temp_dir() . '/candy-kv-binary-demo-' . uniqid();
// A Store touches nothing on disk by design — the demo directory is ours
// to create (construction used to mkdir as a side effect; that ended with
// the reads-never-create contract).
\mkdir($tmp, 0o700, true);
$store = new Store($tmp);
register_shutdown_function(static function () use ($tmp): void {
    foreach ((array) glob("{$tmp}/*") as $file) {
        if (\is_dir($file)) {
            foreach ((array) glob("{$file}/*") as $inner) {
                @\unlink($inner);
            }
            @\rmdir($file);
        } else {
            @\unlink($file);
        }
    }
    @\rmdir($tmp);
});

// Create a test "binary" file (normally you'd use a real image/file)
$imgPath = $tmp . '/test.bin';
$imgData = "\x89PNG\r\n\x1a\n" . str_repeat("\x00\x01\x02\x03", 100);
file_put_contents($imgPath, $imgData);

// Store via setFile
$store->setFile('image:avatar', $imgPath);

echo "File stored, size: " . strlen($imgData) . " bytes\n";

// Retrieve
$outPath = $tmp . '/retrieved.bin';
$store->saveTo('image:avatar', $outPath);

$retrieved = file_get_contents($outPath);
echo "Retrieved size: " . strlen($retrieved) . " bytes\n";
echo "Integrity: " . ($retrieved === $imgData ? "OK ✓" : "FAILED ✗") . "\n";

// Manual binary entry from raw bytes. Store::set() base64-encodes when the
// binary flag is set — pass the RAW bytes, not Entry::binary()'s encoded
// form (encoding twice would print 44 characters for 22 bytes of data).
$rawDoc = "PDF-like\x00\xff\xfe\xfd data here";
$store->set('document', $rawDoc, true);

$restored = $store->entry('document');
echo "\nManual binary entry:\n";
echo "  binary flag: " . ($restored->binary ? 'yes' : 'no') . "\n";
echo "  raw length:  " . strlen($restored->rawValue()) . " bytes\n";
echo "  integrity:   " . ($restored->rawValue() === $rawDoc ? "OK ✓" : "FAILED ✗") . "\n";

echo "\nDone.\n";
