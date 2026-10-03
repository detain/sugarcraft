<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\RepoMap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\RepoMap\PhpSymbolExtractor;
use SugarCraft\Crush\RepoMap\RepoMapRenderer;
use SugarCraft\Crush\RepoMap\SymbolGraph;
use SugarCraft\Crush\RepoMap\Tag;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Roadmap 5.5-3: the outline format, and the binary search that sizes it to a
 * token budget without ever overrunning it.
 */
final class RepoMapRendererBudgetTest extends TestCase
{
    /** @var array<string, string> */
    private array $sources = [];

    /** @var array<string, list<Tag>> */
    private array $tags = [];

    private function addFile(string $path, string $source): void
    {
        $this->sources[$path] = $source;
        $this->tags[$path] = PhpSymbolExtractor::new()->extract($source, $path);
    }

    private function renderer(): RepoMapRenderer
    {
        return RepoMapRenderer::new(
            fn (string $path): array => explode("\n", $this->sources[$path] ?? ''),
            $this->tags,
        );
    }

    /** A synthetic project: $n classes with three methods each, every one referenced. */
    private function project(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->addFile(
                sprintf('src/Service%03d.php', $i),
                "<?php\n\nnamespace App;\n\nfinal class Service{$i}\n{\n"
                . "    public function handleRequest{$i}(int \$id): string\n    {\n        return '';\n    }\n\n"
                . "    public function validateInput{$i}(array \$input): bool\n    {\n        return true;\n    }\n\n"
                . "    public function renderOutput{$i}(): void\n    {\n    }\n}\n",
            );
        }
        $calls = '';
        for ($i = 0; $i < $n; $i++) {
            $calls .= "(new Service{$i}())->handleRequest{$i}(1);\n";
        }
        $this->addFile('src/main.php', "<?php\n" . $calls);
    }

    public function testTheOutlineShowsDefinitionsUnderTheirClassHeaderWithElisions(): void
    {
        $this->addFile('src/Greeter.php', "<?php\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return \$name;\n    }\n}\n");
        $method = array_values(array_filter($this->tags['src/Greeter.php'], static fn (Tag $t): bool => $t->name === 'greet'))[0];

        $out = $this->renderer()->render([
            ['path' => 'src/Greeter.php', 'tag' => $method],
            ['path' => 'README.md', 'tag' => null],
        ]);

        self::assertSame(
            "\nREADME.md\n\nsrc/Greeter.php:\n⋮\n│final class Greeter\n⋮\n│    public function greet(string \$name): string\n⋮\n",
            $out,
        );
    }

    public function testAMissingSourceLineFallsBackToTheTagsTypeAndName(): void
    {
        $renderer = RepoMapRenderer::new(static fn (string $path): array => []);

        self::assertSame(
            "\ngone.php:\n⋮\n│function vanished\n⋮\n",
            $renderer->render([['path' => 'gone.php', 'tag' => Tag::definition('gone.php', 7, 'vanished', Tag::TYPE_FUNCTION)]]),
        );
    }

    public function testEveryLineIsClippedToAHundredCharacters(): void
    {
        $long = 'function ' . str_repeat('a', 150) . '() {}';
        $this->addFile('long.php', "<?php\n" . $long . "\n");

        foreach (explode("\n", $this->renderer()->render(SymbolGraph::new($this->tags)->rankedEntries())) as $line) {
            self::assertLessThanOrEqual(RepoMapRenderer::MAX_LINE_CHARS, mb_strlen($line));
        }
    }

    public function testTheFittedMapNeverExceedsItsBudget(): void
    {
        $this->project(60);
        $entries = SymbolGraph::new($this->tags)->rankedEntries();
        $full = TokenEstimate::ofText($this->renderer()->render($entries));

        foreach ([64, 200, 512, 1024, 2048] as $budget) {
            $map = $this->renderer()->fit($entries, $budget);
            self::assertNotSame('', $map, "budget {$budget}");
            self::assertLessThanOrEqual($budget, TokenEstimate::ofText($map), "budget {$budget}");
        }

        self::assertGreaterThan(2048, $full, 'the fixture must be bigger than every budget to exercise the search');
    }

    public function testAGenerousBudgetRendersEveryEntry(): void
    {
        $this->project(5);
        $entries = SymbolGraph::new($this->tags)->rankedEntries();
        $full = $this->renderer()->render($entries);

        self::assertSame($full, $this->renderer()->fit($entries, TokenEstimate::ofText($full) * 10));
    }

    public function testTheSearchLandsWithinFifteenPercentOfTheBudgetWhenTheMapIsLargeEnough(): void
    {
        $this->project(80);
        $entries = SymbolGraph::new($this->tags)->rankedEntries();

        $tokens = TokenEstimate::ofText($this->renderer()->fit($entries, 1024));

        self::assertGreaterThanOrEqual(1024 * (1 - RepoMapRenderer::ACCEPTABLE_SHORTFALL) - 40, $tokens);
        self::assertLessThanOrEqual(1024, $tokens);
    }

    public function testALargerBudgetNeverShowsLess(): void
    {
        $this->project(40);
        $entries = SymbolGraph::new($this->tags)->rankedEntries();

        $small = TokenEstimate::ofText($this->renderer()->fit($entries, 300));
        $large = TokenEstimate::ofText($this->renderer()->fit($entries, 900));

        self::assertGreaterThan($small, $large);
    }

    public function testTheHighestRankedDefinitionsAreTheOnesKept(): void
    {
        $this->project(40);
        $entries = SymbolGraph::new($this->tags)->rankedEntries();

        $map = $this->renderer()->fit($entries, 150);
        $first = $entries[0];
        self::assertInstanceOf(Tag::class, $first['tag']);

        self::assertStringContainsString($first['path'] . ':', $map);
        self::assertStringContainsString($first['tag']->name, $map);
    }

    public function testNothingFitsMeansAnEmptyMap(): void
    {
        $this->project(3);
        $entries = SymbolGraph::new($this->tags)->rankedEntries();

        self::assertSame('', $this->renderer()->fit($entries, 1));
        self::assertSame('', $this->renderer()->fit([], 4096));
        self::assertSame('', $this->renderer()->render([]));
    }
}
