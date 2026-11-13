<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * The guard for `tools/ansi-view.php`, the interactive tagger that WRITES the
 * committed `ansi/ansi.json` corpus as a side effect of a keystroke.
 *
 * WHY THIS FILE EXISTS AT ALL. The tool carries three load-bearing promises in
 * its header docblock and, before this round, not one of them was checked:
 * (1) THE ENCODER IS THE CANONICAL ONE — its writes are byte-identical in shape
 * to what `gen-ansi-readme.php --remeasure` produces (decode true, re-encode
 * with JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE, one
 * trailing newline), which is what lets an editing tool point at a committed
 * data file without the CI drift guard noticing; (2) `rating` is null until a
 * reviewer chooses — 0 is a real score and pressing the shown digit clears back
 * to unset; (3) anything the viewer cannot honour is REFUSED at load, by name,
 * before a single byte is written. Promise (1) failing means the next tag
 * session rewrites the corpus in a shape `gen-ansi-readme.php --check` reddens
 * on an innocent contributor; (2) failing means a reviewer's deliberate zero
 * scores become invisible unset states and the prune tool keeps art the
 * reviewer wanted deleted; (3) failing is a viewer over a half-parsed corpus
 * tagging the wrong file.
 *
 * EVERY MUTATING ASSERTION RUNS ON A THROWAWAY TREE, for the reason
 * PruneLowRatedAnsiTest.php spells out: the committed corpus is the tool's
 * write target, so one in-repo keystroke would be vandalism with a green check
 * beside it. The fixture tree mirrors the corpus layout — `ansi.json` plus
 * `<category>/<name>.ansi` art under `--root` — and every run pipes its
 * keystrokes through STDIN, the shape the tool's own setupTerminal() docblock
 * names as the test harness. The real corpus is touched READ-ONLY, by the
 * round-trip arm, which only computes what a write WOULD look like and never
 * writes it.
 *
 * THE ROSTER ARM AT THE BOTTOM is the other half of this row. Every script
 * under `tools/` must be named by at least one test here or sit in a
 * reasoned exemption list — a new tools/ script that nobody tests goes red BY
 * NAME instead of by nobody noticing.
 *
 * @internal
 */
final class AnsiViewTest extends TestCase
{
    /** The canonical encoding shape, spelled independently of the tool on purpose. */
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const SCRIPT = '/tools/ansi-view.php';

    /**
     * tools/ scripts covered by an exemption INSTEAD of a naming test. There
     * are none today, and adding a row here is the deliberate, reviewer-visible
     * act the exemption is supposed to be.
     *
     * @var array<string, string>
     */
    private const EXEMPT_WITH_REASON = [];

