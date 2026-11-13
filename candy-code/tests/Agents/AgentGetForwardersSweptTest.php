<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\Mailbox;
use SugarCraft\Code\Agents\TaskList;
use SugarCraft\Code\Agents\Team;
use SugarCraft\Code\Agents\TeamManager;
use SugarCraft\Code\Agents\Teammate;
use SugarCraft\Code\Agents\WorktreeManager;
use SugarCraft\Code\Workflows\WorkflowEngine;

/**
 * G-03 sweep: the get*-prefixed BC forwarders on the Agents/Workflows
 * classes are gone; only the bare accessors remain.
 */
final class AgentGetForwardersSweptTest extends TestCase
{
    public function testLegacyForwardersAreGoneAndBareTwinsRemain(): void
    {
        $map = [
            AgentManager::class => ['getSubAgent' => 'subAgent', 'getTeam' => 'team', 'getTeamManager' => 'teamManager', 'getTeams' => 'teams'],
            AgentWorkerPool::class => ['getActiveCount' => 'activeCount', 'getExecutor' => 'executor', 'getQueueSize' => 'queueSize'],
            Mailbox::class => ['getUnreadCount' => 'unreadCount'],
            TaskList::class => ['getPendingTasks' => 'pendingTasks', 'getTask' => 'task', 'getTasksByStatus' => 'tasksByStatus', 'getTasksForTeammate' => 'tasksForTeammate', 'getUnblockedTasks' => 'unblockedTasks'],
            Team::class => ['getMailbox' => 'mailbox', 'getTaskList' => 'taskList', 'getTeammate' => 'teammate', 'getTeammates' => 'teammates'],
            TeamManager::class => ['getTeam' => 'team', 'getTeamConfig' => 'teamConfig', 'getTeams' => 'teams'],
            Teammate::class => ['getStatus' => 'status'],
            WorkflowEngine::class => ['getStatus' => 'status'],
            WorktreeManager::class => ['getWorktreePath' => 'worktreePath'],
        ];
        foreach ($map as $class => $pairs) {
            foreach ($pairs as $legacy => $bare) {
                self::assertFalse(method_exists($class, $legacy), "$class::$legacy must be removed");
                self::assertTrue(method_exists($class, $bare), "$class::$bare must exist");
            }
        }
    }
}
