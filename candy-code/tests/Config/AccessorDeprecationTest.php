<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentType;
use SugarCraft\Code\Agents\Mailbox;
use SugarCraft\Code\Agents\TaskList;
use SugarCraft\Code\Agents\TaskStatus;
use SugarCraft\Code\Agents\TeamManager;
use SugarCraft\Code\Agents\Teammate;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\MCP\GitOperationResult;
use SugarCraft\Code\MCP\McpAuthStore;
use SugarCraft\Code\MCP\OAuthClientRegistration;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\MultiplexerSplitPane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\StallDetector;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\MCP\McpPromptProvider;

/**
 * Guard for the 2026-10-24 accessor era on candy-code/src (rulings-1024).
 *
 * Every public `get*()` accessor renamed to its bare twin must keep a
 * one-line, house-marked BC forwarder (`@deprecated` + `REMOVED-IN: 2.0.0`);
 * the census must stay roster-driven in both directions (no new unmarked
 * public getters, no stale roster rows), and the README table must tie the
 * roster exactly. Mirrors the sugar-dashboard/sugar-sql era guard shape.
 */
final class AccessorDeprecationTest extends TestCase
{
    private const MARKER = 'REMOVED-IN: 2.0.0';

    /**
     * Public `get*` accessors that keep their prefix by documented ruling:
     * `SkillRegistry::getUserInvocable` (bare name taken by the by-name
     * lookup `userInvocable(string $name): ?Skill`) and `Server\Listener::getAddress`
     * (vendor `React\Socket\ServerInterface` contract).
     */
    private const NAMED_CARVE_OUTS = [
        'SkillRegistry::getUserInvocable',
        'Listener::getAddress',
    ];

