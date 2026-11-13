<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol\Methods;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\Methods\WorkspaceMethods;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

final class WorkspaceMethodsTest extends TestCase
{
    public function testItRegistersTheThreeWorkspaceMethodsWithTheirScopes(): void
    {
        $registry = MethodRegistry::new();

        WorkspaceMethods::register($registry);

        self::assertEqualsCanonicalizing(['workspace.close', 'workspace.list', 'workspace.open'], $registry->names());
        self::assertSame(Scope::Read, $registry->get('workspace.list')?->scope);
        self::assertFalse($registry->get('workspace.list')?->sideEffects);
        foreach (['workspace.open', 'workspace.close'] as $write) {
            self::assertSame(Scope::Write, $registry->get($write)?->scope, $write);
            self::assertTrue($registry->get($write)?->sideEffects, $write);
        }
    }

    public function testASingleRootServerAnswersEveryWorkspaceMethodUnsupported(): void
    {
        $fixture = ProtocolFixture::new();
        try {
            $client = $fixture->client();
            foreach (['workspace.list' => [], 'workspace.open' => ['root' => '/x'], 'workspace.close' => ['root' => '/x']] as $method => $params) {
                $reply = $client->request($method, $params);

                self::assertSame(ErrorCode::UnsupportedInServer->value, $reply['error']['code'] ?? null, $method);
                self::assertSame('no_gateway', $reply['error']['data']['kind'] ?? null, $method);
            }
        } finally {
            $fixture->tearDown();
        }
    }
}
