<?php

declare(strict_types=1);

/**
 * skinny-b-check — verify skinny logo files and optionally render PNG previews.
 *
 * Per file: every line ends with \e[0m, all lines the same visible width,
 * width ≤ --max-w (40), height in --min-h..--max-h (5..15), colour codes pure
 * for the depth named in the filename (tc|256|16), and the WxH in the filename
 * matches the content. Animations are checked on their last frame.
 * Exit status 1 if any file fails.
 *
 * Usage:
 *   php skinny-b-check.php [options] <file.ansi|'glob'>...
 *     --png=DIR           render DIR/<basename>.png for each file (look at them!)
 *     --previewer=NAME    mixed (default) | ttf | block-braille | braille | sextant | block
 *     --cell=WxH          preview cell size in px (default 12x24)
 *     --max-w=N --min-h=N --max-h=N   override 40 / 5 / 15
 *     --jsonl             report whether logos.jsonl lists each file
 *     -h | --help
 *
 * Example:
 *   php skinny-b-check.php --jsonl --png=/tmp/pngs '../logo-phosphor-scope-trace-skinny-*.ansi'
 */

require_once __DIR__ . '/skinny-b-kit.php';

$o = ['png' => null, 'previewer' => 'mixed', 'cell' => '12x24', 'max-w' => '40', 'min-h' => '5', 'max-h' => '15', 'jsonl' => false];
$files = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '-h' || $a === '--help') {
        skbHelp(__FILE__);
        exit(0);
    }
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $a, $m)) {
        $o[$m[1]] = $m[2] ?? true;
        continue;
    }
    array_push($files, ...(glob($a) ?: [$a]));
}
if (!$files) {
    fwrite(STDERR, "usage: php skinny-b-check.php [--png=DIR] [--jsonl] <file.ansi|glob>...   (--help)\n");
    exit(2);
}
[$cw, $ch] = array_map('intval', explode('x', (string) $o['cell']));
$jsonlText = $o['jsonl'] ? (string) @file_get_contents(dirname(__DIR__) . '/logos.jsonl') : '';
$fail = 0;
foreach ($files as $f) {
    $b = basename($f);
    try {
        $s = @file_get_contents($f);
        if ($s === false) {
            throw new RuntimeException('unreadable');
        }
        [$w, $h] = skbVerify($s, $b, (int) $o['max-w'], (int) $o['min-h'], (int) $o['max-h']);
        $note = '';
        if (preg_match('/-(tc|256|16)-(\d+)x(\d+)\.ansi$/', $b, $m)) {
            lkCheckDepth($s, $m[1], $b);
            if ((int) $m[2] !== $w || (int) $m[3] !== $h) {
                throw new RuntimeException("filename says {$m[2]}x{$m[3]}, content is {$w}x{$h}");
            }
        } else {
            $note .= ' (no depth/dims in name)';
        }
        if ($o['jsonl']) {
            $note .= str_contains($jsonlText, "\"filename\":\"$b\"") ? '  jsonl:yes' : '  jsonl:MISSING';
        }
        if ($o['png']) {
            $png = rtrim((string) $o['png'], '/') . '/' . preg_replace('/\.ansi$/', '', $b) . '.png';
            skbPreview($f, $png, (string) $o['previewer'], $cw, $ch);
            $note .= "  → $png";
        }
        printf("OK   %-58s %3dx%-3d%s\n", $b, $w, $h, $note);
    } catch (Throwable $e) {
        $fail++;
        printf("FAIL %-58s %s\n", $b, $e->getMessage());
    }
}
exit($fail ? 1 : 0);
