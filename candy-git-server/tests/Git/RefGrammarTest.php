<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Git\RefGrammar;

/**
 * Pins for the shared refname grammar gate (review M-21): client-controlled
 * ref names must never reach git update-ref unchecked — a leading '-' is an
 * OPTION to update-ref, and '..' addresses files outside the refs tree.
 *
 * @covers \SugarCraft\GitServer\Git\RefGrammar
 */
final class RefGrammarTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function refNameProvider(): array
    {
        return [
            'plain branch'              => ['refs/heads/master', true],
            'nested branch'             => ['refs/heads/feature/foo-bar_1.2', true],
            'tag'                       => ['refs/tags/v1.0', true],
            'notes namespace'           => ['refs/notes/commits', true],
            'head literal'              => ['HEAD', true],
            'dash inside components ok' => ['refs/heads/-leading-after-refs', true],

            'empty'                     => ['', false],
            'bare option'               => ['-d', false],
            'leading dash deep'         => ['-refs/heads/x', false],
            'dot-dot traversal'         => ['refs/heads/../../etc', false],
            'dot-dot anywhere'          => ['refs/heads/a..b', false],
            'space'                     => ['refs/heads/a b', false],
            'newline smuggled'          => ["refs/heads/a\nb", false],
            'nul smuggled'              => ["refs/heads/a\x00b", false],
            'reflog syntax'             => ['refs/heads/x@{0}', false],
            'tilde'                     => ['refs/heads/x~1', false],
            'caret'                     => ['refs/heads/x^', false],
            'colon'                     => ['refs/heads/x:y', false],
            'question'                  => ['refs/heads/x?', false],
            'asterisk'                  => ['refs/heads/x*', false],
            'bracket'                   => ['refs/heads/x[', false],
            'backslash'                 => ['refs/heads/x\\y', false],
            'trailing slash'            => ['refs/heads/x/', false],
            'double slash'              => ['refs/heads//x', false],
            'trailing dot'              => ['refs/heads/x.', false],
            'hidden component'          => ['refs/heads/.hidden', false],
            'lock suffix'               => ['refs/heads/x.lock', false],
            'lock suffix deep'          => ['refs/heads/sub.lock/x', false],
            'missing refs namespace'    => ['heads/master', false],
            'bare refs'                 => ['refs', false],
            'wrong case namespace'      => ['REFS/HEADS/X', false],
            'over length cap'           => ['refs/heads/' . \str_repeat('x', RefGrammar::MAX_LENGTH), false],
        ];
    }

    public function testGrammarAcceptsAndRefusesPerGitRules(): void
    {
        foreach (self::refNameProvider() as $label => [$ref, $expected]) {
            $this->assertSame($expected, RefGrammar::isValidRefName($ref), 'verdict for [' . $label . ']: ' . \var_export($ref, true));
        }
    }
}