    /**
     * Full era roster: 'ClassShort::legacy' => [fqcn, srcPath, legacy, bare, isStatic].
     * This is the bidirectional census source of truth: adding a marked
     * forwarder without a row (or dropping a row's method) reddens the suite.
     */
    private static function eraPairs(): array
    {
        return [
            'AgentManager::getSubAgent' => ['SugarCraft\Code\Agents\AgentManager', 'Agents/AgentManager.php', 'getSubAgent', 'subAgent', false],
            'AgentManager::getTeam' => ['SugarCraft\Code\Agents\AgentManager', 'Agents/AgentManager.php', 'getTeam', 'team', false],
            'AgentManager::getTeamManager' => ['SugarCraft\Code\Agents\AgentManager', 'Agents/AgentManager.php', 'getTeamManager', 'teamManager', false],
            'AgentManager::getTeams' => ['SugarCraft\Code\Agents\AgentManager', 'Agents/AgentManager.php', 'getTeams', 'teams', false],
            'AgentWorkerPool::getActiveCount' => ['SugarCraft\Code\Agents\AgentWorkerPool', 'Agents/AgentWorkerPool.php', 'getActiveCount', 'activeCount', false],
            'AgentWorkerPool::getExecutor' => ['SugarCraft\Code\Agents\AgentWorkerPool', 'Agents/AgentWorkerPool.php', 'getExecutor', 'executor', false],
            'AgentWorkerPool::getQueueSize' => ['SugarCraft\Code\Agents\AgentWorkerPool', 'Agents/AgentWorkerPool.php', 'getQueueSize', 'queueSize', false],
            'BackgroundSupervisor::getActiveSessions' => ['SugarCraft\Code\Background\BackgroundSupervisor', 'Background/BackgroundSupervisor.php', 'getActiveSessions', 'activeSessions', false],
            'BackgroundSupervisor::getSession' => ['SugarCraft\Code\Background\BackgroundSupervisor', 'Background/BackgroundSupervisor.php', 'getSession', 'session', false],
            'BackgroundSupervisor::getStallWarnings' => ['SugarCraft\Code\Background\BackgroundSupervisor', 'Background/BackgroundSupervisor.php', 'getStallWarnings', 'stallWarnings', false],
            'Chat::getTools' => ['SugarCraft\Code\Chat', 'Chat.php', 'getTools', 'tools', false],
            'EnhancedSessionStore::getCheckpoint' => ['SugarCraft\Code\Session\EnhancedSessionStore', 'Session/EnhancedSessionStore.php', 'getCheckpoint', 'checkpoint', false],
            'EnhancedSessionStore::getMessages' => ['SugarCraft\Code\Session\EnhancedSessionStore', 'Session/EnhancedSessionStore.php', 'getMessages', 'messages', false],
            'EnhancedSessionStore::getSession' => ['SugarCraft\Code\Session\EnhancedSessionStore', 'Session/EnhancedSessionStore.php', 'getSession', 'session', false],
            'EnhancedSessionStore::getSessionByName' => ['SugarCraft\Code\Session\EnhancedSessionStore', 'Session/EnhancedSessionStore.php', 'getSessionByName', 'sessionByName', false],
            'EnhancedSessionStore::getSessionMeta' => ['SugarCraft\Code\Session\EnhancedSessionStore', 'Session/EnhancedSessionStore.php', 'getSessionMeta', 'sessionMeta', false],
            'GitOperationResult::getOutputOrNull' => ['SugarCraft\Code\MCP\GitOperationResult', 'MCP/GitOperationResult.php', 'getOutputOrNull', 'outputOrNull', false],
            'HookRegistry::getForEvent' => ['SugarCraft\Code\Hooks\HookRegistry', 'Hooks/HookRegistry.php', 'getForEvent', 'forEvent', false],
            'HttpMcpServer::getPrompt' => ['SugarCraft\Code\MCP\HttpMcpServer', 'MCP/HttpMcpServer.php', 'getPrompt', 'prompt', false],
            'Mailbox::getUnreadCount' => ['SugarCraft\Code\Agents\Mailbox', 'Agents/Mailbox.php', 'getUnreadCount', 'unreadCount', false],
            'McpAuthStore::getServerStatus' => ['SugarCraft\Code\MCP\McpAuthStore', 'MCP/McpAuthStore.php', 'getServerStatus', 'serverStatus', false],
            'McpClient::getPrompt' => ['SugarCraft\Code\MCP\McpClient', 'MCP/McpClient.php', 'getPrompt', 'prompt', false],
            'MenuBar::getActiveItem' => ['SugarCraft\Code\Tui\Components\MenuBar', 'Tui/Components/MenuBar.php', 'getActiveItem', 'activeItem', true],
            'MenuBar::getActiveMenu' => ['SugarCraft\Code\Tui\Components\MenuBar', 'Tui/Components/MenuBar.php', 'getActiveMenu', 'activeMenu', true],
            'MenuBar::getMenuItems' => ['SugarCraft\Code\Tui\Components\MenuBar', 'Tui/Components/MenuBar.php', 'getMenuItems', 'menuItems', true],
            'MultiplexerSplitPane::getMultiplexerType' => ['SugarCraft\Code\Tui\MultiplexerSplitPane', 'Tui/MultiplexerSplitPane.php', 'getMultiplexerType', 'multiplexerType', false],
            'OAuthClientRegistration::getValidAuth' => ['SugarCraft\Code\MCP\OAuthClientRegistration', 'MCP/OAuthClientRegistration.php', 'getValidAuth', 'validAuth', false],
            'Renderer::getTerminalSize' => ['SugarCraft\Code\Tui\Renderer', 'Tui/Renderer.php', 'getTerminalSize', 'terminalSize', true],
            'SessionStore::getMessages' => ['SugarCraft\Code\Session\SessionStore', 'Session/SessionStore.php', 'getMessages', 'messages', false],
            'SessionStore::getPdo' => ['SugarCraft\Code\Session\SessionStore', 'Session/SessionStore.php', 'getPdo', 'pdo', false],
            'SessionStore::getSession' => ['SugarCraft\Code\Session\SessionStore', 'Session/SessionStore.php', 'getSession', 'session', false],
            'SessionStore::getSessionByName' => ['SugarCraft\Code\Session\SessionStore', 'Session/SessionStore.php', 'getSessionByName', 'sessionByName', false],
            'SkillManager::getSkillsForPaths' => ['SugarCraft\Code\Skills\SkillManager', 'Skills/SkillManager.php', 'getSkillsForPaths', 'skillsForPaths', false],
            'SkillManager::getSkillsForTask' => ['SugarCraft\Code\Skills\SkillManager', 'Skills/SkillManager.php', 'getSkillsForTask', 'skillsForTask', false],
            'SkillManager::getUserInvocable' => ['SugarCraft\Code\Skills\SkillManager', 'Skills/SkillManager.php', 'getUserInvocable', 'userInvocable', false],
            'SkillRegistry::getForPaths' => ['SugarCraft\Code\Skills\SkillRegistry', 'Skills/SkillRegistry.php', 'getForPaths', 'forPaths', false],
            'SseMcpServer::getPrompt' => ['SugarCraft\Code\MCP\SseMcpServer', 'MCP/SseMcpServer.php', 'getPrompt', 'prompt', false],
            'StallDetector::getStallWarnings' => ['SugarCraft\Code\Tui\StallDetector', 'Tui/StallDetector.php', 'getStallWarnings', 'stallWarnings', false],
            'StdioMcpServer::getPrompt' => ['SugarCraft\Code\MCP\StdioMcpServer', 'MCP/StdioMcpServer.php', 'getPrompt', 'prompt', false],
            'TaskList::getPendingTasks' => ['SugarCraft\Code\Agents\TaskList', 'Agents/TaskList.php', 'getPendingTasks', 'pendingTasks', false],
            'TaskList::getTask' => ['SugarCraft\Code\Agents\TaskList', 'Agents/TaskList.php', 'getTask', 'task', false],
            'TaskList::getTasksByStatus' => ['SugarCraft\Code\Agents\TaskList', 'Agents/TaskList.php', 'getTasksByStatus', 'tasksByStatus', false],
            'TaskList::getTasksForTeammate' => ['SugarCraft\Code\Agents\TaskList', 'Agents/TaskList.php', 'getTasksForTeammate', 'tasksForTeammate', false],
            'TaskList::getUnblockedTasks' => ['SugarCraft\Code\Agents\TaskList', 'Agents/TaskList.php', 'getUnblockedTasks', 'unblockedTasks', false],
            'Team::getMailbox' => ['SugarCraft\Code\Agents\Team', 'Agents/Team.php', 'getMailbox', 'mailbox', false],
            'Team::getTaskList' => ['SugarCraft\Code\Agents\Team', 'Agents/Team.php', 'getTaskList', 'taskList', false],
            'Team::getTeammate' => ['SugarCraft\Code\Agents\Team', 'Agents/Team.php', 'getTeammate', 'teammate', false],
            'Team::getTeammates' => ['SugarCraft\Code\Agents\Team', 'Agents/Team.php', 'getTeammates', 'teammates', false],
            'TeamManager::getTeam' => ['SugarCraft\Code\Agents\TeamManager', 'Agents/TeamManager.php', 'getTeam', 'team', false],
            'TeamManager::getTeamConfig' => ['SugarCraft\Code\Agents\TeamManager', 'Agents/TeamManager.php', 'getTeamConfig', 'teamConfig', false],
            'TeamManager::getTeams' => ['SugarCraft\Code\Agents\TeamManager', 'Agents/TeamManager.php', 'getTeams', 'teams', false],
            'Teammate::getInboxPath' => ['SugarCraft\Code\Agents\Teammate', 'Agents/Teammate.php', 'getInboxPath', 'inboxPath', false],
            'Teammate::getStatus' => ['SugarCraft\Code\Agents\Teammate', 'Agents/Teammate.php', 'getStatus', 'status', false],
            'WorkflowEngine::getStatus' => ['SugarCraft\Code\Workflows\WorkflowEngine', 'Workflows/WorkflowEngine.php', 'getStatus', 'status', false],
            'WorktreeManager::getWorktreePath' => ['SugarCraft\Code\Agents\WorktreeManager', 'Agents/WorktreeManager.php', 'getWorktreePath', 'worktreePath', false],
        ];
    }

