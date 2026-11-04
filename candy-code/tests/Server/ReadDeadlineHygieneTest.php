<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;

/**
 * The read-deadline middleware (ISS-055) arms a timer and hangs a `close`
 * listener off the request body to stop it early. Settling must retract
 * BOTH (CWPD-20): a request that finished through the handler previously
 * left its stop-closure on the body stream for the stream's whole life,
 * retaining the timer slot, the request, and the promise chain behind it.
 */
final class ReadDeadlineHygieneTest extends TestCase
{
    public function testTheReadDeadlineMiddlewareDetachesItsCloseListenerWhenItSettles(): void
    {
        $source = \file_get_contents(__DIR__ . '/../../src/Server/Server.php');
        $this->assertIsString($source);

        $start = \strpos($source, 'private function readDeadline(');
        $this->assertNotFalse($start, 'the read-deadline middleware must exist to be policed');
        // Slice from the signature to the middleware's own end: the next
        // indented method declaration in the file, or the end of the file.
        // (A bare 'function ' would also match the closures inside the body.)
        $after = \substr($source, $start + \strlen('private function readDeadline('));
        $hasNext = \preg_match('/^    (?:private|public|protected) function /m', "\n" . $after, $ignored, \PREG_OFFSET_CAPTURE);
        $method = $hasNext === 1
            ? \substr($source, $start, $ignored[0][1])
            : \substr($source, $start);

        // The listener must be removed — and removed from inside the same
        // stop-closure every settle path already calls, so no path can
        // forget one half of the cleanup.
        $this->assertStringContainsString("\$body->removeListener('close', \$stopTimer)", $method);
        $stopOpens = \strpos($method, '$stopTimer = function () use (&$timer, $body, &$stopTimer)');
        $this->assertNotFalse($stopOpens, 'the stop-closure must capture the body and itself so it can retract');
        $detach = \strpos($method, "\$body->removeListener('close', \$stopTimer)");
        $this->assertGreaterThan($stopOpens, $detach);
        $arm = \strpos($method, "\$body->on('close', \$stopTimer)");
        $this->assertNotFalse($arm);
        $this->assertGreaterThan($detach, $arm, 'the detach must be defined before the listener is armed');
    }
}
