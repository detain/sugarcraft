<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Notebook;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Notebook\Notebook;

/**
 * The nbformat v4 model behind `NotebookEdit` (roadmap C-TOOL-6.b).
 *
 * The load-bearing promise is that an edit touches ONE cell: every other
 * cell — its metadata, outputs, attachments — round-trips byte-for-byte,
 * so `{}` never becomes `[]`, `1.0` never becomes `1`, a raw U+2028 never
 * gets re-escaped, and the file's own indent is preserved rather than
 * flattened to json_encode's default. The fixtures under
 * `tests/fixtures/notebooks/` carry those legs on purpose: v45-outputs has
 * a stream + image + error cell, markdown attachments with a raw line
 * separator and a float, and v44-idless is a no-ids document the model
 * must still address (by `cell-<n>`).
 */
final class NotebookTest extends TestCase
{
    private const NOTEBOOK_FIXTURES = __DIR__ . '/../fixtures/notebooks/';

    private function notebookOf(string $fixture): Notebook
    {
        $notebook = Notebook::parse((string) file_get_contents(self::NOTEBOOK_FIXTURES . $fixture));
        self::assertNotNull($notebook, $fixture . ' must parse — it is the designed-to-work case');

        return $notebook;
    }

    public function testTheRichFixtureRoundTripsByteForByte(): void
    {
        $bytes = (string) file_get_contents(self::NOTEBOOK_FIXTURES . 'v45-outputs.ipynb');
        // The fixture itself carries a raw U+2028 (not \u2028) and a raw
        // em-dash; if it ever stops carrying them this test proves nothing.
        self::assertStringContainsString("\u{2028}", $bytes, 'fixture lost its raw line separator');
        self::assertStringNotContainsString('\u2028', $bytes, 'fixture re-escaped its line separator');

        self::assertSame($bytes, $this->notebookOf('v45-outputs.ipynb')->serialize());
    }

    public function testTheIdlessFixtureRoundTripsByteForByte(): void
    {
        $bytes = (string) file_get_contents(self::NOTEBOOK_FIXTURES . 'v44-idless.ipynb');
        // String-form sources (not the list form) and an empty metadata
        // object must survive; the v45 file pins the list form.
        self::assertStringContainsString('"source": "id-less', $bytes, 'fixture lost its string-form source');
        self::assertSame($bytes, $this->notebookOf('v44-idless.ipynb')->serialize());
    }

    public function testEmptyObjectAndEmptyListDoNotCollideAcrossRoundTrip(): void
    {
        $bytes = (string) file_get_contents(self::NOTEBOOK_FIXTURES . 'v45-outputs.ipynb');
        // The fixture carries both shapes: `widgets` is a top-level object
        // leg and `tags` an empty list leg. json_decode(assoc) would have
        // merged them at mint time; decode-with-false is what keeps them
        // distinct.
        self::assertMatchesRegularExpression('/"widgets": \{\}/', $bytes);
        self::assertMatchesRegularExpression('/"tags": \[\]/', $bytes);

        $round = json_decode($this->notebookOf('v45-outputs.ipynb')->serialize());
        $original = json_decode($bytes);
        self::assertIsObject($round->widgets);
        self::assertIsArray($round->cells[1]->metadata->tags);
        self::assertEquals($original, $round);
    }

    public function testTheFloatLegStaysFloatOnTheWire(): void
    {
        self::assertStringContainsString(
            '"figure_width": 1.0',
            $this->notebookOf('v45-outputs.ipynb')->serialize(),
            'PRESERVE_ZERO_FRACTION regressed — 1.0 became 1 and every tool that reads the number sees a different type',
        );
    }

    public function testNonV4AndUnparsableNotesRefuseToParse(): void
    {
        self::assertNull(Notebook::parse((string) file_get_contents(self::NOTEBOOK_FIXTURES . 'v3-legacy.ipynb')));
        self::assertNull(Notebook::parse('not json at all {'));
        self::assertNull(Notebook::parse('{"nbformat": 5, "nbformat_minor": 0, "cells": []}'));
        self::assertNull(Notebook::parse('[]'));
        self::assertNull(Notebook::parse('{"nbformat": 4, "cells": {"0": {}}}'));
    }

    public function testIdlessCellsAreAddressedByPositionAndIdBearingCellsAreNot(): void
    {
        $idless = $this->notebookOf('v44-idless.ipynb');
        self::assertSame(['cell-0', 'cell-1'], $idless->cellKeys());
        self::assertTrue($idless->hasCell('cell-1'));

        $rich = $this->notebookOf('v45-outputs.ipynb');
        self::assertSame(['4f1c2b8a', 'a1b2c3d4', 'deadbeef', '01234567'], $rich->cellKeys());
        self::assertFalse($rich->hasCell('cell-0'), 'a positional key is not a back door into an id-bearing document');
        self::assertNull($rich->delete('cell-0'));
    }

