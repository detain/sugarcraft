<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Role;

/**
 * The tier values of the path that runs by DEFAULT, pinned as real numbers.
 *
 * crush_code.md Phase 5 item 4 made every context tier a percentage of the
 * backend's reported window, and its prose said the offline path "acts exactly
 * as it did before" because a backend with no model behind it falls back to
 * {@see ContextWindow::FALLBACK_TOKENS}. That was true of
 * {@see \SugarCraft\Crush\Backend\EchoBackend} and false of the CLI, which
 * never builds one: {@see Bootstrap::backend()} builds
 * `EngineBackend(EchoProvider)` both as the offline fallback and as the
 * degrade-after-a-provider-failure path, and that backend DOES implement the
 * capability. While {@see EchoProvider::contextWindow()} answered with an
 * invented 1,000,000, the default run's four tiers sat at 700,000 / 850,000 /
 * 950,000 / 1,000,000 estimated tokens — i.e. off — rather than at the
 * 70,000 / 85,000 / 95,000 / 100,000 they acted on before the item.
 *
 * Every threshold below is therefore asserted as the ABSOLUTE estimated-token
 * count it fires at, on the backend the CLI really constructs, and each is
 * pinned at its boundary from both sides. A window ten times too large moves
 * every one of them out of reach of these fixtures.
 *
 * HOME and the provider env vars are redirected/cleared for the whole class,
 * same convention as {@see BootstrapHookFileTest}: `backend()` consults
 * $SUGARCRUSH_PROVIDER, $SUGARCRUSH_BACKEND_CMD, $SUGARCRUSH_BACKEND_CMD_STREAM
 * and a persisted provider in `~/.sugar-crush/config.json` before it reaches the
 * echo arm, and this test is about the echo arm. All FOUR tiers, not the two
 * this sentence used to name — the `setUp()` list below was widened when the
 * streaming variable was added and this paragraph was not, which is the same
 * "list short by one" defect in prose instead of in code. $SUGARCRUSH_MODEL is
 * cleared alongside them because it renames whatever tier wins.
 */