    /**
     * The subset with side-effect-free fixtures: the legacy call must answer
     * exactly what the bare call answers on the same object.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: list<mixed>, 4: ?callable(string): object, 5: bool}>
     */
    private static function runtimeEraPairs(): array
    {
        return [
            'TaskList::getPendingTasks' => [TaskList::class, 'getPendingTasks', 'pendingTasks', [], static fn (string $d): object => new TaskList($d . '/tasks.db'), false],
            'TaskList::getTask' => [TaskList::class, 'getTask', 'task', ['nope'], static fn (string $d): object => new TaskList($d . '/tasks.db'), false],
            'TaskList::getTasksByStatus' => [TaskList::class, 'getTasksByStatus', 'tasksByStatus', [TaskStatus::Pending], static fn (string $d): object => new TaskList($d . '/tasks.db'), false],
            'TaskList::getTasksForTeammate' => [TaskList::class, 'getTasksForTeammate', 'tasksForTeammate', ['tm'], static fn (string $d): object => new TaskList($d . '/tasks.db'), false],
            'TaskList::getUnblockedTasks' => [TaskList::class, 'getUnblockedTasks', 'unblockedTasks', ['tm'], static fn (string $d): object => new TaskList($d . '/tasks.db'), false],
            'Mailbox::getUnreadCount' => [Mailbox::class, 'getUnreadCount', 'unreadCount', ['tm'], static fn (string $d): object => new Mailbox($d), false],
            'Teammate::getInboxPath' => [Teammate::class, 'getInboxPath', 'inboxPath', [], static fn (string $d): object => new Teammate('t1', 'team1', 'Name', AgentType::Coder, 'model', []), false],
            'Teammate::getStatus' => [Teammate::class, 'getStatus', 'status', [], static fn (string $d): object => new Teammate('t1', 'team1', 'Name', AgentType::Coder, 'model', []), false],
            'TeamManager::getTeams' => [TeamManager::class, 'getTeams', 'teams', [], static fn (string $d): object => new TeamManager($d . '/teams'), false],
            'TeamManager::getTeam' => [TeamManager::class, 'getTeam', 'team', ['nope'], static fn (string $d): object => new TeamManager($d . '/teams'), false],
            'TeamManager::getTeamConfig' => [TeamManager::class, 'getTeamConfig', 'teamConfig', ['nope'], static fn (string $d): object => new TeamManager($d . '/teams'), false],
            'HookRegistry::getForEvent' => [HookRegistry::class, 'getForEvent', 'forEvent', ['nope.event'], static fn (string $d): object => new HookRegistry(), false],
            'GitOperationResult::getOutputOrNull' => [GitOperationResult::class, 'getOutputOrNull', 'outputOrNull', [], static fn (string $d): object => new GitOperationResult(true, 'out', null, 'op', 'grp'), false],
            'McpAuthStore::getServerStatus' => [McpAuthStore::class, 'getServerStatus', 'serverStatus', ['https://example.invalid/mcp'], static fn (string $d): object => new McpAuthStore(new OAuthClientRegistration(null, $d . '/auth.json')), false],
            'OAuthClientRegistration::getValidAuth' => [OAuthClientRegistration::class, 'getValidAuth', 'validAuth', ['https://s', 'https://t'], static fn (string $d): object => new OAuthClientRegistration(null, $d . '/auth2.json'), false],
            'SessionStore::getSession' => [SessionStore::class, 'getSession', 'session', ['nope'], static fn (string $d): object => new SessionStore($d . '/sessions.db'), false],
            'SessionStore::getSessionByName' => [SessionStore::class, 'getSessionByName', 'sessionByName', ['nope'], static fn (string $d): object => new SessionStore($d . '/sessions.db'), false],
            'SessionStore::getMessages' => [SessionStore::class, 'getMessages', 'messages', ['nope'], static fn (string $d): object => new SessionStore($d . '/sessions.db'), false],
            'SessionStore::getPdo' => [SessionStore::class, 'getPdo', 'pdo', [], static fn (string $d): object => new SessionStore($d . '/sessions.db'), false],
            'EnhancedSessionStore::getSession' => [EnhancedSessionStore::class, 'getSession', 'session', ['nope'], static fn (string $d): object => new EnhancedSessionStore($d . '/enh.db'), false],
            'EnhancedSessionStore::getSessionByName' => [EnhancedSessionStore::class, 'getSessionByName', 'sessionByName', ['nope'], static fn (string $d): object => new EnhancedSessionStore($d . '/enh.db'), false],
            'EnhancedSessionStore::getMessages' => [EnhancedSessionStore::class, 'getMessages', 'messages', ['nope'], static fn (string $d): object => new EnhancedSessionStore($d . '/enh.db'), false],
            'EnhancedSessionStore::getSessionMeta' => [EnhancedSessionStore::class, 'getSessionMeta', 'sessionMeta', ['nope'], static fn (string $d): object => new EnhancedSessionStore($d . '/enh.db'), false],
            'EnhancedSessionStore::getCheckpoint' => [EnhancedSessionStore::class, 'getCheckpoint', 'checkpoint', ['nope', 0], static fn (string $d): object => new EnhancedSessionStore($d . '/enh.db'), false],
            'SkillRegistry::getForPaths' => [SkillRegistry::class, 'getForPaths', 'forPaths', [[]], static fn (string $d): object => new SkillRegistry(), false],
            'MenuBar::getActiveItem' => [MenuBar::class, 'getActiveItem', 'activeItem', [], static fn (string $d): ?object => null, true],
            'MenuBar::getActiveMenu' => [MenuBar::class, 'getActiveMenu', 'activeMenu', [], static fn (string $d): ?object => null, true],
            'MenuBar::getMenuItems' => [MenuBar::class, 'getMenuItems', 'menuItems', ['nope-menu'], static fn (string $d): ?object => null, true],
            'Renderer::getTerminalSize' => [TuiRenderer::class, 'getTerminalSize', 'terminalSize', [], static fn (string $d): ?object => null, true],
            'StallDetector::getStallWarnings' => [StallDetector::class, 'getStallWarnings', 'stallWarnings', [], static fn (string $d): object => new StallDetector(), false],
            'MultiplexerSplitPane::getMultiplexerType' => [MultiplexerSplitPane::class, 'getMultiplexerType', 'multiplexerType', [], static fn (string $d): object => new MultiplexerSplitPane('', ''), false],
        ];
    }

