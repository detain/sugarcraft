<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\ServerConfig;

/**
 * AUT-6: the mode a webhook inherits when its row names none, and the
 * AUT-6-P2 clamp from C-AGT-7.c.
 *
 * THE FLOOR, stated once and shared by its callers. `bypass-permissions`
 * answers every permission ask YES without asking anyone, so
 * {@see ServerConfig::webhooksValue()} refuses it on a hook row "even with
 * --allow-bypass" — a webhook is started by an inbound HTTP request, with no
 * human on the other end to answer. `dont-ask` answers the same ask by
 * REFUSING, so it is not refused: unattended is exactly the posture it is
 * safe in, and refusing the row that names it only taught operators to omit
 * the field — into the inheritance path, which is the other half of this
 * step. {@see ServerConfig::hookPermissionModeFallback()} clamps the
 * inheritance to the STRICTER of the server's mode and `default`: a
 * `bypass-permissions`, `auto` or `accept-edits` server lands mode-less hooks
 * on `default` (whose asks reach the approvals relay inside the
 * `server.askTimeoutSeconds` window), while a server already stricter than
 * `default` — `plan`, `dont-ask` — keeps its own mode. The old clamp
 * flattened every server to the `default` family, which WIDENED a dont-ask
 * server's hooks: the strictest row a human could name was refused at the
 * door, then handed to an inbound request anyway in a looser mode.
 *
 * The polarities are pinned in both directions: a bypass server must not leak
 * its mode to a hook, and a stricter server must not be relaxed by the clamp —
 * a clamp that flattened every mode to `default` would satisfy the red and
 * break the operator's setup just as loudly.
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

    /**
     * The AUT-6-P2 flip: a dont-ask server's mode-less hooks STAY dont-ask.
     * The server was launched in the strictest posture on purpose; handing
     * its inbound-triggered turns the looser `default` — where an ask can be
     * ANSWERED, by a human on the relay — was the widening this step removes.
     */
    public function testADontAskServerKeepsDontAskForAModelessHook(): void
    {
        $config = ServerConfig::resolve(['--allow-bypass' => true], [], [], '/state', 'dont-ask');

        self::assertSame(PermissionMode::DontAsk->value, $config->hookPermissionModeFallback());
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
    #[DataProvider('serverModesClampedToStricterOfDefault')]
    public function testAModelessHookInheritsTheStricterOfServerModeOrDefault(array $flags, string $mode, string $expected): void
    {
        $config = ServerConfig::resolve($flags, [], [], '/state', $mode);

        self::assertSame($expected, $config->hookPermissionModeFallback(), $mode);
    }

    /** @return array<string, array{0: array<string, string|true>, 1: string, 2: string}> */
    public static function serverModesClampedToStricterOfDefault(): array
    {
        // Every mode looser than `default` lands there; every mode already at
        // or stricter than `default` survives the clamp untouched. Both cases
        // run with and without `--allow-bypass` — the clamp is about the MODE.
        $clampedTo = [
            PermissionMode::Default->value => PermissionMode::Default->value,
            PermissionMode::AcceptEdits->value => PermissionMode::Default->value,
            PermissionMode::Auto->value => PermissionMode::Default->value,
            PermissionMode::Plan->value => PermissionMode::Plan->value,
        ];
        $cases = [];
        foreach ($clampedTo as $mode => $expected) {
            $cases[$mode . ' without allow-bypass'] = [[], $mode, $expected];
            $cases[$mode . ' with allow-bypass'] = [['--allow-bypass' => true], $mode, $expected];
        }

        return $cases;
    }

    /**
     * The per-row door (AUT-6-P2): bypass ALONE is refused on a hook row; the
     * other five — dont-ask included — pass, because dont-ask answers an ask
     * by refusing it and so endangers nothing a human would have to catch.
     */
    #[DataProvider('allPermissionModes')]
    public function testTheHookRowDoorRefusesBypassAlone(PermissionMode $mode): void
    {
        self::assertSame($mode === PermissionMode::BypassPermissions, ServerConfig::hookRefusesMode($mode), $mode->value);
    }

    /** @return array<string, array{0: PermissionMode}> */
    public static function allPermissionModes(): array
    {
        $cases = [];
        foreach (PermissionMode::cases() as $mode) {
            $cases[$mode->value] = [$mode];
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
