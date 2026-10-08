<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\ConfigWriter;
use SugarCraft\Top\Config\Schema;

/** btop Config::current_config layout. */
final class ConfigWriterTest extends TestCase
{
    public function testHeader(): void
    {
        $this->assertSame('Config file for candy-top (btop v.1.4.7 compatible)', ConfigWriter::header());
        $this->assertStringStartsWith("#? Config file for candy-top (btop v.1.4.7 compatible)\n\n", ConfigWriter::render(Config::new()));
    }

    public function testFormatPerType(): void
    {
        $this->assertSame('true', ConfigWriter::format(true));
        $this->assertSame('false', ConfigWriter::format(false));
        $this->assertSame('2000', ConfigWriter::format(2000));
        $this->assertSame('"cpu lazy"', ConfigWriter::format('cpu lazy'));
        $this->assertSame('""', ConfigWriter::format(''));
    }

    public function testBlockShapeMatchesBtop(): void
    {
        $out = ConfigWriter::render(Config::new());

        $this->assertStringContainsString(
            "\n#* Update time in milliseconds, recommended 2000 ms or above for better sample times for graphs.\nupdate_ms = 2000\n",
            $out,
        );
        $this->assertStringContainsString(
            "\n#* Default symbols to use for graph creation, \"braille\", \"block\" or \"tty\".\n#* \"braille\" offers the highest resolution",
            $out,
        );
        $this->assertStringContainsString("\nnet_upload = 100\n", $out);
        $this->assertStringContainsString("\n\nnet_upload = 100\n", $out, 'empty description → no comment lines');
        $this->assertStringContainsString("\nproc_sorting = \"cpu lazy\"\n", $out);
        $this->assertStringContainsString("\nrounded_corners = true\n", $out);
        $this->assertStringEndsWith("custom_gpu_name5 = \"\"\n", $out);
    }

    public function testEveryPersistedKeyWrittenOnceInOrderAndRuntimeKeysNever(): void
    {
        $out = ConfigWriter::render(Config::new());
        preg_match_all('/^([a-z_0-9]+) = /m', $out, $m);
        $this->assertSame(Schema::persistedNames(), $m[1]);
        $this->assertStringNotContainsString('tty_mode', $out);
        $this->assertStringNotContainsString('proc_filter =', $out);
    }

    public function testRendersCurrentValues(): void
    {
        $out = ConfigWriter::render(Config::new()->with('update_ms', 500)->with('vim_keys', true)->with('net_iface', 'eth0'));
        $this->assertStringContainsString("\nupdate_ms = 500\n", $out);
        $this->assertStringContainsString("\nvim_keys = true\n", $out);
        $this->assertStringContainsString("\nnet_iface = \"eth0\"\n", $out);
    }
}
