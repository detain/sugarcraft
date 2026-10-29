<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\TrustAsks;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-CTX-3.c: `context.instructionWrite` is the panel's write half, and its
 * whole design is that the JAIL IS THE LISTING. A replace may spell only a
 * path `context.sources` itself listed (or the `personal` sentinel, so a
 * first quick-add works before the file exists), containment is re-checked
 * at write time so a swapped-in symlink cannot carry the write outside the
 * tier's boundary, `expectBytes` latches the conflict the panel measured
 * against the bytes on disk now, and the size ceiling is the loader's own
 * constant — never a document it would refuse to read back. A landed write
 * announces `context.changed` (reason "instructions") so every open panel
 * re-measures. Each refusal here failed against the base tree, where no
 * write-scoped context method existed at all.
 */
final class ContextInstructionWriteTest extends TestCase
{
    use HomeSandboxTrait;

    private ProtocolFixture $fixture;

    private string $homeRoot = '';

    protected function setUp(): void
    {
        TrustAsks::resetForTesting();
        $this->homeRoot = \sys_get_temp_dir() . '/c3c-home-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        \mkdir($this->homeRoot . '/.candy-code', 0o700, true);
        \mkdir($this->homeRoot . '/memory', 0o700, true);
        $this->fixture = ProtocolFixture::new(memoryStore: new MemoryStore($this->homeRoot . '/memory'));
    }

    protected function tearDown(): void
    {
        TrustAsks::resetForTesting();
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        $this->purgeTree($this->homeRoot);
    }

    public function testAListedProjectDocumentReplacesAndAnnouncesContextChanged(): void
    {
        $projectFile = $this->fixture->root . '/AGENTS.md';
        \file_put_contents($projectFile, "before\n");
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        // A fresh subscriber is buffered until the replay flush flips it live
        // (SessionFeed lines 230/714): pump the loop once so what follows is
        // delivered synchronously, exactly as the served panel is.
        $this->fixture->run();
        // A subscriber goes live on a future tick (appendix O catch-up);
        // without the pump the write's announce would only buffer.
        $this->fixture->run();
        $row = $this->listedRow($client, $sessionId, $projectFile);
        self::assertSame('project', $row['tier']);
        self::assertSame(7, $row['bytes']);

        $result = $client->call('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => $row['path'],
            'content' => "after\nmore\n",
        ]);
        self::assertSame(['path' => $row['path'], 'bytes' => 11, 'mode' => 'replace'], $result);
        self::assertSame("after\nmore\n", \file_get_contents($projectFile));

        $this->fixture->run();
        $announced = $this->instructionChanges($client);
        self::assertCount(1, $announced);
        self::assertSame('instructions', $announced[0]['data']['reason']);
        self::assertSame($sessionId, $announced[0]['sessionId'], 'the envelope names the session it speaks for');

