<?php

declare(strict_types=1);

/**
 * HandlerAdapter demo.
 *
 * Shows the three adapter behaviours that are NOT visible on the raw
 * Handler path (walked in examples/debug.php):
 *
 *   1. DECSET/DECRST multi-mode fan-out — `CSI ? 1000 ; 1006 h` calls
 *      decset() once per mode parameter, with the private-marker prefix
 *      byte passed to every call.
 *   2. The OSC 8 decode — the adapter parses `8;params;uri` (and the
 *      backtick-terminated form) out of the raw oscDispatch payload and
 *      hands OscHandler an open/close pair.
 *   3. The sieve and its $fallback escape hatch — BEL and the ESC family
 *      stop dead at the adapter; pass a fallback Handler as the third
 *      constructor argument and they surface again.
 *
 * Run: php examples/adapter.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\AnsiParser\Parser\CsiHandler;
use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\HandlerAdapter;
use SugarCraft\AnsiParser\Parser\OscHandler;
use SugarCraft\AnsiParser\Parser\Parser;

/** Collects human-readable call rows instead of mutating a grid. */
final class CallSink
{
    /** @var list<string> */
    public array $calls = [];

    public function log(string $call): void
    {
        $this->calls[] = $call;
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}

$sink = new CallSink();

$csi = new class($sink) implements CsiHandler {
    public function __construct(private CallSink $sink) {}

    public function printable(string $rune): void
    {
        $this->sink->log("printable('{$rune}')");
    }

    public function cuu(int $count): void
    {
        $this->sink->log("cuu({$count})");
    }

    public function cud(int $count): void
    {
        $this->sink->log("cud({$count})");
    }

    public function cuf(int $count): void
    {
        $this->sink->log("cuf({$count})");
    }

    public function cub(int $count): void
    {
        $this->sink->log("cub({$count})");
    }

    public function cup(int $row, int $col): void
    {
        $this->sink->log("cup({$row}, {$col})");
    }

    public function hvp(int $row, int $col): void
    {
        $this->sink->log("hvp({$row}, {$col})");
    }

    public function sgr(array $params): void
    {
        $this->sink->log('sgr([' . implode(', ', $params) . '])');
    }

    public function ed(int $mode): void
    {
        $this->sink->log("ed({$mode})");
    }

    public function el(int $mode): void
    {
        $this->sink->log("el({$mode})");
    }

    public function decset(int $mode, int $prefix): void
    {
        $this->sink->log(sprintf(
            'decset(%d, prefix=%s)',
            $mode,
            $prefix === 0 ? 'none' : sprintf('0x%02X (%s)', $prefix, chr($prefix)),
        ));
    }

    public function decrst(int $mode, int $prefix): void
    {
        $this->sink->log(sprintf(
            'decrst(%d, prefix=%s)',
            $mode,
            $prefix === 0 ? 'none' : sprintf('0x%02X (%s)', $prefix, chr($prefix)),
        ));
    }

    public function decstbm(int $top, int $bottom): void
    {
        $this->sink->log("decstbm({$top}, {$bottom})");
    }

    public function tbc(int $mode): void
    {
        $this->sink->log("tbc({$mode})");
    }

    public function cht(int $count = 1): void
    {
        $this->sink->log("cht({$count})");
    }

    public function cbt(int $count = 1): void
    {
        $this->sink->log("cbt({$count})");
    }

    public function cr(): void
    {
        $this->sink->log('cr()');
    }

    public function lf(): void
    {
        $this->sink->log('lf()');
    }

    public function su(int $count = 1): void
    {
        $this->sink->log("su({$count})");
    }

    public function sd(int $count = 1): void
    {
        $this->sink->log("sd({$count})");
    }

    public function il(int $count = 1): void
    {
        $this->sink->log("il({$count})");
    }

    public function dl(int $count = 1): void
    {
        $this->sink->log("dl({$count})");
    }

    public function ich(int $count = 1): void
    {
        $this->sink->log("ich({$count})");
    }

    public function dch(int $count = 1): void
    {
        $this->sink->log("dch({$count})");
    }

    public function rep(int $count = 1): void
    {
        $this->sink->log("rep({$count})");
    }

    public function scosc(): void
    {
        $this->sink->log('scosc()');
    }

    public function scorc(): void
    {
        $this->sink->log('scorc()');
    }

    public function gridRows(): int
    {
        return 24;
    }

    public function gridCols(): int
    {
        return 80;
    }
};

$osc = new class($sink) implements OscHandler {
    public function __construct(private CallSink $sink) {}

    public function title(string $title): void
    {
        $this->sink->log("title('{$title}')");
    }

    public function hyperlink(string $uri, string $id): void
    {
        $this->sink->log($uri === ''
            ? "hyperlink('') — close"
            : "hyperlink('{$uri}', id: '{$id}') — open");
    }
};

function run(CallSink $sink, string $label, callable $wire): void
{
    $sink->reset();

    echo "== {$label}\n";
    $wire();
    if ($sink->calls === []) {
        echo "   -> (nothing — the sieve dropped it)\n";
    }
    foreach ($sink->calls as $call) {
        echo "   -> {$call}\n";
    }
    echo "\n";
}

$adapter = new HandlerAdapter($csi, $osc);

run($sink, 'DECSET fan-out: CSI ? 1000 ; 1006 h', static function () use ($adapter): void {
    (new Parser($adapter))->parseComplete("\x1b[?1000;1006h");
});

run($sink, 'OSC 8 open/close pair', static function () use ($adapter): void {
    (new Parser($adapter))->parseComplete(
        "\x1b]8;id=cr;https://example.org\x1b\\click me\x1b]8;;\x1b\\",
    );
});

run($sink, 'The sieve: BEL + ESC family, no fallback', static function () use ($adapter): void {
    (new Parser($adapter))->parseComplete("hi\x07\x1bM");
});

$fallbackDebug = new DebugHandler();
$wired = new HandlerAdapter($csi, $osc, $fallbackDebug);

run($sink, 'Same stream, $fallback = DebugHandler', static function () use ($sink, $wired, $fallbackDebug): void {
    (new Parser($wired))->parseComplete("hi\x07\x1bM");
    foreach ($fallbackDebug->log as $entry) {
        $detail = is_array($entry['detail'])
            ? json_encode($entry['detail'])
            : var_export($entry['detail'], true);
        $sink->log("fallback recovered [{$entry['type']}] {$detail}");
    }
});
