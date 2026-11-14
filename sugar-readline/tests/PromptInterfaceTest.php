<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\ConfirmationPrompt;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\PromptInterface;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\SizingInterface;
use SugarCraft\Readline\Tests\Support\RepaintDriver;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\TextareaPrompt;

/**
 * The prompt contract seam (finding 038): the pump's required surface is
 * named in one interface, every shipped prompt implements it, and a
 * partial object raises at the boundary instead of failing silently
 * inside the loop.
 */
final class PromptInterfaceTest extends TestCase
{
    public function testAllShippedPromptsImplementBothInterfaces(): void
    {
        foreach (self::prompts() as $prompt) {
            self::assertInstanceOf(PromptInterface::class, $prompt);
            self::assertInstanceOf(SizingInterface::class, $prompt);
        }
    }

    public function testInterfaceMethodSetsArePinned(): void
    {
        $names = static fn (string $interface): array =>
            array_map(
                static fn (\ReflectionMethod $m): string => $m->getName(),
                (new \ReflectionClass($interface))->getMethods()
            );
        self::assertSame(
            ['view', 'handleKey', 'isSubmitted', 'isAborted'],
            $names(PromptInterface::class),
            'the required set is the pump-driven surface, nothing more'
        );
        self::assertSame(
            ['displayWidth', 'withDisplayWidth'],
            $names(SizingInterface::class)
        );
    }

    /** @return array<string, object> */
    public static function partialPromptProvider(): array
    {
        return [
            'missing view' => [
                new class {
                    public function handleKey(string $key): self { return $this; }
                    public function isSubmitted(): bool { return true; }
                    public function isAborted(): bool { return false; }
                },
                'view',
            ],
            'missing handleKey' => [
                new class {
                    public function view(): string { return 'x'; }
                    public function isSubmitted(): bool { return true; }
                    public function isAborted(): bool { return false; }
                },
                'handleKey',
            ],
            'missing isSubmitted' => [
                new class {
                    public function view(): string { return 'x'; }
                    public function handleKey(string $key): self { return $this; }
                    public function isAborted(): bool { return false; }
                },
                'isSubmitted',
            ],
            'missing isAborted' => [
                new class {
                    public function view(): string { return 'x'; }
                    public function handleKey(string $key): self { return $this; }
                    public function isSubmitted(): bool { return true; }
                },
                'isAborted',
            ],
        ];
    }

    /**
     * Each formerly-silent failure mode now throws AT the boundary, naming
     * the missing method: no blind paint, no dropped controls, no
     * idling-forever EOF arm.
     */
    public function testPartialPromptsThrowNamingTheMissingMethod(): void
    {
        foreach (self::partialPromptProvider() as $label => [$prompt, $missing]) {
            try {
                (new Readline())->run($prompt);
                self::fail($label . ' reached the loop instead of throwing');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('missing: ' . $missing, $e->getMessage(), $label);
            }
        }
    }

    public function testMissingEverythingNamesTheFullList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing: view, handleKey, isSubmitted, isAborted');
        (new Readline())->run(new \stdClass());
    }

    public function testConformingPromptStillDrivesEndToEnd(): void
    {
        $captured = RepaintDriver::capture(new TextPrompt('N'), "a\r");
        self::assertStringContainsString("\x1b[0J", $captured, 'boundary check did not disturb real prompts');
    }

    /** @return list<object> */
    private static function prompts(): array
    {
        return [
            new TextPrompt('t'),
            new TextareaPrompt('t'),
            new ConfirmationPrompt('t'),
            new SelectionPrompt('t', ['x']),
            new MultiSelectPrompt('t', ['x']),
        ];
    }
}
