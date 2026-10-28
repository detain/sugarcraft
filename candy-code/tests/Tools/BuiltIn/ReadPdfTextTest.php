<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Tools\BuiltIn\Read;

/**
 * C-TOOL-2 (GAP-TL-20): the Read PDF text leg — `pdftotext -layout` through
 * the shared CapturesProcessOutput::runCaptured seam (no new spawn token),
 * paged with the call's own offset/limit in Read's canonical `N: ` shape —
 * and its fall-through chain.
 *
 * THE ONE-ROAD-PER-READ LAW pinned here: when the extractor answers with
 * usable text, the result is a TEXT PAGE and carries NO document bytes (no
 * double-send); when no extractor answers — absent on the host, or present
 * but refusing this file (exit non-zero, empty output, timeout) — the
 * in-cap PDF rides as `documentBytes` for the wire's `document` block, and
 * an over-budget PDF rides nothing but its notice. The extractor-failure
 * arms below are host-independent BY CONSTRUCTION: the capability seam is
 * INJECTED (`pdfTextAvailable: true`) so the spawn is attempted either way
 * — with the binary the extractor errors on the forged body, without it the
 * shell answers 127 — and both land on the same fall-through.
 *
 * The success arms genuinely need the binary; on a host without it they pin
 * the fall-through instead, which is the same pdfAnswer gate from its other
 * side, so the file is deterministic everywhere and never silently vacuous.
 *
 * The road-ban is pinned too: since C-TOOL-2.d no PDF answer tells the
 * model to "@-mention" anything — a mention is something the USER types,
 * not a road the model can walk — and the capability-NEUTRAL description is
 * pinned byte-stable across injected capability polarities (golden-prompt
 * discipline: description() must never read the seam).
 */
