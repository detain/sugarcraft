<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\PagerModel;

/**
 * scriptui-57 — the hint footer is the only in-frame discoverability
 * channel. It must (a) carry the bound key roster across every shipped
 * catalog, (b) fit an 80-column terminal unwrapped, and (c) stop
 * advertising keys the current mode does not bind.
 */
final class HintDiscoverabilityTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function catalogProvider(): array
    {
        $rows = [];
        foreach (glob(__DIR__ . '/../../lang/*.php') ?: [] as $file) {
            $rows[basename($file, '.php')] = [$file];
        }
        return $rows;
    }

    /**
     * Physical key tokens are kept verbatim in all catalogs (only the
     * gloss words translate), so coverage can be asserted locale-blind.
     *
     * @param string $file absolute path of one lang/<locale>.php
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('catalogProvider')]
    public function testPagerFooterCarriesTheBoundKeyRoster(string $file): void
    {
        /** @var array<string, string> $rows */
        $rows = require $file;
        $footer = $rows['hints.pager'] . "\n" . $rows['hints.pager.more'];

        foreach (['j/k', 'h/l', 'g/G', 'PgUp', 'PgDn', 'Ctrl+U/D', 'Home/End', 'q/'] as $token) {
            $this->assertStringContainsString($token, $footer, basename($file) . ' pager footer omits ' . $token);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('catalogProvider')]
    public function testChooseHintsCarryTheSlashFilterLeg(string $file): void
    {
        /** @var array<string, string> $rows */
        $rows = require $file;
        foreach (['hints.choose', 'hints.choose.multi'] as $key) {
            $this->assertStringContainsString('/ ', $rows[$key], basename($file) . ' ' . $key . ' omits the / filter leg');
        }
        $this->assertStringNotContainsString('toggle', $rows['hints.choose'], basename($file) . ' single-select leg still advertises space toggle');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('catalogProvider')]
    public function testEveryHintLineFitsEightyColumns(string $file): void
    {
        /** @var array<string, string> $rows */
        $rows = require $file;
        foreach (['hints.choose', 'hints.choose.multi', 'hints.pager', 'hints.pager.more', 'hints.filter'] as $key) {
            $width = Width::string($rows[$key]);
            $this->assertLessThanOrEqual(78, $width, basename($file) . ' ' . $key . " paints {$width} cols");
        }
    }

    public function testSingleSelectChooseHidesTheSpaceClause(): void
    {
        $single = ChooseModel::fromOptions(['a', 'b'], limit: 1);
        $multi  = ChooseModel::fromOptions(['a', 'b'], limit: 0, noLimit: true);

        $this->assertStringNotContainsString('space toggle', $single->view());
        $this->assertStringContainsString('space toggle', $multi->view());
        $this->assertStringContainsString('/ filter', $single->view());
    }

    public function testPagerFooterYieldsOnTooShortTerminals(): void
    {
        $model = PagerModel::fromContent(str_repeat("line\n", 30), width: 40, height: 0);
        [$model] = $model->update(new WindowSizeMsg(80, 2));

        $lines = explode("\n", $model->view());
        $this->assertLessThanOrEqual(2, count($lines), 'two-row terminal cannot hold footer + content');

        [$tall] = $model->update(new WindowSizeMsg(80, 24));
        $footer = explode("\n", $tall->view());
        $this->assertStringContainsString('q/', end($footer), 'normal terminals show the footer again');
    }
}
