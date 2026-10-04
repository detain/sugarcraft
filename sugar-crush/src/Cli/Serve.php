<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use React\EventLoop\Loop;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\LoginCodes;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\Http\StaticFiles;
use SugarCraft\Crush\Server\Preflight;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\ServerConfigException;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * `sugarcrush serve` — the WebSocket server, in the foreground (Appendix O
 * §4.6, §4.7; roadmap O-3a).
 *
 * The one subcommand that does not answer and exit: it runs until SIGINT or
 * SIGTERM. Like every other verb it is dispatched before `Program` exists, so
 * it never touches the terminal beyond its own stderr lines.
 *
 * WHAT IT REFUSES, at exit 2 before binding anything (nothing was attempted and
 * a retry cannot help): an operand (the `status|stop|logs|url|token` sub-verbs
 * are O-4a), a malformed flag value, a non-loopback `--host` without
 * `--allow-remote`, a `--permission-mode` of `bypass-permissions` or
 * `dont-ask` without `--allow-bypass`, running as root without
 * `--allow-root`, and a PHP without pcntl, posix or FFI ({@see Preflight}).
 * A port already in use is exit 1: it ran, failed, and may succeed later
 * (under `--output-format json`, `{"result":{"listening":false,…}}`).
 *
 * WHAT IT PRINTS. stderr: the address, the root, the session permission mode
 * and a sign-in URL whose one-time code (120 s, single use) rides in the URL
 * fragment. With `--output-format json`, the same facts as ONE document on
 * stdout — for a script that starts the server and wants the URL — and stderr
 * keeps the log. The token itself is never printed.
 */
final class Serve
{
    private function __construct()
    {
    }

    public static function run(ParsedArgs $args): int
    {
        if ($args->subcommandArgs !== []) {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush serve %s: unexpected operand', $args->subcommandArgs[0]),
                $args->outputFormat,
                'Usage: sugarcrush serve [--host <ip>] [--port <n>] [--allow-remote] [--allowed-origin <origins>] [--web-root <dir>] [--no-web] [--allow-bypass] [--allow-root]',
            );
        }

        $env = self::environment();
        try {
            $config = self::config($args, $env, Bootstrap::readUserConfig());
        } catch (ServerConfigException $e) {
            return NonInteractive::failUsage('sugarcrush serve: ' . $e->getMessage(), $args->outputFormat);
        }

        $problems = Preflight::detect()->problems($config);
        if ($problems !== []) {
            return NonInteractive::failUsage(
                'sugarcrush serve: ' . $problems[0],
                $args->outputFormat,
                \count($problems) > 1 ? 'Also: ' . \implode('; ', \array_slice($problems, 1)) . '.' : null,
            );
        }

        // Every file the server creates (the token, later the state file and
        // log) is this user's alone.
        \umask(0o077);

        try {
            $tokens = TokenStore::new($config->stateDir)->withOverride($env['SUGARCRUSH_SERVER_TOKEN'] ?? null);
            $tokens->token();
            $static = StaticFiles::new($config->webRoot ?? ($config->web ? self::installedWebRoot() : null));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return NonInteractive::failUsage('sugarcrush serve: ' . $e->getMessage(), $args->outputFormat);
        }

        $log = static function (string $line): void {
            self::stderr('[' . \date('H:i:s') . '] ' . $line);
        };
        $server = Server::new($config, AuthContext::new($tokens), $static, null, Loop::get(), $log);

        try {
            $server->start();
        } catch (\RuntimeException $e) {
            self::stderr('sugarcrush serve: ' . $e->getMessage());
            // Like `doctor`'s failing report: the answer IS the failure, so the
            // one stdout document carries it as a result rather than minting a
            // new `error.type` for one condition.
            if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
                echo NonInteractive::encodeDocument(['result' => ['listening' => false, 'address' => $config->bindUri(), 'reason' => $e->getMessage()]]) . "\n";
            }

