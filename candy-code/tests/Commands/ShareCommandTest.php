<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
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
        $this->assertStringContainsString('Usage: /share [md|html|json|text] [path] | /share serve [days] | /share list | /share revoke [token]', $output);
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
    // /share list — the cross-session audit view (C-SES-3)
    // =========================================================================

    /**
     * The whole point of C-SES-3: `list` reads {@see ShareStore::liveAll()},
     * so shares published from OTHER sessions — and rows whose session is
     * gone — are visible right where the operator decides to revoke them.
     * Each row shows only the token's 12-char prefix: this output lands in
     * the transcript, and a live token printed whole in a transcript is a
     * capability leaked into every page that transcript is later shared as.
     */
    public function testListShowsEveryLiveShareAcrossSessionsWithItsLabel(): void
    {
        $sessions = new EnhancedSessionStore($this->scratch . '/live.db');
        $sessions->createSession('home', 'p', 'm', name: 'Named Home');
        $store = ShareStore::atPath($this->scratch . '/live.db');
        $mine = $store->publish('home', 'html', '<p>mine</p>', 7);
        $ghost = $store->publish('gone-forever', 'html', '<p>ghost</p>', 7);

        [$exitCode, $output] = $this->share(['list'], store: $store);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Live share pages:', $output);
        $this->assertStringContainsString('Named Home', $output);
        $this->assertStringContainsString('deleted session', $output);
        $this->assertStringContainsString(substr($mine->token, 0, 12), $output);
        $this->assertStringContainsString(substr($ghost->token, 0, 12), $output);
        $this->assertStringContainsString($mine->format, $output);
        $this->assertStringContainsString(gmdate('Y-m-d', $mine->expiresAt), $output);
        $this->assertStringNotContainsString(substr($mine->token, 12), $output, 'the full token never reaches the transcript');
        $this->assertStringNotContainsString(substr($ghost->token, 12), $output, 'the full token never reaches the transcript');
    }

    public function testListWithNothingPublishedSaysSoAndSucceeds(): void
    {
        [$exitCode, $output] = $this->share(['list'], store: ShareStore::atPath($this->scratch . '/session.db'));

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No live share pages.', $output);
    }

    public function testListRefusesArguments(): void
    {
        [$exitCode, $output] = $this->share(['list', 'extra'], store: ShareStore::atPath($this->scratch . '/session.db'));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Too many arguments', $output);
    }

    /**
     * `list` reads through the same store seam as every other verb, and a
     * database that cannot be named is refused as a result, not a throw —
     * the serve-side catch, widened to list and revoke by C-SES-3.
     */
    public function testListWithoutAnyStoreRefusesInsteadOfThrowing(): void
    {
        ob_start();
        $exitCode = (new ShareCommand($this->exportsDir, null))
            ->execute($this->createChatWithMessages(), ['list']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No session database is open', $output);
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

    /**
     * Round-2 review (finding 2): the bare shortcut's write is ANSWERED, not
     * assumed. The row is live when `liveAll()` reads it and gone by the time
     * the UPDATE runs — another window revoked it in between, or a foreign
     * database carries a token this store's own shape guard will not write
     * (here: 40 hex digits, so `ShareStore::revoke()` refuses before touching
     * SQL and answers false). Pre-fix the command printed "Revoked share …"
     * and exited 0 over a write that never happened.
     */
    public function testBareRevokeRefusesInsteadOfClaimingAWriteThatDidNotHappen(): void
    {
        $path = $this->scratch . '/session.db';
        $ghost = str_repeat('ab', 20);
        $this->plantLiveRow($path, $ghost);

        [$exitCode, $output] = $this->share(['revoke'], store: ShareStore::atPath($path));

        $this->assertSame(1, $exitCode, 'a false revoke() is a refusal, not a success');
        $this->assertStringContainsString('No live share page carries that token', $output);
        $this->assertStringNotContainsString('Revoked share', $output, 'the command never claims a write the store refused');
    }

    public function testRevokeWithoutATokenListsTheChoicesWhenSeveralAreLive(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        $first = $store->publish('', 'html', '<p>one</p>', 7)->token;
        $second = $store->publish('', 'html', '<p>two</p>', 7)->token;

        [$exitCode, $output] = $this->share(['revoke'], store: $store);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('several live share pages', $output);
        $this->assertStringContainsString(substr($first, 0, 12), $output);
        $this->assertStringContainsString(substr($second, 0, 12), $output);
        $this->assertStringNotContainsString(substr($first, 12), $output, 'choice rows carry prefixes, like /share list');
        $this->assertNotNull($store->lookup($first), 'listing revokes nothing');
    }

    /**
     * C-SES-3: the bare-revoke listing reads liveAll(), so a foreign
     * session's live share shows up beside the current one instead of being
     * invisible to the session the operator happens to be standing in.
     */
    public function testBareRevokeListsForeignSessionsLiveSharesToo(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        $other = $store->publish('someone-else', 'html', '<p>x</p>', 7)->token;
        $mine = $store->publish('', 'html', '<p>m</p>', 7)->token;

        [$exitCode, $output] = $this->share(['revoke'], store: $store);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(substr($other, 0, 12), $output);
        $this->assertStringContainsString(substr($mine, 0, 12), $output);
        $this->assertStringContainsString('deleted session', $output, 'a shares-only database labels every row honestly');
        $this->assertNotNull($store->lookup($other));
        $this->assertNotNull($store->lookup($mine));
    }

    /**
     * The one-live-share shortcut is scoped to THIS session: with the only
     * live page belonging to somebody else, a bare revoke lists it rather
     * than silently killing a share this session never published.
     */
    public function testBareRevokeNeverAutoRevokesAnotherSessionsShare(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        $other = $store->publish('someone-else', 'html', '<p>x</p>', 7)->token;

        [$exitCode, $output] = $this->share(['revoke'], store: $store);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('several live share pages', $output);
        $this->assertStringContainsString(substr($other, 0, 12), $output);
        $this->assertNotNull($store->lookup($other), 'listing revokes nothing');
    }

    /**
     * A listed 12-char prefix is accepted straight back by revoke — the
     * listing hides the full token on purpose, so the handle the user can
     * SEE has to be the handle they can USE.
     */
    public function testRevokeByUniquePrefixTakesThePageDown(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        $token = $store->publish('', 'html', '<p>one</p>', 7)->token;

        [$exitCode, $output] = $this->share(['revoke', substr($token, 0, 12)], store: $store);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Revoked share ' . $token, $output, 'the reply names the whole token it killed');
        $this->assertNull($store->lookup($token));
    }

    /**
     * Round-2 review (finding 2), the named-prefix arm: the same answer-check
     * the whole-token arm already performed. A unique 12-char prefix resolves
     * to a row whose full token the store will not write (40 hex digits), so
     * `revoke()` answers false and the command refuses with the no-oracle
     * wording — it does not echo `Revoked share` for a page still live.
     */
    public function testRevokeByUniquePrefixRefusesWhenTheWriteIsAnsweredFalse(): void
    {
        $path = $this->scratch . '/session.db';
        $ghost = str_repeat('ab', 20);
        $this->plantLiveRow($path, $ghost);

        [$exitCode, $output] = $this->share(['revoke', substr($ghost, 0, 12)], store: ShareStore::atPath($path));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No live share page carries that token', $output);
        $this->assertStringNotContainsString('Revoked share', $output);
    }

    public function testRevokeByAnAmbiguousPrefixAsksForALongerOne(): void
    {
        $path = $this->scratch . '/session.db';
        $store = ShareStore::atPath($path);
        $store->publish('', 'html', '<p>table-maker</p>', 7);
        // Two hand-written rows sharing their first twelve characters: real
        // 128-bit tokens collide at 12 hex digits essentially never, and the
        // ambiguity branch must still be pinned rather than argued away.
        $shared = str_repeat('a', 12);
        $colliders = [$shared . str_repeat('0', 20), $shared . str_repeat('1', 20)];
        $pdo = new \PDO('sqlite:' . $path);
        $insert = $pdo->prepare('INSERT INTO shares (token, session_id, format, html, created_at, expires_at, revoked_at)'
            . ' VALUES (:token, :session_id, :format, :html, :created_at, :expires_at, NULL)');
        foreach ($colliders as $token) {
            $insert->execute([
                ':token' => $token,
                ':session_id' => '',
                ':format' => 'html',
                ':html' => '<p>collider</p>',
                ':created_at' => time(),
                ':expires_at' => time() + 7 * 86400,
            ]);
        }

        [$exitCode, $output] = $this->share(['revoke', $shared], store: ShareStore::atPath($path));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('begin with that prefix', $output);
        $this->assertGreaterThanOrEqual(2, substr_count($output, $shared), 'both candidates are listed for a longer prefix');
        foreach ($colliders as $token) {
            $this->assertNotNull($store->lookup($token), 'an ambiguous prefix revokes nothing: ' . $token);
        }
    }

    /** A shorter prefix is NOT a handle yet — six hex digits would collide by luck. */
    public function testRevokeOfAShortPrefixIsRefusedLikeAnUnknownToken(): void
    {
        $store = ShareStore::atPath($this->scratch . '/session.db');
        $token = $store->publish('', 'html', '<p>one</p>', 7)->token;

        [$exitCode, $output] = $this->share(['revoke', substr($token, 0, 6)], store: $store);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No live share page carries that token', $output);
        $this->assertNotNull($store->lookup($token));
    }

    /**
     * C-SES-9's SES-3-P3 half, command side: a missing or unopenable
     * database surfaces as this command's own refusal — the serve-side
     * catch, widened to revoke. Before the fix this threw through.
     */
    public function testRevokeWithoutAnyStoreRefusesInsteadOfThrowing(): void
    {
        ob_start();
        $exitCode = (new ShareCommand($this->exportsDir, null))
            ->execute($this->createChatWithMessages(), ['revoke', str_repeat('ab', 16)]);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No session database is open', $output);

        ob_start();
        $bareExit = (new ShareCommand($this->exportsDir, null))
            ->execute($this->createChatWithMessages(), ['revoke']);
        $bareOutput = (string) ob_get_clean();

        $this->assertSame(1, $bareExit);
        $this->assertStringContainsString('No session database is open', $bareOutput);
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
     * Plant exactly one LIVE row carrying $token in the `shares` table of the
     * database at $path, by way of a real publish act and a hand-edit of its
     * token afterwards — the shape a foreign or hand-edited database leaves
     * behind, and the shape whose consequence round 2 pinned: `liveAll()`
     * lists it, `ShareStore::revoke()` refuses to write it and answers false.
     * The store's own CREATE TABLE runs through publish(), so the test never
     * duplicates the schema.
     */
    private function plantLiveRow(string $path, string $token): void
    {
        $store = ShareStore::atPath($path);
        $served = $store->publish('', 'html', '<p>planted</p>', 7)->token;

        $statement = (new \PDO('sqlite:' . $path))->prepare('UPDATE shares SET token = :token WHERE token = :served');
        $statement->execute([':token' => $token, ':served' => $served]);

        $listed = ShareStore::atPath($path)->liveAll();
        $this->assertCount(1, $listed, 'the fixture is a single live row');
        $this->assertSame($token, $listed[0]['record']->token, 'the fixture lists under the hand-edited token');
    }

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
