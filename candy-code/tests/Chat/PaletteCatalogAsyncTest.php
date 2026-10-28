<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\App\ModelCatalogReadyMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\ModelChoice;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\HasModelCatalog;
use SugarCraft\Code\Providers\ModelCatalog;
use SugarCraft\Code\Providers\ModelMetadata;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-PRV-5.c: the model-catalog palette prime answers at once and merges
 * late.
 *
 * The pins walk the whole async life of one palette open without touching
 * the network: the Chat-side wake is proven by what the OPEN shows and
 * what the ModelCatalogReadyMsg merge DERIVES from the memo — the shape
 * the dispatcher itself guarantees through the endpoint seam, pinned
 * end-to-end in OpenAiModelsEndpointTest. The one real dial a Chat pick
 * starts (the wake calls prefetchAsync without a seam, which is the
 * production shape) rides a loopback base on a random unused port and a
 * loop no test ever runs: nothing settles it in-process, and the memo
 * placeholder it leaves behind is exactly what pins once-per-process
 * across the async window.
 */
final class PaletteCatalogAsyncTest extends TestCase
{
    use HomeSandboxTrait;

    private const PERSISTED = 'persisted-sg-model';

    private string|false $originalOptOut;

    protected function setUp(): void
    {
        $this->originalOptOut = getenv(ModelMetadata::OPT_OUT_ENV);
        $this->useHomeSandbox(sys_get_temp_dir() . '/cc-prv5c-' . uniqid('', true));
        // The persisted tier the base rows count: written BEFORE any Chat
        // is built, because the prime reads the user config per call.
        $configDir = getenv('HOME') . '/.candy-code';
        mkdir($configDir, 0700, true);
        file_put_contents($configDir . '/config.json', json_encode(['models' => ['sglang' => self::PERSISTED]], JSON_THROW_ON_ERROR));
        putenv(ModelMetadata::OPT_OUT_ENV . '=0');
        ModelCatalog::resetForTesting();
    }

    protected function tearDown(): void
    {
        ModelCatalog::resetForTesting();
        if ($this->originalOptOut === false) {
            putenv(ModelMetadata::OPT_OUT_ENV);
        } else {
            putenv(ModelMetadata::OPT_OUT_ENV . '=' . $this->originalOptOut);
        }
        $this->restoreHomeSandbox();
    }

    /**
     * THE OPEN ANSWERS AT ONCE ON THE BASE TIER. A first-ever pick on a
     * fresh memo reopens the picker on rows derived from config alone —
     * no live tier, no stall, no keystroke paid to the fetch — and the
     * returned Cmd carries the wake while the memo placeholder pins the
     * in-flight window: a second prefetch for the same base answers
     * "already started" without starting a second dial.
     */
    public function testTheFirstOpenAnswersAtOnceOnTheBaseTierWhileTheReadIsInFlight(): void
    {
        $base = $this->loopbackBase();
        $chat = $this->chatOn($base);

        [$opened, $cmd] = $this->pick($chat, 'sglang');

        self::assertNotNull($opened->palette(), 'the reopen fired: enough base rows to offer a model level');
        self::assertSame('models', $opened->palette()->mode);
        self::assertSame('sglang', $opened->palette()->provider);
        self::assertSame(
            $this->expectedRows($base, null),
            $opened->paletteMatches(),
            'the open shows exactly the base tier — the live tier is NOT there yet, and nothing waited for it',
        );
        self::assertInstanceOf(\Closure::class, $cmd, 'the open returned a Cmd — the switch cmd batched with the wake');
        self::assertSame(2, self::batchMembers($cmd), 'two members: the remeasure cmd selectPaletteProvider returns itself, and the catalog wake — no second dial without the batch');

        self::assertNull(
            ModelCatalog::prefetchAsync($this->providerOn($base)),
            'mid-window a second prefetch sees the placeholder and starts nothing: once-per-process holds across the async window',
        );
        self::assertNull(
            ModelCatalog::live($this->providerOn($base)),
            'the placeholder is the same null stamp a failed fetch leaves — derivation never blocks on it',
        );
    }

