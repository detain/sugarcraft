<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Channels\ChannelInboundLog;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Durable channel-log pins (spec 9.5 Track B): the ledger answers
 * inbound-before-dispatch, dedupes the first delivery, dead-letters the
 * poison at eight attempts or a day of age by stamp-compare, redacts
 * secrets before any byte lands, and answers the two replay shapes —
 * REPLAY from an intact file, RESET after a loud quarantine.
 */
final class ChannelInboundLogTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $inboxHome;
    private string $inboxConfig;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->inboxHome = \sys_get_temp_dir() . '/candy-code-inbox-test-' . \uniqid((string) \getmypid(), true);
        \mkdir($this->inboxHome, 0777, true);
        $this->inboxConfig = $this->inboxHome . '/cfg';
        \mkdir($this->inboxConfig, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->inboxHome);
    }

    private function inboxLedger(): ChannelInboundLog
    {
        return ChannelInboundLog::create(
            fn (): string => $this->inboxConfig,
            function (): int {
                return $this->now;
            },
        );
    }

    public function testARecordedRowLandsOnDiskBeforeAnyAck(): void
    {
        $ledger = $this->inboxLedger();
        self::assertSame(ChannelInboundLog::RECORDED, $ledger->record('chan', 'm-1', ['text' => 'hello there']));

        $reopened = $this->inboxLedger();
        $state = $reopened->pending();
        self::assertSame(ChannelInboundLog::SHAPE_REPLAY, $state['shape']);
        self::assertCount(1, $state['records']);
        self::assertSame('m-1', $state['records'][0]['key']);
        self::assertSame(ChannelInboundLog::STATE_PENDING, $state['records'][0]['state']);
    }

    public function testRawBytesCarryThePendingRowImmediately(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-raw', ['text' => 'persisted first']);

        $raw = \file_get_contents($ledger->path());
        self::assertIsString($raw);
        self::assertStringContainsString('"state": "pending"', $raw);
        self::assertStringContainsString('m-raw', $raw);
    }

    public function testDuplicateDeliveryAnswersWithoutNewRow(): void
    {
        $ledger = $this->inboxLedger();
        self::assertSame(ChannelInboundLog::RECORDED, $ledger->record('chan', 'm-dup', ['n' => 'first copy']));
        self::assertSame(ChannelInboundLog::DUPLICATE, $ledger->record('chan', 'm-dup', ['n' => 'second copy']));

        $state = $ledger->pending();
        self::assertCount(1, $state['records']);
        self::assertSame('first copy', $state['records'][0]['payload']['n'], 'first delivery owns the payload');
    }

    public function testDuplicateOfAckedRowStillAnswersDuplicate(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-ack', ['n' => 'body']);
        $ledger->markAcked('chan', 'm-ack');
        self::assertSame(ChannelInboundLog::DUPLICATE, $ledger->record('chan', 'm-ack', ['n' => 're-send']));
    }

    public function testPoisonAfterDeathNeverRedispatches(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-poi', ['n' => 'bad body']);
        for ($i = 0; $i < ChannelInboundLog::MAX_ATTEMPTS; ++$i) {
            $ledger->noteFailure('chan', 'm-poi');
        }

        self::assertSame(ChannelInboundLog::POISON, $ledger->record('chan', 'm-poi', ['n' => 'same id again']));
        self::assertSame([], $ledger->pending()['records'], 'a dead row never re-enters the replay set');
    }

    public function testDeadLetterAtExactlyTheEighthFailure(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-8', ['n' => 'flaky body']);
        for ($i = 1; $i < ChannelInboundLog::MAX_ATTEMPTS; ++$i) {
            self::assertSame(ChannelInboundLog::STATE_PENDING, $ledger->noteFailure('chan', 'm-8'));
        }
        self::assertSame(ChannelInboundLog::STATE_DEAD, $ledger->noteFailure('chan', 'm-8'));

        $row = $ledger->rows()['chan|m-8'];
        self::assertSame(ChannelInboundLog::MAX_ATTEMPTS, $row['attempts']);
        self::assertSame($this->now, $row['deadAt']);
    }

    public function testNoteFailureOnUnknownRowAnswersDead(): void
    {
        self::assertSame(ChannelInboundLog::STATE_DEAD, $this->inboxLedger()->noteFailure('chan', 'never-seen'));
    }

    public function testTerminalStateWinsOnLaterNoteFailure(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-term', ['n' => 'body']);
        $ledger->markAcked('chan', 'm-term');
        self::assertSame(ChannelInboundLog::STATE_ACKED, $ledger->noteFailure('chan', 'm-term'));
        self::assertSame(0, $ledger->rows()['chan|m-term']['attempts'], 'an ack cannot be poisoned after the fact');
    }

    public function testAgeDeadLetterByStampJump(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-old', ['n' => 'stuck body']);
        $this->now += ChannelInboundLog::RETENTION_SECONDS - 60;

        self::assertSame(ChannelInboundLog::STATE_PENDING, $ledger->noteFailure('chan', 'm-old'), 'a row at 23h59m has not aged out — the door sits at the retention window, never lower');
        $this->now += 60;

        self::assertSame(ChannelInboundLog::STATE_DEAD, $ledger->noteFailure('chan', 'm-old'));
        self::assertSame(2, $ledger->rows()['chan|m-old']['attempts'], 'age alone dead-letters, no retry storm needed');
    }

    public function testSweepAgesPendingRowsToDeadWithoutQuarantine(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-age', ['n' => 'never dispatched']);
        $this->now += ChannelInboundLog::RETENTION_SECONDS - 60;

        $young = $ledger->pending();
        self::assertCount(1, $young['records'], 'the sweep keeps a 23h59m row pending — its age door is the retention window, never lower');

        $this->now += 60;

        self::assertSame([], $ledger->pending()['records']);
        self::assertSame(ChannelInboundLog::STATE_DEAD, $ledger->rows()['chan|m-age']['state']);
        self::assertNull($ledger->quarantinedAt(), 'aging is not corruption — the shape stays REPLAY');
    }

    public function testPendingSetIsOrderedByFirstSeenThenKey(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'k-first', ['n' => 'earliest']);
        $this->now += 10;
        $ledger->record('chan', 'k-zulu', ['n' => 'later z']);
        $ledger->record('chan', 'k-alpha', ['n' => 'later a']);

        $keys = \array_map(static fn (array $r): string => (string) $r['key'], $ledger->pending()['records']);
        self::assertSame(['k-first', 'k-alpha', 'k-zulu'], $keys);
        self::assertSame(['earliest', 'later a', 'later z'], \array_map(static fn (array $r): string => (string) $r['payload']['n'], $ledger->pending()['records']));
    }

    public function testCorruptLedgerIsQuarantinedAndAnswersReset(): void
    {
        $probe = $this->inboxLedger();
        $poison = 'this is not json {{{';
        \file_put_contents($probe->path(), $poison);

        $ledger = $this->inboxLedger();
        self::assertSame($this->now, $ledger->quarantinedAt());
        self::assertSame(ChannelInboundLog::SHAPE_RESET, $ledger->pending()['shape']);
        self::assertSame([], $ledger->pending()['records'], 'garbage bytes are never parsed as an empty set');
        self::assertFileExists($this->inboxConfig . '/' . ChannelInboundLog::FILE_NAME . '.corral-' . $this->now);
        $corralled = \file_get_contents($this->inboxConfig . '/' . ChannelInboundLog::FILE_NAME . '.corral-' . $this->now);
        self::assertSame($poison, $corralled, 'the bytes stay available for an operator');
    }

    public function testTwoQuarantinesAtOneStampPreserveBothCorruptOriginals(): void
    {
        $probe = $this->inboxLedger();
        \file_put_contents($probe->path(), 'first corrupt {{{');
        self::assertNotNull($this->inboxLedger()->quarantinedAt(), 'the first boot quarantines under the bare stamp');

        // A second unreadable ledger inside the SAME stamp second must not
        // rename on top of the first forensic copy — both byte sets survive.
        \file_put_contents($this->inboxConfig . '/' . ChannelInboundLog::FILE_NAME, 'second corrupt }}}');
        $second = $this->inboxLedger();
        self::assertSame(ChannelInboundLog::SHAPE_RESET, $second->pending()['shape'], 'the announce shape is unchanged by the suffix walk');

        $corral = $this->inboxConfig . '/' . ChannelInboundLog::FILE_NAME . '.corral-';
        self::assertSame('first corrupt {{{', \file_get_contents($corral . $this->now), 'the first corrupt original survives intact');
        self::assertSame('second corrupt }}}', \file_get_contents($corral . $this->now . '.2'), 'the collision takes a suffixed name, never an overwrite');
    }

    public function testResetShapeIsStickyForTheProcessLife(): void
    {
        $probe = $this->inboxLedger();
        \file_put_contents($probe->path(), '{{{ not json either');

        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-after', ['n' => 'post-reset body']);
        self::assertSame(ChannelInboundLog::SHAPE_RESET, $ledger->pending()['shape'], 'incomplete history does not heal mid-boot');

        self::assertSame(ChannelInboundLog::SHAPE_REPLAY, $this->inboxLedger()->pending()['shape'], 'the next boot replays the intact file');
    }

    public function testWrongVersionIsQuarantined(): void
    {
        $probe = $this->inboxLedger();
        \file_put_contents($probe->path(), \json_encode(['version' => 2, 'records' => []], \JSON_THROW_ON_ERROR));

        self::assertNotNull($this->inboxLedger()->quarantinedAt());
    }

    public function testNonArrayRecordsIsQuarantined(): void
    {
        $probe = $this->inboxLedger();
        \file_put_contents($probe->path(), \json_encode(['version' => 1, 'records' => 'nope'], \JSON_THROW_ON_ERROR));

        self::assertNotNull($this->inboxLedger()->quarantinedAt());
    }

    public function testIntactLedgerReloadsAsReplayAndKeepsRows(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-a', ['n' => 'row a']);
        $ledger->record('chan', 'm-b', ['n' => 'row b']);
        $ledger->markAcked('chan', 'm-b');

        $reopened = $this->inboxLedger();
        self::assertNull($reopened->quarantinedAt());
        self::assertSame(ChannelInboundLog::SHAPE_REPLAY, $reopened->pending()['shape']);
        self::assertSame(['m-a'], \array_map(static fn (array $r): string => (string) $r['key'], $reopened->pending()['records']));
        self::assertSame(ChannelInboundLog::STATE_ACKED, $reopened->rows()['chan|m-b']['state']);
    }

    public function testLedgerFileIsOwnerOnly(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-mode', ['n' => 'permissions pin']);
        self::assertSame(0600, \fileperms($ledger->path()) & 0777);
    }

    public function testSecretsAreRedactedBeforeStorage(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-sec', [
            'note' => 'a plain note about the channel',
            'key' => 'sk-proj-' . \str_repeat('q', 30),
            'auth' => [
                'bearer' => 'Authorization: Bearer ' . \str_repeat('a', 32),
                'jwt' => 'token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U here',
                'slack' => 'hook xoxp-1234567890-1234567890-1234567890-abcdef',
            ],
        ]);

        $raw = (string) \file_get_contents($ledger->path());
        self::assertStringNotContainsString(str_repeat('q', 30), $raw);
        self::assertStringNotContainsString(str_repeat('a', 32), $raw);
        self::assertStringNotContainsString('doejgNryP4J3jVmNHl0w5N', $raw);
        self::assertStringNotContainsString('xoxp-1234567890', $raw);
        self::assertStringContainsString('[REDACTED]', $raw);
        self::assertStringContainsString('a plain note about the channel', $raw, 'innocent text survives the sweep');
    }

    public function testSignInUrlFragmentIsSwept(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-url', ['body' => ' sign in at https://127.0.0.1:3000/login#0123456789abcdef to continue']);

        $raw = (string) \file_get_contents($ledger->path());
        self::assertStringNotContainsString('#0123456789abcdef', $raw);
        self::assertStringContainsString('login#[REDACTED]', $raw);
    }

    public function testQueryCredentialsAreSwept(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-query', ['a' => 'https://example.test/api?code=9876543 and', 'b' => 'https://example.test/hook&token=abc123xyz tail']);

        $raw = (string) \file_get_contents($ledger->path());
        self::assertStringNotContainsString('9876543', $raw);
        self::assertStringNotContainsString('abc123xyz', $raw);
        self::assertStringContainsString(\chr(63) . 'code=[REDACTED]', $raw);
        self::assertStringContainsString('&token=[REDACTED]', $raw);
    }

    public function testSecretNamedKeysAreMaskedAtTheKeyPosition(): void
    {
        $ledger = $this->inboxLedger();

        self::assertSame('recorded', $ledger->record('chan', 'm-keymask', [
            'api_key' => 'plain english note',
            'harmless' => 'ordinary note',
            'auth' => ['token' => 'hello world greeting'],
        ]));

        $raw = (string) \file_get_contents($ledger->path());
        // The NAMES are gone, at every depth: no key survives that just
        // labels a secret.
        self::assertStringNotContainsString('api_key', $raw);
        self::assertStringNotContainsString('"token"', $raw);
        // The shape stays: each masked key carries its (value-armed) payload.
        self::assertStringContainsString('"[REDACTED]": "plain english note"', $raw);
        self::assertStringContainsString(': "hello world greeting"', $raw);
        // Unaffected keys ride through untouched, including the value arm's
        // own verdict on their content.
        self::assertStringContainsString('"harmless": "ordinary note"', $raw);
    }

    public function testInnocuousKeysAndTheirOrderAreUntouchedByTheKeyArm(): void
    {
        $ledger = $this->inboxLedger();

        self::assertSame('recorded', $ledger->record('chan', 'm-keyclean', [
            'text' => 'plain note',
            // Names that merely CONTAIN a secret word are not secret names;
            // widening the key arm past a whole-name match reddens this leg.
            'tokenizer' => 'wide leg one',
            'secretary' => 'wide leg two',
            'my_api_key' => 'wide leg three',
            'nested' => ['count' => 7, 'label' => 'status: ok'],
        ]));

        $raw = (string) \file_get_contents($ledger->path());
        self::assertStringNotContainsString('[REDACTED]', $raw);
        $positions = [];
        foreach (['text', 'tokenizer', 'secretary', 'my_api_key', 'nested', 'count', 'label'] as $needle) {
            $positions[$needle] = \strpos($raw, '"' . $needle . '"');
            self::assertNotFalse($positions[$needle], 'key ' . $needle . ' survived');
        }
        // Insertion order is preserved through the rebuild, level for level.
        self::assertLessThan($positions['tokenizer'], $positions['text']);
        self::assertLessThan($positions['secretary'], $positions['tokenizer']);
        self::assertLessThan($positions['my_api_key'], $positions['secretary']);
        self::assertLessThan($positions['nested'], $positions['my_api_key']);
        self::assertLessThan($positions['label'], $positions['count']);
    }

    public function testTheRedactionWalkPreservesKeysOrderTypesAndIntegersWhenNoArmFires(): void
    {
        // Direct walk pair: any tree the key arm never touches must come
        // out of the rebuilt level identical — same keys, same insertion
        // order (including integer-key promotion), same value types — so
        // the byte-identity of unaffected records does not rest on the
        // JSON alone.
        $redactTree = new \ReflectionMethod(ChannelInboundLog::class, 'redactTree');
        $redactTree->setAccessible(true);
        $ledger = $this->inboxLedger();

        /** @var array<array-key, mixed> $tree */
        $tree = $redactTree->invoke($ledger, [
            'b_first' => 'plain',
            'a_second' => 7,
            3 => 'integer keyed',
            'nested' => ['deep' => true, 'deeper' => ['n' => null]],
            'floaty' => 1.5,
        ]);

        self::assertSame(
            ['b_first', 'a_second', 3, 'nested', 'floaty'],
            \array_keys($tree),
        );
        self::assertSame('plain', $tree['b_first']);
        self::assertSame(7, $tree['a_second']);
        self::assertSame('integer keyed', $tree[3]);
        self::assertSame(['deep', 'deeper'], \array_keys($tree['nested']), 'nested keys keep order');
        self::assertTrue($tree['nested']['deep']);
        self::assertNull($tree['nested']['deeper']['n']);
        self::assertSame(1.5, $tree['floaty']);
    }

    public function testTwoSecretNamedKeysCollapseToTheOneMaskedRowWithoutMergingValues(): void
    {
        $ledger = $this->inboxLedger();

        self::assertSame('recorded', $ledger->record('chan', 'm-keyclash', [
            'api_key' => 'alpha value here',
            'token' => 'beta value here',
        ]));

        $raw = (string) \file_get_contents($ledger->path());
        self::assertSame(1, \substr_count($raw, '"[REDACTED]"'));
        // The first entry keeps its (value-armed) payload; the colliding
        // later one is dropped whole, so neither value is miscredited to
        // the other and no numbered fake key is invented.
        self::assertStringContainsString(': "alpha value here"', $raw);
        self::assertStringNotContainsString('beta value here', $raw);
        self::assertStringNotContainsString('api_key', $raw);
    }

    public function testRedactionRunsBeforeTheSizeCap(): void
    {
        $shrinker = ' token sk-proj-' . \str_repeat('z', 4000) . ' tail text';
        $payload = ['line' => \str_repeat('p', 15000) . $shrinker];
        self::assertGreaterThan(ChannelInboundLog::MAX_PAYLOAD_BYTES, \strlen((string) \json_encode($payload)), 'the raw form alone would trip the cap');

        $ledger = $this->inboxLedger();
        self::assertSame(ChannelInboundLog::RECORDED, $ledger->record('chan', 'm-straddle', $payload));

        $raw = (string) \file_get_contents($ledger->path());
        self::assertStringNotContainsString(\str_repeat('z', 4000), $raw);
        self::assertStringContainsString('[REDACTED]', $raw, 'HookLog order law: cut-after-redact, never the reverse');
    }

    public function testOversizePayloadIsRefusedAndNothingIsWritten(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-keep', ['n' => 'an earlier survivor']);
        $before = (string) \file_get_contents($ledger->path());

        self::assertSame(ChannelInboundLog::REFUSED_OVERSIZE, $ledger->record('chan', 'm-big', ['n' => \str_repeat('x ', 20000)]));
        self::assertSame($before, \file_get_contents($ledger->path()), 'a refusal writes zero bytes');
        self::assertArrayNotHasKey('chan|m-big', $ledger->rows());
    }

    public function testSaturationRefusesNewRecordsPastThePendingCap(): void
    {
        $ledger = $this->inboxLedger();
        for ($i = 0; $i < ChannelInboundLog::MAX_RECORDS; ++$i) {
            self::assertSame(ChannelInboundLog::RECORDED, $ledger->record('chan', 'k' . $i, ['n' => 'body']));
        }

        self::assertSame(ChannelInboundLog::REFUSED_SATURATED, $ledger->record('chan', 'overflow', ['n' => 'one too many']));
        self::assertCount(ChannelInboundLog::MAX_RECORDS, $ledger->pending()['records']);
    }

    public function testAckedRowsPruneAfterTheWindowWhileAgedPendingRowsSurviveAsDead(): void
    {
        $ledger = $this->inboxLedger();
        $ledger->record('chan', 'm-done', ['n' => 'acked body']);
        $ledger->markAcked('chan', 'm-done');
        $ledger->record('chan', 'm-live', ['n' => 'pending body']);

        $this->now += ChannelInboundLog::RETENTION_SECONDS + 1;
        $ledger->record('chan', 'm-newer', ['n' => 'triggers the sweep']);

        self::assertArrayNotHasKey('chan|m-done', $ledger->rows(), 'the dedupe window for acked rows is bounded');
        self::assertArrayHasKey('chan|m-live', $ledger->rows(), 'pending rows are NEVER dropped');
    }

    public function testTerminalShedKeepsNewestRowsAndNeverPending(): void
    {
        $rows = [];
        $t = $this->now;
        for ($i = 0; $i < 600; ++$i) {
            $rows['chan|k' . $i] = ['channel' => 'chan', 'key' => 'k' . $i, 'state' => 'acked', 'attempts' => 0, 'firstSeen' => $t + $i, 'updatedAt' => $t + $i, 'ackedAt' => $t + $i, 'payload' => ['n' => 'body']];
        }
        $rows['chan|keep-pending'] = ['channel' => 'chan', 'key' => 'keep-pending', 'state' => 'pending', 'attempts' => 0, 'firstSeen' => $t + 5, 'updatedAt' => $t + 5, 'payload' => ['n' => 'must survive']];

        $probe = $this->inboxLedger();
        \file_put_contents($probe->path(), \json_encode(['version' => 1, 'records' => $rows], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        $ledger = $this->inboxLedger();
        $state = $ledger->pending();
        self::assertCount(1, $state['records'], 'the pending row is untouchable by the shed');
        self::assertCount(ChannelInboundLog::MAX_RECORDS, $ledger->rows());
        self::assertArrayNotHasKey('chan|k0', $ledger->rows(), 'oldest acked sheds first');
        self::assertArrayHasKey('chan|k599', $ledger->rows(), 'newest rows stay');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function inboxAddressParts(): array
    {
        $long = \str_repeat('a', ChannelInboundLog::MAX_PART_CHARS + 1);

        return [
            'empty channel' => ['', 'ok-key'],
            'blank channel' => ['   ', 'ok-key'],
            'runaway channel' => [$long, 'ok-key'],
            'empty key' => ['ok-chan', ''],
            'blank key' => ['ok-chan', '   '],
            'runaway key' => ['ok-chan', $long],
        ];
    }

    /**
     * @dataProvider inboxAddressParts
     */
    public function testUnaddressableNamesThrow(string $channel, string $key): void
    {
        $ledger = $this->inboxLedger();
        $this->expectException(\InvalidArgumentException::class);
        $ledger->record($channel, $key, ['n' => 'never stored']);
    }

    public function testMissingConfigDirectoryRefusesToConstruct(): void
    {
        $this->expectException(\RuntimeException::class);
        ChannelInboundLog::create(static fn (): string => '');
    }
}
