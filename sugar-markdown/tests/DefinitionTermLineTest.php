<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;

/**
 * M20: a definition-list term used to glue to its description
 * ("TermA definition.") because renderDescriptionTerm emitted no line
 * break. The term now occupies its own line, matching the two-row shape
 * the README advertises.
 */
final class DefinitionTermLineTest extends TestCase
{
    public function testTermOccupiesItsOwnLine(): void
    {
        $out = Renderer::plain()->render("Term\n: A definition.\n");
        self::assertStringContainsString("Term\nA definition.", $out);
        self::assertStringNotContainsString('TermA definition', $out);
    }
}
