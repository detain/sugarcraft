<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\TerminalNotifier;

/**
 * C-TUI-6.j: the bell cues. Screen-reader mode makes the two moments a
 * non-sighted user cannot see happen audible — a turn ending, and an
 * agent stopping to wait for an approval:
 *
 *  - {@see TerminalNotifier::fromConfig()} upgrades `notify: off` to
 *    `bell` while the mode is on (turn end and goal end ride the same
 *    door, no Chat.php edit);
 *  - {@see Chat::requestPermission()} batches {@see \SugarCraft\Code\Tui\ScreenReaderMode::cue()}
 *    beside the wait promise, so the ASK itself is announced the moment
 *    it lands. With the promoted notifier the ask rings twice — BEL for
 *    the event, BEL for the cue — which is the design's shape, not an
 *    accident: cue-only-when-notify-is-null would be dead code, because
 *    the promotion never leaves `notify` null while the mode is on.
 *  - Off mode is byte-untouched: no cue closure is batched, the
 *    dispatch shape is the pre-6.j one.
 */
final class ScreenReaderBellCueTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-sr-bell-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        UiSettings::forget();
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(array $values): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode($values, JSON_THROW_ON_ERROR));
        UiSettings::forget();
    }

    public function testAPermissionAskBatchesTheCueBesideTheWait(): void
    {
        $this->writeConfig(['screenReader' => true]);

        [, $cmd] = $this->askingChat()->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([new \SugarCraft\Code\ToolCall('Bash', ['command' => 'rm -rf build/'], 'call_sr')]),
        ));

        // The batch carries the deferred wait (not a raw write), the
        // promoted notify BEL and the cue BEL: two rings for the ask.
        self::assertSame(["\x07", "\x07"], self::rawBytes($cmd), 'notify-off promotion + cue — delete either half and this count drops');
    }

    public function testWithTheModeOffTheAskShapeIsThePreWiringOne(): void
    {
        $this->writeConfig(['screenReader' => false]);

        [, $cmd] = $this->askingChat()->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([new \SugarCraft\Code\ToolCall('Bash', ['command' => 'rm -rf build/'], 'call_sr')]),
        ));

        // $notify null (no workspace config key), cue null → the bare
        // wait promise, not a batch: the byte-untouched dispatch shape.
        self::assertSame([], self::rawBytes($cmd));
        self::assertNotNull($cmd, 'the ask still returns its wait cmd with the mode off');
        self::assertFalse($cmd() instanceof BatchMsg, 'off mode does not wrap the wait in a batch — the pre-6.j return shape');
    }

    /** @param array<string, mixed> $with */
    private function chat(?string $mode, array $with = []): Chat
    {
        return new Chat(...array_merge([
            'history' => [Message::user('the prompt')],
            'inFlight' => true,
            'backend' => new EchoBackend(),
            'workspace' => WorkspaceContext::new(root: sys_get_temp_dir(), userConfig: $mode === null ? [] : ['notify' => $mode]),
        ], $with));
    }

    /** A chat whose hook asks on every tool call — the prompt's real door. */
    private function askingChat(): Chat
    {
        $asks = new class implements \SugarCraft\Code\Hooks\HookInterface {
            public function name(): string
            {
                return 'sr-ask-every-tool';
            }

            public function event(): \SugarCraft\Code\Hooks\HookEvent
            {
                return \SugarCraft\Code\Hooks\HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(\SugarCraft\Code\Hooks\HookContext $context): \SugarCraft\Code\Hooks\HookResult
            {
                return \SugarCraft\Code\Hooks\HookResult::ask('Run rm -rf build/?');
            }
        };
        $hooks = new \SugarCraft\Code\Hooks\HookManager(new \SugarCraft\Code\Hooks\HookRegistry());
        $hooks->register($asks);

        return $this->chat('off')
            ->registerTool('Bash', static fn(array $args): string => 'total 0')
            ->withHooks($hooks);
    }

    /**
     * Every RawMsg's bytes $cmd produces, batches opened.
     *
     * @return list<string>
     */
    private static function rawBytes(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            $out = [];
            foreach ($msg->cmds as $inner) {
                // A pending promise (the permission wait) is not a write.
                $out = [...$out, ...self::rawBytes($inner)];
            }

            return $out;
        }

        return $msg instanceof RawMsg ? [$msg->bytes] : [];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
