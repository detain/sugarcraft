<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
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

    /**
     * CC3 lockstep flip: Symfony's inherited unique-prefix resolution used to
     * EXECUTE `choos` as choose — silently running a prompt the user never
     * named. Exact-only resolution refuses with a did-you-mean instead.
     */
    public function testFind_truncatedNameIsRefusedWithSuggestion(): void
    {
        $app = new Application();

        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessage('Did you mean "choose"?');

        $app->find('choos');
    }

    public function testFind_ambiguousPrefixListsEveryCandidate(): void
    {
        $app = new Application();

        // The CC3 misdirect case: old code answered `co` with the single
        // structurally-nearest 3-letter command ("Did you mean log?").
        // Now every plausible candidate is listed, prefix matches first.
        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessage('one of: "completion", "confirm", "log"');

        $app->find('co');
    }

    public function testFind_prefixTypoDoesNotResolveToAnyCommand(): void
    {
        $app = new Application();
        foreach (['choos', 'confir', 'styl', 'tabl', 'inpu'] as $typo) {
            try {
                $app->find($typo);
                self::fail("$typo must not resolve (CC3)");
            } catch (CommandNotFoundException) {
                // expected: refusal, never execution
            }
        }
        $this->addToAssertionCount(5);
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
