<?php

declare(strict_types=1);

/**
 * gen-homebrew-formula.php - render the Homebrew formula for a candy-code
 * phar release (plan_comparison C-SES-6.c).
 *
 * Reads packaging/homebrew/candy-code.rb.tmpl, substitutes the VERSION, URL
 * and SHA256 placeholders and writes the rendered Ruby to --out (default:
 * stdout, which is what the release workflow captures). Per the plan's
 * section 6.1 ruling this formula is distributed as a release asset ONLY -
 * nothing here publishes a tap; C-SES-6.d attaches the rendered file.
 *
 * Usage:
 *   php tools/gen-homebrew-formula.php --version=0.1.1 --phar=build/candy-code.phar
 *   php tools/gen-homebrew-formula.php --version=candy-code/v0.1.1 --sha256=<64-hex> --out=candy-code.rb
 *
 * Every input is validated fail-closed: an unrecognised version spelling, a
 * non-lowercase-hex digest or a leftover placeholder aborts with exit 1 and
 * writes nothing.
 */

const TAG_PREFIX = 'candy-code/';
const RELEASE_DOWNLOAD_BASE = 'https://github.com/detain/sugarcraft/releases/download/' . TAG_PREFIX;

function fail(string $message): never
{
    fwrite(STDERR, 'gen-homebrew-formula: ' . $message . "\n");
    exit(1);
}

function usage(): void
{
    fwrite(STDOUT, <<<TEXT
        Usage: php tools/gen-homebrew-formula.php --version=V (--sha256=HEX | --phar=PATH)
                   [--url=URL] [--out=FILE] [--tmpl=TEMPLATE]

          --version V    Release version: 0.1.1, v0.1.1 or candy-code/v0.1.1.
          --sha256 HEX   The phar's sha256 (64 lowercase hex digits), or use
          --phar PATH    to hash a built phar file directly.
          --url URL      Override the download URL (default: the GitHub
                         release-download URL for the version's tag).
          --out FILE     Write the rendered formula here (default: stdout).
          --tmpl FILE    Template path (default: packaging/homebrew/).

        TEXT);
}

/** Accept "candy-code/v1.2.3", "v1.2.3" or "1.2.3"; return the numeric triple. */
function normalizeVersion(string $input): string
{
    $candidate = trim($input);
    if (str_starts_with($candidate, TAG_PREFIX)) {
        $candidate = substr($candidate, strlen(TAG_PREFIX));
    }
    if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $candidate, $m) !== 1) {
        fail("unrecognised --version '$input' (expected X.Y.Z, vX.Y.Z or " . TAG_PREFIX . 'vX.Y.Z)');
    }

    return $m[1];
}

/** @param array<string,string> $options */
function requested(array $options, string $name, ?string $default = null): ?string
{
    return $options[$name] ?? $default;
}

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') {
        usage();
        exit(0);
    }
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) !== 1) {
        fail("unexpected argument '$arg' (try --help)");
    }
    if (!isset($m[2]) || $m[2] === '') {
        fail("--{$m[1]} requires a value");
    }
    $options[$m[1]] = $m[2];
}

$versionRaw = requested($options, 'version');
if ($versionRaw === null) {
    fail('--version is required (try --help)');
}
$version = normalizeVersion($versionRaw);

$sha256 = requested($options, 'sha256');
$pharPath = requested($options, 'phar');
if ($sha256 !== null && $pharPath !== null) {
    fail('--sha256 and --phar are alternatives; pass only one');
}
if ($sha256 === null && $pharPath !== null) {
    if (!is_file($pharPath) || !is_readable($pharPath)) {
        fail("--phar '$pharPath' is not a readable file");
    }
    $computed = hash_file('sha256', $pharPath);
    if ($computed === false) {
        fail("--phar '$pharPath' could not be hashed");
    }
    $sha256 = $computed;
}
if ($sha256 === null) {
    fail('one of --sha256 or --phar is required (try --help)');
}
$sha256 = trim($sha256);
if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
    fail('--sha256 must be exactly 64 lowercase hex characters');
}

$url = requested($options, 'url', RELEASE_DOWNLOAD_BASE . 'v' . $version . '/candy-code.phar');
if ($url === '' || str_contains($url, "\n") || str_contains($url, '"')) {
    fail('--url must be a single-line URL without quote characters');
}

$templatePath = requested($options, 'tmpl', dirname(__DIR__) . '/packaging/homebrew/candy-code.rb.tmpl');
$template = @file_get_contents($templatePath);
if ($template === false) {
    fail("template '$templatePath' could not be read");
}

$rendered = strtr($template, [
    '{{VERSION}}' => $version,
    '{{URL}}' => $url,
    '{{SHA256}}' => $sha256,
]);
if (str_contains($rendered, '{{') || str_contains($rendered, '}}')) {
    fail('the rendered formula still carries a placeholder; the template and generator disagree');
}

$outPath = requested($options, 'out');
if ($outPath === null) {
    fwrite(STDOUT, $rendered);
    exit(0);
}
if (@file_put_contents($outPath, $rendered) === false) {
    fail("--out '$outPath' could not be written");
}
exit(0);
