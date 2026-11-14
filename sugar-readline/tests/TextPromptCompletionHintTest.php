<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * Finding 085: Tab on an EMPTY buffer used to commit candidates[0]
 * wholesale (str_starts_with filters everything, the full-set LCP is ''),
 * inserting an arbitrary first match with no menu. The cure arms a bounded
 * preview row instead; the next Tab begins cycling the ring.
 */
final class TextPromptCompletionHintTest extends TestCase
{
    /** @var list<string> */
    private const CANDIDATES = ['git add', 'git commit', 'git push', 'ls', 'make', 'npm'];

    public function testTabOnEmptyArmsPreviewWithoutCommitting(): void
    {
        $p = TextPrompt::new('> ')->withCompletions(self::CANDIDATES)
            ->handleKey(Key::Tab);

        $this->assertSame('', $p->value());

        $view = $p->view();
        // Bounded: first four names plus the overflow count.
        $this->assertStringContainsString('git add', $view);
        $this->assertStringContainsString('ls', $view);
        $this->assertStringContainsString('+2', $view);
        $this->assertStringNotContainsString('make', $view);
        $this->assertStringNotContainsString('npm', $view);
    }

    public function testSecondTabBeginsCycling(): void
    {
        $armed = TextPrompt::new('> ')->withCompletions(self::CANDIDATES)
            ->handleKey(Key::Tab);

        $first = $armed->handleKey(Key::Tab);
        $this->assertSame('git add', $first->value());
        $this->assertStringNotContainsString('+2', $first->view());

        // From here the existing ring arm walks the candidates.
        $second = $first->handleKey(Key::Tab);
        $this->assertSame('git commit', $second->value());
    }

    public function testNonEmptyPrefixPathsAreUnchanged(): void
    {
        // Classic LCP completion still engages for a real prefix...
        $p = TextPrompt::new('> ')->withCompletions(['git add', 'git commit'])
            ->handleChar('g')->handleKey(Key::Tab);
        $this->assertSame('git ', $p->value());

        // ...and the from-prefix second Tab still commits the first match.
        $p = $p->handleKey(Key::Tab);
        $this->assertSame('git add', $p->value());
    }

    public function testPreviewRepaintsAfterEditReturnsToEmptyBuffer(): void
    {
        $armed = TextPrompt::new('> ')->withCompletions(self::CANDIDATES)
            ->handleKey(Key::Tab);

        $typed = $armed->handleChar('x');
        $this->assertStringNotContainsString('+2', $typed->view());

        $back = $typed->handleKey(Key::Backspace); // back to empty
        $this->assertSame('', $back->value());
        $this->assertStringContainsString('+2', $back->view());

        // The ring is still armed, so one more Tab advances (no re-commit wart).
        $advanced = $back->handleKey(Key::Tab);
        $this->assertSame('git add', $advanced->value());
    }

    public function testHiddenPromptKeepsPlainCommitAndNeverPaintsThePreview(): void
    {
        // A hidden prompt cannot paint the bounded preview, so Tab keeps the
        // plain commit rather than swallowing a keystroke invisibly.
        $p = TextPrompt::new('> ')->withCompletions(self::CANDIDATES)
            ->withHidden(true)->handleKey(Key::Tab);

        $this->assertSame('git add', $p->value());
        $this->assertStringNotContainsString('+2', $p->view());
    }

    public function testSwappingCompletionsClearsTheArmedPreview(): void
    {
        $armed = TextPrompt::new('> ')->withCompletions(self::CANDIDATES)
            ->handleKey(Key::Tab);

        $swapped = $armed->withCompletions(['ssh', 'ftp']);
        $this->assertStringNotContainsString('+2', $swapped->view());

        // One Tab arms the NEW set's preview; it never lands on stale rows.
        $retabbed = $swapped->handleKey(Key::Tab);
        $this->assertSame('', $retabbed->value());
        $this->assertStringContainsString('ssh', $retabbed->view());
        $this->assertStringContainsString('ftp', $retabbed->view());
    }
}