    /**
     * @dataProvider runtimeEraPairs
     *
     * @param class-string    $fqcn
     * @param list<mixed>     $args
     * @param ?callable(string): ?object $fixture
     */
    public function testTheLegacyNameAnswersExactlyWhatTheBareNameAnswers(
        string $fqcn,
        string $legacy,
        string $bare,
        array $args,
        ?callable $fixture,
        bool $isStatic,
    ): void {
        $dir = (string) realpath(sys_get_temp_dir()) . '/candy-era-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $target = $fixture === null ? null : $fixture($dir);

        if ($isStatic) {
            $legacyAnswer = $fqcn::$legacy(...$args);
            $bareAnswer = $fqcn::$bare(...$args);
        } else {
            self::assertNotNull($target, 'runtime pair needs a fixture');
            $legacyAnswer = $target->$legacy(...$args);
            $bareAnswer = $target->$bare(...$args);
        }

        if (is_object($legacyAnswer)) {
            self::assertSame($legacyAnswer, $bareAnswer, $fqcn . "::{$legacy}() must hand back the identical object as {$bare}()");

            return;
        }
        self::assertSame($legacyAnswer, $bareAnswer, $fqcn . "::{$legacy}() drifted from {$bare}()");
    }

    /**
     * @dataProvider eraPairs
     */
    public function testEveryDeprecatedForwarderIsMarkedSingleLineAndDelegates(
        string $fqcn,
        string $path,
        string $legacy,
        string $bare,
        bool $isStatic,
    ): void {
        $lines = file(__DIR__ . '/../../src/' . $path);
        self::assertNotFalse($lines, 'missing source file ' . $path);

        $forwarderLines = array_values(array_filter(
            $lines,
            static fn (string $l): bool => (bool) preg_match('/^    public ' . ($isStatic ? 'static ' : '') . 'function ' . preg_quote($legacy, '/') . '\(.*\{ return /', $l),
        ));
        self::assertCount(1, $forwarderLines, "$fqcn::$legacy must be exactly one single-line forwarder");

        $shape = '/^    public ' . ($isStatic ? 'static ' : '') . 'function ' . preg_quote($legacy, '/')
            . '\([^)]*\)(?:: [^{]*)? \{ return ' . ($isStatic ? 'self::' : '\$this->') . preg_quote($bare, '/') . '\((?:\$\w+(?:, \$\w+)*)?\); \}$/';
        self::assertSame(1, preg_match($shape, rtrim($forwarderLines[0], "\n")), 'forwarder line drifted from the house shape: ' . rtrim((string) $forwarderLines[0]));

        // The docblock riding the forwarder carries the mark.
        $idx = array_search($forwarderLines[0], $lines, true);
        $window = implode('', array_slice($lines, max(0, (int) $idx - 10), (int) $idx));
        self::assertStringContainsString('@deprecated', $window, "$fqcn::$legacy lost its @deprecated tag");
        self::assertStringContainsString(self::MARKER, $window, "$fqcn::$legacy lost its removal stamp");
        self::assertStringContainsString('{@see self::' . $bare . '()}', $window, "$fqcn::$legacy must point at {$bare}()");

        // The bare twin is public, unmarked, and shaped the same.
        $legacyRef = new \ReflectionMethod($fqcn, $legacy);
        $bareRef = new \ReflectionMethod($fqcn, $bare);
        self::assertTrue($bareRef->isPublic(), "$fqcn::$bare must stay public");
        self::assertSame($legacyRef->getNumberOfParameters(), $bareRef->getNumberOfParameters(), "$fqcn: parameter drift between $legacy and $bare");
        self::assertSame((string) $legacyRef->getReturnType(), (string) $bareRef->getReturnType(), "$fqcn: return type drift between $legacy and $bare");
        self::assertSame($isStatic, $legacyRef->isStatic(), "$fqcn::$legacy static-ness drifted");

        $bareWindow = '';
        foreach ($lines as $n => $line) {
            if (preg_match('/^    public ' . ($isStatic ? 'static ' : '') . 'function ' . preg_quote($bare, '/') . '\(/', $line)) {
                $bareWindow .= $line;
            }
        }
        self::assertNotSame('', $bareWindow, "$fqcn::$bare declaration vanished from the file");
        self::assertStringNotContainsString(self::MARKER, $bareWindow, "the bare twin $fqcn::$bare must not itself be deprecated");
    }

