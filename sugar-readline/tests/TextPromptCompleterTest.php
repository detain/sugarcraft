<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * Finding 089: completions were a static list only, so dynamic sources
 * required rebuilding the whole array AND the prompt on every change, and
 * the prefix filter ran against the full buffer (path completion became
 * caller-sliced work). withCompleter() is the lazy per-Tab seam.
 */
final class TextPromptCompleterTest extends TestCase
{
    public function testCompleterIsConsultedOncePerTabWithExactArgs(): void
    {
        $calls = [];
        $completer = function (string $buffer, int $cursor) use (&$calls): array {
            $calls[] = [$buffer, $cursor];
            return ['alpha', 'beta'];
        };

        $p = TextPrompt::new('> ')->withCompleter($completer);

        $armed = $p->handleKey(Key::Tab);   // empty buffer: arms preview
        $this->assertSame('', $armed->value());

        $advanced = $armed->handleKey(Key::Tab);
        $this->assertSame('alpha', $advanced->value());

        // Exactly one call per Tab, with the live buffer and caret.
        $this->assertSame([['', 0], ['', 0]], $calls);
    }

    public function testCompleterReceivesMidRowBufferAndCursor(): void
    {
        $seen = null;
        $completer = function (string $buffer, int $cursor) use (&$seen): array {
            $seen = [$buffer, $cursor];
            return ['cat /home/ada', 'cat /home/bob'];
        };

        $p = TextPrompt::new('> ')->withCompleter($completer);
        foreach (\str_split('cat /hom') as $ch) {
            $p = $p->handleChar($ch);
        }

        $completed = $p->handleKey(Key::Tab);
        $this->assertSame(['cat /hom', 8], $seen);
        // The answer skips the outer prefix filter: the shared segment of
        // the FULL returned paths completes straight into the buffer.
        $this->assertSame('cat /home/', $completed->value());
    }

    public function testListModeBehaviorIsUnchangedWithoutCompleter(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(['a1', 'a2'])
            ->handleChar('a');

        $completed = $p->handleKey(Key::Tab);
        $this->assertSame('a1', $completed->value());
    }

    public function testCompleterIsLazyBetweenTabsNoPromptRebuild(): void
    {
        $source = ['one', 'two'];
        $completer = function (string $buffer, int $cursor) use (&$source): array {
            return $source;
        };

        $p = TextPrompt::new('> ')
            ->withCompletions(['stale', 'list'])
            ->withCompleter($completer);

        $armed = $p->handleKey(Key::Tab);
        $this->assertStringContainsString('one', $armed->view());

        // The host mutates ITS array; the next Tab sees it — no rebuild.
        $source = ['zulu', 'yankee'];
        $advanced = $armed->handleKey(Key::Tab);
        $this->assertSame('zulu', $advanced->value());
    }

    public function testCompleterReturningEmptyLeavesPromptAlone(): void
    {
        $completer = static fn(string $buffer, int $cursor): array => [];

        $p = TextPrompt::new('> ')->withCompletions(['xy', 'xz'])
            ->withCompleter($completer)
            ->handleChar('q');

        $this->assertSame($p, $p->handleKey(Key::Tab));
    }

    public function testWithCompleterNullRestoresTheListFallback(): void
    {
        $noise = static fn(string $buffer, int $cursor): array => ['never'];

        $p = TextPrompt::new('> ')
            ->withCompleter($noise)
            ->withCompleter(null)
            ->withCompletions(['a1', 'a2'])
            ->handleChar('a');

        $completed = $p->handleKey(Key::Tab);
        $this->assertSame('a1', $completed->value());
    }
}
