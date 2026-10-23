<?php

/**
 * Structural pin for the descriptor the viewer hands to the size
 * ioctl, and for the tty gate in front of it.
 *
 * The defect family this guards (recorded in candy-gifview's CALIBER
 * LEARNINGS): casting a PHP stream constant to int yields its RESOURCE ID,
 * not its file descriptor, and the resource ids of the std streams are not
 * their descriptors — a size query built on the cast asks the kernel about
 * the wrong stream and dies on a perfectly good terminal. This viewer must
 * keep spelling descriptor 1 as the literal behind a core stream_isatty
 * gate, exactly like the fixed sibling.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;

final class SizeQueryDescriptorTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-image';
    private const CLI = __DIR__ . '/../src/Cli.php';

    /** @return array<string, string> label => source text */
    private static function scanTargets(): array
    {
        $bin = file_get_contents(self::BIN);
        self::assertIsString($bin, 'the bin must exist next to the app');
        $cli = file_get_contents(self::CLI);
        self::assertIsString($cli, 'Cli.php must exist');

        return ['bin/candy-image' => $bin, 'src/Cli.php' => $cli];
    }

    public function testTheSizeQueryIsLiteralDescriptorOne(): void
    {
        $cli = (self::scanTargets())['src/Cli.php'];

        $this->assertStringContainsString('SizeIoctl::query(1)', $cli);
        $this->assertStringNotContainsString('query((int)', $cli, 'the cast shape names the wrong stream');
        $this->assertStringNotContainsString('posix_isatty', $cli, 'the gate stays core stream_isatty — no ext-posix symbol in the app');
    }

    public function testTheTtyGateWrapsTheQuery(): void
    {
        $cli = (self::scanTargets())['src/Cli.php'];

        $this->assertMatchesRegularExpression(
            '/stream_isatty\(STDOUT\)[\s\S]{0,120}SizeIoctl::query\(1\)/',
            $cli,
            'the query must sit inside the stream_isatty(STDOUT) branch',
        );
    }

    public function testTheBinDelegatesEverythingToCli(): void
    {
        $bin = (self::scanTargets())['bin/candy-image'];

        $this->assertStringContainsString('SugarCraft\\CandyImage\\Cli::main($argv ?? [], STDOUT, STDERR)', $bin);
        $this->assertStringNotContainsString('fromFile', $bin, 'the bin stays a shim: no logic outside Cli');
        $this->assertStringContainsString('cannot find composer autoload.php', $bin);
    }
}
