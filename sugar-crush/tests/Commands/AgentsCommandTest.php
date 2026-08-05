<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\AgentsCommand;

/**
 * @internal
 */
final class AgentsCommandTest extends TestCase
{
    public function testAgentsCommandExists(): void
    {
        $this->assertTrue(class_exists(AgentsCommand::class));
    }

    public function testAgentsCommandIsFinal(): void
    {
        $reflection = new \ReflectionClass(AgentsCommand::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testAgentsCommandHasExecuteMethod(): void
    {
        $this->assertTrue(method_exists(AgentsCommand::class, 'execute'));
    }

    public function testAgentsCommandExecuteMethodSignature(): void
    {
        $method = new \ReflectionMethod(AgentsCommand::class, 'execute');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('chat', $params[0]->getName());
        $this->assertSame('args', $params[1]->getName());
    }

    public function testAgentsCommandReturnsIntFromExecute(): void
    {
        // Use Reflection to inspect return type without instantiating
        $method = new \ReflectionMethod(AgentsCommand::class, 'execute');
        $returnType = $method->getReturnType();
        $this->assertSame('int', $returnType->getName());
    }

    public function testAgentsCommandHasConstructorWithAgentManagerParameter(): void
    {
        $method = new \ReflectionMethod(AgentsCommand::class, '__construct');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('agentManager', $params[0]->getName());
    }
}
