<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\ScriptUi\Model\PagerModel;

/**
 * scriptui-20 — a WindowSizeMsg re-fit must keep the hint-footer
 * reservation: the composed frame (viewport + hint line) never grows
 * past the terminal rows.
 */
final class PagerResizeFooterTest extends TestCase
{
    private const CONTENT = "alpha\nbravo\ncharlie\ndelta\necho\nfoxtrot\ngolf\nhotel\nindia\njuliet\nkilo\nlima\nmike\nnovember\noscar\npapa";

    public function testFrameFitsRowsAfterResizeAtSmallHeight(): void
    {
        $model = PagerModel::fromContent(self::CONTENT, width: 20, height: 0);
        [$model, ] = $model->update(new WindowSizeMsg(20, 5));

        $lines = explode("\n", $model->view());
        $this->assertLessThanOrEqual(5, count($lines), 'frame must stay within the 5 terminal rows');
        $this->assertSame(4, $model->viewport->height, 'viewport keeps rows-1 after the reservation');
    }

    public function testSuccessiveResizesNeverExceedTheirRows(): void
    {
        $model = PagerModel::fromContent(str_repeat("line\n", 100), width: 40, height: 0);
        foreach ([[10, 6], [24, 3], [80, 5], [80, 2]] as [$cols, $rows]) {
            [$model, ] = $model->update(new WindowSizeMsg($cols, $rows));
            $this->assertLessThanOrEqual(
                $rows,
                count(explode("\n", $model->view())),
                "frame exceeds {$rows} rows after resize to {$cols}x{$rows}",
            );
        }
    }

    public function testFooterLineIsTheSingleReservedRow(): void
    {
        $model = PagerModel::fromContent(str_repeat("line\n", 100), width: 12, height: 7);

        $this->assertSame(7, $model->viewport->height);
        $this->assertSame(8, count(explode("\n", $model->view())), 'view = viewport + one hint line');
    }
}
