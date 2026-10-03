<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\ReportsServedModel;
use SugarCraft\Crush\Renderer;

/**
 * Two status-bar pieces added after the bar's fitting rules were pinned:
 *
 *  - the served-model segment (the audit 15b-35 follow-up): the LOWEST
 *    priority piece, so it may only ever use room nothing else wanted;
 *  - the read-only marker (the audit SES-3 residual): the HIGHEST priority
 *    piece, so a window that refuses every prompt says so on every frame.
 *
 * Both are swept over every terminal width, as every other bar piece is
 * ({@see StatusBarSpendTest}), because the bar is the frame's last row and
 * must never be wider than the terminal.
 */
final class StatusBarModelAndReadOnlyTest extends TestCase
{
    private const MAX_COLS = 200;

    private const MODEL = 'Qwen/Qwen3.8-Flash-Next-FP8';

    // =====================================================================
    // The served-model segment
    // =====================================================================

    public function testAWideBarNamesTheConfiguredModelOfAnEngineBackend(): void
    {
        $bar = $this->bar($this->chat(200, EngineBackend::new(new EchoProvider(), self::MODEL)));

        $this->assertStringEndsWith(' · ' . self::MODEL, $bar);
    }

    /**
     * The rule `Tui\Renderer::modelLabel()` applies: the model requests
     * actually go to, which an SGLang provider on its default id learns from
     * the server, before the static id the backend was built with.
     */
    public function testTheServedModelWinsOverTheConfiguredId(): void
    {
        $bar = $this->bar($this->chat(200, EngineBackend::new($this->servedProvider('Qwen/Qwen3.8-Served'), 'static-default-id')));

        $this->assertStringEndsWith(' · Qwen/Qwen3.8-Served', $bar);
        $this->assertStringNotContainsString('static-default-id', $bar);
    }

    /** The echo and command backends name no model, so they get no segment. */
    public function testABackendThatNamesNoModelGetsNoSegment(): void
    {
        $bar = $this->bar($this->chat(200, new EchoBackend()));

        $this->assertStringEndsWith('/exit or ^C to quit', $bar);
    }

