<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\BundleRecommender;
use SugarCraft\Code\Bundles\MarketplaceEntry;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * C-EXT-4.a: the offline recommender. Every pin here proves the announce-only
 * floor — matching is lexical on keywords against a directory listing, output
 * is capped, installed names never re-appear, and the whole exercise reads no
 * file contents and installs nothing.
 */
final class BundleRecommenderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/cc-cext4a-recommender-' . uniqid((string) getmypid(), true);
        $this->root = $this->tempDir . '/project';
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testScoresRankBySignalHitsAndTiesBreakByName(): void
    {
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/main.tf', '# terraform');

        $recommender = new BundleRecommender($this->root, [
            $this->entryNamed('z-php', ['php']),
            $this->entryNamed('m-two', ['php', 'laravel']),
            $this->entryNamed('a-tf', ['terraform']),
            $this->entryNamed('u-unrelated', ['kotlin']),
        ]);

        $names = array_map(static fn (MarketplaceEntry $e): string => $e->name, $recommender->recommend());

        self::assertSame(['m-two', 'a-tf', 'z-php'], $names);
    }

    public function testOutputIsCappedAndInstalledNamesAreExcluded(): void
    {
        file_put_contents($this->root . '/Dockerfile', 'FROM scratch');

        $catalog = [
            $this->entryNamed('one', ['docker']),
            $this->entryNamed('two', ['container']),
            $this->entryNamed('three', ['devops']),
            $this->entryNamed('four', ['deploy']),
        ];

        $recommender = new BundleRecommender($this->root, $catalog, ['three']);

        $names = array_map(
            static fn (MarketplaceEntry $e): string => $e->name,
            $recommender->recommend(),
        );

        self::assertCount(BundleRecommender::MAX_RECOMMENDATIONS, $names);
        self::assertNotContains('three', $names);
        self::assertSame(['four', 'one', 'two'], $names);
    }

    #[DataProvider('signalFileSpelling')]
    public function testEachManifestSignalVouchesForItsWords(string $file, string $keyword): void
    {
        $target = $this->root . '/' . $file;
        file_put_contents($target, '');

        $recommender = new BundleRecommender($this->root, [
            $this->entryNamed('match', [$keyword]),
            $this->entryNamed('miss', ['cobol']),
        ]);

        $names = array_map(static fn (MarketplaceEntry $e): string => $e->name, $recommender->recommend());

        self::assertSame(['match'], $names, $file . ' should speak ' . $keyword);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function signalFileSpelling(): array
    {
        return [
            'composer' => ['composer.json', 'php'],
            'package' => ['package.json', 'node'],
            'gomod' => ['go.mod', 'golang'],
            'docker' => ['Dockerfile', 'docker'],
            'terraform' => ['infra.tf', 'aws'],
        ];
    }

    public function testARootWithoutSignalsOrAnUnknownRootRecommendsNothing(): void
    {
        self::assertSame([], (new BundleRecommender($this->root))->recommend());
        self::assertSame([], (new BundleRecommender($this->tempDir . '/never-existed', [
            $this->entryNamed('x', ['php']),
        ]))->recommend());
        self::assertSame([], (new BundleRecommender('', [$this->entryNamed('x', ['php'])]))->recommend());
    }

    public function testKeywordlessEntriesAndEmptyCatalogsAnswerEmpty(): void
    {
        file_put_contents($this->root . '/go.mod', 'module x');

        $noKeys = MarketplaceEntry::github('bare', 'acme/bare');
        self::assertSame([], (new BundleRecommender($this->root, [$noKeys]))->recommend());
        self::assertSame([], (new BundleRecommender($this->root))->recommend());
    }

    public function testRecommendingIsPureAndRepeatsItselfExactly(): void
    {
        file_put_contents($this->root . '/composer.json', '{}');
        $before = (string) file_get_contents($this->root . '/composer.json');

        $recommender = new BundleRecommender($this->root, [
            $this->entryNamed('a', ['php']),
            $this->entryNamed('b', ['laravel', 'php']),
        ]);

        $first = $recommender->recommend();
        $second = $recommender->recommend();

        self::assertSame(
            array_map(static fn (MarketplaceEntry $e): string => $e->name, $first),
            array_map(static fn (MarketplaceEntry $e): string => $e->name, $second),
        );
        self::assertSame(['b', 'a'], array_map(static fn (MarketplaceEntry $e): string => $e->name, $first));
        self::assertSame($before, (string) file_get_contents($this->root . '/composer.json'));
    }

    /** A github entry with the given keywords — the catalog's cheapest shape. */
    private function entryNamed(string $name, array $keywords): MarketplaceEntry
    {
        return MarketplaceEntry::github($name, 'acme/' . $name)->withKeywords($keywords);
    }
}
