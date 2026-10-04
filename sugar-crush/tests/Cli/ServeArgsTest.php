<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Help;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Cli\ParsedArgs;
use SugarCraft\Crush\Cli\Serve;
use SugarCraft\Crush\Cli\Subcommands;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Server\Preflight;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\ServerConfigException;

/**
 * `sugarcrush serve`'s argv and configuration (Appendix O §4.7, §8.3, §8.4):
 * the scoped flags, the flag → variable → user-key → default precedence, the
 * refusals that happen before anything binds, and the help screen naming every
 * flag the parser accepts for the verb.
 */
final class ServeArgsTest extends TestCase
{
    /** @param list<string> $argv */
    private static function parse(array $argv): ParsedArgs
    {
        return ArgvParser::parse(['sugarcrush', ...$argv]);
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     * @param array<string, mixed>  $user
     */
    private static function config(array $argv, array $env = [], array $user = []): ServerConfig
    {
        return ServerConfig::resolve(self::parse($argv)->subcommandFlags, $env, $user, '/home/me/.sugar-crush/server', self::parse($argv)->permissionMode);
    }

    public function testServeIsAVerbWithItsOwnScopedFlags(): void
    {
        $args = self::parse(['serve', '--port', '9000', '--host=::1', '--no-web', '--allow-bypass', '--allowed-origin', 'http://a.example,http://b.example']);

        self::assertSame('serve', $args->subcommand);
        self::assertSame([], $args->unknownFlags);
        self::assertNull($args->usageError);
        self::assertSame(
            ['--port' => '9000', '--host' => '::1', '--no-web' => true, '--allow-bypass' => true, '--allowed-origin' => 'http://a.example,http://b.example'],
            $args->subcommandFlags,
        );
        self::assertContains('serve', ParsedArgs::SUBCOMMANDS);
    }

    public function testAServeFlagBeforeTheVerbOrBehindAnotherVerbIsUnknown(): void
    {
        self::assertSame(['--port'], self::parse(['--port', '1', 'serve'])->unknownFlags);
        self::assertSame(['--allow-remote'], self::parse(['doctor', '--allow-remote'])->unknownFlags);
    }

    public function testValueFlagsNeedAValueAndSwitchesTakeNone(): void
    {
        self::assertSame('sugarcrush: serve --port expects a value, but the argument list ended', self::parse(['serve', '--port'])->usageError);
        self::assertSame('sugarcrush: serve --host expects a value, but the next argument is the option --no-web', self::parse(['serve', '--host', '--no-web'])->usageError);
        self::assertSame('sugarcrush: serve --allow-root takes no value', self::parse(['serve', '--allow-root=yes'])->usageError);
    }

    public function testTheDefaultsAreLoopback7420DefaultModeAndNothingWidened(): void
    {
        $config = self::config(['serve']);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(7420, $config->port);
        self::assertSame(ServerConfig::DEFAULT_PORT, $config->port);
        self::assertTrue($config->isLoopback());
        self::assertSame('tcp://127.0.0.1:7420', $config->bindUri());
        self::assertSame(PermissionMode::Default, $config->permissionMode);
        self::assertFalse($config->allowRemote);
        self::assertFalse($config->allowBypass);
        self::assertFalse($config->allowRoot);
        self::assertTrue($config->web);
        self::assertSame([], $config->allowedOrigins);
        self::assertSame([], $config->problems());
        self::assertSame('/home/me/.sugar-crush/server', $config->stateDir);
    }

    public function testFlagBeatsVariableBeatsUserKeyBeatsDefault(): void
    {
        $env = ['SUGARCRUSH_SERVER_PORT' => '8001', 'SUGARCRUSH_SERVER_HOST' => '::1', 'SUGARCRUSH_SERVER_ALLOWED_ORIGINS' => 'http://env.example'];
        $user = ['server.port' => 8002, 'server.host' => '127.0.0.2', 'server.allowedOrigins' => ['http://key.example']];

        $flag = self::config(['serve', '--port', '8000', '--allowed-origin', 'http://flag.example'], $env, $user);
        self::assertSame(8000, $flag->port);
        self::assertSame('::1', $flag->host);
        self::assertSame(['http://flag.example'], $flag->allowedOrigins, 'lists are replaced, not merged');
        self::assertSame('tcp://[::1]:8000', $flag->bindUri());

        $variable = self::config(['serve'], $env, $user);
        self::assertSame(8001, $variable->port);
        self::assertSame(['http://env.example'], $variable->allowedOrigins);

        $key = self::config(['serve'], [], $user);
        self::assertSame(8002, $key->port);
        self::assertSame('127.0.0.2', $key->host);
        self::assertTrue($key->isLoopback(), 'all of 127/8 is loopback');
        self::assertSame(['http://key.example'], $key->allowedOrigins);
    }

    public function testTheUserOnlyKeysAndTheStateDirectory(): void
    {
        $config = self::config(
            ['serve'],
            ['SUGARCRUSH_SERVER_DIR' => '/srv/state/', 'SUGARCRUSH_SERVER_WEB_ROOT' => '/srv/ui'],
            ['server.allowedHosts' => ['Agent.Example.com'], 'server.trustedProxies' => ['127.0.0.1', '10.0.0.0/8'], 'server.allowBypass' => true],
        );

        self::assertSame('/srv/state', $config->stateDir);
        self::assertSame('/srv/ui', $config->webRoot);
        self::assertSame(['agent.example.com'], $config->allowedHosts);
        self::assertSame(['127.0.0.1', '10.0.0.0/8'], $config->trustedProxies);
        self::assertTrue($config->allowBypass);
        self::assertSame('/flag/ui', self::config(['serve', '--web-root', '/flag/ui/'], ['SUGARCRUSH_SERVER_WEB_ROOT' => '/srv/ui'])->webRoot);
    }

    /**
     * @return array<string, array{0: list<string>, 1: array<string, string>, 2: array<string, mixed>, 3: string}>
     */
    public static function malformed(): array
    {
        return [
            'port word' => [['serve', '--port', 'http'], [], [], 'is not a number'],
            'port range' => [['serve', '--port', '70000'], [], [], 'outside 0-65535'],
            'host name' => [['serve', '--host', 'example.com'], [], [], 'is not an IP address'],
            'origin path' => [['serve', '--allowed-origin', 'http://a.example/app'], [], [], 'is not of the form'],
            'origin scheme' => [['serve', '--allowed-origin', 'ftp://a.example'], [], [], 'is not of the form'],
            'origin userinfo' => [['serve', '--allowed-origin', 'http://u:p@a.example'], [], [], 'is not of the form'],
            'mode' => [['--permission-mode', 'yolo', 'serve'], [], [], 'is not one of'],
            'env mode' => [['serve'], ['SUGARCRUSH_PERMISSION_MODE' => 'nope'], [], 'is not one of'],
            'proxy' => [['serve'], [], ['server.trustedProxies' => ['10.0.0.0/99']], 'is not an IP address or CIDR'],
            'allowed host' => [['serve'], [], ['server.allowedHosts' => ['a b']], 'is not a host name'],
            'bypass key type' => [['serve'], [], ['server.allowBypass' => 'yes'], 'must be true or false'],
            'list type' => [['serve'], [], ['server.allowedOrigins' => [1]], 'list of strings'],
        ];
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     * @param array<string, mixed>  $user
     *
     * @dataProvider malformed
     */
    public function testAMalformedValueIsRefusedWithItsReason(array $argv, array $env, array $user, string $why): void
    {
        $this->expectException(ServerConfigException::class);
        $this->expectExceptionMessage($why);
        self::config($argv, $env, $user);
    }

    public function testANonLoopbackBindNeedsAllowRemote(): void
    {
        $lan = self::config(['serve', '--host', '0.0.0.0']);
        self::assertFalse($lan->isLoopback());
        self::assertCount(1, $lan->problems());
        self::assertStringContainsString('--allow-remote', $lan->problems()[0]);

        self::assertSame([], self::config(['serve', '--host', '0.0.0.0', '--allow-remote'])->problems());
        self::assertSame([], self::config(['serve', '--host', 'localhost'])->problems(), 'localhost is loopback');
    }

    public function testTheBypassModesNeedAllowBypass(): void
    {
        foreach ([PermissionMode::BypassPermissions, PermissionMode::DontAsk] as $mode) {
            $refused = self::config(['--permission-mode', $mode->value, 'serve']);
            self::assertFalse($refused->admitsPermissionMode($mode));
            self::assertStringContainsString('--allow-bypass', $refused->problems()[0] ?? '');

            self::assertSame([], self::config(['--permission-mode', $mode->value, 'serve', '--allow-bypass'])->problems());
            self::assertSame([], self::config(['--permission-mode', $mode->value, 'serve'], [], ['server.allowBypass' => true])->problems());
        }

        foreach ([PermissionMode::Default, PermissionMode::AcceptEdits, PermissionMode::Plan, PermissionMode::Auto] as $mode) {
            self::assertTrue(self::config(['serve'])->admitsPermissionMode($mode), $mode->value);
        }
        self::assertSame(PermissionMode::Plan, self::config(['serve'], ['SUGARCRUSH_PERMISSION_MODE' => 'plan'])->permissionMode);
        self::assertSame(PermissionMode::Auto, self::config(['--permission-mode', 'auto', 'serve'], ['SUGARCRUSH_PERMISSION_MODE' => 'plan'])->permissionMode, 'the flag wins');
    }

    public function testPreflightRefusesMissingExtensionsAndRoot(): void
    {
        $config = self::config(['serve']);

        self::assertSame([], Preflight::of(true, true, true, 1000)->problems($config));

        $bare = Preflight::of(false, false, false, 0)->problems($config);
        self::assertCount(4, $bare);
        self::assertStringContainsString('ext-pcntl', $bare[0]);
        self::assertStringContainsString('ext-posix', $bare[1]);
        self::assertStringContainsString('ext-ffi', $bare[2]);
        self::assertStringContainsString('root', $bare[3]);

        self::assertSame([], Preflight::of(true, true, true, 0)->problems($config->withAllowRoot(true)));
        self::assertSame([], Preflight::of(true, true, true, 0)->environmentProblems(), 'root is not an environment problem');
        self::assertSame(['--allow-root' => true], self::parse(['serve', '--allow-root'])->subcommandFlags);
        self::assertTrue(self::config(['serve', '--allow-root'])->allowRoot);
    }

    public function testServeRefusesAnOperandAndAnUnservableConfigAtExitTwoWithoutBinding(): void
    {
        self::assertSame(NonInteractive::EXIT_CONFIG, Subcommands::dispatch(self::parse(['serve', 'bogus'])));
        self::assertSame(NonInteractive::EXIT_CONFIG, Serve::run(self::parse(['serve', 'status', 'extra'])));
        self::assertSame(NonInteractive::EXIT_CONFIG, Serve::run(self::parse(['serve', 'stop', '--detach'])), 'a start flag on a management action');
        self::assertSame(NonInteractive::EXIT_CONFIG, Serve::run(self::parse(['serve', '--force'])), 'a management flag on a start');
        self::assertSame(NonInteractive::EXIT_CONFIG, Serve::run(self::parse(['serve', '--parent-pid', 'abc'])));
        self::assertSame(NonInteractive::EXIT_CONFIG, Serve::run(self::parse(['serve', '--host', '192.0.2.1'])));
        self::assertSame(NonInteractive::EXIT_CONFIG, Serve::run(self::parse(['serve', '--port', 'x'])));
    }

    public function testServeConfigResolvesTheRootAndTheDefaultStateDirectory(): void
    {
        $config = Serve::config(self::parse(['--root', '/tmp', 'serve']), [], []);

        self::assertSame('/tmp', $config->root);
        self::assertStringEndsWith('/.sugar-crush/server', $config->stateDir);
        self::assertSame('/elsewhere', Serve::config(self::parse(['serve']), ['SUGARCRUSH_SERVER_DIR' => '/elsewhere'], [])->stateDir);
    }

    /**
     * The verb-scoped analogue of HelpTest's parser scrape: every flag the
     * parser accepts after `serve` is documented on the help screen, and the
     * screen names the verb itself.
     */
    public function testEveryServeFlagIsOnTheHelpScreen(): void
    {
        $screen = Help::screen();

        self::assertMatchesRegularExpression('/^  serve \[/m', $screen);
        foreach (\array_keys(ParsedArgs::SUBCOMMAND_FLAGS['serve']) as $flag) {
            self::assertMatchesRegularExpression('/^ +(?:-[a-z], )?' . \preg_quote($flag, '/') . '(?:[ =,]|$)/m', $screen, "serve {$flag} is not documented on the help screen");
        }
        foreach (Serve::ACTIONS as $action) {
            self::assertMatchesRegularExpression('/^  serve ' . $action . '\b/m', $screen, "serve {$action} is not on the help screen");
        }
    }

    /**
     * O-4a: every flag the parser accepts after `serve` belongs to exactly the
     * actions {@see Serve::ACTION_FLAGS} names, and nothing in that table is a
     * flag the parser would refuse.
     */
    public function testEveryServeFlagBelongsToAnAction(): void
    {
        $owned = \array_merge(...\array_values(Serve::ACTION_FLAGS));
        \sort($owned);
        $parsed = \array_keys(ParsedArgs::SUBCOMMAND_FLAGS['serve']);
        \sort($parsed);

        self::assertSame($parsed, $owned);
        self::assertSame(['', ...Serve::ACTIONS], \array_keys(Serve::ACTION_FLAGS));
    }

    public function testTheManagementActionsAndTheirFlagsParse(): void
    {
        $stop = self::parse(['serve', 'stop', '--force']);
        self::assertSame(['stop'], $stop->subcommandArgs);
        self::assertSame(['--force' => true], $stop->subcommandFlags);

        self::assertSame(['-f' => true], self::parse(['serve', 'logs', '-f'])->subcommandFlags);
        self::assertSame(['--rotate' => true], self::parse(['serve', 'token', '--rotate'])->subcommandFlags);
        self::assertSame(['--detach' => true, '--parent-pid' => '42'], self::parse(['serve', '--detach', '--parent-pid', '42'])->subcommandFlags);
        self::assertSame(['-f'], self::parse(['-f', 'serve', 'logs'])->unknownFlags, '-f is scoped to the verb');
    }

    public function testTheParentPidComesFromTheFlagThenTheVariable(): void
    {
        self::assertNull(Serve::parentPid(self::parse(['serve']), []));
        self::assertSame(7, Serve::parentPid(self::parse(['serve']), ['SUGARCRUSH_SERVER_PARENT_PID' => '7']));
        self::assertSame(9, Serve::parentPid(self::parse(['serve', '--parent-pid', '9']), ['SUGARCRUSH_SERVER_PARENT_PID' => '7']));

        $this->expectException(ServerConfigException::class);
        Serve::parentPid(self::parse(['serve']), ['SUGARCRUSH_SERVER_PARENT_PID' => '-3']);
    }
}
