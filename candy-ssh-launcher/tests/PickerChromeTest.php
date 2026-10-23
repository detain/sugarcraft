<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;

/**
 * Pins for the picker chrome rework (docs-fix-1023 lane E4): the localized
 * frame (Lang::t chrome with byte-identical 'en' values), the U1 count row,
 * the T1 abort-only screen cleanup, the T2 width clamp seam, and the T3
 * sort-stability contract. Behavior tests only — the byte-level sanitize
 * and UTF-8 integrity pins live in PickerSanitizeTest /
 * PickerInputIntegrityTest and must stay green untouched.
 */
final class PickerChromeTest extends TestCase
{
    /**
     * @param string $keys keystrokes fed to the picker
     * @param array<int,Endpoint> $endpoints
     * @param int|null $cols forced detectColumns() width (null = probe)
     * @return array{?Endpoint,string} [picked, output]
     */
    private function drive(string $keys, array $endpoints, ?int $cols = null): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out, $cols) extends Picker {
            public function __construct($in, $out, private readonly ?int $forcedCols)
            {
                parent::__construct($in, $out);
            }

            protected function setRawMode(bool $on): void
            {
            }

            protected function detectColumns(): ?int
            {
                return $this->forcedCols;
            }
        };

        $picked = $p->pick($endpoints);
        rewind($out);

        return [$picked, (string) stream_get_contents($out)];
    }

    /**
     * @return list<Endpoint>
     */
    private function threeHosts(): array
    {
        return [
            new Endpoint(name: 'prod', host: 'prod.example.com'),
            new Endpoint(name: 'dev', host: 'dev.example.com'),
            new Endpoint(name: 'stage', host: 'stage.example.com'),
        ];
    }

    public function testAbortClearsTheTransientFrameAndPickDoesNot(): void
    {
        // T1: Esc (and ^C) must erase the picker frame — a cancelled
        // session should not leave dead chrome on the terminal. A
        // successful pick deliberately keeps the frame (PickerSanitizeTest
        // pins that the Enter path never emits eraseScreen).
        [, $abortOut] = $this->drive("\x1b", $this->threeHosts());
        self::assertStringContainsString("\x1b[2J", $abortOut);
        self::assertStringContainsString("\x1b[1;1H", $abortOut);

        [$picked, $pickOut] = $this->drive("\r", $this->threeHosts());
        self::assertNotNull($picked);
        self::assertStringNotContainsString("\x1b[2J", $pickOut);
    }

    public function testCountRowAppearsOnlyWhileFiltering(): void
    {
        // U1: typing a filter shows how many of the loaded hosts remain.
        // SmithWaterman is a GAPPED LOCAL aligner: any shared high-value
        // substring scores, so 'p' (present via 'example' in all three)
        // keeps 3 of 3, while 't' (only 'stage' carries it) drops to 1 of 3.
        [, $one] = $this->drive('t' . "\r", $this->threeHosts());
        self::assertStringContainsString('  (1 of 3)', $one);

        [, $all] = $this->drive('p' . "\r", $this->threeHosts());
        self::assertStringContainsString('  (3 of 3)', $all);

        [, $unfiltered] = $this->drive("\r", $this->threeHosts());
        self::assertStringNotContainsString('of 3', $unfiltered);

        // No match must be honest too: 0 shown. ESC (not Enter) ends the
        // session — Enter on an empty match set is a deliberate no-op.
        [, $gone] = $this->drive('zzzz' . "\x1b", $this->threeHosts());
        self::assertStringContainsString('  (0 of 3)', $gone);
        self::assertStringContainsString('  (no matches)', $gone);
    }

    public function testEqualScoresPreserveConfigOrder(): void
    {
        // T3: usort() has been stable since PHP 8.0; equal-match rows keep
        // config order. 'x' matches the host label at the same offset with
        // the same score in both rows, so the FIRST config entry must be
        // the Enter target and the second must sit one j below it.
        $endpoints = [
            new Endpoint(name: 'alpha', host: 'x.test', port: 22),
            new Endpoint(name: 'beta', host: 'x.test', port: 23),
        ];

        [$picked] = $this->drive("x\r", $endpoints);
        self::assertSame(22, $picked?->port, 'first of the equal-score pair wins');

        [$down] = $this->drive("xj\r", $endpoints);
        self::assertSame(23, $down?->port);
    }

    public function testRowsClampToOneLessThanTerminalWidth(): void
    {
        // T2: with a 40-column terminal, data rows never exceed 39 cells
        // (soft-wrap leftovers would survive eraseToEnd redraws). Chrome
        // rows without markers are exempt; non-terminals pass through
        // unclamped (the other tests in this file pin that by never
        // seeing an ellipsis).
        $endpoints = [
            new Endpoint(name: 'tiny', host: 'a.test'),
            new Endpoint(
                name: 'verylonghostname',
                host: 'this-host-name-is-deliberately-longer-than-any-pickable-terminal.example.org',
            ),
        ];

        [, $output] = $this->drive("\r", $endpoints, cols: 40);

        $rows = explode("\r\n", $output);
        $clamped = array_values(array_filter(
            $rows,
            static fn(string $r): bool => str_contains($r, 'this-host-name')
        ));
        self::assertNotEmpty($clamped);
        foreach ($clamped as $row) {
            self::assertLessThanOrEqual(39, Width::of($row), 'row must fit 39 cells: ' . $row);
        }
        self::assertStringContainsString('…', $output, 'the truncated row carries an ellipsis');

        [, $wide] = $this->drive("\r", $endpoints, cols: 200);
        self::assertStringNotContainsString('…', $wide);
        self::assertStringContainsString(
            'this-host-name-is-deliberately-longer',
            preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $wide)
        );
    }

    public function testChromeFollowsTheLocale(): void
    {
        // M4 (picker side): the frame strings are Lang::t lookups, so a
        // non-English locale redraws in that language. Restores 'en'
        // unconditionally — the suite shares one process-global locale.
        /** @var array<string,string> $de */
        $de = require \dirname(__DIR__) . '/lang/de.php';
        try {
            T::setLocale('de');
            [, $output] = $this->drive("\r", $this->threeHosts());
            self::assertStringContainsString($de['picker.filter'], $output);
            self::assertStringContainsString($de['picker.help'], $output);
            self::assertStringNotContainsString('↑/↓ select', $output);
        } finally {
            T::setLocale('en');
        }
    }
}
