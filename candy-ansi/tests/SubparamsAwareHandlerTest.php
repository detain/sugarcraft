<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Ansi\Parser\SubparamsAwareHandler;
use SugarCraft\Ansi\Tests\Support\CapableDispatchRecorder;
use SugarCraft\Ansi\Tests\Support\DispatchRecorder;
use SugarCraft\Ansi\Tests\Support\PullSubparamsRecorder;

/**
 * The optional {@see SubparamsAwareHandler} capability: the parser pushes
 * ECMA-48 colon-continuation flags to handlers that opted in, immediately
 * before each parameterised dispatch.
 *
 * Two properties carry the design, and both are pinned here:
 *
 *  - a capable handler can reconstitute the parameter groups WITHOUT holding a
 *    reference to the parser (the push replaces the late-bound pull closure);
 *  - a handler that did not opt in is dispatched bit-for-bit as before, so this
 *    change is additive for every existing consumer.
 */
final class SubparamsAwareHandlerTest extends TestCase
{
    /**
     * `CSI 38 : 2 : : R : G : B m` — truecolour with the colourspace
     * sub-parameter left at its default (the leading-empty-colon form xterm
     * documents as `38:2::c1:c2:c3`).
     */
    public function testLeadingEmptySubparameterReachesCapableHandler(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1b[38:2::148:199:255m");

        // The flat list is still what `Handler::csiDispatch()` has always seen.
        self::assertSame(['csi:m:[38,2,-1,148,199,255]:0:0'], $capable->calls);
        // …and the group boundary now arrives without the handler reaching back.
        self::assertSame([[true, true, true, true, true, false]], $capable->pushes);
        self::assertSame(
            [[38, 2, -1, 148, 199, 255]],
            Parser::groupSubparameters([38, 2, -1, 148, 199, 255], $capable->pushes[0]),
            'the empty colourspace slot must stay inside the SGR 38 group',
        );
    }

    /**
     * `CSI 38 : 2 : CS : R : G : B m` — the colour-sliced spelling, where the
     * third value is a colourspace id rather than a colour plane. Structurally
     * it is the same single group; the parser interprets neither.
     */
    public function testColorSlicedSubparametersReachCapableHandler(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1b[38:2:7:255:128:0m");

        self::assertSame(['csi:m:[38,2,7,255,128,0]:0:0'], $capable->calls);
        self::assertSame([[true, true, true, true, true, false]], $capable->pushes);
        self::assertSame(
            [[38, 2, 7, 255, 128, 0]],
            Parser::groupSubparameters([38, 2, 7, 255, 128, 0], $capable->pushes[0]),
        );
    }

    /**
     * A colour-sliced group followed by a `;`-separated one is where the flat
     * list is genuinely ambiguous: `[0, 4]` could be "alpha 0 then italic" or
     * "the tail of the sliced colour plus a new parameter". The flags say which.
     */
    public function testColorSlicedGroupWithSemicolonSuffixKeepsBothGroups(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1b[38:2:7:255:128:0;4:3m");

        self::assertSame(
            [true, true, true, true, true, false, true, false],
            $capable->pushes[0],
        );
        self::assertSame(
            [[38, 2, 7, 255, 128, 0], [4, 3]],
            Parser::groupSubparameters([38, 2, 7, 255, 128, 0, 4, 3], $capable->pushes[0]),
        );
    }

    /**
     * `SGR 777 : 1 : 2 : 3 : 4 : 5` — xterm's extended/special-effect form,
     * included as the shape the brief pins: an arbitrary-length sub-parameter
     * run the parser must carry verbatim rather than second-guess.
     */
    public function testExtendedSevenSevenSevenSubparametersStayOneGroup(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1b[777:1:2:3:4:5m");

        self::assertSame([[true, true, true, true, true, false]], $capable->pushes);
        self::assertSame([[777, 1, 2, 3, 4, 5]], Parser::groupSubparameters([777, 1, 2, 3, 4, 5], $capable->pushes[0]));
    }

    /**
     * The push must land BEFORE the dispatch, otherwise the handler would read
     * the previous sequence's flags — the exact staleness the pull closure had
     * to be re-attached around on clone.
     */
    public function testPushPrecedesDispatchInOneTimeline(): void
    {
        $capable = new CapableDispatchRecorder();
        $parser = new Parser($capable);

        $parser->feed("\x1b[4:3m");
        $parser->feed('x');
        $parser->feed("\x1b[38;5;9m");

        self::assertSame([
            'push:[true,false]',
            'csi:m:[4,3]:0:0',
            'print:78',
            'push:[false,false,false]',
            'csi:m:[38,5,9]:0:0',
        ], $capable->timeline);
    }

    /**
     * Non-implementor exact-degradation: the same corpus, in the same order,
     * through a plain {@see Handler} and a capable one, must produce identical
     * `Handler` call logs. Nothing may leak into a handler that did not opt in.
     */
    public function testNonImplementorIsDispatchedBitIdentically(): void
    {
        $corpus = [
            "\x1b[38:2::148:199:255m",
            "\x1b[38:2:7:255:128:0;4:3m",
            "\x1b[4;3m",
            "\x1b[m",
            "\x1b[4:3m",
            "plain \xC3\xA9 text",
            "\x1bM",
            "\x1b]0;window title\x07",
            "\x1bP1;2:3qdata\x1b\\",
            "\x1bXsos-payload\x1b\\",
            "\x1b[?1000;1006h",
            "\x1b[38;5;9m",
        ];

        $plain = new DispatchRecorder();
        $capable = new CapableDispatchRecorder();
        $plainParser = new Parser($plain);
        $capableParser = new Parser($capable);

        foreach ($corpus as $bytes) {
            $plainParser->feed($bytes);
            $capableParser->feed($bytes);
        }
        $plainParser->flush();
        $capableParser->flush();

        self::assertNotEmpty($plain->calls, 'the corpus must actually dispatch');
        self::assertSame($plain->calls, $capable->calls);
        // And the capable side saw one push per parameterised dispatch.
        self::assertNotEmpty($capable->pushes);
    }

    /**
     * The capability is a strict supertype relationship: implementing it implies
     * implementing {@see Handler}, so a capable class can always be handed to
     * the parser. Pinned because vt-B will put this name in an `implements`
     * clause in two other repositories.
     */
    public function testCapabilityExtendsHandler(): void
    {
        self::assertTrue(
            is_a(SubparamsAwareHandler::class, Handler::class, true),
            'SubparamsAwareHandler must extend Handler',
        );
        // `getOwnMethods()`/`getReflectionMethods()` are PHP 8.4+; this lib
        // supports 8.3, so filter the inherited set instead.
        $own = array_filter(
            (new \ReflectionClass(SubparamsAwareHandler::class))->getMethods(),
            static fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === SubparamsAwareHandler::class,
        );
        self::assertCount(1, $own, 'the capability must add exactly one method');
        self::assertSame('setSubparams', array_values($own)[0]->getName());
    }

    /**
     * candy-ansi's own adapter deliberately stays on the flat path: it forwards
     * `sgr($params)` to {@see \SugarCraft\Ansi\Parser\CsiHandler}, which has no
     * colon-aware signature. If it ever becomes capable, candy-vcr's replay
     * semantics change — so this is a guard, not an accident.
     */
    public function testHandlerAdapterStaysANonImplementor(): void
    {
        self::assertFalse(
            is_a(HandlerAdapter::class, SubparamsAwareHandler::class, true),
        );
    }

    public function testSemicolonOnlySequencePushesAllFalseFlags(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1b[38;2;148;199;255m");

        self::assertSame([[false, false, false, false, false]], $capable->pushes);
    }

    /**
     * Coercion edge — a parameter-less sequence still gets a push, and the
     * pushed list stays parallel with (here: equally empty as) the params, so a
     * capable handler can index the two lists against each other unconditionally.
     */
    public function testDefaultSequencePushesEmptyFlags(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1b[m");

        self::assertSame(['csi:m:[]:0:0'], $capable->calls);
        self::assertSame([[]], $capable->pushes);
    }

    /**
     * DCS carries a §14.1.1 parameter string in its prelude exactly as CSI does
     * (`dcsDispatch` already receives `$params`), so the same flags must be
     * pushed — otherwise `DCS 1 ; 2 : 3 q` (sixel/ReGIS sub-parameters) would be
     * structurally ambiguous with no way for the handler to recover the grouping.
     */
    public function testDcsPreludeAlsoReceivesSubparameters(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1bP1;2:3qdata\x1b\\");

        self::assertSame([[false, true, false]], $capable->pushes);
        self::assertContains('dcs:q:[1,2,3]:0:0:data', $capable->calls);
    }

    /**
     * Sequences with no parameter string get no push at all. ESC is
     * `ESC` + intermediates (0x20-0x2F) + one final byte (ECMA-48 §14.1), and
     * OSC/SOS/PM/APC carry an application-defined string payload whose separators
     * are not §14.1.1 parameters — pushing here would have to invent a value.
     */
    public function testUnparameterisedDispatchesReceiveNoPush(): void
    {
        $capable = new CapableDispatchRecorder();
        $parser = new Parser($capable);

        $parser->feed("\x1bM");               // ESC RI — no parameter string
        $parser->feed("\x1b(B");              // ESC ( B — intermediate, still none
        $parser->feed("\x1b]0;title\x07");    // OSC
        $parser->feed("\x1bXpayload\x1b\\");  // SOS

        self::assertSame([], $capable->pushes);
        self::assertNotEmpty($capable->calls, 'those sequences must still dispatch');
    }

    /**
     * The push is per-sequence, not cumulative: a colon-rich SGR followed by a
     * plain one must not leave the second dispatch looking colon-grouped.
     */
    public function testFlagsAreFreshPerSequence(): void
    {
        $capable = new CapableDispatchRecorder();
        $parser = new Parser($capable);

        $parser->feed("\x1b[4:3m");
        $parser->feed("\x1b[31m");

        self::assertSame([[true, false], [false]], $capable->pushes);
    }

    /**
     * Coercion edge at the parameter cap: separators past `MAX_PARAMS` are
     * dropped, and the dropped-early path must not desynchronise the two lists
     * or a capable handler indexing flags by param index reads garbage.
     */
    public function testFlagsStayParallelToParamsAtTheParameterCap(): void
    {
        $capable = new CapableDispatchRecorder();
        // 40 `n:m` groups — twice the cap, so most separators hit the drop path.
        (new Parser($capable))->feed("\x1b[" . str_repeat('1:2;', 40) . 'm');

        self::assertCount(1, $capable->pushes);
        $flags = $capable->pushes[0];
        $params = $capable->csiParams[0];

        self::assertCount(32, $params, 'params must be capped');
        self::assertCount(count($params), $flags, 'flags must stay parallel with params');
    }

    /**
     * Regression pin for the legacy pull route that candy-vt
     * (`attachSubparamsProvider`) and candy-freeze (`bindParser`) still use: a
     * NON-capable handler consulting {@see Parser::subparams()} mid-dispatch must
     * still resolve colon SGRs, and resolve them to exactly what the new push
     * delivers. That equivalence is the contract vt-B relies on when it migrates
     * the consumers and retires the closure plumbing.
     */
    public function testPullFallbackStillResolvesColonSgrsIdenticallyToPush(): void
    {
        $corpus = [
            "\x1b[38:2::148:199:255m",
            "\x1b[4:3m",
            "\x1b[4;3m",
            "\x1b[38;5;9m",
            "\x1b[38:2:7:255:128:0;4:3m",
            "\x1b[m",
        ];

        $puller = new PullSubparamsRecorder();
        $pullParser = new Parser($puller);
        $puller->provider = static fn (): array => $pullParser->subparams();

        $capable = new CapableDispatchRecorder();
        $pushParser = new Parser($capable);

        foreach ($corpus as $bytes) {
            $pullParser->feed($bytes);
            $pushParser->feed($bytes);
        }

        self::assertCount(count($corpus), $puller->flagsDuringDispatch);
        self::assertSame($puller->flagsDuringDispatch, $capable->pushes);
        self::assertSame($puller->calls, $capable->calls, 'both routes see identical params');
        self::assertContains([true, false], $puller->flagsDuringDispatch, 'the pull route still fires');
    }
}