    public function testTheMarkedForwarderCensusEqualsTheRosterExactly(): void
    {
        $marked = $this->collectPublicGettersWithMarker();
        sort($marked);
        $roster = array_keys(self::eraPairs());
        sort($roster);
        self::assertSame($roster, $marked, 'the marked-forwarder census and the era roster moved apart — add/remove rows in lockstep');
        self::assertCount(55, $marked, 'the era carries exactly 55 marked forwarders');
    }

    public function testNoUnmarkedPublicGetAccessorSurvivesOutsideTheCarveOuts(): void
    {
        $offenders = [];
        foreach ($this->srcFiles() as $file) {
            $code = (string) file_get_contents($file);
            $relative = substr($file, (int) strpos($file, '/src/'));
            foreach ($this->publicGetDeclarations($code) as [$class, $name, $docblock]) {
                if (!str_contains($docblock, self::MARKER)) {
                    $offenders[] = $relative . ' ## ' . $class . '::' . $name;
                }
            }
        }
        foreach ($offenders as $i => $offender) {
            $short = substr($offender, (int) strpos($offender, ' ## ') + 4);
            if (in_array($short, self::NAMED_CARVE_OUTS, true)) {
                unset($offenders[$i]);
            }
        }
        self::assertSame([], array_values($offenders), 'unmarked public get* accessor(s) found — era them or add a documented carve-out');
    }