    public function testReplaceKeepsIdAndMetadataAndResetsExecutionState(): void
    {
        $edited = $this->notebookOf('v45-outputs.ipynb')
            ->replace('a1b2c3d4', "print('rewritten')\n");
        self::assertNotNull($edited);

        $cells = $edited->describe();
        self::assertSame(['key' => 'a1b2c3d4', 'type' => 'code', 'id' => 'a1b2c3d4', 'source' => "print('rewritten')\n", 'execution_count' => null, 'outputs' => []], $cells[1]);

        $wire = json_decode($edited->serialize());
        self::assertSame('a1b2c3d4', $wire->cells[1]->id);
        self::assertSame([], (array) $wire->cells[1]->metadata->tags, 'cell metadata survives the replace — only execution state resets');
    }

    public function testUntouchedCellsSerializeByteIdenticallyThroughAnEdit(): void
    {
        $before = json_decode((string) file_get_contents(self::NOTEBOOK_FIXTURES . 'v45-outputs.ipynb'));
        $after = json_decode($this->notebookOf('v45-outputs.ipynb')
            ->replace('a1b2c3d4', 'x = 1')
            ->serialize());

        foreach ([0, 2, 3] as $i) {
            self::assertSame(
                json_encode($before->cells[$i]),
                json_encode($after->cells[$i]),
                "cell $i was re-touched by an edit that named a different cell (attachments/outputs must ride verbatim)",
            );
        }
        self::assertSame(json_encode($before->metadata), json_encode($after->metadata), 'document metadata must ride verbatim');
    }

    public function testInsertAfterAnchorsAndMintsIdsOnlyOnModernDocuments(): void
    {
        $rich = $this->notebookOf('v45-outputs.ipynb');
        $inserted = $rich->insert('4f1c2b8a', "# new\n", 'markdown');
        self::assertNotNull($inserted);
        [$withCell, $index] = $inserted;
        self::assertSame(1, $index);
        $keys = $withCell->cellKeys();
        self::assertSame('4f1c2b8a', $keys[0]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}\z/', $keys[1], 'a nbformat 4.5 insert mints the 8-hex cell id');
        self::assertSame('a1b2c3d4', $keys[2]);

        // A head insert needs no anchor and lands first.
        $atFront = $rich->insert(null, "front\n", 'code');
        self::assertNotNull($atFront);
        self::assertSame(0, $atFront[1]);

        // The 4.4 document has no id concept — inserting must not fabricate keys.
        $idless = $this->notebookOf('v44-idless.ipynb')->insert(null, "# new\n", 'markdown');
        self::assertNotNull($idless);
        self::assertStringNotContainsString('"id"', $idless[0]->serialize(), 'ids below nbformat_minor 5 are a wire-format violation');

        self::assertNull($rich->insert('nosuchcell', 'x', 'code'));
    }

    public function testDeleteRemovesExactlyOneCell(): void
    {
        $deleted = $this->notebookOf('v45-outputs.ipynb')->delete('deadbeef');
        self::assertNotNull($deleted);
        self::assertSame(3, $deleted->cellCount());
        self::assertSame(['4f1c2b8a', 'a1b2c3d4', '01234567'], $deleted->cellKeys());
        self::assertNull($this->notebookOf('v45-outputs.ipynb')->delete('deadbeef2'));
    }

    public function testTypeFlipCarriesExecutionStateInBothDirections(): void
    {
        // code -> markdown: outputs and execution_count leave the cell.
        $flipped = $this->notebookOf('v45-outputs.ipynb')->replace('a1b2c3d4', "# heading\n", 'markdown');
        self::assertNotNull($flipped);
        $wire = json_decode($flipped->serialize());
        self::assertSame('markdown', $wire->cells[1]->cell_type);
        self::assertFalse(property_exists($wire->cells[1], 'outputs'));
        self::assertFalse(property_exists($wire->cells[1], 'execution_count'));
        self::assertSame('a1b2c3d4', $wire->cells[1]->id, 'a type flip is the same cell, not a replace-with-fresh');

        // markdown -> code: the canonical key skeleton comes back.
        $back = $this->notebookOf('v45-outputs.ipynb')->replace('4f1c2b8a', "1 + 1\n", 'code');
        self::assertNotNull($back);
        $codeWire = json_decode($back->serialize());
        self::assertSame(
            ['cell_type', 'execution_count', 'id', 'metadata', 'outputs', 'source'],
            array_keys(get_object_vars($codeWire->cells[0])),
        );
        self::assertNull($codeWire->cells[0]->execution_count);
        self::assertSame([], $codeWire->cells[0]->outputs);
    }

    public function testTheDetectedIndentIsReusedNotJsonEncodesDefault(): void
    {
        $fourIndent = json_encode(json_decode((string) file_get_contents(self::NOTEBOOK_FIXTURES . 'v44-idless.ipynb')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        self::assertStringContainsString("\n    \"cells\": [", $fourIndent);

        $notebook = Notebook::parse($fourIndent);
        self::assertNotNull($notebook);
        $out = $notebook->delete('cell-1')->serialize();
        self::assertStringContainsString("\n    \"cells\": [", $out, 'a 4-space notebook came back with json_encode defaults');
        self::assertStringContainsString("\n \"cells\": [\n", $this->notebookOf('v45-outputs.ipynb')->serialize(), 'the 1-space nbformat shape must come back 1-spaced, not at json_encode defaults');
    }
}
