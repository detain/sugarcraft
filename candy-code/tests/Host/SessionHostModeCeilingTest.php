<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * C-AGT-2.b, the SERVED half: `SubmitOptions::withModeCeiling()` steps the
 * gate of exactly ONE submitted turn down (never up — `stricterOf` decides),
 * and the very next submission runs under the session's own mode again.
 * This is the door C-WEB-2's future ReviewHostCommand walks through: the
 * web `/review` gets its read-only law action-side without the user ever
 * touching the session mode.
 */
final class SessionHostModeCeilingTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-mode-ceiling-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    public function testACeilingedSubmitRefusesWritesAndTheNextOneDoesNot(): void
    {
        $host = $this->hostWith(new PermissionGate(PermissionMode::BypassPermissions));

        $ticket = $host->submit('review the change set', SubmitOptions::new()->withModeCeiling(PermissionMode::Plan));
        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        $this->drain($host);

        // The plan clamp refused the Write INSIDE the ceilinged turn; the
        // structural match (a row BEGINNING with the hook's framing) stays
        // honest even while the turn-context echoes this file's own diff.
        $texts = $this->contents($host->history(), 0);
        self::assertNotEmpty(
            array_filter($texts, static fn (string $t): bool => str_starts_with($t, 'Tool error: Hook denied: Permission mode') && str_contains($t, 'does not allow Write')),
            'the ceilinged turn physically could not Write',
        );
        self::assertStringNotContainsString('ran', $this->results($host->history(), 0), 'the refused call never executed');
        self::assertSame(PermissionMode::BypassPermissions, $host->permissionGate()?->mode(), 'the ceiling is turn-scoped — the session gate never moved');

        $mark = count($host->history());
        $host->submit('now write it');
        $this->drain($host);

        self::assertStringContainsString('ran', $this->results($host->history(), $mark), 'the next submission runs unclamped');
        self::assertEmpty(
            array_filter($this->contents($host->history(), $mark), static fn (string $t): bool => str_starts_with($t, 'Tool error: Hook denied: Permission mode')),
            'the ordinary turn was never refused',
        );
    }

    public function testACeilingNeverLoosensAStricterSessionMode(): void
    {
        // `stricterOf` is the whole story: a BypassPermissions ceiling on a
        // plan session is a no-op, not a step up.
        $host = $this->hostWith(new PermissionGate(PermissionMode::Plan));
        $host->setPermissionMode(PermissionMode::Plan);

        $host->submit('review the change set', SubmitOptions::new()->withModeCeiling(PermissionMode::BypassPermissions));
        $this->drain($host);

        self::assertNotEmpty(
            array_filter(
                $this->contents($host->history(), 0),
                static fn (string $t): bool => str_starts_with($t, 'Tool error: Hook denied: Permission mode') && str_contains($t, 'does not allow Write'),
            ),
            'the session mode is what answers, not the looser ceiling',
        );
        self::assertStringNotContainsString('ran', $this->results($host->history(), 0));
    }

    public function testACeilingOnAGatelessSessionInventsNoGate(): void
    {
        // Disclosed semantics: the ceiling clamps an EXISTING gate; a
        // session wired without one (embedders, most host tests) is not
        // silently brought under plan by a ceilinged submit.
        $host = $this->hostWith(null);

        $host->submit('review the change set', SubmitOptions::new()->withModeCeiling(PermissionMode::Plan));
        $this->drain($host);

        self::assertStringContainsString('ran', $this->results($host->history(), 0), 'no gate, no clamp, the call ran as always');
        self::assertNull($host->permissionGate());
    }

    private function hostWith(?PermissionGate $gate): SessionHost
    {
        // Four entries = two turns' worth under either provider-advance
        // reading (per-child restart or shared counter): call, answer, call,
        // answer — so both the ceilinged turn and the one after it get a
        // Write call to refuse or run.
        $call = new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Write', ['file_path' => 'a.txt', 'content' => 'x'])]);
        $answer = new CompleteResponse(content: 'done');
        $backend = EngineBackend::new(new ScriptedProvider([$call, $answer, $call, $answer]), 'm')
            ->withTools([self::writeToolRan()])
            ->withoutHooks()
            ->withRoot($this->dir);
        if ($gate !== null) {
            $backend = $backend->withPermissionGate($gate);
        }

        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend),
            history: [],
            customCommands: [],
        );
    }

    private static function writeToolRan(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'Write'; }
            public function description(): string { return 'says it ran'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call', content: 'ran');
            }
        };
    }

    /** @param list<Message> $history @return list<string> */
    private function contents(array $history, int $from): array
    {
        return array_map(static fn (Message $m): string => $m->content, array_slice($history, $from));
    }

    /** @param list<Message> $history the tool-result payloads only */
    private function results(array $history, int $from): string
    {
        $rows = [];
        foreach (array_slice($history, $from) as $message) {
            foreach ($message->toolResults as $result) {
                $rows[] = (string) $result->result;
            }
        }

        return implode("\n", $rows);
    }

    private function drain(SessionHost $host): void
    {
        $loop = \React\EventLoop\Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        // rev-w7a MAJOR: the ceiling is a bounded drain guard, not a product
        // timeout — a real forked turn is ~0.1s, but the ten-round host goal grind
        // measured ~15.6s under load ~4.5, so 15s reddened deterministically.
        $guard = $loop->addTimer(60.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertFalse($host->isBusy(), 'the turn settled');
    }
}
