<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Site-body guard — the public docs page for this template must keep
 * saying what the README says (lib-reviews-1102 C1/M7/M13/M12 cluster).
 *
 * docs/_data/candy-starter.body.html is hand-maintained input to
 * tools/gen-docs.php; nothing else pins its prose, so the highest-
 * consequence claims for a template — HOW you install it, WHAT the Model
 * contract requires, WHERE the copyable examples live, and what the entry
 * point costs — are asserted here. In a standalone split-repo clone the
 * monorepo docs tree is absent and each test skips loudly rather than
 * failing for the wrong reason.
 */
final class SiteBodyTruthTest extends TestCase
{
    private function body(): ?string
    {
        $path = dirname(__DIR__, 2) . '/docs/_data/candy-starter.body.html';

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function testTheInstallBlockTeachesCreateProjectNotRequire(): void
    {
        $body = $this->body();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs tree not present in this clone');
        }

        $this->assertStringContainsString(
            'composer create-project sugarcraft/candy-starter',
            $body,
            'the site install block must teach the only working install verb for a type:project package',
        );
        $this->assertStringNotContainsString(
            'composer require sugarcraft/candy-starter',
            $body,
            '"composer require" is refused for project-type packages — the site must not teach it',
        );
        $this->assertStringContainsString(
            'stopSignal()',
            $body,
            'the entry-point card must include the stopSignal() exit tail — the bin is not just three lines any more (M2)',
        );
    }

    public function testTheContractClaimNamesAllFourModelMethods(): void
    {
        $body = $this->body();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs tree not present in this clone');
        }

        $this->assertStringContainsString(
            'init / update / view / subscriptions',
            $body,
            'the pure-Model anatomy card must enumerate the real required set, not a three-method subset',
        );
        $this->assertStringContainsString(
            'subscriptions()',
            $body,
            'the API table must carry the subscriptions() row — omitting it is what made the page contradict the README',
        );
    }

    public function testTheBodyNeverPointsAtPhantomExamplesOrOverclaimsGifs(): void
    {
        $body = $this->body();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs tree not present in this clone');
        }

        $this->assertStringNotContainsString(
            '/examples',
            $body,
            'the starter ships no examples/ tree — no link or row may promise one',
        );
        $this->assertStringNotContainsString(
            'GIFs of every example',
            $body,
            'exactly one .vhs tape ships; the demo section must not claim more',
        );
    }

    public function testTheProgramRowIsAttributedToSugarCoreNotTheStarter(): void
    {
        $body = $this->body();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs tree not present in this clone');
        }

        $this->assertMatchesRegularExpression(
            '/Program <em>\(sugar-core\)<\/em>.{0,120}not shipped by the starter/',
            $body,
            'run() belongs to the sugar-core dependency; the API table must say so',
        );
    }
}
