<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\ServerConfig;

/**
 * AUT-6: the mode a webhook inherits when its row names none.
 *
 * THE FLOOR, stated once and shared by its three callers. `bypass-permissions`
 * and `dont-ask` answer every permission ask without asking anyone, so
 * {@see ServerConfig::webhooksValue()} refuses them on a hook row "even with
 * --allow-bypass" — a webhook is started by an inbound HTTP request, with no
 * human on the other end to answer. That refusal is only worth what its
 * INHERITED case is worth: a row that names no mode used to fall back through
 * `admitsPermissionMode()`, which admits the bypass class whenever the server
 * was launched with `--allow-bypass`. So `serve --allow-bypass
 * --permission-mode bypass-permissions` handed whoever holds the hook secret a
 * fully unattended bypass turn — the exact posture the refusal says a webhook
 * can never have. {@see ServerConfig::hookPermissionModeFallback()} clamps the
 * inheritance to the Default family instead, whatever the server runs in.
 *
 * The polarities are pinned in both directions: a bypass-class server must not
 * leak its mode to a hook, and an ordinary server must still pass its mode
 * through untouched — a clamp that flattened every mode to `default` would
 * satisfy the red and break the operator's setup just as loudly.
 */
final class ServerConfigHookModeInheritanceTest extends TestCase
{
    /**
     * The clamp's reason for existing: the launch posture that used to reach
     * an unattended turn through inheritance. Note `allowBypass` is TRUE here —
     * the server may run bypass for the human's own sessions, and that promise
     * does not extend to a request that carries a hook secret.
     */
    public function testABypassServerDoesNotHandBypassToAModelessHook(): void
    {
        $config = ServerConfig::resolve(['--allow-bypass' => true], [], [], '/state', 'bypass-permissions');

        self::assertTrue($config->allowBypass);
        self::assertSame(PermissionMode::BypassPermissions, $config->permissionMode);
        self::assertTrue(
            $config->admitsPermissionMode($config->permissionMode),
            'the server itself keeps the mode the operator launched it in',
        );
        self::assertSame(PermissionMode::Default->value, $config->hookPermissionModeFallback());
    }

    public function testADontAskServerDoesNotHandDontAskToAModelessHook(): void
    {
        $config = ServerConfig::resolve(['--allow-bypass' => true], [], [], '/state', 'dont-ask');

        self::assertSame(PermissionMode::Default->value, $config->hookPermissionModeFallback());
    }

    /**
     * The clamp is about the MODE, not about the admission door: a bypass-class
     * config that never got `--allow-bypass` (which {@see ServerConfig::problems()}
     * refuses to boot, so this is defence in depth) clamps the same way.
     */
    public function testTheClampDoesNotDependOnAllowBypass(): void
    {
        $config = ServerConfig::new('/state')->withPermissionMode(PermissionMode::BypassPermissions);

        self::assertFalse($config->allowBypass);
        self::assertSame(PermissionMode::Default->value, $config->hookPermissionModeFallback());
    }

    /** @param array<string, string|true> $flags */
    #[DataProvider('ordinaryServerModesInheritThemselves')]
    public function testAModelessHookInheritsANonBypassServerMode(array $flags, string $mode, string $expected): void
    {
        $config = ServerConfig::resolve($flags, [], [], '/state', $mode);

        self::assertSame($expected, $config->hookPermissionModeFallback(), $mode);
    }

    /** @return array<string, array{0: array<string, string|true>, 1: string, 2: string}> */
    public static function ordinaryServerModesInheritThemselves(): array
    {
        $cases = [];
        foreach ([PermissionMode::Default, PermissionMode::AcceptEdits, PermissionMode::Plan, PermissionMode::Auto] as $mode) {
            $cases[$mode->value . ' without allow-bypass'] = [[], $mode->value, $mode->value];
            $cases[$mode->value . ' with allow-bypass'] = [['--allow-bypass' => true], $mode->value, $mode->value];
        }

        return $cases;
    }

    #[DataProvider('askAnsweringModes')]
    public function testTheBypassClassPredicateNamesExactlyTwoOfTheSix(PermissionMode $mode, bool $expected): void
    {
        self::assertSame($expected, ServerConfig::answersAsksWithoutAHuman($mode), $mode->value);
    }

    /** @return array<string, array{0: PermissionMode, 1: bool}> */
    public static function askAnsweringModes(): array
    {
        $cases = [];
        foreach (PermissionMode::cases() as $mode) {
            $cases[$mode->value] = [
                $mode,
                $mode === PermissionMode::BypassPermissions || $mode === PermissionMode::DontAsk,
            ];
        }

        return $cases;
    }

    /**
     * The application point, pinned structurally because it is an inline
     * closure over the live supervisor and no in-process driver reaches it.
     * The webhook's own mode still wins; only the `?? ` fallback may be
     * clamped — and the fallback must be the clamp, never the admission door,
     * which is the line that let the bypass class through.
     */
    public function testTheServeHookRouteUsesTheClampAndNotTheAdmissionDoor(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Serve.php');
        $start = \strpos($source, 'HookWebhooks::new(');
        self::assertIsInt($start, 'the webhook route is mounted in Serve.php');
        $end = \strpos($source, 'spawnSession(', $start);
        self::assertIsInt($end, 'the hook run closure spawns through the background supervisor');
        $closure = \substr($source, $start, $end - $start);

        self::assertStringContainsString('$hook[\'permissionMode\']', $closure, "the row's own mode is read first");
        self::assertStringContainsString('hookPermissionModeFallback()', $closure, 'the fallback is the clamp');
        self::assertStringNotContainsString(
            'admitsPermissionMode',
            $closure,
            'and never the launch-admission door: it admits the bypass class under --allow-bypass',
        );
    }
}
