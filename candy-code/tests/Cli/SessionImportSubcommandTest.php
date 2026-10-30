<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SES-7.b — `candy-code session import <path>`, the CLI arm of the C-SES-7.a
 * importer: the verb that turns a foreign transcript file into a resumable
 * session without leaving the house.
 *
 * The door order the arm commits to, each pin below naming one door:
 * a missing operand and a bad `--format` word are usage doors (exit 2 —
 * nothing was attempted); an unknown third operand is the sibling verbs'
 * `unexpected operand` door; a source the importer itself refuses (unreadable
 * bytes, a shape none of the four readers recognise, a source that shrank
 * under its own ledger) exits 1 typed `import-refused` — the store WAS
 * opened and the import attempted, the same not-found-channel distinction
 * every other verb keeps, applied to the source file instead of a target
 * row. The live lock of a session this file already feeds is asked through
 * {@see Subcommands}' shared ISS-079 door BEFORE the import runs, so
 * `--force` means one thing across the whole verb and a delta can never be
 * appended underneath a live window that would undo it on its next save.
 *
 * Bounds coverage note: the CLI arm catches `ImportRefusedException` as a
 * type — tooManyItems/tooLarge arrive through the identical channel, and
 * SessionImporterTest::testBothBoundsRefuseTypedBeforeAnythingIsPersisted
 * pins that they do; a 64 MiB fixture would re-pin the same one line.
 *
 * In-process, the way SessionSubcommandsTest drives its verbs: ArgvParser →
 * Subcommands::dispatch with `HOME` pointed at a private directory. stdout
 * is ob-captured; stderr is not capturable in-process, so every failure arm
 * is asserted through the `--output-format json` document.
 */