    /**
     * THE MERGE IS BY DERIVATION. The wake's then writes the memo before
     * the message dispatches; the arm itself moves no state — it is the
     * repaint, and the repaint re-derives rows from the memo the answer
     * just landed in. (The reflection write below IS that then's write,
     * applied at the moment a canned answer settles; the dispatcher's own
     * version of it is pinned in OpenAiModelsEndpointTest.)
     */
    public function testTheReadyMsgRepaintDerivesTheMergedPicker(): void
    {
        $base = $this->loopbackBase();
        $chat = $this->chatOn($base);
        [$opened] = $this->pick($chat, 'sglang');
        self::assertSame($this->expectedRows($base, null), $opened->paletteMatches(), 'precondition: base tier only');

        $ids = ['zz-catalog-model', 'aa-second-model'];
        $this->writeMemo($base, $ids);

        [$mergedRaw, $cmd] = $opened->update(new ModelCatalogReadyMsg('sglang', $ids));
        self::assertNull($cmd, 'the arm arms nothing — it is only the repaint');
        self::assertInstanceOf(Chat::class, $mergedRaw);
        $merged = $mergedRaw;
        self::assertSame(
            $this->expectedRows($base, $ids),
            $merged->paletteMatches(),
            'the open picker now shows base > live in merge order — the answer merged without any re-open',
        );
        self::assertSame('models', $merged->palette()->mode, 'the picker is still open on the same level');
        self::assertSame('sglang', $merged->palette()->provider);

        // The arm derives; it does not splice. A re-stamp whose payload
        // is nothing — the failed-arrival shape — repaints the rows the
        // memo already holds, and another provider's arrival cannot
        // disturb this picker because no memo key was ever written for it.
        [$againRaw, $againCmd] = $merged->update(new ModelCatalogReadyMsg('sglang', null));
        self::assertNull($againCmd);
        self::assertInstanceOf(Chat::class, $againRaw);
        $again = $againRaw;
        self::assertSame($merged->paletteMatches(), $again->paletteMatches(), 'an inert arrival re-paints identical rows: derivation, not a splice');

        [$foreignRaw, $foreignCmd] = $merged->update(new ModelCatalogReadyMsg('openai', ['unrelated']));
        self::assertNull($foreignCmd);
        self::assertInstanceOf(Chat::class, $foreignRaw);
        $foreign = $foreignRaw;
        self::assertSame($merged->paletteMatches(), $foreign->paletteMatches(), 'another provider answer cannot disturb this picker: its memo was never written');

        // And the settled memo serves the NEXT open directly — the wake
        // starts nothing a second time, the rows arrive at open.
        [$reopened] = $this->pick($this->chatOn($base), 'sglang');
        self::assertSame($this->expectedRows($base, $ids), $reopened->paletteMatches(), 'the late answer is in the memo for every later open — the whole point of prefetching');
    }

    /**
     * A FAILED ANSWER IS THE ABSENCE OF A TIER, NEVER A CHANGE. The
     * in-flight placeholder — the exact stamp the dispatcher leaves when
     * its transport rejects, same key, same null — meets the ids-null
     * arrival: the open picker derives exactly the base rows it showed
     * before the dial started, and the memo stamps the base answered-
     * nothing, so this session does not re-ask it.
     */
    public function testAFailedAnswerChangesNothingInTheOpenPicker(): void
    {
        $base = $this->loopbackBase();
        $chat = $this->chatOn($base);
        [$opened] = $this->pick($chat, 'sglang');
        $before = $opened->paletteMatches();
        self::assertSame($this->expectedRows($base, null), $before);

        [$afterRaw, $cmd] = $opened->update(new ModelCatalogReadyMsg('sglang', null));

        self::assertNull($cmd);
        self::assertInstanceOf(Chat::class, $afterRaw);
        $after = $afterRaw;
        self::assertSame($before, $after->paletteMatches(), 'a failed catalog leaves the base tier exactly as it was — null is "no live tier", not a failure');
        self::assertNull(ModelCatalog::live($this->providerOn($base)), 'the placeholder IS the failure stamp: the open already holds the key for this session');
    }