    /** @var list<string> scratch trees, removed in tearDown */
    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $dir) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($walk as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($dir);
        }
        $this->temps = [];
    }

    // -----------------------------------------------------------------
    // Promise 1: the encoder is the canonical one
    // -----------------------------------------------------------------

    public function testTheRealCorpusRoundTripsCmpSilentThroughTheCanonicalShape(): void
    {
        $corpus = \dirname(__DIR__, 2) . '/ansi/ansi.json';
        $raw = (string) \file_get_contents($corpus);

        // The claim is the header's: decode true, re-encode with the canonical
        // flags plus the single trailing newline, and the bytes do not move.
        // This arm computes exactly that against the COMMITTED file and
        // compares — no write happens anywhere near the corpus, which the
        // md5 pin below keeps honest for the whole run of this method.
        $before = \md5($raw);
        $canonical = \json_encode(\json_decode($raw, true, 512, JSON_THROW_ON_ERROR), self::FLAGS) . "\n";

        $this->assertSame(
            $raw,
            $canonical,
            'ansi/ansi.json is not in the canonical encoding shape — which is exactly what '
            . 'ansi-view.php writes with, so the next tag session would rewrite corpus bytes its '
            . 'editor never touched and gen-ansi-readme.php --check would not be the only thing '
            . 'going red',
        );
        $this->assertSame($before, \md5((string) \file_get_contents($corpus)), 'this test wrote to the corpus');
    }

    public function testAKeystrokeWriteEmitsTheCanonicalShapeAndExactlyOneTrailingNewline(): void
    {
        $root = $this->makeTree($this->index($this->entry(rating: null)));
        $jsonPath = $root . '/ansi.json';
        $before = (string) \file_get_contents($jsonPath);

        $run = $this->drive($root, 'k7q');
        $this->assertSame(0, $run['exit'], 'viewer stderr: ' . $run['stderr']);

        $after = (string) \file_get_contents($jsonPath);
        $expectedIndex = $this->index($this->entry(rating: 7, tags: ['keep']));

        // Byte identity against an INDEPENDENT re-encode: the writer's flags and
        // its single newline are what the corpus arm above relies on, and the
        // only honest proof of the writer's shape is the writer's own bytes.
        $this->assertSame(
            \json_encode($expectedIndex, self::FLAGS) . "\n",
            $after,
            'a tag-and-rate keystroke wrote something other than FLAGS-shaped JSON with one '
            . "trailing newline — gen-ansi-readme.php --check reads exactly this shape",
        );
        $this->assertFalse(\str_ends_with($after, "\n\n"), 'the writer emitted two trailing newlines');
        $this->assertNotSame($before, $after, 'the write was supposed to land and did not');
        $this->assertSame([], \glob($root . '/ansi.json.ansi-view.*.tmp'), 'an atomic temp file survived the run');
    }

    // -----------------------------------------------------------------
    // Promise 2: rating is null until chosen; 0 is real; shown digit clears
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{0: int|null, 1: string, 2: int|null}>
     */
    public static function ratingKeyPresses(): iterable
    {
        yield 'a digit from unset sets it' => [null, '7', 7];
        yield 'zero is a real score, not unset' => [null, '0', 0];
        yield 'pressing the shown digit clears back to unset' => [7, '7', null];
        yield 'pressing zero at zero clears too' => [0, '0', null];
        yield 'a different digit retargets' => [7, '2', 2];
    }

    /**
     * @dataProvider ratingKeyPresses
     */
    public function testTheRatingFollowsTheNullIsUnsetContract(?int $initial, string $key, ?int $expected): void
    {
        $root = $this->makeTree($this->index($this->entry(rating: $initial)));
        $run = $this->drive($root, $key . 'q');
        $this->assertSame(0, $run['exit'], 'viewer stderr: ' . $run['stderr']);

        $decoded = \json_decode((string) \file_get_contents($root . '/ansi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            $expected,
            $decoded['demo/one.ansi']['rating'],
            "from rating " . \var_export($initial, true) . ", pressing '$key' must leave "
            . \var_export($expected, true) . ' — null is the ONLY unset state and 0 is a score',
        );
    }

    public function testTheTagKeysToggleBothWaysAndAnUnboundKeyPersistsNothing(): void
    {
        $clean = $this->makeTree($this->index($this->entry(rating: null)));
        $this->drive($clean, 'q');
        $pristine = (string) \file_get_contents($clean . '/ansi.json');

        $add = $this->makeTree($this->index($this->entry(rating: null)));
        $this->drive($add, 'kq');
        $decoded = \json_decode((string) \file_get_contents($add . '/ansi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['keep'], $decoded['demo/one.ansi']['tags']);

        $twice = $this->makeTree($this->index($this->entry(rating: null)));
        $this->drive($twice, 'kkq');
        $decoded = \json_decode((string) \file_get_contents($twice . '/ansi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $decoded['demo/one.ansi']['tags'], 'toggle-on then toggle-off must restore the entry');

        $stray = $this->makeTree($this->index($this->entry(rating: null)));
        $this->drive($stray, 'Zq');
        $this->assertSame(
            $pristine,
            (string) \file_get_contents($stray . '/ansi.json'),
            'an unbound key rewrote the index — the tool\'s whole write discipline is that only '
            . 'bound edits persist',
        );
        $this->assertSame([], \glob($stray . '/ansi.json.ansi-view.*.tmp'), 'an unbound key left a temp file');
    }

    // -----------------------------------------------------------------
    // Promise 3: load refuses, by name, anything it cannot honour
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedIndexes(): iterable
    {
        yield 'a top-level array is not an index' => ['null', 'must hold a JSON object'];
        yield 'an entry that is not an object' => ['{"demo/one.ansi": 42}', 'is not an object'];
        yield 'an entry with no name' => [
            '{"demo/one.ansi": {"category": "demo", "depth": "16", "description": "d", "rating": null, "tags": []}}',
            "has no string 'name'",
        ];
        yield 'a depth outside the ranked three' => [
            '{"demo/one.ansi": ' . \json_encode(self::entry(depth: 'greyscale')) . '}',
            "has unknown depth 'greyscale'",
        ];
        yield 'an entry missing the rating key entirely' => [
            '{"demo/one.ansi": ' . \json_encode(self::entry(ratingAbsent: true)) . '}',
            'has no rating field',
        ];
        yield 'a rating above the scale' => [
            '{"demo/one.ansi": ' . \json_encode(self::entry(rating: 10)) . '}',
            'rating must be null (unset) or an integer 0-9',
        ];
        yield 'a rating that is a string' => [
            '{"demo/one.ansi": ' . \json_encode(self::entry(rating: 'four')) . '}',
            'rating must be null (unset) or an integer 0-9',
        ];
        yield 'tags that are not a list' => [
            '{"demo/one.ansi": ' . \json_encode(self::entry(tagsAssoc: true)) . '}',
            'tags must be a JSON array',
        ];
        yield 'a tag that is not a string' => [
            '{"demo/one.ansi": ' . \json_encode(self::entry(badTag: true)) . '}',
            'carries a non-string tag',
        ];
    }

    /**
     * @dataProvider malformedIndexes
     */
    public function testTheViewerRefusesAMalformedIndexByNameBeforeWritingAnything(string $raw, string $reason): void
    {
        $root = \sys_get_temp_dir() . '/ansi-view-refuse-' . \bin2hex(\random_bytes(6));
        \mkdir($root . '/demo', 0777, true);
        $this->temps[] = $root;
        \file_put_contents($root . '/demo/one.ansi', "\x1b[31mok\x1b[0m\n");
        \file_put_contents($root . '/ansi.json', $raw . "\n");

        $run = $this->drive($root, 'q');
        $this->assertSame(3, $run['exit'], 'stderr: ' . $run['stderr']);
        $this->assertStringContainsString($reason, $run['stderr'], 'refused, but not by name: ' . $run['stderr']);
        $this->assertSame($raw . "\n", (string) \file_get_contents($root . '/ansi.json'), 'a refusal rewrote the file');
        $this->assertSame([], \glob($root . '/ansi.json.ansi-view.*.tmp'), 'a refusal left a temp file');
    }

    public function testAnUnreadableIndexExitsTwoNamingThePath(): void
    {
        $root = \sys_get_temp_dir() . '/ansi-view-none-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0777, true);
        $this->temps[] = $root;
        $missing = $root . '/ansi.json';
        $run = $this->drive($root, 'q');
        $this->assertSame(2, $run['exit'], 'stderr: ' . $run['stderr']);
        $this->assertStringContainsString('cannot read ' . $missing, $run['stderr']);
    }

    // -----------------------------------------------------------------
    // The roster arm: no tools/ script ships untested-and-unannounced
    // -----------------------------------------------------------------

    public function testEveryToolsScriptIsNamedByATestOrExemptedByName(): void
    {
        $scripts = \glob(\dirname(__DIR__) . '/*.php') ?: [];
        $this->assertGreaterThanOrEqual(13, \count($scripts), 'the tools/ roster went empty — this test is vacuous');
        $this->assertContains(\dirname(__DIR__) . '/ansi-view.php', $scripts);

        $tests = \glob(__DIR__ . '/*Test.php') ?: [];
        $unnamed = [];
        foreach ($scripts as $script) {
            $name = \basename($script);
            if (isset(self::EXEMPT_WITH_REASON[$name])) {
                continue;
            }
            $named = false;
            foreach ($tests as $test) {
                if (\strpos((string) \file_get_contents($test), $name) !== false) {
                    $named = true;

                    break;
                }
            }
            if (!$named) {
                $unnamed[] = $name;
            }
        }
        \sort($unnamed);

        $this->assertSame(
            [],
            $unnamed,
            'These tools/ scripts are named by nothing under tools/tests/ and carry no exemption '
            . 'row. A script that deletes, rewrites, or gates is a liability with a shebang: write '
            . 'the test, or add it to AnsiViewTest::EXEMPT_WITH_REASON with the reason a reviewer '
            . 'can argue with.',
        );
    }

    // -----------------------------------------------------------------
    // Instruments
    // -----------------------------------------------------------------

    /**
     * One committed-shaped entry. Only the fields loadIndex demands are hard;
     * width/height sit in the 's' tier box so render takes the plain path.
     * The rating is `mixed` because the refusal arms need to hand it things a
     * reviewer would never type — an entry() alias keeps call sites readable.
     *
     * @return array<string, mixed>
     */
    private static function entry(
        mixed $rating = null,
        array $tags = [],
        bool $ratingAbsent = false,
        ?string $depth = null,
        bool $tagsAssoc = false,
        bool $badTag = false,
    ): array {
        $entry = [
            'name' => 'one.ansi',
            'category' => 'demo',
            'resolution' => '60x10',
            'width' => 60,
            'height' => 10,
            'depth' => $depth ?? '16',
            'colors' => 1,
            'description' => 'fixture art',
        ];
        if (!$ratingAbsent) {
            $entry['rating'] = $rating;
        }
        $entry['tags'] = $tagsAssoc ? ['keep' => 'keep'] : ($badTag ? [4] : $tags);

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, array<string, mixed>>
     */
    private function index(array $entry): array
    {
        return ['demo/one.ansi' => $entry];
    }

    /** @param array<string, array<string, mixed>> $index */
    private function makeTree(array $index): string
    {
        $root = \sys_get_temp_dir() . '/ansi-view-tree-' . \bin2hex(\random_bytes(6));
        \mkdir($root . '/demo', 0777, true);
        $this->temps[] = $root;
        \file_put_contents($root . '/demo/one.ansi', "\x1b[31mok\x1b[0m\n");
        \file_put_contents($root . '/ansi.json', \json_encode($index, self::FLAGS) . "\n");

        return $root;
    }

    /**
     * Drive the viewer with a piped keystroke script, stdout and stderr to
     * files (the frames are screen paint and would flood exec()'s capture).
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function drive(string $root, string $keys): array
    {
        $out = $root . '/.frame.out';
        $err = $root . '/.frame.err';
        $cmd = 'printf %s ' . \escapeshellarg($keys)
            . ' | ' . \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__, 2) . self::SCRIPT)
            . ' --root=' . \escapeshellarg($root)
            . ' > ' . \escapeshellarg($out) . ' 2> ' . \escapeshellarg($err);
        $lines = [];
        $exitCode = 0;
        \exec($cmd, $lines, $exitCode);
        $stderr = (string) \file_get_contents($err);
        @unlink($out);
        @unlink($err);

        return ['exit' => $exitCode, 'stdout' => '', 'stderr' => $stderr];
    }
}
