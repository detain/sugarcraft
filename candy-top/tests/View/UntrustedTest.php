<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\ContainerInfo;
use SugarCraft\Top\Collect\ContainerRef;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Panel\Proc\ProcEntry;
use SugarCraft\Top\Panel\Ctr\CtrView;
use SugarCraft\Top\Panel\Proc\ProcView;
use SugarCraft\Top\View\Untrusted;

/**
 * candy-top-096: the shared untrusted-identifier sink. Invisible bidi and
 * zero-width format characters must never paint a column silently reversed
 * or blanked — they render as their visible <U+XXXX> markers, while the
 * panel-local space-fold of control bytes and the scrub of invalid UTF-8
 * keep their shipped semantics.
 */
final class UntrustedTest extends TestCase
{
    public function testInvisibleFormattersAreMarkedAndControlBytesSpaceFolded(): void
    {
        $hostile = "web\u{202E}can \u{200B}server\u{FEFF}";
        $clean = Untrusted::text($hostile);
        self::assertStringContainsString('<U+202E>', $clean, 'the RLO shows itself');
        self::assertStringNotContainsString("\u{202E}", $clean);
        self::assertStringContainsString('<U+200B>', $clean, 'the zero-width space shows itself');
        self::assertStringContainsString('<U+FEFF>', $clean, 'the BOM shows itself');
        self::assertSame('a b', Untrusted::text("a\x07b"), 'control bytes keep the space fold');
        self::assertSame('plain', Untrusted::text('plain'), 'clean names are untouched');
    }

    public function testTheProcTagSinkMarks(): void
    {
        $mk = static fn (?ContainerRef $info): ProcEntry => new ProcEntry(
            new Process(1, 1, 'p', 'p', 'root', 0, 'S', 1, 0, 0, 0.0, 0.0, container: $info),
            0.0, 0.0, 0, 1, 0.0, 0.0,
        );
        $tainted = $mk(new ContainerRef('docker', 'abc', "bad\u{202E}name", '/docker/abc'));
        self::assertStringContainsString('<U+202E>', ProcView::tag($tainted));
        self::assertStringNotContainsString("\u{202E}", ProcView::tag($tainted));
        self::assertSame('', ProcView::tag($mk(null)), 'no container, no tag');
    }

    public function testTheCtrDetailTitleSinkMarks(): void
    {
        $c = new ContainerInfo('podman', "good\u{202E}stuff", '/kubepods/p1');
        $title = CtrView::detailTitle($c, 60, false, 4);
        self::assertStringContainsString('<U+202E>', $title, 'the override shows itself');
        self::assertStringNotContainsString("\u{202E}", $title);
    }

    public function testTheVmCardTitleGoesThroughTheSink(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Panel/Vms/VmCard.php');
        self::assertStringContainsString(
            '$name = Width::truncate(Untrusted::text($g->name)',
            $source,
            'the card title is sink-cleaned before truncation',
        );
    }
}
