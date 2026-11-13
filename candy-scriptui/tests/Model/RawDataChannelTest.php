<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\FilterModel;

/**
 * scriptui-15 (finding 013): the selection payload is the DATA channel.
 * A real filename with a TAB must come back with its TAB, a styled line
 * with its SGR — `printf 'a\tb\n' | candy-scriptui filter` on a file
 * literally named "a<TAB>b" used to emit "a·b", naming nothing that
 * exists. The paint channel stays escaped (UntrustedPaintTest); these
 * tests pin the EMIT channel's byte fidelity.
 */
final class RawDataChannelTest extends TestCase
{
    public function testChooseEmitsTabBearingNameVerbatim(): void
    {
        $model = ChooseModel::fromOptions(["a\tb"]);
        $submitted = $model->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame("a\tb", $submitted->selected());
    }

    public function testFilterEmitsEscAndC1BearingLinesVerbatim(): void
    {
        $styled = "c\x1b[31md";            // real ANSI-coloured ls output
        $c1     = "e\x{9b}f";             // UTF-8-encoded C1 CSI
        $model     = FilterModel::fromOptions([$styled, 'plain', $c1]);
        $submitted = $model->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame($styled, $submitted->selected());

        [$model, ] = $model->update(new KeyMsg(KeyType::Down, ''));
        [$model, ] = $model->update(new KeyMsg(KeyType::Down, ''));
        $submitted = $model->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame($c1, $submitted->selected());
    }

    public function testEveryInputLineRoundTripsThroughThePayload(): void
    {
        $options = ["tab\there", "esc\x1b[5mend", "bel\x07low", "nul\x00point", 'clean'];
        $model   = FilterModel::fromOptions($options, noLimit: true);
        // Tick every row (Tab toggles the cursor row; Space is eaten by
        // the filter buffer in this model), then submit.
        for ($i = 0; $i < count($options); $i++) {
            [$model, ] = $model->update(new KeyMsg(KeyType::Tab, ''));
            [$model, ] = $model->update(new KeyMsg(KeyType::Down, ''));
        }
        $submitted = $model->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame($options, $submitted->selectedAll());
    }

    public function testMarkedFrameCannotLeakNulOrBelToTheWire(): void
    {
        // The frame the human sees is still escaped even though the
        // model stores raw bytes (both channels verified from ONE model).
        $model = FilterModel::fromOptions(["raw\x00\x07bytes"], noLimit: true);
        $view  = $model->view();
        self::assertStringNotContainsString("\x00", $view);
        self::assertStringNotContainsString("\x07", $view);
        self::assertStringContainsString('raw', $view);
        self::assertStringContainsString('bytes', $view);
    }
}
