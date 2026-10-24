<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README-contract guard — the doc fence and the runtime interface must
 * list the same members (docs-fix-1023 D1/I1/N1).
 *
 * The starter README is the one screen every newcomer trusts before
 * writing a Model, and it once taught a THREE-method contract while
 * `SugarCraft\Core\Model` declares four members — a skeleton copied
 * from that fence dies at instantiate time with
 * `contains 1 abstract method`. This guard derives the required member
 * list from Reflection and the taught list from the README fence, so
 * the two can never silently drift apart again.
 */
final class ModelContractDocumentationTest extends TestCase
{
    /** Exact heading that must own the contract fence. */
    private const SECTION_HEADING = '## Anatomy of a SugarCraft Model';

    public function testTheModelInterfaceStillDeclaresExactlyFourMembers(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\SugarCraft\Core\Model::class))->getMethods(),
        );
        sort($methods);

        // Staleness arm: if the contract changes shape, this test must
        // be re-read together with the README fence below.
        $this->assertSame(['init', 'subscriptions', 'update', 'view'], $methods);
    }

    public function testReadmeAnatomyTeachesEveryRequiredInterfaceMethod(): void
    {
        $fence = $this->anatomyFence();

        foreach (['init', 'update', 'view', 'subscriptions'] as $member) {
            $this->assertMatchesRegularExpression(
                '/public function ' . $member . '\(/',
                $fence,
                'the README anatomy fence must teach ' . $member . '() — the interface requires it',
            );
        }
    }

    public function testReadmeStillTellsTheReaderOmittingAMemberFatals(): void
    {
        $readme = $this->readme();

        $this->assertStringContainsString(
            'contains 1 abstract method',
            $readme,
            'the fence comment must stay — it is the whole point of the four-method lesson',
        );
    }

    /** The php fence under the Anatomy heading, body only. */
    private function anatomyFence(): string
    {
        $readme  = $this->readme();
        $heading = strpos($readme, self::SECTION_HEADING);
        $this->assertIsInt($heading, 'README lost its "' . self::SECTION_HEADING . '" heading');

        $fence = substr($readme, $heading);
        if (preg_match('/^```php[ \t]*\n(.*?)\n```/ms', $fence, $block) !== 1) {
            $this->fail('no fenced php block under "' . self::SECTION_HEADING . '" — the extractor broke');
        }

        return $block[1];
    }

    private function readme(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/README.md');
    }
}