    public function testOwnInterfacesWereRewrittenOutright(): void
    {
        self::assertTrue(method_exists(McpPromptProvider::class, 'prompt'));
        self::assertFalse(method_exists(McpPromptProvider::class, 'getPrompt'), 'the interface contract must not keep the legacy name');
        self::assertTrue(method_exists(WorkflowEngineInterface::class, 'status'));
        self::assertFalse(method_exists(WorkflowEngineInterface::class, 'getStatus'), 'the interface contract must not keep the legacy name');
    }

    public function testTheReadmeTableTiesTheRosterExactly(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $sections = preg_split('/^## /m', $readme);
        $section = null;
        foreach ((array) $sections as $s) {
            if (str_starts_with((string) $s, 'Accessor deprecation')) {
                $section = (string) $s;
                break;
            }
        }
        self::assertNotNull($section, 'README lost its Accessor deprecation section');
        self::assertSame(1, preg_match('/^Accessor deprecation \(2026-10-24\)/m', $section), 'the section heading lost its date stamp');

        preg_match_all('/^\| [^|]* \| `[^`]+` \| `(get[A-Za-z]+)\(\)` \| `([a-z][A-Za-z]+)\(\)` \|$/m', $section, $rows, PREG_SET_ORDER);
        self::assertCount(count(self::eraPairs()), $rows, 'README table row count drifted from the era roster');

        $fromReadme = [];
        foreach ($rows as $r) {
            $fromReadme[] = $r[1] . '|' . $r[2];
        }
        sort($fromReadme);
        $fromRoster = [];
        foreach (self::eraPairs() as [$fqcn, $path, $legacy, $bare]) {
            $fromRoster[] = $legacy . '|' . $bare;
        }
        sort($fromRoster);
        self::assertSame($fromRoster, $fromReadme, 'the README table and the era roster must tie pair-for-pair');
    }