final class SessionImportSubcommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;
    private string|false $originalRetention;
    private EnhancedSessionStore $store;
    private ?SessionLock $held = null;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/csi7b-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.candy-code', 0700, true));
        chmod($this->home, 0700);
        $this->originalRetention = getenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $this->useHomeSandbox($this->home);
        putenv('CANDY_CODE_SESSION_RETENTION_DAYS');

        $this->store = new EnhancedSessionStore($this->home . '/.candy-code/session.db');
        $this->store->createSession('sess-main-0001', 'openai', 'gpt-4o', null, 'login work');
    }

    protected function tearDown(): void
    {
        $this->held = null;
        $this->restoreHomeSandbox();
        putenv($this->originalRetention === false
            ? 'CANDY_CODE_SESSION_RETENTION_DAYS'
            : 'CANDY_CODE_SESSION_RETENTION_DAYS=' . $this->originalRetention);
        $this->removeTree($this->home);
    }

    // ── the happy path ───────────────────────────────────────────────────

    public function testATranscriptImportsAndReportsInTextWithAResumeHint(): void
    {
        $file = $this->export('a.json', [
            ['role' => 'user', 'content' => 'map the import verb'],
            ['role' => 'assistant', 'content' => 'four doors and a ledger'],
        ]);

        [$rc, $out] = $this->dispatch(['session', 'import', $file]);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);

        self::assertSame(1, preg_match('/Imported 2 item\(s\) from CandyCode as session ([0-9a-f]{32}); 2 appended/', $out, $m), $out);
        $id = $m[1];
        self::assertStringContainsString('Resume it with `candy-code --resume ' . $id . '`.', $out);

        // The session really is resumable: the row exists and the transcript
        // carries the importer's untrusted-material notice plus both rows.
        $row = $this->store->session($id);
        self::assertNotNull($row);
        self::assertStringStartsWith('import:', (string) $row['provider']);
        self::assertCount(3, $this->store->loadTranscript($id));
    }

    public function testTheJsonFormCarriesIdentityFormatAndDigest(): void
    {
        $file = $this->export('b.json', [
            ['role' => 'user', 'content' => 'foreign thing happened'],
            ['role' => 'assistant', 'content' => 'and is now ours'],
        ]);

        [$rc, $out] = $this->dispatch(['session', 'import', $file, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$out}");
        $result = $decoded['result'] ?? null;
        self::assertIsArray($result, $out);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $result['imported']);
        self::assertSame('candy-code', $result['format']);
        self::assertSame(2, $result['items']);
        self::assertSame(2, $result['appended']);
        self::assertSame(hash_file('sha256', $file), $result['digest']);
        self::assertNull($result['name']);
    }

    public function testAnExplicitNameBecomesTheResumeTarget(): void
    {
        $file = $this->export('named.json', [
            ['role' => 'user', 'content' => 'quarterly budget review'],
        ]);

        [$rc, $out] = $this->dispatch(['session', 'import', $file, '--name', 'Board Room Q4']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);

        [$rc, $shown] = $this->dispatch(['session', 'show', 'Board Room Q4']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $shown);
        self::assertStringContainsString('Board Room Q4', $shown);
    }

    // ── the ledger through the CLI ───────────────────────────────────────

    public function testAByteIdenticalReimportAppendsNothingAndKeepsTheSession(): void
    {
        $file = $this->export('again.json', [
            ['role' => 'user', 'content' => 'first pass'],
        ]);

        [, $first] = $this->dispatch(['session', 'import', $file]);
        self::assertSame(1, preg_match('/as session ([0-9a-f]{32}); 1 appended/', $first, $m), $first);

        [, $again] = $this->dispatch(['session', 'import', $file]);
        self::assertStringContainsString('as session ' . $m[1] . '; 0 appended', $again);
    }

    public function testAGrownSourceAppendsOnlyTheDeltaRows(): void
    {
        $rows = [
            ['role' => 'user', 'content' => 'the head stays'],
            ['role' => 'assistant', 'content' => 'and so does its reply'],
        ];
        $file = $this->export('grown.json', $rows);
        [, $first] = $this->dispatch(['session', 'import', $file]);
        self::assertSame(1, preg_match('/as session ([0-9a-f]{32});/', $first, $m), $first);

        $rows[] = ['role' => 'user', 'content' => 'a tail line lands now'];
        self::assertSame($file, $this->exportNamed('grown.json', $rows));

        [$rc, $grown] = $this->dispatch(['session', 'import', $file]);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $grown);
        self::assertStringContainsString('as session ' . $m[1] . '; 1 appended', $grown);
        self::assertStringContainsString('Imported 3 item(s)', $grown);
        self::assertCount(4, $this->store->loadTranscript($m[1]));
    }

    // ── usage doors (exit 2) ─────────────────────────────────────────────

    public function testAMissingFileOperandIsAUsageDoor(): void
    {
        self::assertSame('session import: no transcript file given', $this->usageDoor(['session', 'import']));
    }

    public function testAnUnknownFormatWordIsAUsageDoor(): void
    {
        $file = $this->export('fmt.json', [['role' => 'user', 'content' => 'anything']]);
        self::assertSame(
            'session import --format frobnicate: not a known source format',
            $this->usageDoor(['session', 'import', $file, '--format', 'frobnicate']),
        );
    }

    public function testAnExtraOperandIsTheSiblingsUnexpectedOperandDoor(): void
    {
        $file = $this->export('two.json', [['role' => 'user', 'content' => 'one file']]);
        self::assertSame(
            'session import second.json: unexpected operand',
            $this->usageDoor(['session', 'import', $file, 'second.json']),
        );
    }

    public function testTheImportersOwnRefusalsExitOneTypedNotTwo(): void
    {
        $path = $this->home . '/garbage.jsonl';
        self::assertNotFalse(file_put_contents($path, "these are not a transcript's bytes\n"));

        [$rc, $out] = $this->dispatch(['session', 'import', $path, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $out);
        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$out}");
        self::assertSame('import-refused', $decoded['error']['type'] ?? null, $out);
        self::assertNull($decoded['result'] ?? null);
    }

    // ── the live-lock ask (ISS-079 parity) ───────────────────────────────

    public function testTheLiveLockOfAnImportedSessionRefusesAndForceProceeds(): void
    {
        $rows = [['role' => 'user', 'content' => 'locked conversation head']];
        $file = $this->export('locked.json', $rows);
        [, $out] = $this->dispatch(['session', 'import', $file]);
        self::assertSame(1, preg_match('/as session ([0-9a-f]{32});/', $out, $m), $out);

        $this->held = $this->store->lockSession($m[1]);
        self::assertNotNull($this->held, 'the fixture lock must be held');

        // A delta cannot be appended underneath a live window: the shared
        // door refuses BEFORE the importer runs.
        $rows[] = ['role' => 'assistant', 'content' => 'a tail that must wait'];
        self::assertSame($file, $this->exportNamed('locked.json', $rows));
        [$rc, $refused] = $this->dispatch(['session', 'import', $file, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $refused);
        $decoded = json_decode(trim($refused), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$refused}");
        self::assertSame('usage', $decoded['error']['type'] ?? null, $refused);
        self::assertStringContainsString('(pid ' . getmypid() . ')', (string) $decoded['error']['message']);
        self::assertCount(2, $this->store->loadTranscript($m[1]), 'the refusal wrote nothing');

        [$rc, $forced] = $this->dispatch(['session', 'import', $file, '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $forced);
        self::assertStringContainsString('as session ' . $m[1] . '; 1 appended', $forced);
    }

    // ── the parser scoping the verb depends on ───────────────────────────

    public function testTheImportersScopedValueFlagsParseOnlyWhereTheyBelong(): void
    {
        $args = ArgvParser::parse([
            'candy-code', 'session', 'import', 'x.json',
            '--format', 'claude-code', '--name', 'Kept', '--force',
        ]);
        self::assertNull($args->usageError);
        self::assertSame([], $args->unknownFlags);
        self::assertSame(
            ['--format' => 'claude-code', '--name' => 'Kept', '--force' => true],
            $args->subcommandFlags,
        );

        // Admitted by the parser does not mean accepted by the action: the
        // per-verb door still says who the flags belong to.
        $file = $this->export('scoped.json', [['role' => 'user', 'content' => 'anything']]);
        self::assertSame(
            'session show: --format does not apply to this action',
            $this->usageDoor(['session', 'show', 'sess-main-0001', '--format', 'x']),
        );
        self::assertSame(
            'session import: --limit does not apply to this action',
            $this->usageDoor(['session', 'import', $file, '--limit', '5']),
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param list<array{role: string, content: string}> $rows
     */
    private function export(string $name, array $rows): string
    {
        return $this->exportNamed($name, $rows);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function exportNamed(string $name, array $rows): string
    {
        $path = $this->home . '/' . $name;
        self::assertNotFalse(file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));

        return $path;
    }

    /**
     * @param list<string> $args
     */
    private function dispatch(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['candy-code', ...$args]));

        return [$rc, (string) ob_get_clean()];
    }

    /**
     * @param list<string> $args
     */
    private function usageDoor(array $args): string
    {
        [$rc, $stdout] = $this->dispatch([...$args, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$stdout}");
        self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);

        return (string) ($decoded['error']['message'] ?? '');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
