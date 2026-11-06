<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Permissions\ToolRefusal;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Core\I18n\T;

/**
 * Guards the machine-stability law of the headless refusal envelope
 * (RS-DRIVER c): every entry under the envelope's `refusals` key answers
 * in locale-independent bytes, so a driver that keys automation on
 * reason text is not silently re-localised by an operator's `LANG`.
 *
 * The pin shape is deliberately comparative — same call, two locales,
 * identical bytes — rather than a snapshot of English prose, because the
 * contract is stability, not spelling. A non-vacuity twin on the raw
 * translated key proves the comparison has teeth: `host.spend.mid_turn`
 * genuinely carries German, so the row would drift without the pin-to-en
 * inside {@see NonInteractive::spendCapRow()}.
 *
 * Scope note (honest boundaries): the built-in hook producers
 * (`PromptHook`, `HttpHook`, `ScriptHook`) still render their deny
 * messages through `Lang::t()`, so a hook-authored reason follows the
 * locale; that surgery lives in files this contract does not cover and
 * is reported as a seam, not pinned as stable. What IS pinned: the kind
 * tokens, the frozen denial prefixes, the spend-cap row, the tool-row
 * machinery, and the fidelity rule that raw bytes copy unchanged.
 */
final class RefusalLangStabilityTest extends TestCase
{
    use PinsEnglishLocaleTrait;

    /**
     * Runs one callable under German and under English and hands back both
     * results. The trait has already pinned `en`; the explicit setLocale in
     * the try/finally restores it either way, so no test order can leak a
     * foreign locale into the suite.
     *
     * @template TValue
     * @param callable(): TValue $probe
     * @return array{TValue, TValue}
     */
    private static function underBothLocales(callable $probe): array
    {
        $previous = T::locale();
        T::setLocale('de');

        try {
            $german = $probe();
        } finally {
            T::setLocale($previous);
        }

        return [$german, $probe()];
    }

    /**
     * @return array{tool: string, kind: string, reason: string}
     */
    private static function callSpendCapRow(SpendCapBreached $event): array
    {
        $method = new ReflectionMethod(NonInteractive::class, 'spendCapRow');

        /** @var array{tool: string, kind: string, reason: string} $row */
        $row = $method->invoke(null, $event);

        return $row;
    }

    /**
     * @return array{tool: string, kind: string, reason: string}|null
     */
    private static function callRefusalFrom(ToolFinished $event): ?array
    {
        $method = new ReflectionMethod(NonInteractive::class, 'refusalFrom');

        /** @var array{tool: string, kind: string, reason: string}|null $row */
        $row = $method->invoke(null, $event);

        return $row;
    }

    public function testTheSpendCapRowReasonIsIdenticalUnderEveryLocale(): void
    {
        $event = new SpendCapBreached(completedCalls: 3, spentUsd: 2.75, capUsd: 2.5);

        [$german, $english] = self::underBothLocales(
            static fn(): array => self::callSpendCapRow($event),
        );

        self::assertSame($english, $german, 'the envelope spend-cap row drifted with the locale');
        self::assertStringContainsString('2.5000', $english['reason']);
        self::assertSame('turn', $english['tool']);
        self::assertSame('budget', $english['kind']);
    }

    public function testTheMidTurnKeyReallyCarriesTranslationSoTheStabilityPinHasTeeth(): void
    {
        $params = ['calls' => 3, 'spent' => '$2.7500', 'cap' => '2.5000'];

        [$german, $english] = self::underBothLocales(
            static fn(): string => Lang::t('host.spend.mid_turn', $params),
        );

        self::assertNotSame($english, $german, (string) $german);
        self::assertStringNotContainsString('abgebrochen', $english);
    }

    /**
     * The envelope reason must spell the same money sentence the ledger
     * itself renders under English — the ISS-006 shared-wording ruling is
     * observed exactly where both sides answer in `en`.
     */
    public function testThePinnedSpendCapRowKeepsTheLedgerWordingUnderEnglish(): void
    {
        $event = new SpendCapBreached(completedCalls: 3, spentUsd: 2.75, capUsd: 2.5);
        $row = self::callSpendCapRow($event);

        self::assertSame(
            Lang::t('host.spend.mid_turn', [
                'calls' => $event->completedCalls,
                'spent' => \SugarCraft\Code\Host\SpendLedger::boundUsd($event->unpriced, $event->spentUsd),
                'cap' => \sprintf('%.4f', $event->capUsd),
            ]),
            $row['reason'],
        );
    }

    #[DataProvider('kinds')]
    public function testDenialKindTokensPrefixesAndValuesNeverMoveWithLocale(DenialKind $kind): void
    {
        [$german, $english] = self::underBothLocales(
            static fn(): array => [
                $kind->token(),
                $kind->value,
                $kind->reason('d3 stability probe detail'),
                DenialKind::prefixes(),
            ],
        );

        self::assertSame($english, $german, 'DenialKind bytes drifted with the locale');
    }

    /**
     * @return array<string, array{DenialKind}>
     */
    public static function kinds(): array
    {
        $cases = [];

        foreach (DenialKind::cases() as $case) {
            $cases[$case->name] = [$case];
        }

        self::assertNotEmpty($cases);

        return $cases;
    }

    public function testToolRefusalRowsBuiltFromDeniedResultsNeverMoveWithLocale(): void
    {
        $event = new ToolFinished('call-1', 'Edit', ToolResult::denied(
            'call-1',
            DenialKind::Refused,
            'no approver is attached to this run: edit src/x.php',
        ));

        $refusal = ToolRefusal::fromEvent($event);
        self::assertNotNull($refusal);

        [$german, $english] = self::underBothLocales(
            static fn(): ?array => self::callRefusalFrom($event),
        );

        self::assertSame($english, $german, 'a tool refusal row drifted with the locale');
        self::assertIsArray($english);
        self::assertSame('Edit', $english['tool']);
        self::assertSame('refused', $english['kind']);
        self::assertStringContainsString('no approver is attached', $english['reason']);
    }

    public function testUserAuthoredHookBytesRideTheRefusalUnchanged(): void
    {
        $message = 'd3 fidelity question — keep this exact text';

        $verdict = HookResult::deny($message);
        self::assertSame($message, $verdict->message);

        $event = new ToolFinished('call-2', 'Bash', ToolResult::denied(
            'call-2',
            DenialKind::Hook,
            $verdict->message,
        ));

        [$german, $english] = self::underBothLocales(
            static fn(): ?array => self::callRefusalFrom($event),
        );

        self::assertSame($english, $german);
        self::assertIsArray($english);
        self::assertSame('hook', $english['kind']);
        self::assertStringContainsString($message, $english['reason']);
    }
}
