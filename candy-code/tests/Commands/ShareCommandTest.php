<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Share\ShareStore;

/**
 * @see ShareCommand
 *
 * Argument parsing and the RETIRED upload host. The local export itself is
 * pinned by {@see ShareLocalExportTest}; the `shares` table's own edges by
 * tests/Share/ShareStoreTest. SES-3 (COMPARISON §6.5): no test here may ever
 * see a fabricated upload success — the uploader is gone, and `/share serve`
 * publishes a local snapshot page instead.
 */
final class ShareCommandTest extends TestCase
{
    /** @var array<string, string|false> Original values of every variable a test overrode. */
    private array $envBackup = [];

    private string $exportsDir = '';

    private string $scratch = '';

    // =========================================================================
    // Argument parsing
    // =========================================================================

    public function testExecuteWithInvalidFormat(): void
    {
        [$exitCode, $output] = $this->share(['invalid_format']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("Invalid format 'invalid_format'", $output);
        $this->assertStringContainsString('Usage: /share [md|html|json|text] [path] | /share serve [days] | /share revoke [token]', $output);
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
        $this->clearUploadKeys();

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

    public function testNoUploadKeyMeansNoUploadLine(): void
    {
        $this->clearUploadKeys();

        [$exitCode, $output] = $this->share([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Upload', $output);
        $this->assertStringNotContainsString('https://', $output);
    }

    /**
     * SES-3: the uploader is deleted, and a still-set host key must not pass
     * unnoticed — the reply refuses it BY NAME and points at `/share serve`.
     * The local export still lands (exit 0): refusing the phantom upload must
     * never eat the real half of the command.
     */
    public function testARetiredUploadKeyIsRefusedLoudlyAndNamesTheReplacement(): void
    {
        $this->setEnv('CANDY_CODE_SHARE_UPLOAD_URL', 'https://private.example');

        [$exitCode, $output] = $this->share(['markdown']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Exported ', $output);
        $this->assertStringContainsString('Uploads are retired: CANDY_CODE_SHARE_UPLOAD_URL no longer sends anything anywhere', $output);
        $this->assertStringContainsString('/share serve publishes a snapshot page', $output);
        $this->assertStringNotContainsString('Session shared successfully', $output);
        $this->assertStringNotContainsString('Share URL:', $output);
    }

    // =========================================================================
    // The retired-key reader: CANDY_CODE_SHARE_UPLOAD_URL, with the pre-rename
    // SUGARCRUSH_/SUGAR_CRUSH_ spellings still reaching it so the refusal
    // fires for their owners too. // @rename-keep legacy dual-read
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportsDir = sys_get_temp_dir() . '/share_command_' . bin2hex(random_bytes(6));
        $this->scratch = sys_get_temp_dir() . '/share_cmd_store_' . bin2hex(random_bytes(6));
        @mkdir($this->scratch, 0700);
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
        foreach (glob($this->scratch . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratch);

        parent::tearDown();
    }

    /** X-35a + SES-3: unset means "say nothing"; there is no host to act on. */
    public function testRetiredKeyReaderIsNullWhenNeitherVariableIsSet(): void
    {
        $this->clearUploadKeys();

        $this->assertNull($this->retiredUploadKey());
    }

    public function testCanonicalVariableIsNamedByTheRetirementRefusal(): void
    {
        $this->setEnv('CANDY_CODE_SHARE_UPLOAD_URL', 'https://canonical.example');

        $this->assertSame('CANDY_CODE_SHARE_UPLOAD_URL', $this->retiredUploadKey());
    }

    /**
     * An operator on either legacy spelling gets the same loud refusal —
     * honoring the old names here is about WHO HEARS the retirement, not
     * about what still runs. // @rename-keep legacy dual-read
     */
    public function testLegacyUnderscoredVariableStillTriggersTheRefusal(): void
    {
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example'); // @rename-keep legacy dual-read

        $this->assertSame('SUGAR_CRUSH_SHARE_UPLOAD_URL', $this->retiredUploadKey());
    }

    public function testCanonicalVariableWinsWhenBothAreSet(): void
    {
        $this->setEnv('CANDY_CODE_SHARE_UPLOAD_URL', 'https://canonical.example');
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example'); // @rename-keep legacy dual-read

        $this->assertSame('CANDY_CODE_SHARE_UPLOAD_URL', $this->retiredUploadKey());
    }

    /**
     * An exported-but-empty canonical name is "unset", not "refuse on the
     * empty string" — the legacy value still earns its refusal.
     */
    public function testAnEmptyCanonicalVariableFallsThroughToTheLegacyName(): void
    {
        $this->setEnv('CANDY_CODE_SHARE_UPLOAD_URL', '');
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example'); // @rename-keep legacy dual-read

        $this->assertSame('SUGAR_CRUSH_SHARE_UPLOAD_URL', $this->retiredUploadKey());
    }

    // =========================================================================
    // /share serve — the publish act (SES-3)
    // =========================================================================

    public function testServePublishesASnapshotPageAndNamesItsPath(): void
    {
        $this->clearUploadKeys();
        $store = ShareStore::atPath($this->scratch . '/session.db');

        [$exitCode, $output] = $this->share(['serve'], store: $store);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('#/s/([0-9a-f]{32})#', $output, $match), 'the reply names the /s/{token} path: ' . $output);
        $this->assertSame(32, strlen($match[1]), 'a 128-bit token, hex');
        $this->assertStringContainsString('live for 7 day(s)', $output);
        $this->assertNotNull($store->lookup($match[1]), 'the row is readable straight away');
    }

    public function testServeHonoursAnExplicitDayCount(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');

        [$exitCode, $output] = $this->share(['serve', '30'], store: $store);

        $this->assertSame(0, $exitCode);
        preg_match('#/s/([0-9a-f]{32})#', $output, $match);
        $record = $store->lookup($match[1]);
        $this->assertNotNull($record);
        $this->assertGreaterThanOrEqual(time() + 29 * 86400, $record->expiresAt);
        $this->assertLessThanOrEqual(time() + 30 * 86400 + 1, $record->expiresAt);
    }

    public function testServeRefusesADayCountOutsideItsRange(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');

        foreach (['zero' => '0', 'negative' => '-3', 'word' => 'soon', 'over-cap' => '366'] as $label => $arg) {
            [$exitCode, $output] = $this->share(['serve', $arg], store: $store);

            $this->assertSame(1, $exitCode, $label);
            $this->assertStringContainsString('Days must be a whole number from 1 to 365', $output, $label);
        }
    }

    public function testServeRefusesTooManyArguments(): void
    {
        [$exitCode, $output] = $this->share(['serve', '7', 'extra'], store: ShareStore::atPath($this->scratch . '/session.db'));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Too many arguments', $output);
    }

    public function testServeRefusesASessionWithNothingVisible(): void
    {
        [$exitCode, $output] = $this->share(['serve'], messages: [], store: ShareStore::atPath($this->scratch . '/session.db'));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Nothing to publish', $output);
    }

    /**
     * THE SECURITY MODEL, pinned at the command seam: the row holds the HTML
     * rendered at the act. The chat after serving is a different transcript —
     * the published page must not notice.
     */
    public function testTheSnapshotIsFrozenAtPublishTime(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');

        [, $output] = $this->share(['serve'], store: $store);
        preg_match('#/s/([0-9a-f]{32})#', $output, $match);
        $before = $store->lookup($match[1]);
        $this->assertNotNull($before);

        $later = new Chat([
            Message::system('You are a helpful assistant.'),
            Message::user('Hello, how are you?'),
            Message::assistant('I am doing well, thank you!'),
            Message::user('A secret typed AFTER the share act.'),
        ]);
        ob_start();
        $this->assertSame(0, (new ShareCommand($this->exportsDir, $store))->execute($later, []));
        ob_end_clean();

        $after = $store->lookup($match[1]);
        $this->assertNotNull($after);
        $this->assertSame($before->html, $after->html, 'later transcript state never reaches a published page');
        $this->assertStringNotContainsString('A secret typed AFTER the share act', $after->html);
    }

    // =========================================================================
    // /share revoke
    // =========================================================================

    public function testRevokeTakesThePageDownImmediately(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        [, $output] = $this->share(['serve'], store: $store);
        preg_match('#/s/([0-9a-f]{32})#', $output, $match);

        [$exitCode, $output] = $this->share(['revoke', $match[1]], store: $store);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Revoked share ' . $match[1], $output);
        $this->assertNull($store->lookup($match[1]));
    }

    /**
     * No token: exactly one live share for this session is revoked outright;
     * two or more are LISTED for the user to choose between, because
     * silently revoking one of several is its own surprise.
     */
    public function testRevokeWithoutATokenRevokesTheSessionsOnlyShare(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        [, $output] = $this->share(['serve'], store: $store);
        preg_match('#/s/([0-9a-f]{32})#', $output, $match);

        [$exitCode, $output] = $this->share(['revoke'], store: $store);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Revoked share ' . $match[1], $output);
        $this->assertNull($store->lookup($match[1]));
    }

    public function testRevokeWithoutATokenListsTheChoicesWhenSeveralAreLive(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        $first = $store->publish('', 'html', '<p>one</p>', 7)->token;
        $second = $store->publish('', 'html', '<p>two</p>', 7)->token;

        [$exitCode, $output] = $this->share(['revoke'], store: $store);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('several live share pages', $output);
        $this->assertStringContainsString($first, $output);
        $this->assertStringContainsString($second, $output);
        $this->assertNotNull($store->lookup($first), 'listing revokes nothing');
    }

    public function testRevokeOfAnUnknownTokenIsRefusedWithoutSayingWhy(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');

        [$exitCode, $output] = $this->share(['revoke', str_repeat('ab', 16)], store: $store);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No live share page carries that token', $output);
        $this->assertStringNotContainsString('expired', $output);
        $this->assertStringNotContainsString('revoked', $output);
    }

    public function testRevokeWithNothingPublishedIsRefused(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');

        [$exitCode, $output] = $this->share(['revoke'], store: $store);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('no live share page to revoke', $output);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * The name of a retired upload variable, or null. Read directly because
     * the production reader exists ONLY to seed the refusal line.
     */
    private function retiredUploadKey(): ?string
    {
        $method = new \ReflectionMethod(ShareCommand::class, 'getRetiredUploadKey');

        return $method->invoke(new ShareCommand());
    }

    /**
     * Run the command against the fixture chat, exporting into this test's
     * own directory and publishing into the given store.
     *
     * @param list<string> $args
     * @param ?list<Message> $messages
     * @return array{0: int, 1: string}
     */
    private function share(array $args, ?array $messages = null, ?ShareStore $store = null): array
    {
        ob_start();
        $exitCode = (new ShareCommand($this->exportsDir, $store))->execute(
            $this->createChatWithMessages($messages),
            $args,
        );

        return [$exitCode, (string) ob_get_clean()];
    }

    /**
     * Unset every spelling of the retired upload key.
     */
    private function clearUploadKeys(): void
    {
        $this->setEnv('CANDY_CODE_SHARE_UPLOAD_URL', null);
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', null); // @rename-keep legacy dual-read
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', null); // @rename-keep legacy dual-read
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
     *
     * @param ?list<Message> $messages
     */
    private function createChatWithMessages(?array $messages = null): Chat
    {
        return new Chat($messages ?? [
            Message::system('You are a helpful assistant.'),
            Message::user('Hello, how are you?'),
            Message::assistant('I am doing well, thank you!'),
        ]);
    }
}
