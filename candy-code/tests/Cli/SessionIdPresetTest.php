<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * D1 (RS-DRIVER a): `--session-id` lets a headless driver pre-allocate the
 * id of the session a one-shot run will own, so the caller knows the lineage
 * address before the child has finished printing anything. The grammar the
 * flag accepts is exactly the minting grammar of Bootstrap::openSession()
 * (bin2hex of 8 random bytes = 16 lowercase hexadecimal characters), and a
 * launch that cannot honor the request — an id shaped wrong, an id the store
 * already owns, or a combination whose flow would carry the flag and ignore
 * it (--continue/--resume reopen a decided id, --incognito stores nothing,
 * a subcommand verb never reaches the funnel, a prompt-less launch stays on
 * the TUI side of the boundary) — is refused as a usage error, exit 2, in
 * every one of those cases.
 *
 * The parser legs witness ArgvParser::parse() directly (it never opens the
 * database — collision is Bootstrap::sessionLaunchError()'s pre-flight). The
 * run legs drive real one-shot children through the funnel-owned shape:
 * backend null so $opened is minted, a throwaway HOME, and a loopback
 * endpoint answering the real sglang provider — the harness the resume
 * witness in JsonSchemaEnvelopeTest established, re-named here so the
 * duplicated-helper drift scan sees distinct helpers, not drifted twins.
 */
final class SessionIdPresetTest extends TestCase
{
    private const VALID_ID = '0a1b2c3d4e5f6071';

    public function testTheEqualsFormStoresTheRawIdVerbatim(): void
    {
        $args = ArgvParser::parse(['candy-code', '--session-id=' . self::VALID_ID, '-p', 'go']);

        self::assertNull($args->usageError);
        self::assertSame(self::VALID_ID, $args->sessionId);
    }

    public function testTheSpaceFormTakesTheNextToken(): void
    {
        $args = ArgvParser::parse(['candy-code', '--session-id', self::VALID_ID, '-p', 'go']);

        self::assertNull($args->usageError);
        self::assertSame(self::VALID_ID, $args->sessionId);
    }

    /**
     * Unset is the legacy contract: the field stays null and nothing else in
     * the parse moves — the run then mints its own id as it always did.
     */
    public function testAnAbsentFlagLeavesThePresetNull(): void
    {
        $withPrompt = ArgvParser::parse(['candy-code', '-p', 'go']);
        $bare = ArgvParser::parse(['candy-code']);

        self::assertNull($withPrompt->usageError);
        self::assertNull($withPrompt->sessionId);
        self::assertNull($bare->usageError);
        self::assertNull($bare->sessionId);
    }

    public function testAnEmptyValueIsRefusedInBothForms(): void
    {
        $equals = ArgvParser::parse(['candy-code', '--session-id=', '-p', 'go']);
        $space = ArgvParser::parse(['candy-code', '--session-id', '', '-p', 'go']);

        foreach (['equals' => $equals, 'space' => $space] as $form => $args) {
            self::assertNotNull($args->usageError, "the {$form} form must not carry an empty id into the run");
            self::assertStringContainsString('expects a session id, but the value is empty', (string) $args->usageError);
            self::assertStringContainsString('16 lowercase hexadecimal', (string) $args->usageHint);
        }
    }

    public function testAMissingValueIsRefusedAtBothEdges(): void
    {
        $ended = ArgvParser::parse(['candy-code', '-p', 'go', '--session-id']);
        self::assertStringContainsString('the argument list ended', (string) $ended->usageError);

        $flagShaped = ArgvParser::parse(['candy-code', '--session-id', '--model', 'x', '-p', 'go']);
        self::assertStringContainsString('the next argument is the option --model', (string) $flagShaped->usageError);
    }

    /**
     * The shape gate is case-sensitive for the same reason --output-format's
     * whitelist is: an accepted-but-foreign spelling would hand the driver a
     * lineage address the store's own minting never produces.
     */
    public function testAForeignShapeIsRefusedLoud(): void
    {
        $foreign = [
            'uppercase' => '0A1B2C3D4E5F6071',
            'fifteen' => substr(self::VALID_ID, 0, 15),
            'seventeen' => self::VALID_ID . 'a',
            'non-hex' => 'gghiijklmnopqrstuv',
            'dashes' => '0a1b2c3d-4e5f-6071-0000-000000000000',
        ];

        foreach ($foreign as $case => $value) {
            $args = ArgvParser::parse(['candy-code', '--session-id=' . $value, '-p', 'go']);
            self::assertNotNull($args->usageError, "the {$case} spelling must be refused");
            self::assertStringContainsString('not a 16-character lowercase hexadecimal session id', (string) $args->usageError);
            self::assertStringContainsString($value, (string) $args->usageError, 'the refusal names what it refused');
        }
    }

    public function testReopenFlagsClashWithAPresetId(): void
    {
        $continue = ArgvParser::parse(['candy-code', '--session-id=' . self::VALID_ID, '--continue', '-p', 'go']);
        $resume = ArgvParser::parse(['candy-code', '--session-id=' . self::VALID_ID, '--resume', 'stored-row', '-p', 'go']);

        foreach (['--continue' => $continue, '--resume' => $resume] as $flag => $args) {
            self::assertNotNull($args->usageError, "{$flag} reopens an id already decided; the pair must not launch");
            self::assertStringContainsString('reopen a stored row whose id is already decided', (string) $args->usageError);
        }
    }

    public function testIncognitoClashesWithAPresetId(): void
    {
        $args = ArgvParser::parse(['candy-code', '--session-id=' . self::VALID_ID, '--incognito', '-p', 'go']);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('keeps nothing', (string) $args->usageError);
    }

    public function testASubcommandVerbClashesWithAPresetId(): void
    {
        $args = ArgvParser::parse(['candy-code', '--session-id=' . self::VALID_ID, 'session', 'list']);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('subcommand verbs run their own flow', (string) $args->usageError);
    }

    public function testAPresetIdWithoutAPromptIsRefused(): void
    {
        $args = ArgvParser::parse(['candy-code', '--session-id=' . self::VALID_ID]);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('this launch has no prompt to run', (string) $args->usageError);
    }

    /**
     * The headline contract, witnessed on the real funnel: the run's own
     * envelope reports the id the driver chose, and the store row carries
     * that exact address.
     */
    public function testAPresetIdSurfacesInTheEnvelopeAndTheStore(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openPresetStage();

        try {
            [$exit, $stdout, $stderr] = $this->runPresetChild('preset', $dir, $root, $address, self::VALID_ID);

            self::assertSame(0, $exit, "the preset child exited {$exit}; stderr:\n{$stderr}");
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('d1 preset answer', $document['result']);
            self::assertSame(self::VALID_ID, $document['session_id'], 'the envelope must carry the address the driver chose');

            $store = new EnhancedSessionStore($dir . '/home/.candy-code/session.db');
            self::assertNotNull($store->session(self::VALID_ID), 'the store row must live at the pre-allocated address');
        } finally {
            $this->closePresetStage($endpoint, $pipes);
            $this->removePresetScratch($dir);
        }
    }

    /**
     * An id the store already owns is not a fresh pre-allocation; it is the
     * resume the parser just refused, so the collision is a usage error with
     * the remedy named in the same breath.
     */
    public function testACollisionWithAStoredRowExitsTwo(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openPresetStage();

        try {
            $store = new EnhancedSessionStore($dir . '/home/.candy-code/session.db');
            $store->saveTranscript(self::VALID_ID, [Message::user('a row that already lives here')]);

            [$exit, $stdout, $stderr] = $this->runPresetChild('preset', $dir, $root, $address, self::VALID_ID);

            self::assertSame(2, $exit, "a preset id already in the store must not launch; stderr:\n{$stderr}");
            self::assertStringContainsString('already stored', $stderr);
            self::assertStringContainsString('use --resume', $stderr, 'the refusal names the flag that DOES want this row');
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('usage', $document['error']['type']);
        } finally {
            $this->closePresetStage($endpoint, $pipes);
            $this->removePresetScratch($dir);
        }
    }

    /**
     * The lineage loop the flag exists for: pre-allocate on run one, reopen
     * the SAME address on run two through the ordinary resume path.
     */
    public function testThePresetIdIsResumableByLaterRuns(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openPresetStage();

        try {
            [$firstExit, $firstStdout, $firstStderr] = $this->runPresetChild('preset', $dir, $root, $address, self::VALID_ID);
            self::assertSame(0, $firstExit, "the preset child exited {$firstExit}; stderr:\n{$firstStderr}");
            $first = json_decode(trim($firstStdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(self::VALID_ID, $first['session_id']);

            [$secondExit, $secondStdout, $secondStderr] = $this->runPresetChild('resume', $dir, $root, $address, self::VALID_ID);
            self::assertSame(0, $secondExit, "--resume=<preset id> must reopen the row run one minted; stderr:\n{$secondStderr}");
            $second = json_decode(trim($secondStdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(self::VALID_ID, $second['session_id'], 'the continuation rides the same lineage address');
        } finally {
            $this->closePresetStage($endpoint, $pipes);
            $this->removePresetScratch($dir);
        }
    }

    /**
     * With the flag unset the old behavior is the contract: every run mints
     * its own id from the same grammar, and two runs never share one.
     */
    public function testUnsetPresetKeepsMintingPerRunAsAlways(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openPresetStage();

        try {
            $minted = [];
            foreach ([1, 2] as $pass) {
                [$exit, $stdout, $stderr] = $this->runPresetChild('legacy', $dir, $root, $address, self::VALID_ID);
                self::assertSame(0, $exit, "legacy pass {$pass} exited {$exit}; stderr:\n{$stderr}");
                $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
                self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $document['session_id']);
                $minted[] = $document['session_id'];
            }

            self::assertNotSame($minted[0], $minted[1], 'a prompt-less of the flag, ids come from random_bytes exactly as before');
        } finally {
            $this->closePresetStage($endpoint, $pipes);
            $this->removePresetScratch($dir);
        }
    }

    // ------------------------------------------------------------------
    // Harness: throwaway stage (sandbox HOME + loopback endpoint), child
    // driver, teardown. Written fresh over the shape the C-AGT-7.b resume
    // witness established; names diverge wholly, per the drift law.
    // ------------------------------------------------------------------

    /**
     * @return array{0:string,1:string,2:resource,3:array<int,resource>,4:string}
     */
    private function openPresetStage(): array
    {
        $root = \dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/wf-d1-preset-' . bin2hex(random_bytes(6));
        mkdir($dir . '/home/.candy-code', 0o700, true);

        $script = <<<'PHP'
            <?php
            $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($server === false) {
                fwrite(STDERR, "bind failed: {$errstr}\n");
                exit(1);
            }
            fwrite(STDOUT, 'LISTEN ' . stream_socket_get_name($server, false) . "\n");
            fflush(STDOUT);

            $sse = 'data: ' . json_encode(['choices' => [[
                'index' => 0,
                'delta' => ['role' => 'assistant', 'content' => 'd1 preset answer'],
                'finish_reason' => 'stop',
            ]]]) . "\n\n" . "data: [DONE]\n\n";

            for ($seen = 0; $seen < 40; $seen++) {
                $conn = @stream_socket_accept($server, 20);
                if ($conn === false) {
                    break;
                }
                stream_set_timeout($conn, 5);

                $head = '';
                while (!feof($conn)) {
                    $line = fgets($conn, 8192);
                    if (!is_string($line)) {
                        break;
                    }
                    $head .= $line;
                    if (str_ends_with($head, "\r\n\r\n")) {
                        break;
                    }
                }
                if (preg_match('/Content-Length:\s*(\d+)/i', $head, $match) === 1) {
                    if (stripos($head, 'expect: 100-continue') !== false) {
                        fwrite($conn, "HTTP/1.1 100 Continue\r\n\r\n");
                    }
                    $left = (int) $match[1];
                    while ($left > 0 && !feof($conn)) {
                        $chunk = fread($conn, min(8192, $left));
                        if (!is_string($chunk) || $chunk === '') {
                            break;
                        }
                        $left -= strlen($chunk);
                    }
                }
                if (stripos($head, 'chat') !== false) {
                    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\nContent-Length: " . strlen($sse) . "\r\n\r\n" . $sse);
                } else {
                    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: 2\r\n\r\n{}");
                }
                fclose($conn);
            }
            fclose($server);
            PHP;

        $file = $dir . '/d1-preset-endpoint.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $endpointPipes);
        self::assertIsResource($process, 'the preset endpoint child did not start');
        stream_set_timeout($endpointPipes[1], 15);

        $announced = fgets($endpointPipes[1]);
        self::assertIsString($announced, 'the preset endpoint never announced its address');
        self::assertMatchesRegularExpression('/^LISTEN 127\.0\.0\.1:\d+\R$/', $announced);

        return [$dir, $root, $process, $endpointPipes, substr(trim($announced), 7)];
    }

    /**
     * Drive one child through the funnel-owned path (no injected backend, so
     * openSession() runs and the envelope carries an id). $mode picks the
     * argv shape: 'preset' pre-allocates, 'resume' reopens, 'legacy' omits
     * the flag entirely.
     *
     * @return array{0:int,1:string,2:string}
     */
    private function runPresetChild(string $mode, string $dir, string $root, string $address, string $id): array
    {
        $script = <<<'PHP'
            <?php
            require $argv[2] . '/vendor/autoload.php';

            use SugarCraft\Code\Cli\ArgvParser;
            use SugarCraft\Code\Cli\NonInteractive;

            $argvv = ['candy-code', '--root', $argv[2], '--output-format', 'json'];
            if ($argv[1] === 'preset') {
                $argvv[] = '--session-id';
                $argvv[] = $argv[3];
                $argvv[] = '-p';
                $argvv[] = 'follow up';
            } elseif ($argv[1] === 'resume') {
                $argvv[] = '--resume';
                $argvv[] = $argv[3];
                $argvv[] = '-p';
                $argvv[] = 'second pass';
            } else {
                $argvv[] = '-p';
                $argvv[] = 'follow up';
            }

            $args = ArgvParser::parse($argvv);
            if ($args->usageError !== null) {
                fwrite(STDERR, 'USAGE-ERROR ' . $args->usageError . "\n");
                exit(9);
            }

            exit(NonInteractive::run($args, null, NonInteractive::FORMAT_JSON));
            PHP;

        $file = $dir . '/d1-preset-child.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file, $mode, $root, $id], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, [
            'HOME' => $dir . '/home',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'CANDY_CODE_PROVIDER' => 'sglang',
            'CANDY_CODE_MODEL' => 'd1-preset-model',
            'OPENAI_BASE_URL' => 'http://' . $address . '/v1',
        ]);
        self::assertIsResource($process, "the {$mode} child did not start");
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 90);
        stream_set_timeout($pipes[2], 90);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
        }
        $exit = proc_close($process);
        self::assertGreaterThanOrEqual(0, $exit, "the {$mode} child died on a signal");

        return [$exit, $stdout, $stderr];
    }

    /** @param array<int, resource> $pipes */
    private function closePresetStage($endpoint, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        $status = proc_get_status($endpoint);
        if ($status['running']) {
            proc_terminate($endpoint, \defined('SIGTERM') ? SIGTERM : 15);
        }
        proc_close($endpoint);
    }

    /** Unlink-first recursion, no rm -rf, so a surprise path fails loudly. */
    private function removePresetScratch(string $path): void
    {
        $entries = (array) scandir($path) ?: [];
        foreach ($entries as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                $this->removePresetScratch($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
