<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;
use PHPUnit\Framework\TestCase;

/**
 * Capability awareness (-11): the palette and the addressing switches
 * follow the fleet color authority instead of steamrolling NO_COLOR and
 * TERM=dumb. Every drive pins the FULL byte shape: what must vanish and
 * what must survive on each capability rung.
 */
final class PickerColorProfileTest extends TestCase
{
    private const CAPABILITY_ENV = ['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR', 'TERM', 'COLORTERM', 'TERM_PROGRAM', 'CI', 'GITHUB_ACTIONS'];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        foreach (self::CAPABILITY_ENV as $name) {
            $this->envSnapshot[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CAPABILITY_ENV as $name) {
            $value = $this->envSnapshot[$name];
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }

    /**
     * @return array{0:?Endpoint, 1:string}
     */
    private function drive(string $keys): array
    {
        $in  = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, $keys);
        rewind($in);

        $p = new class ($in, $out) extends Picker {
            /** @param unused */
            protected function setRawMode(bool $_on): void { /* noop in tests */ }
        };

        $picked = $p->pick([
            new Endpoint(name: 'prod', host: 'prod.example.com'),
            new Endpoint(name: 'dev',  host: 'dev.example.com'),
        ]);
        rewind($out);

        return [$picked, (string) stream_get_contents($out)];
    }

    public function testDefaultCapabilityKeepsTheHistoricalRichFrame(): void
    {
        putenv('TERM=xterm-256color');
        foreach (['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR'] as $off) {
            putenv($off);
        }

        [$picked, $out] = $this->drive("\r");
        $this->assertNotNull($picked);
        $this->assertStringContainsString("\x1b[?1049h", $out, 'alternate buffer on an addressing terminal');
        $this->assertStringContainsString("\x1b[?1049l", $out);
        $this->assertStringContainsString("\x1b[?2004h", $out);
        $this->assertStringContainsString("\x1b[1;36m▸\x1b[0m ", $out, 'colored marker survives');
        $this->assertStringContainsString("\x1b[36m", $out, 'filter echo keeps its color');
        $this->assertStringContainsString("\x1b[1;1H\x1b[0J", $out, 'redraw addressing survives');
    }

    public function testNoColorDropsEverySgrButKeepsLayoutAndAddressing(): void
    {
        putenv('TERM=xterm-256color');
        putenv('NO_COLOR=1');

        [$picked, $out] = $this->drive("\r");
        $this->assertNotNull($picked);
        $this->assertStringNotContainsString("\x1b[36m", $out);
        $this->assertStringNotContainsString("\x1b[1;36m", $out);
        $this->assertStringNotContainsString("\x1b[90m", $out);
        $this->assertStringNotContainsString("\x1b[0m", $out);
        $this->assertStringContainsString('▸ ', $out, 'the marker glyph itself survives color-off');
        // NO_COLOR is a COLOR opt-out, not a device one: addressing stays.
        $this->assertStringContainsString("\x1b[?1049h", $out);
        $this->assertStringContainsString("\x1b[1;1H\x1b[0J", $out);
    }

    public function testClicolorZeroBehavesLikeNoColor(): void
    {
        putenv('TERM=xterm-256color');
        putenv('CLICOLOR=0');

        [, $out] = $this->drive("\r");
        $this->assertStringNotContainsString("\x1b[36m", $out);
        $this->assertStringContainsString('▸ ', $out);
        $this->assertStringContainsString("\x1b[?1049h", $out);
    }

    public function testDumbTerminalPrintsPlainSinglePassFrames(): void
    {
        putenv('TERM=dumb');
        foreach (['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR'] as $off) {
            putenv($off);
        }

        [$picked, $out] = $this->drive('prod' . "\r");
        $this->assertNotNull($picked, 'even the dumb rung must complete a session');
        $this->assertStringNotContainsString("\x1b[?1049h", $out, 'no buffer switches on a device that cannot honor them');
        $this->assertStringNotContainsString("\x1b[?2004h", $out);
        $this->assertStringNotContainsString("\x1b[1;1H", $out, 'no cursor addressing');
        $this->assertStringNotContainsString("\x1b[0J", $out);
        $this->assertStringNotContainsString("\x1b[36m", $out);
        $this->assertStringContainsString('prod', $out, 'the rows themselves still print');
        $this->assertStringContainsString('▸ ', $out);
    }

    public function testForceColorOverrulesTheDumbRung(): void
    {
        // Mirrors the upstream ladder: TERM=dumb is NoTTY "unless
        // CLICOLOR_FORCE=1" — a forced log pipeline gets the rich frame.
        putenv('TERM=dumb');
        putenv('CLICOLOR_FORCE=1');
        putenv('NO_COLOR');

        [, $out] = $this->drive("\r");
        $this->assertStringContainsString("\x1b[?1049h", $out);
        $this->assertStringContainsString("\x1b[1;36m▸\x1b[0m ", $out);
    }
}
