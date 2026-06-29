<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Tests for Application::find() typo-suggestion integration.
 *
 * Note: Symfony Console has its own built-in fuzzy matching that resolves
 * minor typos (e.g. 'choos' → 'choose') before our TypoSuggester runs.
 * Our typo-suggestion code only fires for truly unknown commands where
 * Symfony's fuzzy matching finds no close match.
 */
final class ApplicationFindTest extends TestCase
{
    public function testFind_exactMatchReturnsCommand(): void
    {
        $app = new Application();

        $command = $app->find('style');
        $this->assertSame('style', $command->getName());
    }

    public function testFind_typoWithSymfonyMatchReturnsCommand(): void
    {
        $app = new Application();

        // 'choos' is close to 'choose' (Levenshtein distance 1) so Symfony's
        // built-in fuzzy matching finds it without throwing.
        $command = $app->find('choos');
        $this->assertSame('choose', $command->getName());
    }

    public function testFind_unknownCommandThrowsCommandNotFoundException(): void
    {
        $app = new Application();

        $this->expectException(CommandNotFoundException::class);
        $app->find('xyzzy');
    }

    public function testFind_unknownCommandExceptionMessage(): void
    {
        $app = new Application();

        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessage('xyzzy');
        $app->find('xyzzy');
    }
}
