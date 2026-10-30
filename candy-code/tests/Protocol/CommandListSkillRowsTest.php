<?php

/**
 * C-WEB-2.d (GAP-SKL-06, menu half): `command.list` shows the session's
 * user-invocable skills as `source: "skill"` rows, so the web `/` popup and
 * palette can offer them the way the TUI's `$`-mention completion does. A
 * skill is NOT a command — `command.exec` never runs one — and selecting a
 * row means sending the typed `/name args` over `session.send`, which the
 * host rewrites through the admission gate C-WEB-2.b lifted
 * ({@see \SugarCraft\Code\Skills\SkillSlashInvocation::draft()}).
 *
 * The listing rule the pins below hold together: LIST EXACTLY WHAT ADMISSION
 * WOULD DISPATCH. Each row must satisfy the gate the same spelling passes
 * when typed — so a built-in or file-claimed name, a disabled skill, a
 * non-user-invocable one, and any name holding characters the slash shape
 * rejects (the PUA-sentinel / control-byte spoof case) never appears.
 *
 * Driven through the real dispatcher over a hub-built host, the shape
 * `Cli\Serve` opens (sibling of `CommandMethodsMcpPromptsTest`).
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Skills\SkillSlashInvocation;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

final class CommandListSkillRowsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-cmdlist-skill-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project . '/.candy-code/commands', 0o700, true);
        \mkdir($this->project . '/.candy-code/skills', 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    /**
     * A registry over real SKILL.md files — the same shape
     * `ServedSkillSlashTest` builds, so the rewrite the row promises would
     * really resolve off disk. `$off` disables names; `$hidden` registers
     * `user-invocable: false` bodies.
     *
     * @param array<string, string> $bodies name => body
     * @param list<string>          $off
     * @param list<string>          $hidden
     */
    private function diskRegistry(array $bodies, array $off = [], array $hidden = []): SkillRegistry
    {
        $found = [];
        foreach ($bodies as $name => $body) {
            // The loader files a skill by its DIRECTORY name (probe-verified:
            // `Skill::fromFile` never reads a frontmatter `name:`), so the
            // directory is the spelling — colons and all, legal on ext4.
            $dir = $this->project . '/.candy-code/skills/' . $name;
            if (!\is_dir($dir)) {
                \mkdir($dir, 0o700, true);
            }
            $invocable = \in_array($name, $hidden, true) ? 'false' : 'true';
            \file_put_contents($dir . '/SKILL.md', "---\nname: {$name}\ndescription: the {$name} skill\nuser-invocable: {$invocable}\n---\n" . $body);
            $found[] = Skill::fromFile($dir . '/SKILL.md');
        }

        $registry = new SkillRegistry();
        $registry->register($found);
        $registry->disableMultiple($off);

        return $registry;
    }

    /**
     * `command.list` over the real dispatcher for a hub-built host carrying
     * $skills — the raw item list, in the response's own order.
     *
     * @return list<array<string, mixed>>
     */
    private function listedItems(?SkillRegistry $skills): array
    {
        $workspace = WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: new EchoBackend(),
            commandLoader: new CommandLoader(),
            skills: $skills,
        );
        $hub = SessionHub::new($workspace);
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'cweb2d-cmd', Loop::get());
        $dispatcher = Dispatcher::new($context);
        $client = WireClient::open($dispatcher, 'cweb2d-cmd-client');
        $client->hello();

        $items = (array) $client->call('command.list', ['sessionId' => 's'])['items'];

        $dispatcher->stop('test over');
        $hub->closeAll();
        $settle = Loop::addTimer(0.001, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($settle);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $items): array
    {
        return \array_column($items, null, 'name');
    }

    public function testAUserInvocableSkillIsListedWithSkillSource(): void
    {
        $rows = $this->keyed($this->listedItems($this->diskRegistry(['shipit' => 'Review the diff.'])));

        self::assertSame('skill', $rows['shipit']['source'] ?? null, 'the skill the host would dispatch is listed');
        self::assertSame('server', $rows['shipit']['runsIn'] ?? null, 'it runs by admission on the server');
        self::assertSame('the shipit skill', $rows['shipit']['description'] ?? null);
        self::assertArrayHasKey('argumentHint', $rows['shipit'], 'the row carries the full five-field shape');
        self::assertNull($rows['shipit']['argumentHint'], 'a skill takes no declared hint');
    }

    public function testADisabledSkillAndANonUserInvocableOneAreNotListed(): void
    {
        $rows = $this->keyed($this->listedItems($this->diskRegistry(
            ['shown' => 'x', 'off' => 'x', 'quiet' => 'x'],
            off: ['off'],
            hidden: ['quiet'],
        )));

        self::assertSame('skill', $rows['shown']['source'] ?? null);
        self::assertArrayNotHasKey('off', $rows, 'a disabled skill is an item the user cannot run');
        self::assertArrayNotHasKey('quiet', $rows, 'user-invocable: false stays off every menu');
    }

    public function testABuiltInSpellingKeepsItsRowAgainstAShadowingSkill(): void
    {
        $items = $this->listedItems($this->diskRegistry(['keys' => 'x']));
        $rows = $this->keyed($items);

        self::assertSame('builtin', $rows['keys']['source'] ?? null, 'the built-in owns the name; no second row');
        $spellings = \array_values(\array_filter(
            \array_map(static fn (array $r): string => (string) $r['name'], $items),
            static fn (string $n): bool => $n === 'keys',
        ));
        self::assertCount(1, $spellings, 'exactly one /keys row — the skill never adds a second');
    }

    public function testAFileCommandNameKeepsItsRowAgainstAShadowingSkill(): void
    {
        \file_put_contents($this->project . '/.candy-code/commands/notes.md', 'Note the diff.');
        $rows = $this->keyed($this->listedItems($this->diskRegistry(['notes' => 'x'])));

        self::assertSame('file', $rows['notes']['source'] ?? null, 'the file wins the name, as at admission');
    }

    public function testEveryListedSkillRowSurvivesTheAdmissionGate(): void
    {
        // The no-spoof law: a row the listing advertises is a row the SAME
        // gate rewrites — name shape and the gate's case-insensitive
        // built-in re-check included — so selecting it can never degrade into
        // inert prose or a smuggled action.
        $registry = $this->diskRegistry(['shipit' => 'x', 'ns:sub' => 'x']);
        $rows = $this->keyed($this->listedItems($registry));

        $names = [];
        foreach ($rows as $name => $row) {
            if (($row['source'] ?? null) === 'skill') {
                $names[] = (string) $name;
            }
        }
        self::assertNotSame([], $names, 'the fixture contributes skill rows to walk');
        foreach ($names as $name) {
            self::assertNotNull(
                SkillSlashInvocation::draft('/' . $name, $registry),
                '/'.$name.' is listed but admission would not dispatch it',
            );
        }
    }

    public function testANameTheSlashShapeRefusesIsNeverListed(): void
    {
        // A hand-built registry (frontmatter names are usually screened, but
        // the listing must not TRUST that): a name carrying an escape/PUA
        // sentinel cannot be a command.exec target and cannot rewrite at
        // admission, so it is no row at all — forged terminal bytes never
        // reach the roster the web renders.
        $registry = new SkillRegistry();
        $registry->register([
            new Skill(
                name: "evil\x1b]8;;http://x\x07tag\x1b]8;;\x07",
                description: "OSC-8 forged \u{e000}sentinel",
                userInvocable: true,
                disableModelInvocation: true,
                allowedTools: null,
                disallowedTools: null,
                model: null,
                effort: null,
                context: 'inline',
                paths: [],
                content: 'x',
                sourcePath: '/nowhere/SKILL.md',
            ),
        ]);

        foreach ($this->listedItems($registry) as $row) {
            self::assertNotSame('skill', $row['source'] ?? null, 'hostile name must not be listed');
        }
    }

    public function testSkillRowsRideTheMergedSortedOrder(): void
    {
        $items = $this->listedItems($this->diskRegistry(['zzz-skill' => 'x', 'aaa-skill' => 'x']));

        $names = \array_map(static fn (array $r): string => (string) $r['name'], $items);
        $sorted = $names;
        \sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names, 'every row, skill or not, is one ksort-ed list');
        self::assertContains('aaa-skill', $names);
    }

    public function testNoRegistryMeansNoSkillRows(): void
    {
        foreach ($this->listedItems(null) as $row) {
            self::assertNotSame('skill', $row['source'] ?? null, 'a session without skills lists none');
        }
    }

    public function testCommandExecRefusesASkillNameTheMenuRoutesThroughSend(): void
    {
        // The row is listed; the action is `session.send`, not exec. exec
        // answers the SAME `command_not_found` it answers for any name it
        // does not own — the menu cannot turn a skill row into an exec call.
        $rows = $this->keyed($this->listedItems($this->diskRegistry(['shipit' => 'x'])));
        self::assertSame('skill', $rows['shipit']['source'] ?? null);

        $workspace = WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: new EchoBackend(),
            commandLoader: new CommandLoader(),
            skills: $this->diskRegistry(['shipit' => 'x']),
        );
        $hub = SessionHub::new($workspace);
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'cweb2d-exec', Loop::get());
        $dispatcher = Dispatcher::new($context);
        $client = WireClient::open($dispatcher, 'cweb2d-exec-client');
        $client->hello();

        $response = $client->request('command.exec', ['sessionId' => 's', 'name' => 'shipit']);
        self::assertSame('command_not_found', $response['error']['data']['kind'] ?? null, 'a skill is not an exec target');

        $dispatcher->stop('test over');
        $hub->closeAll();
        $settle = Loop::addTimer(0.001, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($settle);
    }
}
