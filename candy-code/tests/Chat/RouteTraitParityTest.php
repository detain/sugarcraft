<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Chat\Handlers\HandlesKeys;
use SugarCraft\Code\Chat\Handlers\HandlesRunControl;
use SugarCraft\Code\Chat\Handlers\HandlesSessionSurface;
use SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion;
use PHPUnit\Framework\TestCase;

/**
 * W7-S4: route() is a thin chain over four family handlers living under
 * `src/Chat/Handlers/`. The behavioural pins for the moved arms are the
 * pre-existing slash/dispatch/update()-driven suites (they exercised the
 * same answers inline before the split and stayed green through it); THIS
 * file pins the STRUCTURE that keeps that equivalence true over time:
 *
 *  - route() itself has shrunk to the chain — no `instanceof` survives
 *    inside it, so a future arm cannot be re-added inline and quietly
 *    bypass the family census below;
 *  - each named handler resolves to its trait, not to Chat;
 *  - the four handlers' top-level `$msg instanceof` arm sets are pairwise
 *    disjoint (the chain's order-independence is therefore inert), their
 *    union equals the frozen roster of message classes route() claimed
 *    before the split, and KeyMsg — the absorbing family — is claimed by
 *    no handler but HandlesKeys;
 *  - the Keys gate answers "not my family" with `return null;` (the split
 *    rewrite) and the chain's terminal `?? [$this, null]` still exists to
 *    re-materialise the old gate's early reply.
 */
final class RouteTraitParityTest extends TestCase
{
    /**
     * Every message class route() dispatched between the split's base
     * (21,411-line Chat.php, route() 2364-3909) and now. If a family
     * handler legitimately starts or stops claiming a class, this list —
     * and only this list — changes alongside it.
     */
    private const FROZEN_ARM_ROSTER = [
        'AgentControlMsg',
        'AssistantMsg',
        'AutoCommittedMsg',
        'BackgroundSessionSpawnedMsg',
        'BackgroundSessionStoppedMsg',
        'BackgroundTickMsg',
        'BackendToolEventsMsg',
        'BangShellResultMsg',
        'CancelAgentRunMsg',
        'CancelledWorkflowReportMsg',
        'ClipboardImagePastedMsg',
        'CloseAgentViewMsg',
        'ContextMeasuredMsg',
        'CustomCommandExpandedMsg',
        'DreamPassCompletedMsg',
        'GenerateFromMediaFormMsg',
        'GoalJudgedMsg',
        'HandoffSeededMsg',
        'HistoryCompactedMsg',
        'IdleCompactTickMsg',
        'InitialPromptMsg',
        'KeyMsg',
        'LoadMoreMsg',
        'McpLoginProgressMsg',
        'MemoryConsolidatedMsg',
        'ModelCatalogReadyMsg', // C-PRV-5.c palette wake arm (HandlesSessionSurface) — row landed in-step
        'MouseMsg',
        'PasteMsg',
        'PermissionModeToggledMsg',
        'PermissionReplyMsg',
        'PermissionRequestMsg',
        'PromptSuggestionMsg',
        'RuntimeNoticePumpMsg',
        'SessionLockRetryMsg',
        'SessionPickerSearchMsg', // C-SES-2 arm landed without its roster row — base red cured in-step (P-CTX.d, 2026-10-27)
        'SessionTitledMsg',
        'SideQuestionAnsweredMsg',
        'StatusLineResultMsg',
        'StatusLineTickMsg',
        'StartupSummaryGatheredMsg',
        'TelemetryRetryTickMsg', // C-SES-4.b arm landed without its roster row — base red cured in-step (C-PRV-5.c)
        'TickRequest',
        'ToolEventPumpMsg',
        'ToolResultsMsg',
        'TranscriptFlushMsg',
        'TurnHooksResolvedMsg',
        'WindowSizeMsg',
    ];

    /** @return array<string, string> handler method => trait FQN */
    private static function families(): array
    {
        return [
            'handleTurnCompletionMessages' => HandlesTurnCompletion::class,
            'handleRunControlMessages' => HandlesRunControl::class,
            'handleSessionSurfaceMessages' => HandlesSessionSurface::class,
            'handleKeysMessages' => HandlesKeys::class,
        ];
    }

    /** @return array<string, list<string>> trait FQN => file path */
    private static function handlerFiles(): array
    {
        $out = [];
        foreach (self::families() as $trait) {
            $file = (new ReflectionClass($trait))->getFileName();
            self::assertIsString($file, $trait . ' does not resolve to a file');
            $out[$trait] = $file;
        }

        return $out;
    }

