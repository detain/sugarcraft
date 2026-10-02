<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\ToolCall;

/**
 * Audit F-D1: `docs/PERMISSIONS.md`'s introduction against the matcher.
 *
 * The page's opening paragraph said "rule patterns match tool NAMES only" and
 * that `Bash(rm *)` "matches nothing" for two rounds after argument-scoped
 * matching shipped (`d3d90fece`, `c8fc573a5`) and `WebFetch(domain:…)` host
 * rules joined it (`a9c5edbdd`) — the body had been rewritten and the
 * first thing every reader sees still contradicted it. Each example pattern
 * the introduction now quotes is checked against {@see PermissionRule}, so a
 * matcher change that makes one of them false has to change the page too.
 */
final class PermissionsIntroDriftTest extends TestCase
{
    private const DOC = __DIR__ . '/../../docs/PERMISSIONS.md';

    public function testTheIntroductionNoLongerClaimsNameOnlyMatching(): void
    {
        $intro = self::intro();

        $this->assertDoesNotMatchRegularExpression('/names?\s+only/i', $intro, 'the retracted name-only claim is back in the introduction');
        $this->assertStringNotContainsString('matches nothing', $intro);
    }

    /**
     * Every `Tool(argument)` example the introduction quotes is an
     * argument-scoped rule over a tool whose subject the matcher reads.
     */
    public function testEveryQuotedArgumentPatternIsOneTheMatcherReads(): void
    {
        preg_match_all('/`([A-Za-z_]+)\(([^`)]+)\)`/', self::intro(), $m, PREG_SET_ORDER);
        $tools = array_column($m, 1);

        $this->assertSame(['Bash', 'Read', 'WebFetch'], $tools, 'the introduction should show one shell, one path and one host pattern');

        foreach ($m as [$quoted, $tool]) {
            $rule = new PermissionRule(trim($quoted, '`'), PermissionAction::Deny);
            $this->assertNotNull($rule->argumentPattern(), "{$quoted} is not argument-scoped");
            $this->assertNotNull(PermissionRule::subjectArgumentName($tool), "{$quoted}: the matcher reads no subject for {$tool}");
        }
    }

    /**
     * The introduction's three claims as behaviour: `Bash(rm *)` matches a
     * command, `Read(.env)` matches a symlink to the file once the project
     * root is known, and `WebFetch(domain:github.com)` matches by host, not
     * by url text.
     */
    public function testTheIntroductionsExamplesMatchWhatItSaysTheyMatch(): void
    {
        $this->assertTrue((new PermissionRule('Bash(rm *)', PermissionAction::Deny))
            ->matches(new ToolCall('Bash', ['command' => 'rm -rf build'])));

        $dir = sys_get_temp_dir() . '/sc_perm_intro_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        try {
            file_put_contents($dir . '/.env', 'x');
            symlink('.env', $dir . '/settings');

            $envRule = new PermissionRule('Read(.env)', PermissionAction::Deny);
            $this->assertTrue($envRule->matches(new ToolCall('Read', ['file_path' => 'settings']), true, $dir));
            $this->assertFalse(
                $envRule->matches(new ToolCall('Read', ['file_path' => 'settings'])),
                'without the root only the spelling is judged — the intro says the root is what follows the link',
            );
        } finally {
            @unlink($dir . '/settings');
            @unlink($dir . '/.env');
            @rmdir($dir);
        }

        $host = new PermissionRule('WebFetch(domain:github.com)', PermissionAction::Allow);
        $this->assertTrue($host->matches(new ToolCall('WebFetch', ['url' => 'https://github.com/x'])));
        $this->assertFalse($host->matches(new ToolCall('WebFetch', ['url' => 'https://github.com@evil.example/'])));
    }

    /** The introduction's link lands on a heading that exists. */
    public function testTheIntroductionLinksToTheMatchingSection(): void
    {
        $this->assertSame(1, preg_match('/\]\(#([a-z0-9-]+)\)/', self::intro(), $m), 'the introduction no longer links to the matching section');

        preg_match_all('/^#{2,4} (.+)$/m', (string) file_get_contents(self::DOC), $headings);
        $slugs = array_map(
            // GitHub's anchor rule, reduced to what this page's headings use:
            // lower-case, drop punctuation, spaces to hyphens.
            static fn(string $h): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 -]/', '', strtolower(trim($h)))),
            $headings[1],
        );

        $this->assertContains($m[1], $slugs);
    }

    /** Everything between the H1 and the first horizontal rule. */
    private static function intro(): string
    {
        $doc = (string) file_get_contents(self::DOC);
        $end = strpos($doc, "\n---\n");
        self::assertNotFalse($end, 'PERMISSIONS.md lost the rule that ends its introduction');

        return substr($doc, 0, $end);
    }
}