            return NonInteractive::EXIT_FAILURE;
        }

        self::announce($args, $config, $server, $static);

        $stop = null;
        $stop = static function () use ($server, &$stop): void {
            self::stderr('sugarcrush serve: stopping');
            $server->stop();
            Loop::removeSignal(\SIGINT, $stop);
            Loop::removeSignal(\SIGTERM, $stop);
            Loop::stop();
        };
        // Never SIGCHLD: the turn children are reaped by EngineBackend's own
        // sweep, and a SIGCHLD handler would race it (Appendix O §4.6).
        Loop::addSignal(\SIGINT, $stop);
        Loop::addSignal(\SIGTERM, $stop);

        Loop::run();

        return NonInteractive::EXIT_OK;
    }

    /**
     * The configuration `serve` would run with for $args, $env and the user
     * config — the whole resolution, without binding anything.
     *
     * @param array<string, string> $env
     * @param array<string, mixed>  $userConfig
     *
     * @throws ServerConfigException
     */
    public static function config(ParsedArgs $args, array $env, array $userConfig): ServerConfig
    {
        $home = HomeDirectory::owned();
        $default = $home === null ? '' : $home . '/.sugar-crush/server';
        $config = ServerConfig::resolve(
            $args->subcommandFlags,
            $env,
            $userConfig,
            $default,
            $args->permissionMode,
            $args->root ?? (\getcwd() ?: null),
        );
        if ($config->stateDir === '') {
            throw new ServerConfigException('cannot determine a home directory this user owns for the server state; set SUGARCRUSH_SERVER_DIR');
        }

        return $config;
    }

    /**
     * The variables `serve` consults, read by name rather than taken from a
     * whole-environment dump, so the set this command depends on is the list
     * below and nothing an unrelated variable could widen.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = [];
        foreach ([
            'SUGARCRUSH_SERVER_HOST',
            'SUGARCRUSH_SERVER_PORT',
            'SUGARCRUSH_SERVER_ALLOWED_ORIGINS',
            'SUGARCRUSH_SERVER_WEB_ROOT',
            'SUGARCRUSH_SERVER_TOKEN',
            'SUGARCRUSH_SERVER_DIR',
            'SUGARCRUSH_PERMISSION_MODE',
        ] as $name) {
            $value = \getenv($name);
            if (\is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * The installed `sugarcraft/sugar-crush-web` build, or null when the
     * package is absent. Looked up by name so this package carries no hard
     * dependency on it (it is a composer `suggest`).
     */
    private static function installedWebRoot(): ?string
    {
        $assets = 'SugarCraft\\CrushWeb\\Assets';
        if (!\class_exists($assets) || !\method_exists($assets, 'distPath')) {
            return null;
        }

        $path = $assets::distPath();

        return \is_string($path) && \is_dir($path) ? $path : null;
    }

    private static function announce(ParsedArgs $args, ServerConfig $config, Server $server, StaticFiles $static): void
    {
        $url = $server->url();
        $loginUrl = $server->loginUrl();

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => [
                'url' => $url,
                'loginUrl' => $loginUrl,
                'pid' => \getmypid(),
                'protocol' => ServerConfig::PROTOCOL_MAJOR,
                'root' => $config->root,
                'permissionMode' => $config->permissionMode->value,
                'web' => $static->root() !== null,
            ]]) . "\n";
        }

        $lines = [
            'sugarcrush serve: listening on ' . $url . ' (pid ' . \getmypid() . ')',
            '  root:            ' . ($config->root ?? '(none)'),
            '  permission mode: ' . $config->permissionMode->value . ($config->allowBypass ? ' (clients may choose bypass)' : ''),
            '  web UI:          ' . ($static->root() ?? 'not installed (composer require sugarcraft/sugar-crush-web)'),
            '  sign in:         ' . $loginUrl,
            '                   (one-time code, valid ' . LoginCodes::TTL_SECONDS . ' s; Ctrl+C stops the server)',
        ];
        if (!$config->isLoopback()) {
            $lines[] = '';
            $lines[] = '  !! LISTENING BEYOND THIS MACHINE (' . $config->host . ') WITHOUT TLS.';
            $lines[] = '  !! Anyone who can reach the port and learns the token can run code as you.';
            $lines[] = '  !! Put a TLS reverse proxy in front of it (see docs/SERVER.md).';
        }
        self::stderr(\implode("\n", $lines));
    }

    /**
     * The server's one stderr channel: startup lines, the request log and the
     * stop notice. Stderr alone, never the transcript seam — there is no
     * session to report into, and the terminal running `serve` IS the log.
     */
    private static function stderr(string $text): void
    {
        \fwrite(\STDERR, $text . "\n");
    }
}
