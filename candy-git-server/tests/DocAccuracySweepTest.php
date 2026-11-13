<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Lang;

/**
 * S35 (findings 043, 077, 092, 094): docblocks and rename markers are
 * claims, and claims rot silently unless something re-checks them. The
 * cured mislabels here understated a security guard ("BOTH" where the
 * constructor refuses EITHER), advertised a grammar the loader throws
 * on ("cron format"), claimed the wrong i18n namespace, and left the
 * pre-rename key-path relics unmarked so they read as missed renames.
 * Each gets a structural guard so the next editor cannot re-introduce
 * the same species of drift unnoticed.
 */
final class DocAccuracySweepTest extends TestCase
{
    private string $configSource;

    private string $langSource;

    private string $binSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configSource = (string) \file_get_contents(__DIR__ . '/../src/Config.php');
        $this->langSource   = (string) \file_get_contents(__DIR__ . '/../src/Lang.php');
        $this->binSource    = (string) \file_get_contents(__DIR__ . '/../bin/candy-git-server');
    }

    /**
     * 043: the TLS docblock must describe the disjunction the constructor
     * actually enforces. The EITHER refusal itself is behavior-pinned in
     * ConfigTest; this twin keeps the PROSE from drifting back.
     */
    public function testTlsDocblockNamesTheGuardTheConstructorRuns(): void
    {
        $at = \strpos($this->configSource, 'RESERVED — NOT yet enforced');
        $this->assertNotFalse($at, 'the TLS block must keep its reserved-feature header for this guard to exist');
        $block = \substr($this->configSource, $at, 700);

        $this->assertStringContainsString('EITHER', $block, 'the guard refuses EITHER path alone (043)');
        $this->assertStringNotContainsString('setting BOTH paths is rejected', $block, 'the understated "BOTH" claim is what misled readers');

        // And the constructor really does answer the OR — if someone
        // softens the code to && without touching prose, this catches it.
        $this->assertMatchesRegularExpression(
            '/tlsKeyPath !== \'\' \|\| \$this->tlsCertPath !== \'\'/',
            $this->configSource,
            'the constructor must keep the EITHER-disjunction the docblock promises'
        );
    }

    /**
     * 077: the mirror schedule field doc once said "cron format" while
     * Schedule accepts @every/aliases and THROWS on full cron. The prose
     * must name the accepted grammar and the refusal.
     */
    public function testMirrorScheduleDocblockStatesTheRealGrammar(): void
    {
        $this->assertMatchesRegularExpression(
            '/\/\*\* Mirror job schedule[^\n]*@every[^\n]*throws[^\n]*\*\//',
            $this->configSource,
            'the schedule docblock must teach @every/aliases + the full-cron throw (077)'
        );
        $this->assertStringNotContainsString('(cron format)', $this->configSource);
    }

    /**
     * 092: the facade docblock once claimed the baked namespace was
     * 'serve'; the shipped constant says 'gitserver'. Pin doc-claim and
     * constant to each other — whichever side changes, they must move
     * together.
     */
    public function testLangDocblockClaimsTheNamespaceTheConstantCarries(): void
    {
        $constant = (new \ReflectionClass(Lang::class))->getReflectionConstant('NAMESPACE');
        $this->assertNotFalse($constant);
        $this->assertSame('gitserver', $constant->getValue());
        $this->assertMatchesRegularExpression(
            "/`'gitserver'` namespace baked in/",
            $this->langSource,
            'the Lang.php docblock must name the real namespace (092)'
        );
    }

    /**
     * 094: every pre-rename soft_serve relic in shipped code must sit
     * under (or within earshot of) a @rename-keep marker. A bare relic
     * reads as a missed rename; a marker without a relic also reads as
     * a lie in the other direction, so the marker census is tied to the
     * literal census.
     */
    public function testRelicPathsCarryDatedRenameKeepMarkers(): void
    {
        $sources = [
            'src/Config.php'          => $this->configSource,
            'bin/candy-git-server'    => $this->binSource,
        ];

        foreach ($sources as $label => $source) {
            $lines = \explode("\n", $source);
            foreach ($lines as $index => $line) {
                if (\strpos($line, 'soft_serve') === false) {
                    continue;
                }
                $windowStart = \max(0, $index - 12);
                $window      = \implode("\n", \array_slice($lines, $windowStart, $index - $windowStart + 1));
                $this->assertStringContainsString(
                    '@rename-keep',
                    $window,
                    "unmarked soft_serve relic at {$label}:" . ($index + 1) . ' — rename-audit hazard (094)'
                );
            }
        }
    }
}
