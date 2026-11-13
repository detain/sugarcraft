<?php
declare(strict_types=1);
// S-L07 probe: Renderer::insertAtColumn tokenizer (preg_split, SGR+OSC+zone sentinel+\X)
// vs sugar-ansi-parser Parser (VT state machine). Run: php prompt_kit/tools/probe-ansi-tokenizer.php
require __DIR__ . '/../../candy-code/vendor/autoload.php';

use SugarCraft\AnsiParser\Parser\{Handler, Parser};

const RE = '/(\x1b\[[0-?]*[ -\/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\xEE\x80\x80\/?[A-Za-z0-9._:-]*\xEE\x80\x81|\X)/u';

final class Collect implements Handler {
    public array $printed = []; public int $esc = 0;
    public function printChar(string $rune): void { $this->printed[] = $rune; }
    public function execute(int $byte): void {}
    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void { $this->esc++; }
    public function escDispatch(int $final, int $intermediate): void { $this->esc++; }
    public function oscDispatch(string $data): void { $this->esc++; }
    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void {}
    public function sosPmApcDispatch(string $kind, string $data): void {}
}

$z = "\u{E000}tool:1\u{E001}";
$rows = [
  'plain'   => str_repeat('hello world ', 10),
  'sgr'     => str_repeat("\x1b[38;2;1;2;3mfoo\x1b[0m bar ", 10),
  'osc8'    => "\x1b]8;;https://x.y\x07link\x1b]8;;\x07 tail",
  'zone'    => "ab{$z}cd" . str_repeat('x', 40),
  'family'  => "a\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}b",   // ZWJ cluster
  'combine' => "e\u{0301}x",
];
foreach ($rows as $name => $row) {
    $t = preg_split(RE, $row, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $h = new Collect; (new Parser($h))->parseComplete($row);
    $reTok = count($t); $reRoundTrip = implode('', $t) === $row;
    $pTok = count($h->printed);
    printf("%-8s regex tokens=%-3d lossless=%s | parser printed runes=%-3d escapes=%d (zone sentinel/graphemes: %s)\n",
        $name, $reTok, $reRoundTrip ? 'yes' : 'NO', $pTok, $h->esc,
        $name === 'family' ? "parser splits cluster ($pTok runes vs 3 tokens)" : '-');
}
// Speed: 24-col-wide row x 40 rows, 2000 iterations
$row = str_repeat("\x1b[38;2;1;2;3mfoo\x1b[0m bar baz ", 8);
$n = 3000;
$t0 = hrtime(true);
for ($i=0;$i<$n;$i++) preg_split(RE, $row, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
$re = (hrtime(true)-$t0)/$n/1000;
$t0 = hrtime(true);
for ($i=0;$i<$n;$i++) { $h = new Collect; (new Parser($h))->parseComplete($row); }
$pa = (hrtime(true)-$t0)/$n/1000;
printf("speed per %d-byte row: regex %.1f us, parser %.1f us (%.1fx)\n", strlen($row), $re, $pa, $pa/$re);