    /**
     * The message classes one handler source claims: EVERY
     * `$msg instanceof <Name>` occurrence, deduped. The scan is
     * depth-insensitive on purpose — interior re-checks (the Keys
     * handler tests `$msg instanceof KeyMsg` twice more below its gate)
     * name classes their own handler already owns, so neither the union
     * nor the disjointness verdict changes; the Keys gate's negated
     * `!$msg instanceof` is excluded by the leading `!` requirement
     * being absent from the pattern's subject.
     *
     * @return list<string> short class names, in source order
     */
    private static function topLevelArms(string $source): array
    {
        // `(?!...)` cannot go right after the `if`/`elseif` verbs without
        // missing the shapes here, so: match the verb+paren+optional bang
        // explicitly and only harvest when there was no bang.
        $arms = [];
        preg_match_all(
            '/(?:if|elseif) \((!)?\$msg instanceof \\\\?(?:[A-Za-z_\\\\]*\\\\)?([A-Z][A-Za-z0-9_]*)/',
            $source,
            $all,
            PREG_SET_ORDER,
        );
        foreach ($all as [$whole, $bang, $name]) {
            if ($bang === '') {
                $arms[] = $name;
            }
        }

        return array_values(array_unique($arms));
    }

    public static function testRouteIsTheThinChainWithNoInlineArmsLeft(): void
    {
        $method = new ReflectionMethod(Chat::class, 'route');
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = preg_split('/\R/', file_get_contents($file) ?: '') ?: [];
        $slice = array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

        self::assertLessThanOrEqual(
            30,
            count($slice),
            "route() has grown past the thin-chain envelope:\n" . implode("\n", $slice),
        );

        $body = implode("\n", $slice);
        self::assertStringNotContainsString(
            'instanceof',
            $body,
            'an inline arm survived (or returned) inside route() — the family '
            . 'census below only ranges over src/Chat/Handlers/, so a re-added '
            . 'inline dispatch would silently escape it',
        );

        foreach (array_keys(self::families()) as $handler) {
            self::assertStringContainsString(
                '$this->' . $handler . '($msg)',
                $body,
                'route() no longer chains through ' . $handler,
            );
        }

        self::assertMatchesRegularExpression(
            '/\?\? \[\$this, null\];\s*\}/',
            $body,
            "the terminal null-coalesce IS the old KeyMsg gate's reply:\n" . $body,
        );
    }

    public static function testEachFamilyHandlerIsComposedFromItsTraitNotChat(): void
    {
        // The origin of a trait-composed method is NOT
        // getDeclaringClass() — that answers the using class (Chat) for
        // every copied method. The file the body compiles FROM is the
        // truth, alongside Chat's own use-trait roster.
        $used = (new ReflectionClass(Chat::class))->getTraitNames();
        self::assertEqualsCanonicalizing(
            array_values(self::families()),
            $used,
            'Chat must compose exactly the four family traits',
        );

        foreach (self::families() as $handler => $trait) {
            $method = new ReflectionMethod(Chat::class, $handler);
            self::assertSame(
                (new ReflectionClass($trait))->getFileName(),
                $method->getFileName(),
                $handler . ' must be composed from ' . $trait
                . ', not re-inlined into Chat.php',
            );
        }
    }

    public function testTheArmFamiliesArePairwiseDisjointAndCoverTheFrozenRoster(): void
    {
        $byFile = [];
        $union = [];
        foreach (self::handlerFiles() as $trait => $file) {
            $arms = self::topLevelArms(file_get_contents($file) ?: '');
            self::assertNotSame([], $arms, $trait . ' claims no instanceof arm at all');
            $byFile[$trait] = $arms;
            $union = array_merge($union, $arms);
        }

        $union = array_values(array_unique($union));
        sort($union);
        $expected = self::FROZEN_ARM_ROSTER;
        sort($expected);

        self::assertSame(
            $expected,
            $union,
            'the handlers\' union of claimed message classes drifted from the frozen roster — '
            . 'a family was added, removed, or re-wired (the roster is the split\'s record)',
        );

        foreach ($byFile as $trait => $arms) {
            foreach ($byFile as $other => $otherArms) {
                if ($trait === $other) {
                    continue;
                }
                self::assertSame(
                    [],
                    array_values(array_intersect($arms, $otherArms)),
                    $trait . ' and ' . $other . ' both claim a class — the chain\'s '
                    . 'order-independence rests on disjointness',
                );
            }
        }

        self::assertContains('KeyMsg', $byFile[HandlesKeys::class]);
        foreach ($byFile as $trait => $arms) {
            if ($trait !== HandlesKeys::class) {
                self::assertNotContains('KeyMsg', $arms, $trait . ' claims KeyMsg outside the Keys spine');
            }
        }
    }

    public static function testTheKeysGateAnswersTheSplitRewrite(): void
    {
        $source = file_get_contents(self::handlerFiles()[HandlesKeys::class]) ?: '';

        self::assertSame(
            1,
            preg_match('/!\$msg instanceof KeyMsg\) \{\s*\n\s*return null;\s*\n\s*\}/', $source),
            'the Keys gate must pass non-key messages with `return null;` — reverting it '
            . 'to the old `return [$this, null];` would starve every later family in the chain',
        );
    }
}