        // The answer the panel re-reads carries the new byte count.
        self::assertSame(11, $this->listedRow($client, $sessionId, $projectFile)['bytes']);
    }

    public function testThePersonalSentinelEnsuresTheDirectoryAndAppendsUnderSixHundred(): void
    {
        // No .candy-code yet: the sentinel arm must ensure it, mint the file
        // 0600, and land the line — the TUI quick-add's laws over the wire.
        @rmdir($this->homeRoot . '/.candy-code');
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        // A fresh subscriber is buffered until the replay flush flips it live
        // (SessionFeed lines 230/714): pump the loop once so what follows is
        // delivered synchronously, exactly as the served panel is.
        $this->fixture->run();
        $this->fixture->run();

        $result = $client->call('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => 'personal',
            'append' => "- keep secrets out of the logs\n",
        ]);
        $personal = (string) realpath($this->homeRoot . '/.candy-code/AGENTS.md');
        self::assertNotFalse($personal, 'the sentinel resolved');
        self::assertSame(['path' => $personal, 'bytes' => 31, 'mode' => 'append'], $result);
        self::assertSame("- keep secrets out of the logs\n", \file_get_contents($personal));
        self::assertSame('600', \decoct((int) \fileperms($personal) & 0o777), 'a personal file is nobody else\'s');

        $again = $client->call('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => 'personal',
            'append' => "- second line\n",
        ]);
        self::assertSame(45, $again['bytes'], 'the second append grows the same file, it does not rewrite it');
        self::assertSame("- keep secrets out of the logs\n- second line\n", \file_get_contents($personal));
        $this->fixture->run();
        self::assertCount(2, $this->instructionChanges($client));
    }

    public function testAPathTheListingNeverSpelledIsRefused(): void
    {
        \file_put_contents($this->fixture->root . '/notes.md', "mine\n");
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $reply = $client->request('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => $this->fixture->root . '/notes.md',
            'content' => "over\n",
        ]);
        self::assertSame(-32004, $reply['error']['code']);
        self::assertSame('instruction_not_listed', $reply['error']['data']['kind']);
        self::assertSame("mine\n", \file_get_contents($this->fixture->root . '/notes.md'), 'refusal costs the file nothing');
    }

    public function testAListedPathSwappedForASymlinkIsRefused(): void
    {
        $projectFile = $this->fixture->root . '/AGENTS.md';
        \file_put_contents($projectFile, "before\n");
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $row = $this->listedRow($client, $sessionId, $projectFile);

        $elsewhere = $this->fixture->dir . '/elsewhere.md';
        \file_put_contents($elsewhere, "victim\n");
        \unlink($projectFile);
        \symlink($elsewhere, $projectFile);

        $reply = $client->request('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => $row['path'],
            'content' => "escaped\n",
        ]);
        self::assertSame(-32003, $reply['error']['code']);
        self::assertSame('instruction_symlink', $reply['error']['data']['kind']);
        self::assertSame("victim\n", \file_get_contents($elsewhere));
    }

    public function testADeletedListedPathRefusesAsAStaleListing(): void
    {
        $projectFile = $this->fixture->root . '/AGENTS.md';
        \file_put_contents($projectFile, "before\n");
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $row = $this->listedRow($client, $sessionId, $projectFile);
        \unlink($projectFile);

        $reply = $client->request('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => $row['path'],
            'content' => "ghost\n",
        ]);
        self::assertSame(-32004, $reply['error']['code']);
        self::assertSame('instruction_not_listed', $reply['error']['data']['kind'], 'a vanished listing is re-read, not silently re-created');
        self::assertFalse(\is_file($projectFile));
    }

    public function testExpectBytesLatchesTheConflictThePanelMeasured(): void
    {
        $projectFile = $this->fixture->root . '/AGENTS.md';
        \file_put_contents($projectFile, "before\n");
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $row = $this->listedRow($client, $sessionId, $projectFile);

        // Someone else grew the file while the modal stayed open.
        \file_put_contents($projectFile, "someone else grew this\n");

        $reply = $client->request('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => $row['path'],
            'content' => "mine instead\n",
            'expectBytes' => $row['bytes'],
        ]);
        self::assertSame(-32009, $reply['error']['code']);
        self::assertSame('instruction_changed', $reply['error']['data']['kind']);
        self::assertSame("someone else grew this\n", \file_get_contents($projectFile));

        $ok = $client->call('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => $row['path'],
            'content' => "mine instead\n",
            'expectBytes' => 23,
        ]);
        self::assertSame(13, $ok['bytes']);
    }

    public function testARefusedSentinelSaveOnTheStandInRowMintsNothingAndKeepsTheStandIn(): void
    {
        // Round-1 fix (MAJOR-1): the sentinel must resolve WITHOUT creating.
        // Pre-fix, personalWriteTarget minted an empty 0600 AGENTS.md before
        // the expectBytes latch, so a panel Edit of the personal row while it
        // stands on the ~/.claude/CLAUDE.md fallback refused
        // `instruction_changed` (latch saw the fresh 0, the panel spelled the
        // stand-in's bytes) AND left the empty primary in place — which
        // loadPersonal then prefers, silently evicting the operator's
        // personal instructions from context while landing nothing. A
        // refusal must cost nothing, on the sentinel arm too.
        $standIn = $this->homeRoot . '/.claude';
        \mkdir($standIn, 0o700, true);
        \file_put_contents($standIn . '/CLAUDE.md', "personal standing instructions\n");
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $row = $this->listedRow($client, $sessionId, $standIn . '/CLAUDE.md');
        self::assertSame('personal', $row['tier'], 'the stand-in is listed as the personal row');
        self::assertSame(31, $row['bytes']);
        self::assertFalse(\is_file($this->homeRoot . '/.candy-code/AGENTS.md'), 'the fixture carries no primary');

        $reply = $client->request('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => 'personal',
            'content' => "edited over the stand-in\n",
            'expectBytes' => $row['bytes'],
        ]);
        self::assertSame(-32009, $reply['error']['code']);
        self::assertSame('instruction_changed', $reply['error']['data']['kind']);
        self::assertFalse(\is_file($this->homeRoot . '/.candy-code/AGENTS.md'), 'the refusal minted no primary');
        self::assertSame("personal standing instructions\n", \file_get_contents($standIn . '/CLAUDE.md'), 'the refusal wrote nothing through the stand-in either');

        // The stand-in is still the personal source: same path, same bytes.
        $again = $this->listedRow($client, $sessionId, $standIn . '/CLAUDE.md');
        self::assertSame($row['path'], $again['path']);
        self::assertSame(31, $again['bytes']);

        // Creation rides the SUCCESS path: an unlatched quick-add write mints
        // the primary 0600 here, and only now does the primary mask the
        // stand-in — the operator's own act, not a refused save's side effect.
        $ok = $client->call('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => 'personal',
            'append' => "- earned the hard way\n",
        ]);
        self::assertSame((string) realpath($this->homeRoot . '/.candy-code/AGENTS.md'), $ok['path']);
        self::assertSame(22, $ok['bytes']);
        self::assertSame('600', \decoct((int) \fileperms($ok['path']) & 0o777), 'a personal file is nobody else\'s');
    }

    public function testExactlyOneOfContentAndAppendMustBeSpelled(): void
    {
        $projectFile = $this->fixture->root . '/AGENTS.md';
        \file_put_contents($projectFile, "before\n");
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $row = $this->listedRow($client, $sessionId, $projectFile);

        foreach (['both' => ['content' => 'a', 'append' => 'b'], 'neither' => []] as $label => $extra) {
            $reply = $client->request('context.instructionWrite', ['sessionId' => $sessionId, 'path' => $row['path']] + $extra);
            self::assertSame(-32602, $reply['error']['code'], $label . ' refuses');
            self::assertSame('instruction_mode', $reply['error']['data']['kind']);
        }
        self::assertSame("before\n", \file_get_contents($projectFile));
    }

    public function testTheAppendResultMayNotGrowPastTheLoaderCeiling(): void
    {
        $projectFile = $this->fixture->root . '/AGENTS.md';
        $near = \str_repeat('a', InstructionFileLoader::MAX_DOCUMENT_BYTES - 8);
        \file_put_contents($projectFile, $near);
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $row = $this->listedRow($client, $sessionId, $projectFile);

        $reply = $client->request('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => $row['path'],
            'append' => "overflow\n",
        ]);
        self::assertSame(-32602, $reply['error']['code']);
        self::assertSame('instruction_too_large', $reply['error']['data']['kind'], 'the cap is the result\'s, not the payload\'s alone — a document past it would only be read back truncated');
        self::assertSame(\strlen($near), (int) \filesize($projectFile), 'the refused append costs the file nothing');
    }

    public function testAPersonalDirectoryThatIsASymlinkIsRefused(): void
    {
        if (\is_file($this->homeRoot . '/.candy-code/AGENTS.md')) {
            \unlink($this->homeRoot . '/.candy-code/AGENTS.md');
        }
        \rmdir($this->homeRoot . '/.candy-code');
        $decoy = $this->fixture->dir . '/decoy-code';
        \mkdir($decoy, 0o700, true);
        \symlink($decoy, $this->homeRoot . '/.candy-code');
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $reply = $client->request('context.instructionWrite', [
            'sessionId' => $sessionId,
            'path' => 'personal',
            'append' => "- x\n",
        ]);
        self::assertSame(-32003, $reply['error']['code']);
        self::assertSame('instruction_home_unavailable', $reply['error']['data']['kind']);
        self::assertFalse(\is_file($decoy . '/AGENTS.md'));
    }

    public function testTheMethodIsWriteScopedAndClaimsItsSideEffect(): void
    {
        // No live read-scope connection exists yet to drive (the viewer token
        // arrives with its own step), so the scope law is pinned on the spec:
        // Dispatcher::requireScope consults exactly this against the
        // connection's granted scopes.
        $spec = Dispatcher::methods()->get('context.instructionWrite');
        self::assertNotNull($spec, 'context.instructionWrite is on the roster');
        self::assertSame(Scope::Write, $spec->scope, 'a write through a read surface would let any viewer edit the session\'s memory of itself');
        self::assertTrue($spec->sideEffects, 'it writes the disk and announces');
    }

    /** @return array{path: string, bytes: int, tier: string} */
    private function listedRow(WireClient $client, string $sessionId, string $file): array
    {
        $rows = $client->call('context.sources', ['sessionId' => $sessionId])['instructionFiles'] ?? [];
        foreach ($rows as $row) {
            if ($row['path'] === $file || realpath($row['path']) === realpath($file)) {
                return $row;
            }
        }
        self::fail('the loader listed no row for ' . $file);
    }

    /** @return list<array<string, mixed>> */
    private function instructionChanges(WireClient $client): array
    {
        return \array_values(\array_filter(
            $client->events('context.changed'),
            static fn (array $envelope): bool => ($envelope['data']['reason'] ?? null) === 'instructions',
        ));
    }

    private function purgeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_link($path)) {
                @\unlink($path);
            } elseif (\is_dir($path)) {
                $this->purgeTree($path);
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
