<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Skills\SkillSlashInvocation;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-WEB-2.b: the VIS-5 rewrite as ONE pure function —
 * {@see SkillSlashInvocation::draft()} — which both the TUI delegate
 * (`Chat::skillInvocationDraft()`) and the served route
 * (`Host\SessionHost::admit()`) call.
 *
 * The sibling `SlashSkillInvocationTest` drives the whole `submit()` chain
 * and stays the precedence fence; this file pins the gate itself over its
 * input space, and closes the lift with a BYTE-IDENTITY pin: the private
 * delegate and the static function answer every corpus entry the same, so
 * the extraction cannot have changed a TUI decision on its way out.
 *
 * Fixtures here are in-memory `Skill` objects — the pure gate consults only
 * the registry, so unlike the sibling it needs no SKILL.md files on disk.
 */
final class SkillSlashInvocationTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    private string $rootDir = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/crush-skill-gate-' . getmypid() . '-' . spl_object_id($this);
        $this->rootDir = $base . '/project';
        mkdir($this->rootDir, 0700, true);
        $this->home = $base . '/home';
        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir(dirname($this->rootDir))) {
            exec('rm -rf ' . escapeshellarg(dirname($this->rootDir)) . ' 2>&1');
        }
    }

    /**
     * A registry over in-memory skills. `$plain` maps name => body; names in
     * `$silent` register non-user-invocable; `$off` registers them and then
     * disables them, the state the registry's own gate answers null for.
     *
     * @param array<string, string> $plain
     * @param list<string>          $silent
     * @param list<string>          $off
     */
    private function gateRegistry(array $plain, array $silent = [], array $off = []): SkillRegistry
    {
        $skills = [];
        foreach ($plain + array_fill_keys($silent, 'x') + array_fill_keys($off, 'x') as $name => $body) {
            $skills[$name] = new Skill(
                (string) $name,
                'the ' . $name . ' skill',
                !in_array($name, $silent, true),
                false,
                null,
                null,
                null,
                null,
                '',
                [],
                (string) $body,
                $this->rootDir . '/.candy-code/skills/' . $name . '/SKILL.md',
            );
        }

        $registry = new SkillRegistry();
        $registry->register($skills);
        foreach ($off as $name) {
            // disable() parks the name behind get(), which userInvocable()
            // consults — the flag itself stays true, as in a live registry.
            $registry->disable($name);
        }

        return $registry;
    }

    /** The TUI's private delegate, reached on a real Chat over $registry. */
    private function delegateAnswer(?SkillRegistry $registry, string $text): ?string
    {
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            projectRoot: $this->rootDir,
            commandLoader: new CommandLoader(),
            workspace: WorkspaceContext::new(root: $this->rootDir, skills: $registry),
        );

        $invoke = new ReflectionMethod($chat, 'skillInvocationDraft');

        return $invoke->invoke($chat, $text);
    }

    /**
     * The corpus every pin below draws from, plus the byte-identity sweep:
     * one entry per decision the gate can make.
     *
     * @return array<string, ?string> draft => expected rewrite (null = prose)
     */
    private function corpus(): array
    {
        return [
            '/shipit review the diff' => '$shipit review the diff',
            '/shipit' => '$shipit',
            '/SHIPIT now' => '$shipit now',
            '/shipit:review the diff' => '$shipit review the diff',
            '/ns:sub do the thing' => '$ns:sub do the thing',
            '/ns:sub:quickly' => '$ns:sub quickly',
            '/alpha:beta extra' => '$alpha:beta extra',
            '/nope missing' => null,
            '/off disabled' => null,
            '/silent flagged' => null,
            '/keys' => null,
            '/exit now' => null,
            '/etc/hosts' => null,
            '/2fa' => null,
            '//text' => null,
            'plain prose' => null,
            "/shipit\nmore" => "\$shipit\nmore",
            "\n/shipit" => null,
        ];
    }

    public function testTheRegistryAnsweredNameRewritesIntoTheMentionSpelling(): void
    {
        $registry = $this->gateRegistry(['shipit' => 'Ship the checked-in work.']);

        $this->assertSame('$shipit review the diff', SkillSlashInvocation::draft('/shipit review the diff', $registry));
        $this->assertSame('$shipit', SkillSlashInvocation::draft('/shipit', $registry), 'the bare name still rewrites');
        $this->assertSame('$shipit now', SkillSlashInvocation::draft('/SHIPIT now', $registry), 'the lookup case-folds like the mention door');
    }

    public function testANullRegistryAnswersNull(): void
    {
        // No channel, nothing rewrites — the served route reaches the gate
        // with a workspace-less skill roster exactly like the TUI does.
        $this->assertNull(SkillSlashInvocation::draft('/shipit review', null));
    }

    public function testBuiltInSpellingsStayProseEvenUnderAShadowingSkill(): void
    {
        $registry = $this->gateRegistry(['keys' => 'shadow', 'exit' => 'shadow']);

        $this->assertNull(SkillSlashInvocation::draft('/keys', $registry));
        $this->assertNull(SkillSlashInvocation::draft('/exit now', $registry), 'the guard re-checks the built-in spellings the call site already consulted');
    }

    public function testTheColonWalkReachesTheDeepNameAndFallsBackToTheHead(): void
    {
        $registry = $this->gateRegistry(['ns:sub' => 'deep', 'shipit' => 'head']);

        $this->assertSame('$ns:sub do the thing', SkillSlashInvocation::draft('/ns:sub do the thing', $registry), 'LONGEST NAME FIRST');
        $this->assertSame('$ns:sub quickly', SkillSlashInvocation::draft('/ns:sub:quickly', $registry), 'a second colon is the separator');
        $this->assertSame('$shipit review the diff', SkillSlashInvocation::draft('/shipit:review the diff', $registry), 'a miss at depth lands on the head, unchanged meaning');
    }

    public function testTheLongestRegisteredNameWinsTheColonTie(): void
    {
        // Discriminating for the walk's direction: shortest-first would
        // answer '$alpha beta extra' and keep every other pin here green.
        $registry = $this->gateRegistry(['alpha' => 'short', 'alpha:beta' => 'deep']);

        $this->assertSame('$alpha:beta extra', SkillSlashInvocation::draft('/alpha:beta extra', $registry));
    }

    public function testDisabledAndNonUserInvocableNamesStayProse(): void
    {
        $registry = $this->gateRegistry([], silent: ['silent'], off: ['off', 'gone']);

        $this->assertNull(SkillSlashInvocation::draft('/off x', $registry));
        $this->assertNull(SkillSlashInvocation::draft('/silent x', $registry));
        $this->assertNull(SkillSlashInvocation::draft('/gone x', $registry));
    }

    public function testShapeThatIsNotASlashNameStaysProse(): void
    {
        $registry = $this->gateRegistry(['shipit' => 'x']);

        foreach (['/etc/hosts', '/2fa', '//text', 'plain prose', "\n/shipit", '/ship it'] as $text) {
            $this->assertNull(SkillSlashInvocation::draft($text, $registry), $text);
        }
    }

    public function testTheSharedGateMatchesTheTuiDelegateByteForByte(): void
    {
        // The extraction promise: the lifted body answers exactly what the
        // private method it came out of answers, over the whole corpus and
        // both registry states. Moving the body could not change a TUI
        // decision without reddening this pin (mutation-proven: a drift in
        // either answer fails it while every other file stays green).
        $registry = $this->gateRegistry(
            ['shipit' => 'a', 'ns:sub' => 'b', 'alpha' => 'c', 'alpha:beta' => 'd'],
            silent: ['silent'],
            off: ['off'],
        );

        foreach ($this->corpus() as $text => $expected) {
            $this->assertSame($expected, SkillSlashInvocation::draft($text, $registry), 'static gate: ' . $text);
            $this->assertSame($expected, $this->delegateAnswer($registry, (string) $text), 'TUI delegate: ' . $text);
            $this->assertNull(SkillSlashInvocation::draft($text, null), 'null registry: ' . $text);
        }
    }
}
