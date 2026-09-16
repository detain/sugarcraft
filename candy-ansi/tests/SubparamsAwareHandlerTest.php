<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Handler;
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
     * A corpus that touches every dispatch kind the parser knows, in one pass:
     * colon/semicolon SGR, empty params, UTF-8 runs, ESC, OSC, a DCS prelude with
     * a colon, SOS, and a prefixed multi-mode CSI.
     */
    private const DEGRADATION_CORPUS = [
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

    /**
     * The exact `Handler` call log `master` produces for
     * {@see self::DEGRADATION_CORPUS} through a plain, non-capable handler.
     *
     * Captured by running the same corpus and the same normalisation against the
     * parser as it stands before this capability, not by reading the new code —
     * so it is an independent witness of "additive", and it is what makes the
     * degradation test able to see a regression that hits every handler alike.
     * Regenerate only by checking the diff against `git show master:...`.
     *
     * @var list<string>
     */
    private const MASTER_DISPATCH_LOG = [
        'csi:m:[38,2,-1,148,199,255]:0:0',
        'csi:m:[38,2,7,255,128,0,4,3]:0:0',
        'csi:m:[4,3]:0:0',
        'csi:m:[]:0:0',
        'csi:m:[4,3]:0:0',
        'print:70', 'print:6c', 'print:61', 'print:69', 'print:6e', 'print:20',
        'print:c3a9', 'print:20', 'print:74', 'print:65', 'print:78', 'print:74',
        'esc:M:0',
        'osc:0;window title',
        'dcs:q:[1,2,3]:0:0:data',
        'esc:\\:0',
        'sosPmApc:sos:sos-payload',
        'esc:\\:0',
        'csi:h:[1000,1006]:63:0',
        'csi:m:[38,5,9]:0:0',
    ];

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
     * Non-implementor exact-degradation, pinned TWO ways:
     *
     *  - against `self::MASTER_DISPATCH_LOG` below, captured from the parser as
     *    it stands on `master` (before this capability existed). Comparing only
     *    "plain vs capable on the new parser" — which this test also does — is
     *    blind to a regression that hits every handler uniformly, because both
     *    sides move together. The literal cannot move: it is the pre-change wire
     *    behaviour, written out by hand from `git show master` output.
     *  - plain vs capable, which catches anything the parser leaks into a
     *    handler that did not opt in.
     */
    public function testNonImplementorIsDispatchedBitIdentically(): void
    {
        $plain = new DispatchRecorder();
        $capable = new CapableDispatchRecorder();
        $plainParser = new Parser($plain);
        $capableParser = new Parser($capable);

        foreach (self::DEGRADATION_CORPUS as $bytes) {
            $plainParser->feed($bytes);
            $capableParser->feed($bytes);
        }
        $plainParser->flush();
        $capableParser->flush();

        self::assertSame(self::MASTER_DISPATCH_LOG, $plain->calls, 'a plain Handler must still see exactly what master shows it');
        self::assertSame($plain->calls, $capable->calls);
        // And the capable side saw one push per parameterised dispatch.
        self::assertNotEmpty($capable->pushes);
    }

    /**
     * The parser consults exactly ONE object — the {@see Handler} handed to
     * {@see Parser::__construct()}. A capable handler nested behind a plain
     * wrapper therefore receives no push at all.
     *
     * Pinned because candy-vt's renderer path is shaped like this (`Terminal.php`
     * gives the parser a `RendererHandler` wrapping a {@see HandlerAdapter},
     * both plain, with the colon-consuming `CsiHandlerImpl` behind them), so the
     * consumer migration cannot be mechanical: either the wrapper chain grows the
     * capability and forwards, or that path keeps the pull route. Discovering
     * this from a silent colon-SGR regression instead of here is the failure mode
     * this test exists to prevent.
     */
    public function testCapabilityIsOnlyConsultedOnTheHandlerGivenToTheParser(): void
    {
        $nested = new CapableDispatchRecorder();
        $wrapper = new class ($nested) extends DispatchRecorder {
            public function __construct(private readonly SubparamsAwareHandler $inner)
            {
            }

            public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
            {
                parent::csiDispatch($final, $params, $prefix, $intermediate);
                $this->inner->csiDispatch($final, $params, $prefix, $intermediate);
            }
        };

        (new Parser($wrapper))->feed("\x1b[4:3m");

        self::assertSame(['csi:m:[4,3]:0:0'], $wrapper->calls, 'the wrapper was dispatched');
        self::assertSame(['csi:m:[4,3]:0:0'], $nested->calls, 'and delegated the flat list');
        self::assertSame([], $nested->pushes, 'a capable handler behind a plain wrapper is never pushed to');
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
     * The DCS push must also land BEFORE the dispatch, not merely exist. The
     * interface publishes an ordering contract at two call sites; this pins the
     * second one — without it, moving the DCS push after `dcsDispatch()` would
     * leave the suite green while a handler that reads its stored flags during a
     * sixel/ReGIS prelude saw the *previous* sequence's grouping.
     */
    public function testDcsPushPrecedesDcsDispatchInOneTimeline(): void
    {
        $capable = new CapableDispatchRecorder();
        (new Parser($capable))->feed("\x1bP1;2:3qdata\x1b\\");

        self::assertSame([
            'push:[false,true,false]',
            'dcs:q:[1,2,3]:0:0:data',
            'esc:\\:0',
        ], $capable->timeline);
    }

    /**
     * A DCS left unterminated at end-of-stream is dispatched by `flush()`, which
     * routes through the same `dispatch()` method — so the prelude grouping must
     * survive to that callback too rather than arriving only on the tidy path.
     */
    public function testFlushedDcsStillReceivesItsPreludeGrouping(): void
    {
        $capable = new CapableDispatchRecorder();
        $parser = new Parser($capable);

        $parser->feed("\x1bP0;1:2qtruncated-without-terminator");
        $parser->flush();

        self::assertSame([[false, true, false]], $capable->pushes);
        self::assertSame(
            ['push:[false,true,false]', 'dcs:q:[0,1,2]:0:0:truncated-without-terminator'],
            $capable->timeline,
        );
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
     * What the parameter cap actually costs. Lengths stay parallel (the test
     * above), but past slot 32 the parser drops the separator itself, so a real
     * `:` is reported as an independent parameter AND its digits merge into the
     * full slot. Pinning this as documented behaviour: the interface promises
     * faithful grouping only below the cap, and a consumer that must know the
     * difference cannot infer it from the flags alone.
     */
    public function testGroupingStopsBeingFaithfulOnceParamsAreCapped(): void
    {
        $capable = new CapableDispatchRecorder();
        // 31 `7;` separators fill slots 0..31 with the 32nd `7`, then the genuine
        // sub-parameter boundary `:8` arrives with the list already full.
        (new Parser($capable))->feed("\x1b[" . str_repeat('7;', 31) . '7:8m');

        $params = $capable->csiParams[0];
        $flags = $capable->pushes[0];

        self::assertCount(32, $params);
        self::assertSame([7, 7, 78], array_slice($params, -3), 'the digits merged into the full slot');
        self::assertFalse($flags[31], 'the colon at the cap is reported as an independent parameter');
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