    /**
     * THE PRIORITY CLAIM, swept: at every width the bar with the segment is the
     * bar without it, plus at most a trailing segment. So the model never
     * shortened, dropped or displaced a piece that was there before it.
     */
    public function testTheModelSegmentOnlyEverTakesRoomNothingElseWanted(): void
    {
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $without = $this->bar($this->chat($cols, new EchoBackend()));
            $with = $this->bar($this->chat($cols, EngineBackend::new(new EchoProvider(), self::MODEL)));

            $this->assertLessThanOrEqual($cols, Width::of($with), "the bar over-ran a {$cols}-column terminal");
            $this->assertStringStartsWith($without, $with, "the model segment displaced another piece at {$cols} columns");
        }
    }

    /**
     * The model segment's forms, read back: the whole id, then the part after
     * the organisation prefix, then that part ellipsised, then nothing. The
     * table is the width at which each form first appears.
     */
    public function testTheSegmentCollapsesThroughItsFormsAsTheTerminalNarrows(): void
    {
        $steps = [];
        $previous = null;
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $bar = $this->bar($this->chat($cols, EngineBackend::new(new EchoProvider(), self::MODEL)));
            $without = $this->bar($this->chat($cols, new EchoBackend()));
            $segment = $bar === $without ? '' : substr($bar, \strlen($without) + \strlen(' · '));
            if ($segment !== $previous) {
                $steps[$cols] = $segment;
                $previous = $segment;
            }
        }

        $this->assertSame(
            [
                1 => '',
                74 => 'Qwen3…',
                // The idle hint's full form ("Enter to send · …") becomes
                // affordable here and takes the room: the model yields,
                // because it is the lowest-priority piece.
                75 => '',
                84 => 'Qwen3…',
                85 => 'Qwen3.…',
                86 => 'Qwen3.8…',
                87 => 'Qwen3.8-…',
                88 => 'Qwen3.8-F…',
                89 => 'Qwen3.8-Fl…',
                90 => 'Qwen3.8-Fla…',
                91 => 'Qwen3.8-Flas…',
                92 => 'Qwen3.8-Flash…',
                93 => 'Qwen3.8-Flash-…',
                94 => 'Qwen3.8-Flash-N…',
                95 => 'Qwen3.8-Flash-Ne…',
                96 => 'Qwen3.8-Flash-Nex…',
                97 => 'Qwen3.8-Flash-Next…',
                98 => 'Qwen3.8-Flash-Next-…',
                99 => 'Qwen3.8-Flash-Next-F…',
                100 => 'Qwen3.8-Flash-Next-FP8',
                105 => self::MODEL,
            ],
            $steps,
        );
    }

    /**
     * The served id is a string a remote server chose. Nothing in it may reach
     * the terminal as a control, and no zone sentinel may reach the scanner
     * on the one row that carries a real click zone.
     */
    public function testAServedIdIsSanitisedBeforeItIsPainted(): void
    {
        $hostile = "evil\e[31m\u{E000}zone\u{E001}\x07\nid";
        $raw = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(
            null,
            $this->chat(200, EngineBackend::new($this->servedProvider($hostile), 'static')),
        );

        $this->assertStringNotContainsString("\e", $raw);
        $this->assertStringNotContainsString("\x07", $raw);
        $this->assertStringNotContainsString("\n", $raw);
        $baseline = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $this->chat(200, new EchoBackend()));
        $this->assertSame(
            substr_count($baseline, "\u{E000}"),
            substr_count($raw, "\u{E000}"),
            'the id added no zone sentinel: only the pane:menu zone is on the bar',
        );
        $this->assertStringEndsWith(' · evilzone id', (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $raw));
    }

    // =====================================================================
    // The read-only marker
    // =====================================================================

    public function testAReadOnlySessionLeadsTheBarWithTheMarkerAndTheWayOut(): void
    {
        $bar = $this->bar($this->readOnly($this->chat(120, new EchoBackend())));

        $this->assertStringStartsWith('read-only: /branch to fork · ', $bar);
    }

    public function testAWritableSessionHasNoMarker(): void
    {
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $this->assertStringNotContainsString('read-only', $this->bar($this->chat($cols, new EchoBackend())));
        }
    }

    /**
     * THE PRIORITY CLAIM: at every width — down to one column, where only its
     * first letter fits — the marker is on the bar, ahead of everything, and
     * the bar still fits the terminal. Idle and in flight.
     */
    public function testTheMarkerSurvivesTheFitAtEveryWidthAndTheBarStillFits(): void
    {
        foreach (['idle' => false, 'in flight' => true] as $state => $inFlight) {
            for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
                $chat = $this->readOnly($this->chat($cols, EngineBackend::new(new EchoProvider(), self::MODEL)), $inFlight);
                $bar = $this->bar($chat);

                $this->assertLessThanOrEqual($cols, Width::of($bar), "{$state}: the bar over-ran a {$cols}-column terminal");
                $this->assertTrue(
                    str_starts_with($bar, 'read-only') || str_starts_with($bar, Width::truncate('RO · ', $cols)),
                    "{$state}: the marker was lost at {$cols} columns: {$bar}",
                );
            }
        }
    }

    /** The marker's forms, read back: where each first appears. */
    public function testTheMarkerNarrowsThroughItsForms(): void
    {
        $steps = [];
        $previous = null;
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $bar = $this->bar($this->readOnly($this->chat($cols, new EchoBackend())));
            $form = match (true) {
                str_starts_with($bar, 'read-only: /branch to fork · ') => 'long',
                str_starts_with($bar, 'read-only · ') => 'short',
                default => 'RO',
            };
            if ($form !== $previous) {
                $steps[$cols] = $form;
                $previous = $form;
            }
        }

        $this->assertSame([1 => 'RO', 24 => 'short', 41 => 'long'], $steps);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function chat(int $cols, \SugarCraft\Crush\Backend $backend): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: $backend,
        ))->withSize($cols, 30);
    }

    private function readOnly(Chat $chat, bool $inFlight = false): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, [
            'readOnlySession' => true,
            'inFlight' => $inFlight,
        ]);
    }

    private function servedProvider(string $served): ProviderInterface
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, ReportsServedModel::class]);
        $provider->method('name')->willReturn('sglang');
        $provider->method('servedModel')->willReturn($served);
        $provider->method('contextWindow')->willReturn(100_000);

        return $provider;
    }

    /** The bar as painted, zone sentinels off. */
    private function bar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat);

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }
}
