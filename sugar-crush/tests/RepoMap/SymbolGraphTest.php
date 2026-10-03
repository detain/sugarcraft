<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\RepoMap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\RepoMap\PhpSymbolExtractor;
use SugarCraft\Crush\RepoMap\SymbolGraph;
use SugarCraft\Crush\RepoMap\Tag;

/**
 * Roadmap 5.5-3: the referencer -> definer graph with Aider's weights.
 */
final class SymbolGraphTest extends TestCase
{
    public function testAiderIdentifierMultipliers(): void
    {
        self::assertSame(1.0, SymbolGraph::identMultiplier('get', false, 1));
        self::assertSame(1.0, SymbolGraph::identMultiplier('Foo', false, 1), 'camel but shorter than 8');
        self::assertSame(10.0, SymbolGraph::identMultiplier('handlePermissionKey', false, 1), 'specific name');
        self::assertSame(10.0, SymbolGraph::identMultiplier('snake_case_name', false, 1));
        self::assertSame(100.0, SymbolGraph::identMultiplier('handlePermissionKey', true, 1), 'mentioned and specific');
        self::assertEqualsWithDelta(1.0, SymbolGraph::identMultiplier('_private_helper', false, 1), 1e-12, 'specific x private');
        self::assertEqualsWithDelta(0.1, SymbolGraph::identMultiplier('render', false, 6), 1e-12, 'generic: six definers');
        self::assertSame(1.0, SymbolGraph::identMultiplier('render', false, 5), 'five definers is not yet generic');
    }

    public function testEdgeWeightIsTheSquareRootOfTheReferenceCount(): void
    {
        $graph = SymbolGraph::new([
            'A.php' => [Tag::definition('A.php', 3, 'Foo', Tag::TYPE_CLASS)],
            'B.php' => array_map(static fn (int $l): Tag => Tag::reference('B.php', $l, 'Foo'), [1, 2, 3, 4]),
        ]);

        self::assertSame([['from' => 'B.php', 'to' => 'A.php', 'weight' => 2.0, 'ident' => 'Foo']], $graph->edges());
    }

    public function testAFocusReferencerMultipliesItsEdgesByFifty(): void
    {
        $graph = SymbolGraph::new([
            'A.php' => [Tag::definition('A.php', 3, 'Foo', Tag::TYPE_CLASS)],
            'B.php' => [Tag::reference('B.php', 1, 'Foo')],
        ], focusFiles: ['B.php']);

        self::assertSame(50.0, $graph->edges()[0]['weight']);
    }

    public function testAnUnreferencedDefinitionGetsATenthSelfEdge(): void
    {
        $graph = SymbolGraph::new([
            'A.php' => [Tag::definition('A.php', 3, 'Lonely', Tag::TYPE_CLASS), Tag::reference('A.php', 9, 'Other')],
            'B.php' => [Tag::definition('B.php', 2, 'Other', Tag::TYPE_CLASS)],
        ]);

        self::assertContains(['from' => 'A.php', 'to' => 'A.php', 'weight' => 0.1, 'ident' => 'Lonely'], $graph->edges());
    }

    public function testDefinitionsWithNoReferencesAnywhereStillLinkTheirFiles(): void
    {
        $graph = SymbolGraph::new([
            'A.php' => [Tag::definition('A.php', 3, 'Foo', Tag::TYPE_CLASS)],
        ]);

        self::assertSame([['from' => 'A.php', 'to' => 'A.php', 'weight' => 1.0, 'ident' => 'Foo']], $graph->edges());
    }

    public function testPersonalisationFavoursFocusMentionedAndIdentifierNamedFiles(): void
    {
        $graph = SymbolGraph::new(
            [
                'src/Chat.php' => [],
                'src/Renderer.php' => [],
                'src/Runtime.php' => [],
                'docs/x.md' => [],
            ],
            focusFiles: ['src/Chat.php'],
            mentionedFiles: ['src/Renderer.php', 'src/Chat.php'],
            mentionedIdents: ['Runtime', 'docs'],
        );

        self::assertSame(
            ['docs/x.md' => 25.0, 'src/Chat.php' => 25.0, 'src/Renderer.php' => 25.0, 'src/Runtime.php' => 25.0],
            $graph->personalization(),
        );

        $doubled = SymbolGraph::new(['Chat.php' => [], 'b.php' => []], focusFiles: ['Chat.php'], mentionedIdents: ['Chat']);
        self::assertSame(['Chat.php' => 100.0], $doubled->personalization(), 'focus plus a basename match');
    }

    public function testTheMostReferencedDefinitionRanksFirstAndFocusFilesAreLeftOut(): void
    {
        $extract = PhpSymbolExtractor::new();
        $tags = [
            'src/Core.php' => $extract->extract("<?php\nfinal class CoreService\n{\n    public function handleRequest(): void {}\n}\n", 'src/Core.php'),
            'src/Edge.php' => $extract->extract("<?php\nfinal class EdgeThing\n{\n}\n", 'src/Edge.php'),
            'src/One.php' => $extract->extract("<?php\n(new CoreService())->handleRequest();\n", 'src/One.php'),
            'src/Two.php' => $extract->extract("<?php\n(new CoreService())->handleRequest();\nnew EdgeThing();\n", 'src/Two.php'),
            'src/Focus.php' => $extract->extract("<?php\nfinal class FocusOnly {}\nnew CoreService();\n", 'src/Focus.php'),
            'README.md' => [],
        ];

        $entries = SymbolGraph::new($tags, focusFiles: ['src/Focus.php'])->rankedEntries();

        self::assertNotSame([], $entries);
        self::assertSame('src/Core.php', $entries[0]['path']);
        self::assertInstanceOf(Tag::class, $entries[0]['tag']);
        $paths = array_column($entries, 'path');
        self::assertNotContains('src/Focus.php', $paths);
        self::assertContains('README.md', $paths, 'a file with no tags still appears, as a bare entry');
        self::assertNull($entries[array_search('README.md', $paths, true)]['tag']);
        self::assertLessThan(array_search('src/Edge.php', $paths, true), array_search('src/Core.php', $paths, true));
    }

    public function testAMentionedIdentifierOutranksAnEquallyReferencedOne(): void
    {
        $tags = [
            'A.php' => [Tag::definition('A.php', 2, 'Alpha', Tag::TYPE_CLASS)],
            'B.php' => [Tag::definition('B.php', 2, 'Bravo', Tag::TYPE_CLASS)],
            'C.php' => [Tag::reference('C.php', 1, 'Alpha'), Tag::reference('C.php', 2, 'Bravo')],
        ];

        $plain = SymbolGraph::new($tags)->rankedEntries();
        $hinted = SymbolGraph::new($tags, mentionedIdents: ['Bravo'])->rankedEntries();

        self::assertSame('B.php', $hinted[0]['path']);
        self::assertSame(['B.php', 'A.php'], [$plain[0]['path'], $plain[1]['path']], 'ties break on path, descending');
    }

    public function testRankingIsDeterministic(): void
    {
        $tags = [
            'x.php' => [Tag::definition('x.php', 1, 'X', Tag::TYPE_CLASS), Tag::reference('x.php', 2, 'Y')],
            'y.php' => [Tag::definition('y.php', 1, 'Y', Tag::TYPE_CLASS), Tag::reference('y.php', 2, 'X')],
        ];

        self::assertEquals(SymbolGraph::new($tags)->rankedEntries(), SymbolGraph::new(array_reverse($tags, true))->rankedEntries());
    }
}
