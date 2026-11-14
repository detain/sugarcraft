<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\DebugHandler as LibDebugHandler;

/**
 * House façade law: the folded DebugHandler fork gets ONE alias smoke test,
 * not a copied suite (the ring semantics themselves are pinned in
 * sugar-ansi-parser's DebugHandlerTest).
 */
final class DebugHandlerFacadeTest extends TestCase
{
    public function testOldNameIsTheSameClass(): void
    {
        // class_alias targets are invisible to static analysis, so the
        // façade is spelled as a runtime string and asserted into its type.
        $legacyName = 'SugarCraft\Vterm\Parser\DebugHandler';
        $this->assertTrue(\class_exists($legacyName));

        $handler = new $legacyName();
        $this->assertInstanceOf(LibDebugHandler::class, $handler);
        $this->assertSame(LibDebugHandler::class, \get_class($handler));

        $handler->execute(0x07);
        $this->assertSame([['type' => 'execute', 'detail' => 7]], $handler->filter('execute'));
    }
}
