<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Byte-pin for the SahilmMatcher::matchAllGenerator() de-duplication refactor
 * (audit finding 7, LOW): the generator now delegates to matchAll instead of
 * re-listing the loop. These vectors were captured from the PRE-refactor code
 * on this box; regenerating them must stay byte-identical — the refactor
 * changed wiring, not output.
 *
 * Row format: "haystack|score|json(indices)".
 */
#[CoversClass(SahilmMatcher::class)]
#[CoversClass(SmithWatermanMatcher::class)]
final class GeneratorBytePinTest extends TestCase
{
    private const CANDIDATES = ['apple', 'applet', 'application', 'apply', 'apricot', 'banana', 'cherry', 'THE-app'];

    /**
     * @return array<string, array{0: 'sm'|'sw', 1: string, 2: int|null, 3: int, 4: list<string>}>
     */
    public static function preRefactorVectors(): array
    {
        return [
            'sm|app||1' => ['sm', 'app', null, 1, [
                'apple|31|[0,1,2]', 'applet|31|[0,1,2]', 'application|31|[0,1,2]', 'apply|31|[0,1,2]', 'THE-app|26|[4,5,6]',
            ]],
            'sw|app||1' => ['sw', 'app', null, 1, [
                'THE-app|19|[4,5,6]', 'apple|19|[0,1,2]', 'applet|19|[0,1,2]', 'application|19|[0,1,2]', 'apply|19|[0,1,2]', 'apricot|11|[0,1]', 'banana|3|[1]',
            ]],
            'sm|app|2|1' => ['sm', 'app', 2, 1, ['apple|31|[0,1,2]', 'applet|31|[0,1,2]']],
            'sw|app|2|1' => ['sw', 'app', 2, 1, ['THE-app|19|[4,5,6]', 'apple|19|[0,1,2]']],
            'sm|he||10' => ['sm', 'he', null, 10, []],
            'sw|he||10' => ['sw', 'he', null, 10, ['THE-app|11|[1,2]', 'cherry|11|[1,2]']],
            'sm|a||1' => ['sm', 'a', null, 1, [
                'apple|17|[0]', 'applet|17|[0]', 'application|17|[0]', 'apply|17|[0]', 'apricot|17|[0]', 'THE-app|12|[4]', 'banana|2|[1]',
            ]],
            'sw|a||1' => ['sw', 'a', null, 1, [
                'THE-app|3|[4]', 'apple|3|[0]', 'applet|3|[0]', 'application|3|[0]', 'apply|3|[0]', 'apricot|3|[0]', 'banana|3|[1]',
            ]],
            'sm|xyz||1' => ['sm', 'xyz', null, 1, []],
            'sw|xyz||1' => ['sw', 'xyz', null, 1, ['apply|3|[4]', 'cherry|3|[5]']],
        ];
    }

    #[Test]
    #[DataProvider('preRefactorVectors')]
    public function testGeneratorOutputIsByteIdenticalToPreRefactorCapture(string $matcher, string $query, ?int $limit, int $minScore, array $expected): void
    {
        $instance = $matcher === 'sm' ? new SahilmMatcher() : new SmithWatermanMatcher();

        $rows = [];
        foreach ($instance->matchAllGenerator($query, self::CANDIDATES, limit: $limit, minScore: $minScore) as $result) {
            $rows[] = self::serialize($result);
        }

        $this->assertSame($expected, $rows);
    }

    #[Test]
    public function testGeneratorIsLazyGeneratorNotArray(): void
    {
        // Delegating to matchAll must not collapse the return type: still a Generator.
        $this->assertInstanceOf(\Generator::class, (new SahilmMatcher())->matchAllGenerator('app', self::CANDIDATES));
    }

    private static function serialize(MatchResult $result): string
    {
        return sprintf('%s|%d|%s', $result->haystack, $result->score, json_encode($result->indices()));
    }
}
