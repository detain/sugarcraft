<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * 1021 v1:F-7 — the single choke for the `candy-code: ` error label.
 *
 * BEFORE: failUsage() let callers bake the label into the message, so the
 * JSON document's `error.message` carried it, while failUnusableProvider()
 * ran the inverse — two labeled stderr lines and a raw exception in the
 * document. AFTER: documents carry the raw sentence on every path, and the
 * label is composed by the emitters on stderr alone, exactly once.
 *
 * The document is stdout, so it is capturable in-process with ob_start;
 * stderr is not, so each arm pins the DOCUMENT side of the choke (that is
 * where the label was leaking) plus the forged-label arms, which prove the
 * unlabel step is load-bearing rather than decorative: a caller — or an
 * exception message — that still carries the prefix can neither double it
 * on stderr nor smuggle it into a machine-consumed field.
 */
final class NonInteractiveErrorLabelTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * Everything that could make `CANDY_CODE_PROVIDER=openai` actually
     * constructable: the command tiers that would answer for it, and the
     * three env vars the OpenAI branch of ProviderFactory reads. The twin
     * hammer in NonInteractiveProviderFailureTest unsets a wider roster
     * through a const this guard would accuse if this file minted a second
     * spelling, so this list is the minimal hermetic set for the one arm
     * that needs it, written under a unique name.
     *
     * @var list<string>
     */
    private const LABEL_ENV_KEYS = [
        'CANDY_CODE_BACKEND_CMD',
        'CANDY_CODE_BACKEND_CMD_STREAM',
        'CANDY_CODE_MODEL',
        'OPENAI_API_KEY',
        'OPENAI_ORG_ID',
        'OPENAI_BASE_URL',
    ];

    private string $tempDir;

    /** @var array<string, string|false> */
    private array $savedLabelEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_error_label_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        mkdir($this->tempDir . '/repo', 0700, true);

        $this->useHomeSandbox($this->tempDir . '/home');

        foreach (self::LABEL_ENV_KEYS as $key) {
            $this->savedLabelEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedLabelEnv as $key => $value) {
            if (is_string($value)) {
                putenv($key . '=' . $value);
            } else {
                putenv($key);
            }
        }
        $this->savedLabelEnv = [];
        $this->restoreHomeSandbox();

        $this->removeTree($this->tempDir);

        parent::tearDown();
    }
    public function testALabeledUsageMessageReachesTheDocumentUnlabeled(): void
    {
        $document = $this->usageDocument(static fn (): int => NonInteractive::failUsage(
            'candy-code: synthetic: bad flag',
            NonInteractive::FORMAT_JSON,
        ));

        self::assertSame('usage', $document['error']['type']);
        self::assertSame('synthetic: bad flag', $document['error']['message'], '1021 F-7: the label belongs to stderr alone');
    }

    public function testARawUsageMessagePassesThroughTheChokeUnchanged(): void
    {
        $document = $this->usageDocument(static fn (): int => NonInteractive::failUsage(
            'no prompt was given for the one-shot run',
            NonInteractive::FORMAT_JSON,
        ));

        self::assertSame('no prompt was given for the one-shot run', $document['error']['message']);
    }

    public function testTheHintStaysOnStderrAndNeverLeaksIntoTheDocument(): void
    {
        $document = $this->usageDocument(static fn (): int => NonInteractive::failUsage(
            'candy-code: bad value',
            NonInteractive::FORMAT_JSON,
            'Try `candy-code --help`.',
        ));

        self::assertSame('bad value', $document['error']['message']);
        self::assertStringNotContainsString('candy-code --help', \json_encode($document, \JSON_THROW_ON_ERROR), 'the hint is stderr-only prose');
    }

    public function testABackendThrowHasItsForgedLabelStrippedFromTheDocument(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'hi']);

        ob_start();
        $code = NonInteractive::run($args, $this->labelThrowing('candy-code: forged inner label'), NonInteractive::FORMAT_JSON);
        $stdout = \trim((string) ob_get_clean());

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);

        $decoded = \json_decode($stdout, true);
        self::assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($stdout, true));
        self::assertSame('backend', $decoded['error']['type']);
        self::assertSame('forged inner label', $decoded['error']['message'], '1021 F-7: the catch-composer unlabels before emitting, same choke as failUsage()');
    }

    public function testTheProviderDocumentCarriesTheTypedSentenceNotALabel(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');

        $args = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format', 'json', '--root', $this->tempDir . '/repo']);

        ob_start();
        $code = NonInteractive::run($args, null, $args->outputFormat);
        $stdout = \trim((string) ob_get_clean());

        self::assertSame(NonInteractive::EXIT_CONFIG, $code);

        $decoded = \json_decode($stdout, true);
        self::assertIsArray($decoded, 'a JSON caller must never get an empty stdout on failure');
        self::assertSame('provider_configuration', $decoded['error']['type']);
        self::assertSame('openai', $decoded['error']['provider']);
        self::assertStringStartsWith(
            "provider 'openai' is unusable: ",
            $decoded['error']['message'],
            '1021 F-7: the document carries the same typed sentence the operator sees on stderr, label-free',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function usageDocument(callable $fail): array
    {
        ob_start();
        $code = $fail();
        $stdout = \trim((string) ob_get_clean());

        self::assertSame(NonInteractive::EXIT_CONFIG, $code);

        $decoded = \json_decode($stdout, true);
        self::assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($stdout, true));

        return $decoded;
    }

    private function labelThrowing(string $message): Backend
    {
        return new class($message) implements Backend {
            public function __construct(private readonly string $message)
            {
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \RuntimeException($this->message);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException($this->message));
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
