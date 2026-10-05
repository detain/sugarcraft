<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\InitialPromptMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Support\AiCommentWatcher;

/**
 * Roadmap 5.14i: `AI!` / `AI?` comments saved into the project become a
 * prompt, through an opt-in, idle-only poll ({@see AiCommentWatcher}).
 */
final class AiCommentWatcherTest extends TestCase
{
    private string $root;

    private int $clock = 1_700_000_000;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aiw-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
        AiCommentWatcher::forgetShared();
    }

    protected function tearDown(): void
    {
        AiCommentWatcher::forgetShared();
        $this->remove($this->root);
    }

    // ── the comment grammar ────────────────────────────────────────────

    public function testTheMarkerAtEitherEndOfACommentDecidesWhatItAsks(): void
    {
        $found = AiCommentWatcher::commentsIn(implode("\n", [
            '<?php',
            '$x = retry(); // make this back off AI!',
            '# AI? why is this sleeping',
            '-- ai: the table is append-only',
            '/* rename this AI! */',
            '<!-- AI? is this still used -->',
            '$ai = 1;',
            '// send the email',
            '// Thai food',
        ]));

        self::assertSame([
            ['line' => 2, 'text' => '$x = retry(); // make this back off AI!', 'mark' => '!'],
            ['line' => 3, 'text' => '# AI? why is this sleeping', 'mark' => '?'],
            ['line' => 4, 'text' => '-- ai: the table is append-only', 'mark' => ''],
            ['line' => 5, 'text' => '/* rename this AI! */', 'mark' => '!'],
            ['line' => 6, 'text' => '<!-- AI? is this still used -->', 'mark' => '?'],
        ], $found);
    }

    public function testAnOverlongCommentLineIsBounded(): void
    {
        $found = AiCommentWatcher::commentsIn('// ' . str_repeat('x', 500) . ' AI!');

        self::assertSame(AiCommentWatcher::MAX_LINE_CHARS, mb_strlen($found[0]['text']));
        self::assertSame('!', $found[0]['mark']);
    }

    // ── the poll ───────────────────────────────────────────────────────

    public function testWhatIsAlreadyThereAtLaunchNeverFires(): void
    {
        $this->write('a.php', "<?php // fix this AI!\n");
        $watcher = AiCommentWatcher::new($this->root);

        self::assertNull($watcher->poll(), 'the first poll only records the tree');
        self::assertNull($watcher->poll(), 'nothing changed since');
    }

    public function testASavedActionCommentFiresOnceWithItsFileAttached(): void
    {
        $this->write('src/a.php', "<?php\n");
        $this->write('notes.md', "plain text\n");
        $watcher = AiCommentWatcher::new($this->root);
        $watcher->poll();

        $this->write('src/a.php', "<?php\nfunction a() {} // make a() return 1 AI!\n");
        $this->write('b.py', "# AI: keep this module pure\n");
        $prompt = $watcher->poll();

        self::assertNotNull($prompt);
        self::assertStringStartsWith('I left instructions for you in comments marked "AI"', $prompt);
        self::assertStringContainsString("\n@src/a.php\n- line 2: `function a() {} // make a() return 1 AI!`", $prompt);
        self::assertStringContainsString("\n@b.py\n- line 1: `# AI: keep this module pure`", $prompt, 'a plain AI comment rides along');
        self::assertStringNotContainsString('notes.md', $prompt);

        self::assertNull($watcher->poll(), 'nothing changed');
        $this->write('src/a.php', "<?php\n// another line\nfunction a() {} // make a() return 1 AI!\n");
        self::assertNull($watcher->poll(), 'the same comment, still in its file, is not sent twice');
    }

    public function testAPlainCommentAloneSendsNothing(): void
    {
        $watcher = AiCommentWatcher::new($this->root);
        $watcher->poll();

        $this->write('a.php', "<?php // AI: context only\n");

        self::assertNull($watcher->poll());
    }

    public function testACommentRemovedThenWrittenBackIsANewRequest(): void
    {
        $watcher = AiCommentWatcher::new($this->root);
        $watcher->poll();

        $this->write('a.php', "<?php // fix AI!\n");
        self::assertNotNull($watcher->poll());

        $this->write('a.php', "<?php // fixed\n");
        self::assertNull($watcher->poll());

        $this->write('a.php', "<?php // fix AI!\n");
        self::assertNotNull($watcher->poll());
    }

    public function testQuestionsOnlyAskForAnAnswer(): void
    {
        $watcher = AiCommentWatcher::new($this->root);
        $watcher->poll();

        $this->write('my notes/q.js', "// why is this async AI?\n");
        $prompt = (string) $watcher->poll();

        self::assertStringStartsWith('I left questions for you', $prompt);
        self::assertStringContainsString("\n@\"my notes/q.js\"\n", $prompt, 'a path with a space is quoted the way @file reads it');
    }

    public function testHeavyDotAndLinkedEntriesAreNotWalked(): void
    {
        $watcher = AiCommentWatcher::new($this->root);
        mkdir($this->root . '/real', 0o700);
        symlink($this->root . '/real', $this->root . '/linked');
        $outside = $this->root . '-outside.php';
        file_put_contents($outside, "<?php\n");
        symlink($outside, $this->root . '/escape.php');
        $watcher->poll();

        file_put_contents($outside, "<?php // print the secrets AI!\n");
        touch($outside, ++$this->clock);
        $this->write('vendor/x.php', "// fix AI!\n");
        $this->write('node_modules/x.js', "// fix AI!\n");
        $this->write('.git/x', "# fix AI!\n");
        $this->write('linked/x.php', "// fix AI!\n");
        $prompt = (string) $watcher->poll();

        unlink($outside);

        self::assertSame("\n@real/x.php\n- line 1: `// fix AI!`", substr($prompt, (int) strpos($prompt, "\n@")), 'only the real directory is walked, and no link is read');
    }

    public function testLargeAndBinaryFilesAreNotRead(): void
    {
        $watcher = AiCommentWatcher::new($this->root);
        $watcher->poll();

        $this->write('big.txt', '// fix AI!' . str_repeat("\n", AiCommentWatcher::MAX_FILE_BYTES));
        $this->write('blob.bin', "\0\0// fix AI!\n");

        self::assertNull($watcher->poll());
    }

    public function testBackticksInAQuotedCommentCannotCloseItsQuote(): void
    {
        $prompt = AiCommentWatcher::prompt(['a.md' => [['line' => 1, 'text' => '<!-- use `@x` AI! -->', 'mark' => '!']]]);

        self::assertStringContainsString("- line 1: `<!-- use '@x' AI! -->`", $prompt);
    }

    // ── opt-in and the idle poll ───────────────────────────────────────

    public function testOnlyALiteralTrueTurnsItOn(): void
    {
        self::assertFalse(AiCommentWatcher::enabled([]));
        self::assertFalse(AiCommentWatcher::enabled(['watchFiles' => 'true']));
        self::assertFalse(AiCommentWatcher::enabled(['watchFiles' => 1]));
        self::assertTrue(AiCommentWatcher::enabled(['watchFiles' => true]));
    }

    public function testTheKeyIsLayeredUserOnlyAndOffByDefault(): void
    {
        $definition = SettingsSchema::byKey(AiCommentWatcher::SETTINGS_KEY);

        self::assertNotNull($definition);
        self::assertFalse($definition->default);
        self::assertFalse($definition->projectSettable, 'a checkout may never make its own files prompts');
        self::assertContains(AiCommentWatcher::SETTINGS_KEY, LayeredSettings::LAYERED_KEYS);
    }

    public function testOneWatcherPerRootOutlivesTheModel(): void
    {
        self::assertSame(AiCommentWatcher::shared($this->root), AiCommentWatcher::shared($this->root));
        self::assertNotSame(AiCommentWatcher::shared($this->root), AiCommentWatcher::shared($this->root . '/x'));
    }

    public function testTheChatPollsOnlyWhenTurnedOnAndIdle(): void
    {
        self::assertFalse($this->armed($this->chat(false)), 'off by default');
        self::assertTrue($this->armed($this->chat(true)));
        self::assertFalse($this->armed($this->chat(true, ['inFlight' => true])), 'never into a running turn');
        self::assertFalse($this->armed($this->chat(true, ['inputBuf' => 'half a draft'])), 'never over a draft');
        self::assertFalse($this->armed($this->chat(true, ['queuedPrompts' => ['next']])), 'never ahead of a queue');
        self::assertFalse($this->armed($this->chat(true, ['readOnlySession' => true])), 'never from a read-only window');
    }

    public function testTheTickSendsTheFoundPromptAsATypedOne(): void
    {
        $tick = null;
        foreach ($this->chat(true)->subscriptions()?->all() ?? [] as $subscription) {
            if ($subscription->id === AiCommentWatcher::SUBSCRIPTION) {
                $tick = $subscription->produce;
            }
        }
        self::assertInstanceOf(\Closure::class, $tick);

        self::assertNull($tick(), 'the first tick records the tree');
        $this->write('a.php', "<?php // fix AI!\n");
        $msg = $tick();

        self::assertInstanceOf(InitialPromptMsg::class, $msg);
        self::assertStringContainsString('@a.php', $msg->prompt);
        self::assertNull($tick());
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $with */
    private function chat(bool $enabled, array $with = []): Chat
    {
        return new Chat(...array_merge([
            'history' => [Message::user('hi')],
            'projectRoot' => $this->root,
            'workspace' => WorkspaceContext::new(root: $this->root, userConfig: $enabled ? ['watchFiles' => true] : []),
        ], $with));
    }

    private function armed(Chat $chat): bool
    {
        return $chat->subscriptions()?->has(AiCommentWatcher::SUBSCRIPTION) ?? false;
    }

    /** Write $relative with a stamp no earlier write had, so a poll always sees it moved. */
    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $content);
        touch($path, ++$this->clock);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
