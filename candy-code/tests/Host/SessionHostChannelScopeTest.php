<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Channels\ChannelToolPolicyService;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\ChannelToolScope;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * D6: admission is where the scope lands. A session whose stored name (or
 * ephemeral create-name) spells the §2.3 grammar gets its permission gate
 * wrapped with the channels.json policy for that group/channel — resolved
 * FRESH at every gate computation, refused LOUD through the existing hook
 * denial framing, and never silently: an unscoped session is byte-identical
 * to the one this lane's base shipped.
 *
 * The pins below drive real submitted turns through a scripted provider, so
 * they prove the whole seam chain (store → service → SessionHost::channelScoped
 * → gate arm → hook wording → transcript row), not just a unit in isolation.
 */
final class SessionHostChannelScopeTest extends TestCase
{
    private string $dir;

    private string $chanDir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-chan-scope-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->chanDir = $this->dir . '/cfg';
        mkdir($this->chanDir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->chanDir);
        @rmdir($this->dir);
    }

    public function testAWorkspaceWithoutThePolicyServiceIsByteUntouched(): void
    {
        $this->durableSession('agent:main:discord:group:g1');
        $gate = new PermissionGate(PermissionMode::BypassPermissions);
        $host = $this->host($gate, service: null);

        $this->assertSame($gate, $host->permissionGate(), 'no service registered means no scope layer at all');
        $host->submit('write it');
        $this->drain($host);
        $this->assertStringContainsString('ran', $this->results($host->history()), 'the ordinary session runs as before');
    }

    public function testAGroupSessionIsAdmittedUnderItsChannelPolicy(): void
    {
        $this->durableSession('agent:main:discord:group:g1');
        $this->writeChannels('{"channels":{"candy-discord|discord":{"tools":{"allow":["Read"]}}}}');
        $host = $this->host(new PermissionGate(PermissionMode::BypassPermissions), service: $this->service(['candy-discord']));

        $scope = $host->permissionGate()?->channelScope();
        $this->assertInstanceOf(ChannelToolScope::class, $scope, 'the admitted gate carries the resolved scope');
        $this->assertSame('discord channel', $scope?->label(), 'no group block exists, so the channel level supplies the policy');

        $ticket = $host->submit('write it');
        $this->assertSame(TurnTicket::STARTED, $ticket->admitted, 'admission itself is where the policy layer is consulted — before the turn runs');
        $this->drain($host);
        $this->assertNotEmpty(
            array_filter(
                $this->contents($host->history()),
                static fn (string $t): bool => str_starts_with($t, 'Tool error: Hook denied: Channel tool policy') && str_contains($t, 'does not allow Write'),
            ),
            'the refusal speaks loudly through the existing hook framing',
        );
        $this->assertStringNotContainsString('ran', $this->results($host->history()), 'the refused call never executed');
    }

    public function testAGroupOverrideWinsAtAdmission(): void
    {
        $this->durableSession('agent:main:discord:group:g1');
        $this->writeChannels('{"channels":{"candy-discord|discord":{"tools":{"allow":["Read"]},"groups":{"g1":{"tools":{"allow":["Write"]}}}}}}');
        $host = $this->host(new PermissionGate(PermissionMode::BypassPermissions), service: $this->service(['candy-discord']));

        $scope = $host->permissionGate()?->channelScope();
        $this->assertSame('discord group g1', $scope?->label(), 'the group level is the resolved level');
        $host->submit('write it');
        $this->drain($host);
        $this->assertStringContainsString('ran', $this->results($host->history()), 'the group allowlist admitted the very tool the channel barred');
    }

    public function testAnOrdinarySessionNameIsNeverScoped(): void
    {
        $this->durableSession('sprint notes');
        $this->writeChannels('{"channels":{"candy-discord|discord":{"tools":{"allow":["Read"]}}}}');
        $host = $this->host(new PermissionGate(PermissionMode::BypassPermissions), service: $this->service(['candy-discord']));

        $this->assertNull($host->permissionGate()?->channelScope(), 'a name outside the grammar is not a channel session');
        $host->submit('write it');
        $this->drain($host);
        $this->assertStringContainsString('ran', $this->results($host->history()));
    }

    public function testAnEphemeralHostCarriesItsCreateNameIntoTheScope(): void
    {
        // Ephemeral sessions have no store row to read; the name carried at
        // create is the identity, exactly as session.create put it there.
        $this->writeChannels('{"channels":{"candy-discord|discord":{"tools":{"allow":["Read"]}}}}');
        $gate = new PermissionGate(PermissionMode::BypassPermissions);
        $backend = $this->backend($gate);
        $host = SessionHost::new(
            'eph-1',
            $this->workspace($backend)->withService(ChannelToolPolicyService::class, $this->service(['candy-discord'])),
            history: [],
            customCommands: [],
            ephemeral: true,
            ephemeralName: 'agent:main:discord:group:g1',
        );

        $this->assertNotNull($host->permissionGate()?->channelScope(), 'the create-name alone is enough to resolve a scope');
        $host->submit('write it');
        $this->drain($host);
        $this->assertStringNotContainsString('ran', $this->results($host->history()), 'the ephemeral turn was admitted under the policy');
    }

    public function testTheScopeRefusesEvenAgainstAConfiguredAllow(): void
    {
        $this->durableSession('agent:main:discord:group:g1');
        $this->writeChannels('{"channels":{"candy-discord|discord":{"tools":{"allow":["Read"]}}}}');
        $gate = new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('Write', PermissionAction::Allow)]);
        $host = $this->host($gate, service: $this->service(['candy-discord']));

        $host->submit('write it');
        $this->drain($host);
        $this->assertStringNotContainsString('ran', $this->results($host->history()), 'a home-configured allow cannot outrank the channel policy');
    }

    public function testAnUntrustedBundleKeepsThePolicyInert(): void
    {
        $this->durableSession('agent:main:discord:group:g1');
        $this->writeChannels('{"channels":{"candy-discord|discord":{"tools":{"allow":["Read"]}}}}');
        $host = $this->host(new PermissionGate(PermissionMode::BypassPermissions), service: $this->service([]));

        $this->assertNull($host->permissionGate()?->channelScope(), 'policy only binds through the same trust door hooks use');
        $host->submit('write it');
        $this->drain($host);
        $this->assertStringContainsString('ran', $this->results($host->history()));
    }

    public function testABrokenPolicyRefusesEverythingLoudly(): void
    {
        $this->durableSession('agent:main:discord:group:g1');
        $this->writeChannels('{"channels":{"candy-discord|discord":true}}');
        $host = $this->host(new PermissionGate(PermissionMode::BypassPermissions), service: $this->service(['candy-discord']));

        $scope = $host->permissionGate()?->channelScope();
        $this->assertTrue($scope?->blocksEverything(), 'a policy that cannot be read refuses every tool, never silently opens');
        $host->submit('write it');
        $this->drain($host);
        $this->assertNotEmpty(
            array_filter(
                $this->contents($host->history()),
                static fn (string $t): bool => str_contains($t, 'Channel tool policy does not allow Write') && str_contains($t, 'could not be honoured'),
            ),
            'the fail-closed reason names itself in the refusal',
        );
        $this->assertStringNotContainsString('ran', $this->results($host->history()));
    }

    // ---- wiring ----------------------------------------------------------

    private function durableSession(?string $name): void
    {
        $this->store->createSession('s', 'p', 'm', name: $name);
    }

    private function writeChannels(string $json): void
    {
        file_put_contents($this->chanDir . '/channels.json', $json);
    }

    private function service(array $trusted): ChannelToolPolicyService
    {
        return ChannelToolPolicyService::forStore(
            ChannelsStore::create(fn (): string => $this->chanDir),
            $trusted,
        );
    }

    private function backend(PermissionGate $gate): EngineBackend
    {
        $call = new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Write', ['file_path' => 'a.txt', 'content' => 'x'])]);
        $answer = new CompleteResponse(content: 'done');

        return EngineBackend::new(new ScriptedProvider([$call, $answer]), 'm')
            ->withTools([self::writeToolRan()])
            ->withoutHooks()
            ->withRoot($this->dir)
            ->withPermissionGate($gate);
    }

    private function workspace(EngineBackend $backend): WorkspaceContext
    {
        return WorkspaceContext::new(sessionStore: $this->store, backend: $backend);
    }

    private function host(PermissionGate $gate, ?ChannelToolPolicyService $service): SessionHost
    {
        $workspace = $this->workspace($this->backend($gate));
        if ($service !== null) {
            $workspace = $workspace->withService(ChannelToolPolicyService::class, $service);
        }

        return SessionHost::new('s', $workspace, history: [], customCommands: []);
    }

    private static function writeToolRan(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'Write';
            }

            public function description(): string
            {
                return 'says it ran';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call', content: 'ran');
            }
        };
    }

    /** @param list<Message> $history @return list<string> */
    private function contents(array $history): array
    {
        return array_map(static fn (Message $m): string => $m->content, $history);
    }

    /** @param list<Message> $history the tool-result payloads only */
    private function results(array $history): string
    {
        $rows = [];
        foreach ($history as $message) {
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
        $guard = $loop->addTimer(60.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        $this->assertFalse($host->isBusy(), 'the turn settled');
    }
}