final class BootstrapContextWindowTest extends TestCase
{
    private string $tempDir;
    private string $home;
    private string $project;
    private string $originalHome;
    private mixed $originalServerHome;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_ctx_window_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->project = $this->tempDir . '/project';
        mkdir($this->home, 0700, true);
        mkdir($this->project, 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        foreach (['SUGARCRUSH_PROVIDER', 'SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_BACKEND_CMD_STREAM', 'SUGARCRUSH_MODEL'] as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        foreach ($this->originalEnv as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }

        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    /**
     * The seam itself: echo says "unknown", and the resolver — not the provider
     * — supplies the number the tiers divide by.
     */
    public function testTheOfflineBackendResolvesToTheFallbackWindowNotAnEchoGuess(): void
    {
        $this->assertSame(
            0,
            (new EchoProvider())->contextWindow(),
            'echo has no model behind it, so its window is UNKNOWN (0), never a figure',
        );

        $backend = Bootstrap::backend($this->project);

        $this->assertInstanceOf(
            ReportsContextWindow::class,
            $backend,
            'the CLI offline/degrade path builds an EngineBackend, which DOES carry the capability',
        );
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::ofBackend($backend));
        $this->assertSame(100_000, ContextWindow::ofBackend($backend), 'and that fallback is 100,000');
        $this->assertSame(100_000, (new Chat(backend: $backend))->contextTokenLimit());
    }

    /**
     * Tier 1 of 4 — the reminder — at 70,000 estimated tokens exactly, pinned
     * from both sides one token apart. 279,956 and 279,960 chars are
     * `ceil(chars/4) + 10` = 69,999 and 70,000.
     */
    public function testTheOfflineReminderTierFiresAt70000EstimatedTokens(): void
    {
        $backend = Bootstrap::backend($this->project);

        $under = new Chat(
            history: [Message::user(str_repeat('x', 279_956))],
            inputBuf: 'hello',
            backend: $backend,
        );
        $this->assertSame(69_999, $under->contextTokens());
        [$quiet] = $under->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(2, $quiet->history, 'one token under the tier, nothing rides along');

        $at = new Chat(
            history: [Message::user(str_repeat('x', 279_960))],
            inputBuf: 'hello',
            backend: $backend,
        );
        $this->assertSame(70_000, $at->contextTokens());
        [$warned] = $at->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(3, $warned->history);
        $this->assertSame(Role::System, $warned->history[2]->role, 'the reminder, AFTER the user turn');
    }

    /**
     * Tier 2 of 4 — automatic compaction — at 85,000 estimated tokens exactly.
     *
     * Observable structurally rather than by wording: the notice this tier
     * leaves goes in BEFORE the user turn (it reports on history that already
     * existed), while the reminder goes AFTER it. So one token under the tier
     * the turn is `…, user, system` with the whole 26-message history intact,
     * and at the tier it is `…, system, user` with 6 of those messages gone.
     */
    public function testTheOfflineBackgroundTierFiresAt85000EstimatedTokens(): void
    {
        $backend = Bootstrap::backend($this->project);

        $under = new Chat(history: self::pairedHistory(84_999), inputBuf: 'hello', backend: $backend);
        $this->assertSame(84_999, $under->contextTokens());
        [$quiet] = $under->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(28, $quiet->history, '26 untouched + the user turn + the reminder');
        $this->assertSame(Role::User, $quiet->history[26]->role);
        $this->assertSame(Role::System, $quiet->history[27]->role);

        $at = new Chat(history: self::pairedHistory(85_000), inputBuf: 'hello', backend: $backend);
        $this->assertSame(85_000, $at->contextTokens());
        [$compacted] = $at->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(25, $compacted->history, '3 summaries + 20 preserved + the notice + the user turn');
        $this->assertSame(Role::System, $compacted->history[23]->role, 'the notice, BEFORE the user turn');
        $this->assertSame(Role::User, $compacted->history[24]->role);
        $this->assertSame('hello', $compacted->history[24]->content);
    }

    /**
     * Tier 3 of 4 — the blocking refusal — reached on the offline backend by a
     * history whose ten PRESERVED exchanges alone are past 95,000 estimated
     * tokens. 123,500 estimated tokens over 13 equal exchanges leaves ~94,800
     * in the preserved twenty messages plus their overhead, which compaction
     * cannot shrink; measured, the compacted history is 95,000+ and the turn is
     * refused. Against a 1,000,000-token window the same fixture is at 12% and
     * goes straight out.
     */
    public function testTheOfflineBlockingTierRefusesTheTurn(): void
    {
        $backend = Bootstrap::backend($this->project);

        $chat = new Chat(history: self::pairedHistory(123_500), inputBuf: 'hello', backend: $backend);
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'nothing was scheduled');
        $this->assertFalse($next->inFlight);
        $this->assertCount(26, $next->history, '3 summaries + 20 preserved + notice + user turn + refusal');
        $this->assertSame(Role::User, $next->history[24]->role);
        $this->assertSame('hello', $next->history[24]->content);
        $this->assertSame(Role::Assistant, $next->history[25]->role);
    }

    /**
     * Tier 4 of 4 — the idle prompt — at the WHOLE window, so 100,000 on this
     * path and 100,001 the first count past it.
     */
    public function testTheOfflineIdleTierFiresPast100000EstimatedTokens(): void
    {
        $idle = new \DateTimeImmutable('2 hours ago');
        $chat = (new Chat(backend: Bootstrap::backend($this->project)))->withLastActivity($idle);

        $this->assertFalse($chat->shouldPromptIdleCompaction(100_000, $idle));
        $this->assertTrue($chat->shouldPromptIdleCompaction(100_001, $idle));
    }

    /**
     * 13 exchanges (26 messages) whose {@see Chat::contextTokens()} is EXACTLY
     * $tokens, so a tier boundary can be approached from one token away.
     *
     * Each message is `size * 4` copies of a distinct letter: `ceil(4n/4) = n`
     * makes the arithmetic exact, and distinctness keeps the three summaries
     * apart — identical ones are collapsed into one `[3x] …` entry by the
     * compactor's stage 3 and every count below would shift. Thirteen pairs is
     * three more than `recentPreserveCount`, which is what makes compaction
     * able to free anything at all.
     *
     * @return list<Message>
     */
    private static function pairedHistory(int $tokens): array
    {
        $contentTokens = $tokens - 26 * 10; // 10 tokens of role overhead per message
        $per = intdiv($contentTokens, 26);
        $remainder = $contentTokens - $per * 26;

        $history = [];
        for ($i = 0; $i < 26; $i++) {
            $content = str_repeat(chr(97 + $i), ($per + ($i === 25 ? $remainder : 0)) * 4);
            $history[] = $i % 2 === 0 ? Message::user($content) : Message::assistant($content);
        }

        return $history;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
