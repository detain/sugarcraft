<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\ShellSubjectTools;

/**
 * rv-php-2 (crush-review-1014): the shell-subject roster `Bash` + `PtyRun`
 * lives in SIX production places — three now read {@see ShellSubjectTools},
 * three still hold literals: SafetyClassifier's `match` arm (patterns cannot
 * interpolate a constant), and Renderer + Runtime, files the fix lane may not
 * edit. This test is the mechanical stitch: it re-derives the roster from
 * the canonical class and greps EVERY site — owned and foreign alike,
 * read-only — so adding a shell tool without wiring all six reddens here
 * instead of silently unbinding a `Deny Bash(rm *)` rule from the new tool.
 *
 * Site inventory (verified at writing, 2026-10-14):
 *  1. src/Permissions/PermissionRule.php        — const init == NAMES
 *  2. src/Permissions/PermissionGate.php        — isRmRfRootOrHome calls covers()
 *  3. src/Permissions/SessionPermissionMemo.php — grantArguments calls covers()
 *  4. src/Permissions/SafetyClassifier.php      — literals in the classifyBash arm
 *  5. src/Renderer.php                          — literals in the $isShell predicate
 *  6. src/Runtime.php                           — WRITE_CAPABLE_TOOL_NAMES superset
 */
final class ShellSubjectToolsCompletenessTest extends TestCase
{
    /** @return list<string> */
    private static function quotedLiterals(string $expression): array
    {
        preg_match_all("/'([^']+)'/", $expression, $m);

        return $m[1];
    }

    private static function src(string $relative): string
    {
        $source = file_get_contents(__DIR__ . '/../../src/' . $relative);
        self::assertIsString($source, 'unreadable source file ' . $relative);

        return $source;
    }

    public function testTheCanonicalRosterIsTheShellToolPair(): void
    {
        self::assertSame(['Bash', 'PtyRun'], ShellSubjectTools::NAMES, 'the roster itself moved — audit the six sites below before accepting');
        self::assertSame(['bash', 'ptyrun'], array_map('strtolower', ShellSubjectTools::NAMES), 'names are compared case-sensitively at the sites; a case respell splits the roster');
    }

    public function testCoversAnswersWithRosterPolarity(): void
    {
        foreach (ShellSubjectTools::NAMES as $name) {
            self::assertTrue(ShellSubjectTools::covers($name), $name . ' must be covered');
        }
        self::assertFalse(ShellSubjectTools::covers('Read'));
        self::assertFalse(ShellSubjectTools::covers('bash'), 'tool names are exact — a lowercase miss must not slip through the breaker');
    }

    public function testPermissionRuleConstIsTheCanonicalList(): void
    {
        self::assertSame(
            ShellSubjectTools::NAMES,
            (array) (new ReflectionClass(PermissionRule::class))->getConstant('SHELL_SUBJECT_TOOLS'),
            'PermissionRule::SHELL_SUBJECT_TOOLS no longer equals ShellSubjectTools::NAMES',
        );
    }

    public function testPermissionGateBreakerReadsTheCanonicalRoster(): void
    {
        $body = self::rosterSiteBody('Permissions/PermissionGate.php', 'function isRmRfRootOrHome');
        self::assertStringContainsString('ShellSubjectTools::covers($call->name)', $body, 'site 2 reverted to hand-copied names inside the rm-rf breaker');
        self::assertStringNotContainsString("'PtyRun'", $body, 'the breaker body must not re-spell roster literals next to the covers() call');
    }

    public function testSessionMemoNormaliserReadsTheCanonicalRoster(): void
    {
        $body = self::rosterSiteBody('Permissions/SessionPermissionMemo.php', 'function grantArguments');
        self::assertStringContainsString('ShellSubjectTools::covers($tool)', $body, 'site 3 reverted to hand-copied names in grantArguments');
    }

    public function testClassifierMatchArmLiteralsEqualTheRoster(): void
    {
        // Site 4: `match` patterns cannot interpolate a constant, so this is
        // the one sibling that KEEPS literals — pinned equal to canonical here.
        $source = self::src('Permissions/SafetyClassifier.php');
        self::assertSame(
            1,
            preg_match("/^(\s*)((?:'[A-Za-z]+',\s*)*'PtyRun') => \\\$this->classifyBash\(/m", $source, $m),
            'SafetyClassifier lost its literal classifyBash arm or reshaped it — re-anchor this pin against ShellSubjectTools::NAMES',
        );
        self::assertSame(
            ShellSubjectTools::NAMES,
            self::quotedLiterals($m[2]),
            'SafetyClassifier\'s match arm diverged from the canonical roster',
        );
    }

    public function testRendererShellPredicateMatchesTheRoster(): void
    {
        // Site 5 (foreign file, read-only pin): the modal's subject lines.
        $source = self::src('Renderer.php');
        self::assertSame(
            1,
            preg_match('/\$isShell = ([^;]+);/s', $source, $m),
            'Renderer::$isShell predicate vanished or gained a statement break — re-anchor this pin',
        );
        $expression = $m[1];
        if (str_contains($expression, 'ShellSubjectTools')) {
            $this->expectNotToPerformAssertions();

            return; // the site adopted the roster; nothing left to pin textually
        }
        self::assertSame(
            ShellSubjectTools::NAMES,
            self::quotedLiterals($expression),
            'Renderer\'s inline shell-tool list diverged from the canonical roster — update it or route it through ShellSubjectTools::covers()',
        );
    }

    public function testRuntimeWriteCapableRosterContainsTheShellPair(): void
    {
        // Site 6: the roster is wider than the shell pair by design (Edit,
        // Write, ...), so the law here is CONTAINMENT. Since rv-php-2b the
        // declaring line spreads ShellSubjectTools::NAMES instead of respelling
        // the pair, so a literal-text grep would pin the OLD shape; the pin
        // reads the RESOLVED constant through reflection, which holds under
        // either spelling.
        $roster = (new \ReflectionClass(\SugarCraft\Crush\Runtime::class))->getConstant('WRITE_CAPABLE_TOOL_NAMES');
        self::assertIsArray($roster, 'Runtime::WRITE_CAPABLE_TOOL_NAMES vanished or stopped being an array constant');
        foreach (ShellSubjectTools::NAMES as $tool) {
            self::assertContains($tool, $roster, 'the write-capable roster lost a shell-subject tool');
        }
        $source = self::src('Runtime.php');
        if ( ! str_contains($source, 'WRITE_CAPABLE_TOOL_NAMES = [')) {
            self::fail('Runtime::WRITE_CAPABLE_TOOL_NAMES no longer declares an array constant — re-anchor this pin');
        }
    }

    /**
     * The declaring line through the first line at brace depth 0 again —
     * comment-stripped so doc prose naming a pattern cannot fake a hit.
     *
     * @return non-falsy-string
     */
    private static function rosterSiteBody(string $relativeFile, string $anchor): string
    {
        // php_strip_whitespace takes a PATH (and warns-then-empties on a
        // string of contents), so the comment-strip reads the file directly.
        $source = php_strip_whitespace(__DIR__ . '/../../src/' . $relativeFile);
        self::assertNotSame('', $source, 'unreadable/empty source ' . $relativeFile);
        $start = strpos($source, $anchor);
        self::assertIsInt($start, $anchor . ' not found in ' . $relativeFile . ' — re-anchor this pin');
        $depth = 0;
        $length = \strlen($source);
        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }
        self::fail('unbalanced braces after ' . $anchor . ' in ' . $relativeFile);
    }
}