    /**
     * @return list<string> every 'ClassShort::legacy' whose docblock carries MARKER
     */
    private function collectPublicGettersWithMarker(): array
    {
        $out = [];
        foreach ($this->srcFiles() as $file) {
            $code = (string) file_get_contents($file);
            foreach ($this->publicGetDeclarations($code) as [$class, $name, $docblock]) {
                if (str_contains($docblock, self::MARKER)) {
                    $out[] = $class . '::' . $name;
                }
            }
        }
        return $out;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [class short name, method name, riding docblock]
     */
    private function publicGetDeclarations(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $found = [];
        $n = count($tokens);
        $currentClass = '';
        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i]->is(T_CLASS) || $tokens[$i]->is(T_INTERFACE)) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($tokens[$j]->is(T_WHITESPACE)) {
                        continue;
                    }
                    if ($tokens[$j]->is(T_STRING)) {
                        $currentClass = $tokens[$j]->text;
                    }
                    break;
                }
                continue;
            }
            if ($tokens[$i]->id !== T_FUNCTION) {
                continue;
            }
            $nameTok = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($tokens[$j]->is(T_WHITESPACE)) {
                    continue;
                }
                if ($tokens[$j]->is(T_STRING)) {
                    $nameTok = $tokens[$j];
                }
                break;
            }
            if ($nameTok === null || !preg_match('/^get[A-Z]/', $nameTok->text)) {
                continue;
            }
            $isPublic = false;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($tokens[$j]->is(T_WHITESPACE)) {
                    continue;
                }
                if ($tokens[$j]->is([T_PUBLIC, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY])) {
                    $isPublic = $isPublic || $tokens[$j]->is(T_PUBLIC);
                    continue;
                }
                break;
            }
            if (!$isPublic) {
                continue;
            }
            $docblock = '';
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_PUBLIC, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY])) {
                    continue;
                }
                if ($tokens[$j]->is(T_DOC_COMMENT)) {
                    $docblock = $tokens[$j]->text;
                }
                break;
            }
            $found[] = [$currentClass, $nameTok->text, $docblock];
        }
        return $found;
    }

    /**
     * @return list<string> absolute paths of every PHP file under src/
     */
    private function srcFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
