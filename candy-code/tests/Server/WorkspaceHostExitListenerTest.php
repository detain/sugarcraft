<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Workspace\WorkspaceHostClient;

/** S-V06: a throwing exit listener is logged, and its siblings still run. */
final class WorkspaceHostExitListenerTest extends TestCase
{
    public function testAThrowingExitListenerLogsAndSiblingsStillRun(): void
    {
        $logFile = \tempnam(\sys_get_temp_dir(), 'exitlog');
        $prevLog = \ini_set('error_log', $logFile);
        $prevQuiet = \getenv('CANDY_CODE_QUIET_LOGS');
        \putenv('CANDY_CODE_QUIET_LOGS');

        try {
            $ref = new \ReflectionClass(WorkspaceHostClient::class);
            $client = $ref->newInstanceWithoutConstructor();
            $ref->getProperty('running')->setValue($client, true);
            $ran = [];
            $ref->getProperty('exitListeners')->setValue($client, [
                static function (string $r) use (&$ran): void {
                    $ran[] = 'first';
                },
                static function (string $r): void {
                    throw new \RuntimeException('listener boom');
                },
                static function (string $r) use (&$ran): void {
                    $ran[] = 'third:' . $r;
                },
            ]);
            $ref->getMethod('exited')->invoke($client, 'gone');

            self::assertSame(['first', 'third:gone'], $ran);
            $log = (string) \file_get_contents($logFile);
            self::assertStringContainsString('workspace host exit listener: RuntimeException: listener boom', $log);
        } finally {
            \ini_set('error_log', $prevLog === false ? '' : $prevLog);
            if ($prevQuiet !== false) {
                \putenv('CANDY_CODE_QUIET_LOGS=' . $prevQuiet);
            }
            @\unlink($logFile);
        }
    }
}
