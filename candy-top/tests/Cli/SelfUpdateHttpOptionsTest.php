<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Cli\SelfUpdate;

/**
 * S-X09 pins for the self-update client's TIME BOUNDS: the E646 ceilings
 * are per-operation (connect + read), http_errors stays off (the suite's
 * fake server asserts its own answers), and the non-curl connect bridge
 * only exists where the handler would otherwise ignore connect_timeout.
 */
final class SelfUpdateHttpOptionsTest extends TestCase
{
    public function testPerOperationCeilingsAndSilentHttpErrors(): void
    {
        $options = SelfUpdate::httpOptionsForTesting();

        // Literal values on purpose: a silent constant change must go red here.
        $this->assertSame(5.0, $options['connect_timeout']);
        $this->assertSame(60.0, $options['read_timeout']);
        $this->assertFalse($options['http_errors']);
        $this->assertSame('candy-top-self-update', $options['headers']['User-Agent']);
    }

    public function testTotalRequestClockFollowsTheHandlerOnly(): void
    {
        $options = SelfUpdate::httpOptionsForTesting();

        if (\extension_loaded('curl')) {
            // With the curl handler 'timeout' IS a total-request clock —
            // E646 forbids it here (slow-but-live phar downloads are legal).
            $this->assertArrayNotHasKey('timeout', $options);

            return;
        }

        // Without curl it only bounds the socket open; StreamHandler's
        // stream_set_timeout(read_timeout) still re-bounds every read.
        $this->assertSame(5.0, $options['timeout']);
    }
}
