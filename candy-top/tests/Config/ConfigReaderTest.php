<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\ConfigReader;
use SugarCraft\Top\Config\ConfigWriter;

/** btop Config::load semantics, line-based. */
final class ConfigReaderTest extends TestCase
{
    private static function header(): string
    {
        return '#? ' . ConfigWriter::header() . "\n";
    }

    public function testParsesEachType(): void
    {
        $result = ConfigReader::parse(self::header() . <<<'CONF'
            update_ms = 1500
            vim_keys = True
            color_theme = "nord"
            clock_format = "%H:%M:%S /host"
            shown_boxes="cpu proc"
            CONF);

        $this->assertSame([], $result->warnings);
        $this->assertFalse($result->needsRewrite);
        $this->assertSame(1500, $result->config->updateMs());
        $this->assertTrue($result->config->bool('vim_keys'));
        $this->assertSame('nord', $result->config->colorTheme());
        $this->assertSame('%H:%M:%S /host', $result->config->clockFormat());
        $this->assertSame(['cpu', 'proc'], $result->config->shownBoxes());
    }

    public function testCommentsBlankLinesAndIndentationAreSkipped(): void
    {
        $result = ConfigReader::parse("#* comment\n\n   # indented comment\n\t  io_mode   =   true   # trailing\n");
        $this->assertTrue($result->config->bool('io_mode'));
        $this->assertSame([], $result->warnings);
    }

    public function testUnknownKeysAndRuntimeKeysAreIgnoredSilently(): void
    {
        $result = ConfigReader::parse(self::header() . "future_option = 7\ntty_mode = true\nproc_filter = \"x\"\nno equals sign here\n");
        $this->assertSame([], $result->warnings);
        $this->assertFalse($result->config->ttyMode());
        $this->assertSame('', $result->config->string('proc_filter'));
        $this->assertSame(Config::new()->toArray(), $result->config->toArray());
    }

    public function testInvalidValuesKeepDefaultAndWarn(): void
    {
        $result = ConfigReader::parse(self::header() . <<<'CONF'
            update_ms = 50
            vim_keys = yes
            graph_symbol = "dots"
            net_download = abc
            presets = "cpu:9:default"
            proc_sorting = "program"
            temp_scale = "kelvin"
            CONF);

        $this->assertSame([
            'Config value update_ms set too low (<100).',
            'Got an invalid bool value for config name: vim_keys',
            'Invalid graph symbol identifier: dots',
            'Got an invalid integer value for config name: net_download',
            'Invalid position value in config value presets!',
            'Invalid value for proc_sorting: program',
        ], $result->warnings);
        $this->assertTrue($result->needsRewrite);

        $config = $result->config;
        $this->assertSame(2000, $config->updateMs());
        $this->assertFalse($config->bool('vim_keys'));
        $this->assertSame('braille', $config->graphSymbol());
        $this->assertSame(100, $config->int('net_download'));
        $this->assertSame('cpu lazy', $config->procSorting());
        $this->assertSame('kelvin', $config->tempScale(), 'valid lines still apply');
    }

    public function testBoolAndIntTakeFirstTokenOnly(): void
    {
        $result = ConfigReader::parse(self::header() . "update_ms = 3000 extra\nnet_auto = False junk\n");
        $this->assertSame(3000, $result->config->updateMs());
        $this->assertFalse($result->config->bool('net_auto'));
    }

    public function testUnquotedStringTakesFirstToken(): void
    {
        $result = ConfigReader::parse(self::header() . "color_theme = gruvbox_dark trailing\n");
        $this->assertSame('gruvbox_dark', $result->config->colorTheme());
    }

    public function testQuotedStringStopsAtClosingQuoteAndUnterminatedEndsAtLine(): void
    {
        $result = ConfigReader::parse(self::header() . "custom_cpu_name = \"My CPU\" ignored\nnet_iface = \"eth0   \n");
        $this->assertSame('My CPU', $result->config->string('custom_cpu_name'));
        $this->assertSame('eth0', $result->config->string('net_iface'));
    }

    public function testEmptyQuotedStringIsValid(): void
    {
        $result = ConfigReader::parse(self::header() . "clock_format = \"\"\n");
        $this->assertSame('', $result->config->clockFormat());
        $this->assertSame([], $result->warnings);
    }

    public function testLaterDuplicateWins(): void
    {
        $result = ConfigReader::parse(self::header() . "update_ms = 1000\nupdate_ms = 4000\n");
        $this->assertSame(4000, $result->config->updateMs());
    }

