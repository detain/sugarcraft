<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Tests\Support\PtyRun;

/**
 * S-X11 / candy-top-134: the startup stderr choke pinned BEHAVIOURALLY.
 *
 * The BinTest law is structural (one delegation, two carved-out literals);
 * this file is why the structure exists: hostile bytes planted in a copied
 * config value, and a config path that cannot be derived at all, must leave
 * the pre-TUI stream as readable text — never a live OSC (window title /
 * OSC 52 clipboard) and never a PHP stack trace (audit M6, m27).
 */
final class ReporterChokeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $why = PtyRun::unavailable();
        if ($why !== null) {
            $this->markTestSkipped($why);
        }
        $this->dir = sys_get_temp_dir() . '/ct-choke-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /**
     * M6: a copied-over btop.conf whose graph_symbol carries `ESC ] 0 ; ... BEL`
     * must not be able to set the terminal title. The warning still reaches the
     * user — named, sanitized — and the control bytes are gone.
     */
    public function testPlantedOscInAConfigValueNeverReachesTheTerminal(): void
    {
        $config = $this->dir . '/btop.conf';
        // Bytes built, not typed: the payload must be literal ESC/BEL.
        \file_put_contents($config, "#? v.1.4.7\ngraph_symbol = \"\x1b]0;PWNED\x07evil\"\n");

        $run = PtyRun::start(['--fake', '--config', $config]);

        $this->assertTrue(
            $run->readUntil('Invalid graph symbol identifier'),
            'the warning is reported at startup; got: ' . substr(json_encode($run->output()) ?: '', 0, 300),
        );
        // The alt screen proves startup continued past the warning onto the
        // paint path, so nothing was skipped over by an early fatal (m27 class).
        $this->assertTrue($run->readUntil("\x1b[?1049h"), 'the TUI boots after the sanitized warning');

        $run->signal(15); // SIGTERM — the clean-stop drive SignalExitTest pins.
        $this->assertNotNull($run->reap(), 'the child exits under a bounded wait');

        $out = $run->output();
        $this->assertStringNotContainsString("\x1b]0;PWNED", $out, 'no live OSC from the planted value');
        $this->assertStringNotContainsString("\x07", $out, 'no BEL on the stream');
        $this->assertStringNotContainsString('PWNED', $out, 'the payload token itself is not echoed');
    }

    /**
     * m27: with no derivable config path, ConfigFile::new throws. That has to
     * be a `candy-top: ` refusal with the bad-argument exit code 2 — not a
     * stack trace on the one stream the user is reading.
     */
    public function testUndervivableConfigPathIsASanitizedRefusalNotAFatal(): void
    {
        $run = PtyRun::start(['--fake'], [
            'HOME' => '',
            'XDG_CONFIG_HOME' => '',
            'XDG_CONFIG_DIRS' => '',
            'APPDATA' => '',
            'USERPROFILE' => '',
        ]);

        $this->assertTrue(
            $run->readUntil('candy-top: '),
            'the refusal arrives on the prefixed stream; got: ' . substr(json_encode($run->output()) ?: '', 0, 300),
        );
        $code = $run->reap();
        $this->assertSame(2, $code, 'a config-path refusal exits 2, like a bad argument');

        $out = $run->output();
        $this->assertStringNotContainsString('Stack trace', $out, 'no uncaught fatal on stderr');
        $this->assertStringNotContainsString("\x1b[?1049h", $out, 'the refusal answers before the alt screen');
    }
}
