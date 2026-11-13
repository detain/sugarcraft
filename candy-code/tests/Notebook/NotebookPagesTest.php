<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Notebook;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Notebook\Notebook;
use SugarCraft\Code\Notebook\NotebookPages;

final class NotebookPagesTest extends TestCase
{
    /** @param list<array<string, mixed>> $cells */
    private function notebook(array $cells, int $minor = 5): Notebook
    {
        $json = json_encode(
            ['nbformat' => 4, 'nbformat_minor' => $minor, 'metadata' => new \stdClass(), 'cells' => $cells],
            JSON_UNESCAPED_SLASHES,
        );
        $notebook = Notebook::parse((string) $json);
        self::assertNotNull($notebook);

        return $notebook;
    }

    private function md(string $src, ?string $id = null): array
    {
        $c = ['cell_type' => 'markdown', 'metadata' => new \stdClass(), 'source' => $src];
        if ($id !== null) {
            $c['id'] = $id;
        }

        return $c;
    }

    private function code(string $src, array $outputs = [], ?int $count = null, ?string $id = null): array
    {
        $c = ['cell_type' => 'code', 'metadata' => new \stdClass(), 'source' => $src, 'outputs' => $outputs, 'execution_count' => $count];
        if ($id !== null) {
            $c['id'] = $id;
        }

        return $c;
    }

    public function testHeaderCarriesIndexTypeIdAndRunCount(): void
    {
        $nb = $this->notebook([$this->md('# Title', 'abc'), $this->code("print(1)\n", [], 4, 'def')]);

        $page = NotebookPages::render($nb, 1, 10);

        self::assertSame("[cell 1 · markdown · id abc]\n# Title\n[cell 2 · code · id def · ran 4]\nprint(1)", $page);
    }

    public function testIdlessCellOmitsIdAndEmptySourceIsMarked(): void
    {
        $page = NotebookPages::render($this->notebook([$this->md('')], 4), 1, 5);

        self::assertSame("[cell 1 · markdown]\n(empty)", $page);
    }

    public function testPaginationFooterNamesTheNextOffset(): void
    {
        $nb = $this->notebook([$this->md('a'), $this->md('b'), $this->md('c')], 4);

        $page = NotebookPages::render($nb, 1, 2);

        self::assertStringContainsString("[cell 1 · markdown]\na\n[cell 2 · markdown]\nb", (string) $page);
        self::assertStringNotContainsString('c' . "\n", (string) $page);
        self::assertStringEndsWith('[cells 1-2 of 3 — call Read with offset=3 to continue]', (string) $page);
    }

    public function testLastPageHasNoFooter(): void
    {
        $nb = $this->notebook([$this->md('a'), $this->md('b'), $this->md('c')], 4);

        $page = (string) NotebookPages::render($nb, 3, 5);

        self::assertSame("[cell 3 · markdown]\nc", $page);
    }

    public function testOffsetBoundsAndLimitValidation(): void
    {
        $nb = $this->notebook([$this->md('a')], 4);

        self::assertNull(NotebookPages::render($nb, 0, 1));
        self::assertNull(NotebookPages::render($nb, 1, 0));
        self::assertNull(NotebookPages::render($nb, 3, 1));
        // offset one past the end is tolerated and renders an empty page
        self::assertSame('', NotebookPages::render($nb, 2, 1));
    }

    public function testOutputDigestsPerKind(): void
    {
        $outputs = [
            (object) ['output_type' => 'stream', 'name' => 'stdout', 'text' => ['héllo', ' wörld']],
            (object) ['output_type' => 'error', 'ename' => 'ValueError', 'evalue' => 'x', 'traceback' => []],
            (object) ['output_type' => 'error'],
            (object) ['output_type' => 'display_data', 'data' => (object) ['text/plain' => 'abcd'], 'metadata' => new \stdClass()],
            (object) ['output_type' => 'display_data', 'data' => new \stdClass(), 'metadata' => new \stdClass()],
            (object) ['output_type' => 'mystery'],
            'not-an-object',
        ];
        $page = (string) NotebookPages::render($this->notebook([$this->code('x', $outputs, 1)], 4), 1, 1);

        self::assertStringContainsString("  outputs:\n", $page);
        self::assertStringContainsString("  - stream 11 chars\n", $page);
        self::assertStringContainsString("  - error: ValueError\n", $page);
        self::assertStringContainsString("  - error: <unknown>\n", $page);
        self::assertStringContainsString("  - text/plain 4 chars\n", $page);
        self::assertStringContainsString("  - display_data\n", $page);
        self::assertStringEndsWith("  - mystery", $page);
        self::assertStringNotContainsString('not-an-object', $page);
    }

    public function testImagePayloadsReportDecodedSizeAndPreferredMime(): void
    {
        $png = base64_encode(str_repeat('x', 3000));
        $out = (object) [
            'output_type' => 'execute_result',
            'data' => (object) ['text/plain' => '<Figure>', 'image/png' => $png],
            'metadata' => new \stdClass(),
            'execution_count' => 1,
        ];

        $page = (string) NotebookPages::render($this->notebook([$this->code('plot()', [$out], 1)], 4), 1, 1);

        
        self::assertStringContainsString("  - image/png 3 KB", $page);
        self::assertStringNotContainsString($png, $page);
    }

    public function testSmallBinaryPayloadReportsBytes(): void
    {
        $out = (object) [
            'output_type' => 'display_data',
            'data' => (object) ['image/png' => base64_encode('abcdef')],
            'metadata' => new \stdClass(),
        ];

        $page = (string) NotebookPages::render($this->notebook([$this->code('x', [$out], 1)], 4), 1, 1);

        self::assertStringContainsString('  - image/png 6 B', $page);
    }

    public function testMarkdownCellsNeverListOutputs(): void
    {
        $page = (string) NotebookPages::render($this->notebook([$this->md('hi')], 4), 1, 1);

        self::assertStringNotContainsString('outputs:', $page);
    }

    public function testCodeCellWithNoOutputsOmitsOutputsBlock(): void
    {
        $page = (string) NotebookPages::render($this->notebook([$this->code('x')], 4), 1, 1);

        self::assertStringNotContainsString('outputs:', $page);
    }
}