    public function testParsesOverBase(): void
    {
        $base = Config::new()->with('vim_keys', true);
        $result = ConfigReader::parse(self::header() . "update_ms = 1000\n", $base);
        $this->assertTrue($result->config->bool('vim_keys'));
        $this->assertSame(1000, $result->config->updateMs());
    }

    public function testShownBoxesIsAcceptedVerbatimAtLoadLikeBtop(): void
    {
        $result = ConfigReader::parse(self::header() . "shown_boxes = \"\"\n");
        $this->assertSame([], $result->warnings);
        $this->assertSame([], $result->config->shownBoxes());

        $result = ConfigReader::parse(self::header() . "shown_boxes = \"cpu disk gpu3\"\n");
        $this->assertSame([], $result->warnings);
        $this->assertSame(['cpu', 'disk', 'gpu3'], $result->config->shownBoxes(), 'settled later by the app');
    }

    public function testHeaderVersionMarker(): void
    {
        $this->assertTrue(ConfigReader::isCurrentHeader('#? Config file for btop v.1.4.7'));
        $this->assertTrue(ConfigReader::isCurrentHeader('#? Config file for btop v. 1.4.7'));
        $this->assertTrue(ConfigReader::isCurrentHeader('#? ' . ConfigWriter::header()));
        $this->assertFalse(ConfigReader::isCurrentHeader('#? Config file for btop v. 1.3.0'));
        $this->assertFalse(ConfigReader::isCurrentHeader('#? Config file for btop v.1.4.70'));
        $this->assertFalse(ConfigReader::isCurrentHeader('# Config file for btop v.1.4.7'), 'needs the #? marker');
        $this->assertFalse(ConfigReader::isCurrentHeader('update_ms = 2000'));
    }

    public function testHeaderIsLocaleIndependent(): void
    {
        T::setLocale('fr');
        try {
            $this->assertSame('Config file for candy-top (btop v.1.4.7 compatible)', ConfigWriter::header());
            $this->assertFalse(ConfigReader::parse(ConfigWriter::render(Config::new()))->needsRewrite);
        } finally {
            T::setLocale('en');
        }
    }

    public function testBtop147FileIsCurrent(): void
    {
        $this->assertFalse(ConfigReader::parse("#? Config file for btop v.1.4.7\nupdate_ms = 1000\n")->needsRewrite);
    }

    public function testForeignHeaderRequestsRewrite(): void
    {
        $this->assertTrue(ConfigReader::parse("#? Config file for btop v. 1.3.0\nupdate_ms = 1000\n")->needsRewrite);
        $this->assertTrue(ConfigReader::parse('')->needsRewrite);
        $this->assertFalse(ConfigReader::parse(self::header())->needsRewrite);
    }

    public function testCrlfLineEndings(): void
    {
        $result = ConfigReader::parse("#? x\r\nupdate_ms = 1200\r\ncolor_theme = \"nord\"\r\n");
        $this->assertSame(1200, $result->config->updateMs());
        $this->assertSame('nord', $result->config->colorTheme());
    }

    public function testRealBtopConfigLoadsCleanly(): void
    {
        // An unmodified btop 1.3.0 config.conf — capitalised True/False,
        // its own header, every value its default.
        $result = ConfigReader::parse((string) file_get_contents(__DIR__ . '/fixtures/btop-1.3.0.conf'));
        $this->assertSame([], $result->warnings);
        $this->assertTrue($result->needsRewrite, 'foreign header');
        $this->assertSame(Config::new()->toArray(), $result->config->toArray());
    }

    public function testWriterOutputRoundTrips(): void
    {
        $config = Config::new()
            ->with('update_ms', 750)
            ->with('vim_keys', true)
            ->with('presets', 'cpu:0:tty proc:1:block,mem:0:default')
            ->with('clock_format', '')
            ->with('disks_filter', 'exclude=/boot /home/user')
            ->with('io_graph_speeds', '/mnt/media:100 /:20')
            ->with('shown_boxes', 'cpu gpu0 proc')
            ->with('tty_mode', true);

        $result = ConfigReader::parse(ConfigWriter::render($config));

        $this->assertSame([], $result->warnings);
        $this->assertFalse($result->needsRewrite);
        $expected = $config->with('tty_mode', false)->toArray();
        $this->assertSame($expected, $result->config->toArray(), 'runtime tty_mode is not persisted');
    }
}
