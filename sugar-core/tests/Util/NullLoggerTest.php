<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\NullLogger;

/**
 * First tests for {@see NullLogger} (finding sugar-core-067 leg: utility
 * classes shipped with zero tests). The contract under test is the no-op
 * itself: every method answers void, emits nothing, and refuses nothing —
 * a logger callers may hand to any code path without arming output.
 *
 * The deliberate non-contract is PSR-3: the class docblock names the
 * reason (psr/log stays out of the dependency set), so this test pins
 * the duck-typed shape that consumers actually rely on — same method
 * names, same accepting signature — without asserting an interface the
 * lib chose not to implement.
 */
final class NullLoggerTest extends TestCase
{
    public function testTheClassIsFinalWithNoDependencies(): void
    {
        // Plain `new`, not the value-object `::new()` factory: NullLogger
        // carries no promoted state to construct, and the lib does not
        // invent factories where upstream (bubbletea's internal no-op
        // logger idiom) has none.
        $reflection = new \ReflectionClass(new NullLogger());
        $this->assertTrue($reflection->isFinal());
        $this->assertSame([], $reflection->getInterfaceNames(), 'PSR-3 stays out by design: no psr/log dependency (src/Util/NullLogger.php docblock)');
    }

    /**
     * Every severity method plus log() must exist with the accepting
     * signature; a renamed or dropped method would fatal the callers
     * that wire this in place of a real logger.
     */
    public function testTheFullDuckTypedSurfaceExists(): void
    {
        $logger = new NullLogger();
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            $this->assertTrue(method_exists($logger, $method), "NullLogger::$method() must exist — callers pass this where a PSR-3-shaped logger is expected");
        }
    }

    public function testEveryMethodIsSilentVoidForEveryMessageShape(): void
    {
        $logger  = new NullLogger();
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable-payload';
            }
        };

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            \ob_start();
            $this->assertNull($logger->$method('plain', ['k' => 'v']));
            $this->assertNull($logger->$method($message, []));
            $this->assertSame('', \ob_get_clean(), "NullLogger::$method() must emit nothing");
        }

        \ob_start();
        $this->assertNull($logger->log('anything-even-non-string', 'mixed level is accepted', ['context' => 1]));
        $this->assertNull($logger->log(7, 'numeric level is accepted'));
        $this->assertSame('', \ob_get_clean(), 'NullLogger::log() must emit nothing');
    }
}