final class ReadPdfTextTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_readpdftext_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0o700, true));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }

        rmdir($this->dir);
    }

    /**
     * A genuinely valid 1-page PDF whose text layer is $text, assembled with
     * byte-accurate xref offsets (verified against poppler's parser). The
     * fixture must be REAL because the success arms drive the real
     * extractor; the magic header alone is what re-sniffing gates demand.
     */
    private function writeRealPdf(string $name, string $text): string
    {
        $ops = "BT\n/F1 12 Tf\n16 TL\n72 720 Td\n";
        foreach (explode("\n", $text) as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $ops .= '(' . $escaped . ") Tj\nT*\n";
        }
        $ops .= 'ET';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($ops) . " >>\nstream\n" . $ops . "\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefAt = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer' . "\n" . '<< /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n"
            . "startxref\n" . $xrefAt . "\n%%EOF\n";

        $path = $this->dir . '/' . $name;
        self::assertTrue(file_put_contents($path, $pdf) !== false);

        return $path;
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        self::assertTrue(file_put_contents($path, $content) !== false);

        return $path;
    }

    // -------------------------------------------------------------------------
    // Door one: the extractor answers (host-guarded; fall-through pinned else)
    // -------------------------------------------------------------------------

    public function testTheExtractorAnswerIsPagedLikeAnyOtherFile(): void
    {
        $path = $this->writeRealPdf('plan.pdf', "Hello PDF world\nSecond line of the document");

        if (!Bootstrap::capabilityPresent('pdftotext')) {
            $result = (new Read(pdfTextAvailable: true))->execute(['file_path' => $path]);
            $this->assertTrue($result->hasDocument(), 'without the binary the injected seam still falls through to the document door');

            return;
        }

        $result = (new Read(pdfTextAvailable: true))->execute(['file_path' => $path]);

        $this->assertFalse($result->isError());
        $this->assertSame("1: Hello PDF world\n2: Second line of the document\n", $result->content());
        $this->assertFalse($result->hasDocument(), 'ONE ROAD PER READ: the extractor answered, so no bytes ride too');
        $this->assertFalse($result->hasImage());
    }

    public function testTheExtractorPageHonoursOffsetAndLimit(): void
    {
        $path = $this->writeRealPdf('plan.pdf', "Hello PDF world\nSecond line of the document");

        if (!Bootstrap::capabilityPresent('pdftotext')) {
            $this->assertTrue((new Read(pdfTextAvailable: true))->execute(['file_path' => $path])->hasDocument());

            return;
        }

        $result = (new Read(pdfTextAvailable: true))->execute(['file_path' => $path, 'offset' => 2, 'limit' => 1]);

        $this->assertFalse($result->isError());
        $this->assertSame("2: Second line of the document\n", $result->content(), 'the last line pages without a footer, exactly like readPage');
    }

    public function testTheExtractorPageCarriesTheContinuationFooter(): void
    {
        $path = $this->writeRealPdf('plan.pdf', "Hello PDF world\nSecond line of the document");

        if (!Bootstrap::capabilityPresent('pdftotext')) {
            $this->assertTrue((new Read(pdfTextAvailable: true))->execute(['file_path' => $path])->hasDocument());

            return;
        }

        $result = (new Read(pdfTextAvailable: true))->execute(['file_path' => $path, 'limit' => 1]);

        $this->assertSame("1: Hello PDF world\n\n\n[line 1 of 2 — call Read with offset=2 to continue]", $result->content());
    }

    public function testAnOffsetPastTheExtractedEndIsAnErrorRowNamingTheCount(): void
    {
        $path = $this->writeRealPdf('plan.pdf', "Hello PDF world\nSecond line of the document");

        if (!Bootstrap::capabilityPresent('pdftotext')) {
            $this->assertTrue((new Read(pdfTextAvailable: true))->execute(['file_path' => $path])->hasDocument());

            return;
        }

        $result = (new Read(pdfTextAvailable: true))->execute(['file_path' => $path, 'offset' => 5]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('past the end', $result->content());
        $this->assertStringContainsString('2 lines', $result->content());
    }

    // -------------------------------------------------------------------------
    // Door two: the extractor refuses, the bytes ride
    // -------------------------------------------------------------------------

    public function testAPdfTheExtractorCannotReadFallsThroughToCarryingTheDocument(): void
    {
        // Host-independent BY CONSTRUCTION: with the binary, pdftotext errors
        // on the forged body; without it the shell answers 127. Either way
        // the gate (exitCode !== 0) lands on the document door — and the
        // caption says "answered nothing usable", not "no extractor".
        $pdf = "%PDF-1.4\nthis can not be a content stream\n%%EOF\n";
        $path = $this->write('spec.pdf', $pdf);

        $result = (new Read(pdfTextAvailable: true))->execute(['file_path' => $path]);

        $this->assertFalse($result->isError());
        $this->assertTrue($result->hasDocument());
        $this->assertSame($pdf, $result->documentBytes());
        $this->assertSame('spec.pdf', $result->documentName());
        $this->assertStringContainsString('text extractor on this host answered nothing usable', $result->content());
        $this->assertStringNotContainsString('@', $result->content(), 'no answer tells the model to mention anything');
    }

    public function testWithoutAnExtractorTheInCapPdfRidesTheResult(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat('a', 64);
        $path = $this->write('spec.pdf', $pdf);

        $result = (new Read(pdfTextAvailable: false))->execute(['file_path' => $path]);

        $this->assertFalse($result->isError());
        $this->assertTrue($result->hasDocument());
        $this->assertFalse($result->hasImage(), 'a PDF is never smuggled through the image leg');
        $this->assertSame($pdf, $result->documentBytes());
        $this->assertSame('spec.pdf', $result->documentName());
        $this->assertStringContainsString('Binary PDF document', $result->content());
        $this->assertStringContainsString('no text extractor is available on this host', $result->content());
        $this->assertStringContainsString('bytes ride', $result->content());
        $this->assertStringNotContainsString('NUL byte', $result->content());
        $this->assertStringNotContainsString('1: ', $result->content(), 'a PDF has no lines to number');
        $this->assertStringNotContainsString('@', $result->content(), 'the C-TOOL-2 caption names no road the model cannot walk');
    }

    public function testAPdfOverTheResultBudgetRidesNothingButItsNotice(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat('a', AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES + 1);
        $path = $this->write('huge.pdf', $pdf);

        $result = (new Read(pdfTextAvailable: false))->execute(['file_path' => $path]);

        $this->assertFalse($result->isError());
        $this->assertFalse($result->hasDocument(), 'the budget refuses OVER it, and a cut PDF is not a PDF — never truncated bytes');
        $this->assertStringContainsString('1,048,576-byte result budget', $result->content());
        $this->assertStringContainsString('not carried either', $result->content());
        $this->assertStringNotContainsString('@', $result->content());
    }

    public function testTheOverBudgetNoticeAlsoAnswersWhenTheExtractorFailsOnIt(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat('a', AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES + 1);
        $path = $this->write('huge.pdf', $pdf);

        $result = (new Read(pdfTextAvailable: true))->execute(['file_path' => $path]);

        $this->assertFalse($result->hasDocument());
        $this->assertStringContainsString('not carried either', $result->content());
    }

    // -------------------------------------------------------------------------
    // The pager's capped-extractor honesty note (direct shape pin)
    // -------------------------------------------------------------------------

    public function testTheExtractionCapNoteRidesThePageOnlyWhenTheCaptureWasCut(): void
    {
        $page = new ReflectionMethod(Read::class, 'pageExtracted');
        $page->setAccessible(true);
        $read = new Read(pdfTextAvailable: false);

        $capped = $page->invoke($read, 'c_1', "line one\nline two", null, null, '/tmp/x.pdf', true);
        $this->assertStringContainsString("1: line one\n2: line two\n", $capped->content());
        $this->assertStringContainsString('262,144-byte extraction cap', $capped->content());
        $this->assertStringContainsString('pageable above', $capped->content());

        $whole = $page->invoke($read, 'c_2', "line one\nline two", null, null, '/tmp/x.pdf', false);
        $this->assertStringNotContainsString('extraction cap', $whole->content());
    }

    // -------------------------------------------------------------------------
    // The description: both doors named, zero branching on the host
    // -------------------------------------------------------------------------

    public function testTheDescriptionSpeaksBothDoorsAndNeverBranchesOnTheSeam(): void
    {
        $withExtractor = (new Read(pdfTextAvailable: true))->description();
        $withoutExtractor = (new Read(pdfTextAvailable: false))->description();

        $this->assertSame($withExtractor, $withoutExtractor, 'golden-prompt discipline: description() never reads the capability seam');
        $this->assertStringContainsString('as a page of its extracted text when this host has a PDF text extractor', $withExtractor);
        $this->assertStringContainsString(number_format(AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES) . '-byte result budget', $withExtractor);
        $this->assertStringNotContainsString('Mention the file', $withExtractor, 'the pre-C-TOOL-2 @-mention advice is gone from the PDF road the description advertises');
    }
}
