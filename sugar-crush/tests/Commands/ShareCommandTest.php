<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Message;

/**
 * @see ShareCommand
 *
 * Argument parsing and the opt-in upload host. The local export itself is
 * pinned by {@see ShareLocalExportTest}. ShareUploader still has no backend,
 * so no test here may ever see a fabricated success URL.
 */
final class ShareCommandTest extends TestCase
{
    /** @var array<string, string|false> Original values of every variable a test overrode. */
    private array $envBackup = [];

    private string $exportsDir = '';

    // =========================================================================
    // Argument parsing
    // =========================================================================

    public function testExecuteWithInvalidFormat(): void
    {
        [$exitCode, $output] = $this->share(['invalid_format']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("Invalid format 'invalid_format'", $output);
        $this->assertStringContainsString('Usage: /share [md|html|json|text] [path]', $output);
        $this->assertFalse(is_dir($this->exportsDir), 'a refused command writes nothing');
    }

    public function testExecuteWithTooManyArgumentsIsRefused(): void
    {
        [$exitCode, $output] = $this->share(['json', 'out.json', 'extra_arg']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Too many arguments', $output);
    }

    /**
     * @dataProvider formatProvider
     */
    public function testEveryFormatWordExportsWithItsExtension(string $formatArg, string $extension): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', null);
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', null);

        [$exitCode, $output] = $this->share([$formatArg]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('/to `([^`]+)`/', $output, $written));
        $this->assertStringEndsWith('.' . $extension, $written[1]);
        $this->assertFileExists($written[1]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formatProvider(): array
    {
        return [
            'markdown' => ['markdown', 'md'],
            'md alias' => ['md', 'md'],
            'html' => ['html', 'html'],
            'json' => ['json', 'json'],
            'text' => ['text', 'txt'],
            'plain alias' => ['plain', 'txt'],
        ];
    }

    public function testNoUploadHostMeansNoUploadLine(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', null);
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', null);

        [$exitCode, $output] = $this->share([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Upload', $output);
        $this->assertStringNotContainsString('https://', $output);
    }

    /**
     * The opt-in path reaches the dormant uploader, which still always fails:
     * the export is written anyway and the reply says the upload did not
     * happen. The old fabricated "shared successfully" URL must never appear.
     */
    public function testAnUploadHostStillWritesLocallyAndSaysTheUploadDidNotHappen(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', 'https://private.example');

        [$exitCode, $output] = $this->share(['markdown']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Exported ', $output);
        $this->assertStringContainsString('Upload to https://private.example did not happen', $output);
        $this->assertStringContainsString('No data was uploaded', $output);
        $this->assertStringNotContainsString('Session shared successfully', $output);
        $this->assertStringNotContainsString('Share URL:', $output);
    }

    // =========================================================================
    // Upload base URL: SUGARCRUSH_SHARE_UPLOAD_URL, with the pre-rename
    // SUGAR_CRUSH_SHARE_UPLOAD_URL honoured for one release
    // (crush_code.md Phase 4 item 4).
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportsDir = sys_get_temp_dir() . '/share_command_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $original) {
            $original === false ? putenv($name) : putenv($name . '=' . $original);
        }
        $this->envBackup = [];

        foreach (glob($this->exportsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->exportsDir);

        parent::tearDown();
    }

    /** X-35a: no public default host — unset means "do not upload". */
    public function testUploadBaseUrlIsNullWhenNeitherVariableIsSet(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', null);
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', null);

        $this->assertNull($this->uploadBaseUrl());
    }

    public function testCanonicalVariableSetsTheUploadBaseUrl(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', 'https://canonical.example');

        $this->assertSame('https://canonical.example', $this->uploadBaseUrl());
    }

    /**
     * The compat shim: an operator who has pointed /share at a private host
     * must not silently start addressing the public default on the release
     * that renames the variable.
     */
    public function testLegacyUnderscoredVariableIsStillHonoured(): void
    {
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example');

        $this->assertSame('https://legacy.example', $this->uploadBaseUrl());
    }

    public function testCanonicalVariableWinsWhenBothAreSet(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', 'https://canonical.example');
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example');

        $this->assertSame('https://canonical.example', $this->uploadBaseUrl());
    }

    /**
     * An exported-but-empty canonical name is "unset", not "override with the
     * empty string" — a `/share` pointed at "" would be worse than either the
     * legacy value or the default.
     */
    public function testAnEmptyCanonicalVariableFallsThroughToTheLegacyName(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', '');
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example');

        $this->assertSame('https://legacy.example', $this->uploadBaseUrl());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * The resolved upload host. Read directly because the only production
     * caller hands it to ShareUploader, which always throws while no upload
     * backend exists.
     */
    private function uploadBaseUrl(): ?string
    {
        $method = new \ReflectionMethod(ShareCommand::class, 'getUploadBaseUrl');

        return $method->invoke(new ShareCommand());
    }

    /**
     * Run the command against the fixture chat, exporting into this test's
     * own directory.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function share(array $args): array
    {
        ob_start();
        $exitCode = (new ShareCommand($this->exportsDir))->execute($this->createChatWithMessages(), $args);
        $output = (string) ob_get_clean();

        return [$exitCode, $output];
    }

    /**
     * Set (or, with a null value, unset) an environment variable, recording
     * its original value for tearDown().
     */
    private function setEnv(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }

        $value === null ? putenv($name) : putenv($name . '=' . $value);
    }

    /**
     * Create a Chat instance with some test messages.
     */
    private function createChatWithMessages(): Chat
    {
        $messages = [
            Message::system('You are a helpful assistant.'),
            Message::user('Hello, how are you?'),
            Message::assistant('I am doing well, thank you!'),
        ];

        return new Chat($messages);
    }
}