    /**
     * THE SYNC POLARITIES STAY BYTE-IDENTICAL. A backend that speaks no
     * catalog starts no async read and batches no wake: the prime falls
     * back to the sync path exactly as every pre-C-PRV-5.c pin describes
     * it — including the refused switch, whose backend never moved.
     */
    public function testProvidersWithoutAnAsyncPathStayOnTheSyncPrime(): void
    {
        $syncOnly = new class implements HasModelCatalog {
            public function catalogKey(): ?string
            {
                return 'https://sync-only.example/v1';
            }

            public function listModels(): ?array
            {
                return ['tier-of-a-sync-transport'];
            }
        };
        self::assertNull(ModelCatalog::prefetchAsync($syncOnly), 'no async sibling, nothing async starts');

        $chat = new Chat(
            backend: new EngineBackend(new EchoProvider(), 'echo-model'),
            backendFactory: static function (string $name): EngineBackend {
                if ($name === 'broken') {
                    throw new \RuntimeException('switch refused');
                }

                return new EngineBackend(new EchoProvider(), 'echo-model');
            },
        );

        [$picked, $cmd] = $this->pick($chat, 'echo');
        // The select path always batch-arms its context-remeasure wake, so
        // the no-wake shape is "same member count as the plain select" —
        // a catalog wake would add exactly one.
        [, $selectCmd] = $this->selectOnly($chat, 'echo');
        self::assertTrue($cmd === null || $selectCmd === null || self::batchMembers($cmd) === self::batchMembers($selectCmd), 'an echo pick carries no wake — the batch gains no member where nothing async started');
        $echoRows = ModelCatalog::merge(
            ModelChoice::paletteLabels('echo-model', null, $this->defaultFor('echo')),
            null,
            ModelCatalog::litellm('echo'),
        );
        if ($picked->palette() !== null) {
            self::assertSame($echoRows, $picked->paletteMatches(), 'what the echo picker shows is the sync-primed derivation — no live tier a non-catalog transport cannot have');
        } else {
            self::assertLessThan(2, count($echoRows), 'no reopen precisely because the sync-primed derivation offers <2 rows');
            self::assertSame([], $picked->paletteMatches());
        }

        [$failedRaw, $failedCmd] = $this->pick($chat, 'broken');
        self::assertInstanceOf(Chat::class, $failedRaw);
        $failed = $failedRaw;
        self::assertNull($failed->palette(), 'the refused switch closes the palette as before');
        self::assertNull($failedCmd);
    }

    // ------------------------------------------------------------------

    private function loopbackBase(): string
    {
        // A port nothing listens on: were a dial ever run, it answers
        // refused without DNS. No test here runs the loop, so none is.
        return 'http://127.0.0.1:' . (49152 + random_int(0, 16000)) . '/v1';
    }

    /**
     * The plain select behind the pick, for the cmd-shape baseline.
     *
     * @return array{0: Chat, 1: ?\Closure}
     */
    private function selectOnly(Chat $chat, string $name): array
    {
        $select = new ReflectionMethod(Chat::class, 'selectPaletteProvider');

        /** @var array{0: Chat, 1: ?\Closure} $result */
        $result = $select->invoke($chat, $name);

        return $result;
    }

    private function providerOn(string $base): SglangProvider
    {
        return new SglangProvider($base, 'model-sg', null, new Client());
    }

    private function chatOn(string $base): Chat
    {
        $factory = fn (string $_name): EngineBackend => new EngineBackend($this->providerOn($base), 'first-model');

        return new Chat(backend: $factory('sglang'), backendFactory: $factory);
    }

    /**
     * @return array{0: Chat, 1: ?\Closure}
     */
    private function pick(Chat $chat, string $name): array
    {
        $pick = new ReflectionMethod(Chat::class, 'pickPaletteProvider');

        /** @var array{0: Chat, 1: ?\Closure} $result */
        $result = $pick->invoke($chat, $name);

        return $result;
    }

    /**
     * The memo write prefetchAsync's own then performs, applied directly:
     * the key is the catalogKey of providerOn($base).
     *
     * @param list<string>|null $ids
     */
    private function writeMemo(string $base, ?array $ids): void
    {
        $memo = new ReflectionProperty(ModelCatalog::class, 'live');
        /** @var array<string, list<string>|null> $current */
        $current = $memo->getValue();
        $current[rtrim($base, '/')] = $ids;
        $memo->setValue(null, $current);
    }

    private function defaultFor(string $provider): ?string
    {
        $defaults = Bootstrap::availableProviders();

        return isset($defaults[$provider]['model']) && is_string($defaults[$provider]['model'])
            ? $defaults[$provider]['model']
            : null;
    }

    /**
     * The member count of a Cmd::batch() closure (its `use ($filtered)`
     * static), or 0 for any closure the batch seam did not build. Reading
     * the shape is deliberate: INVOKING a select-path cmd would arm the
     * context-remeasure machinery this file is not about.
     */
    private static function batchMembers(\Closure $cmd): int
    {
        $statics = (new \ReflectionFunction($cmd))->getStaticVariables();

        return isset($statics['filtered']) && is_array($statics['filtered']) ? count($statics['filtered']) : 0;
    }

    /**
     * The rows the picker should show for $base given the live tier:
     * derived through the SAME public functions the product reads, never
     * hand-spelled literals.
     *
     * @param list<string>|null $live
     *
     * @return list<string>
     */
    private function expectedRows(string $base, ?array $live): array
    {
        return ModelCatalog::merge(
            ModelChoice::paletteLabels('first-model', self::PERSISTED, $this->defaultFor('sglang')),
            $live,
            ModelCatalog::litellm('sglang'),
        );
    }
}
