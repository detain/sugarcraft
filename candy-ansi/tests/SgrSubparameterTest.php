<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * ECMA-48 sub-parameter structure through the SGR path.
 *
 * candy-core EMITS SGR 58 (underline colour) from
 * {@see \SugarCraft\Core\Util\Color::toUnderline()} as `58;2;R;G;B` /
 * `58;5;N`, so the parser must round-trip those byte forms — semicolon
 * spelling from candy-core and the `:`-subparameter spelling accepted by
 * modern terminals — without collapsing their structure into an
 * ambiguous flat list.
 */
final class SgrSubparameterTest extends TestCase
{
    /** @var list<list<int>> every params list handed to CsiHandler::sgr(). */
    private array $sgrCalls = [];

    private Parser $parser;

    protected function setUp(): void
    {
        $this->sgrCalls = [];
        $csi = $this->createMock(CsiHandler::class);
        $csi->method('sgr')->willReturnCallback(
            function (array $params): void {
                $this->sgrCalls[] = $params;
            },
        );
        $this->parser = new Parser(new HandlerAdapter($csi, $this->createMock(OscHandler::class)));
    }

    public function testColonSubparameterMarksContinuation(): void
    {
        $this->parser->feed("\x1b[4:3m");

        // Flattened list preserved for existing handlers...
        self::assertSame([4, 3], $this->onlySgrParams());
        // ...but the boundary survives in the continuation flags.
        self::assertSame([true, false], $this->parser->subparams());
        self::assertSame([[4, 3]], Parser::groupSubparameters([4, 3], $this->parser->subparams()));
    }

    public function testSemicolonParamsFormSeparateGroups(): void
    {
        $this->parser->feed("\x1b[4;3m");

        // Same flat list as `4:3` — only the flags tell underline+italic
        // apart from curly underline, which is the mis-split this guards.
        self::assertSame([4, 3], $this->onlySgrParams());
        self::assertSame([false, false], $this->parser->subparams());
        self::assertSame([[4], [3]], Parser::groupSubparameters([4, 3], $this->parser->subparams()));
    }

    public function testUnderlineColorColonFormKeepsGroupIntact(): void
    {
        // xterm/ITU spelling `SGR 58 : 2 : : R : G : B :` — one parameter
        // (58) whose sub-parameters are 2, default, R, G, B.
        $this->parser->feed("\x1b[58:2::148:199:255m");

        $groups = Parser::groupSubparameters($this->onlySgrParams(), $this->parser->subparams());

        self::assertCount(1, $groups, 'colon sub-parameters must not split into separate parameters');
        self::assertSame([58, 2, -1, 148, 199, 255], $groups[0]);
    }

    public function testUnderlineColorSemicolonFormRoundTripsCandyCoreEmission(): void
    {
        // Wire form emitted by candy-core's Util\Color::toUnderline() at a
        // truecolor profile (candy-core/src/Util/Color.php:523) — e.g.
        // Color::rgb(148, 199, 255) renders exactly "\x1b[58;2;148;199;255m".
        // candy-ansi has no candy-core dependency, so this pins the emitted
        // bytes rather than generating them; keep it in sync with Color.php.
        $emitted = "\x1b[58;2;148;199;255m";

        $this->parser->feed($emitted);

        self::assertSame([58, 2, 148, 199, 255], $this->onlySgrParams());
        self::assertSame([false, false, false, false, false], $this->parser->subparams());
    }

    public function testUnderlineColorIndexedFormRoundTrips(): void
    {
        // Indexed wire form of Color::toUnderline() (Color.php:526), e.g.
        // what Color::ansi256(178) emits — `58;5;n`, one slot per number.
        $this->parser->feed("\x1b[58;5;178m");

        self::assertSame([58, 5, 178], $this->onlySgrParams());
    }

    public function testUnderlineColorResetRoundTrips(): void
    {
        $this->parser->feed("\x1b[59m");

        self::assertSame([59], $this->onlySgrParams());
    }

    public function testSgr21PassesThroughUninterpreted(): void
    {
        // SGR 21 is disputed (ECMA-48 "bold off" vs xterm "double
        // underline"); the parser must carry it verbatim so the consumer
        // — not the parser — decides the semantics.
        $this->parser->feed("\x1b[21m");

        self::assertSame([21], $this->onlySgrParams());
    }

    public function testMixedSemicolonAndColonGroups(): void
    {
        // `38;5;1;4:3` — fg colour, then a single underline-style param.
        $this->parser->feed("\x1b[38;5;1;4:3m");

        $groups = Parser::groupSubparameters($this->onlySgrParams(), $this->parser->subparams());

        self::assertSame([[38], [5], [1], [4, 3]], $groups);
    }

    public function testLeadingColonKeepsImplicitDefaultInGroup(): void
    {
        $this->parser->feed("\x1b[:5m");

        $groups = Parser::groupSubparameters($this->onlySgrParams(), $this->parser->subparams());

        self::assertSame([[-1, 5]], $groups);
    }

    public function testFlagsResetOnNextSequence(): void
    {
        $this->parser->feed("\x1b[4:3m");
        self::assertSame([true, false], $this->parser->subparams());

        $this->parser->feed("\x1b[31m");
        self::assertSame([false], $this->parser->subparams());
    }

    public function testGroupingHelperIsPure(): void
    {
        $params = [4, 3];
        $flags = [true, false];

        $first = Parser::groupSubparameters($params, $flags);
        $second = Parser::groupSubparameters($params, $flags);

        self::assertSame($first, $second);
        self::assertSame([[4, 3]], $first);
        self::assertSame([4, 3], $params, 'input list must not be mutated');
    }

    /**
     * @return list<int>
     */
    private function onlySgrParams(): array
    {
        self::assertCount(1, $this->sgrCalls, 'exactly one SGR dispatch expected');

        return $this->sgrCalls[0];
    }
}
