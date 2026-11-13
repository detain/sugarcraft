<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use SugarCraft\Code\Cli\Serve\ServeManagement;
use SugarCraft\Code\Cli\Serve\ServeStartup;
use SugarCraft\Code\Host\Commands\BackgroundCommand;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\Capability\MediaAffordance;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\LoginCodes;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Auth\ViewerTokens;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\Http\CanvasLinks;
use SugarCraft\Code\Server\Http\CanvasPageController;
use SugarCraft\Code\Server\Http\HookWebhooks;
use SugarCraft\Code\Server\Http\SharePageController;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\InterfaceAddresses;
use SugarCraft\Code\Server\Listener;
use SugarCraft\Code\Server\LogTail;
use SugarCraft\Code\Server\Pairing\QrEncoder;
use SugarCraft\Code\Server\Pairing\QrTerminal;
use SugarCraft\Code\Server\ParentPidWatchdog;
use SugarCraft\Code\Server\Preflight;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\ServerConfigException;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Share\ShareSession;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Support\Daemonize;
use SugarCraft\Code\Support\Directories\DirectoryBrowser;
use SugarCraft\Code\Support\Directories\DirectoryBrowserException;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * `candy-code serve` — the WebSocket server, in the foreground or detached,
 * and the verbs that manage a running one (Appendix O §4.6, §4.7; roadmap
 * O-3a, O-4a).
 *
 *     serve [flags] [--detach] [--parent-pid <pid>]
 *     serve status | stop [--force] | logs [-f] | url | token [--rotate]
 *
 * Like every other verb it is dispatched before `Program` exists, so it never
 * touches the terminal beyond its own stderr lines.
 *
 * STARTING. Refused at exit 2 before binding anything (nothing was attempted
 * and a retry cannot help): an unknown action or a stray operand, a flag that
 * does not belong to the action, a malformed flag value, a non-loopback
 * `--host` without `--allow-remote`, a `--permission-mode` of
 * `bypass-permissions` or `dont-ask` without `--allow-bypass`, running as root
 * without `--allow-root`, a PHP without pcntl, posix or FFI ({@see Preflight}),
 * an unsafe state directory, and a `--parent-pid` that is not running. A port
 * already in use, or a server already running from the same state directory
 * (the singleton lock is held), is exit 1: it ran, failed, and may succeed
 * later (under `--output-format json`, `{"result":{"listening":false,…}}`).
 *
 * WHAT IT PRINTS. stderr: the address, the root, the session permission mode
 * and a sign-in URL whose one-time code (120 s, single use) rides in the URL
 * fragment. With `--output-format json`, the same facts as ONE document on
 * stdout. `--detach` prints the same once the background server has bound its
 * port, then returns: the URL it prints is one that answers.
 *
 * WHAT IT SERVES: the `candy-code.v1` protocol (roadmap O-3b) over the
 * served root's workspace — the same {@see Bootstrap::workspace()} bundle a
 * TUI session is built on, its sessions driven through a
 * {@see \SugarCraft\Code\Host\SessionHub} and answered by the
 * {@see Dispatcher} ({@see protocol()}).
 *
 * WHILE IT RUNS it holds `server.lock`, keeps `server.json` (the discovery
 * record `status`/`stop`/`attach` read) and answers `serve url` on a 0600
 * control socket in the 0700 state directory ({@see StateDir}). SIGTERM or
 * SIGINT drains it: tell every client (`server.shutdown`), stop accepting,
 * close every WebSocket with 1001, release every session, remove the record
 * and the socket, release the lock. Guaranteed half: the record and socket
 * never outlive a SIGTERM/SIGINT ({@see Serve\ServeResidueGuard} covers the
 * boot window before the loop owns the signals); the `server.shutdown` frame
 * and 1001 closes need the loop to reach its drain handler.
 */
final class Serve
{
    use ServeManagement;
    use ServeStartup;

    /** The management verbs; none of them starts a server. */
    public const ACTIONS = ['status', 'stop', 'logs', 'url', 'token'];

    /**
     * The {@see ParsedArgs::SUBCOMMAND_FLAGS} each action accepts, keyed by
     * action ('' = starting a server). A flag outside its action's list is a
     * usage error rather than silently ignored.
     *
     * @var array<string, list<string>>
     */
    public const ACTION_FLAGS = [
        '' => ['--allow-bypass', '--allow-dir-browse', '--allow-remote', '--allow-root', '--allowed-host', '--allowed-ips', '--allowed-origin', '--browse-root', '--detach', '--host', '--no-web', '--parent-pid', '--port', '--tls-cert', '--tls-key', '--web-root'],
        'status' => [],
        'stop' => ['--force'],
        'logs' => ['--follow', '-f'],
        'url' => ['--qr'],
        'token' => ['--rotate'],
    ];

    /**
     * How long `stop` waits after SIGTERM for the server to drain before
     * SIGKILL — at least, and longer when `server.drainSeconds` asks the
     * server to wait longer ({@see stopWaitSeconds()}).
     */
    public const STOP_DRAIN_SECONDS = 30.0;

    /** What `stop` allows beyond the configured drain for the rest of the shutdown. */
    public const STOP_DRAIN_MARGIN_SECONDS = 5.0;

    /** How long `stop` waits for the kernel to take a SIGKILLed server away. */
    public const STOP_KILL_WAIT_SECONDS = 5.0;

    /** How long `--detach` waits for the background server to report its URL. */
    public const DETACH_REPORT_SECONDS = 20.0;

    /** How often a detached server checks whether its log needs rotating. */
    public const LOG_ROTATE_CHECK_SECONDS = 60.0;

    /** Lines `serve logs` prints before following (or exiting). */
    public const LOG_TAIL_LINES = 100;

    /** The control-socket request `serve url` sends: `login-url <token>`. */
    private const CONTROL_LOGIN_URL = 'login-url';

    /**
     * The control-socket request `serve token --rotate` sends, carrying the
     * NEW token: `reload-token <token>`. The server re-reads the token file
     * first, so the request proves it was sent by someone who can read it.
     */
    private const CONTROL_RELOAD_TOKEN = 'reload-token';

    private function __construct()
    {
    }

    public static function run(ParsedArgs $args): int
    {
        $action = $args->subcommandArgs[0] ?? '';
        if ($action !== '' && !\in_array($action, self::ACTIONS, true)) {
            return NonInteractive::failUsage(Lang::t('cli.serve.unknown_action', ['action' => $action]), $args->outputFormat, Lang::t('cli.serve.usage'));
        }
        if (\count($args->subcommandArgs) > 1) {
            return NonInteractive::failUsage(
                Lang::t('cli.serve.unexpected_operand', ['action' => $action, 'operand' => $args->subcommandArgs[1]]),
                $args->outputFormat,
                Lang::t('cli.serve.usage'),
            );
        }
        foreach (\array_keys($args->subcommandFlags) as $flag) {
            if (!\in_array($flag, self::ACTION_FLAGS[$action], true)) {
                return NonInteractive::failUsage(
                    $action === ''
                        ? Lang::t('cli.serve.flag_not_for_start', ['flag' => $flag])
                        : Lang::t('cli.serve.flag_not_for_action', ['action' => $action, 'flag' => $flag]),
                    $args->outputFormat,
                    Lang::t('cli.serve.usage'),
                );
            }
        }

        $env = self::environment();

        return match ($action) {
            '' => self::start($args, $env),
            'status' => self::status($args, $env),
            'stop' => self::stop($args, $env),
            'logs' => self::logs($args, $env),
            'url' => self::url($args, $env),
            'token' => self::token($args, $env),
        };
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
        $config = ServerConfig::resolve(
            $args->subcommandFlags,
            $env,
            $userConfig,
            self::defaultStateDir(),
            $args->permissionMode,
            $args->root ?? (\getcwd() ?: null),
        );
        if ($config->stateDir === '') {
            throw new ServerConfigException(Lang::t('cli.serve.no_home'));
        }
        // Read once, here: the guard answers to these and the sign-in URLs
        // name them, since `0.0.0.0` is not an address a browser can open.
        if ($config->isWildcard() && $config->allowRemote) {
            $config = $config->withInterfaceAddresses(InterfaceAddresses::detect());
        }
        // The browse root is resolved once, here, so a typo is a startup
        // refusal (exit 2) rather than every picker failing later.
        if ($config->dirBrowse) {
            $root = $config->browseRoot ?? HomeDirectory::resolved();
            if ($root === null) {
                throw new ServerConfigException(Lang::t('serve.browse_root.no_home'));
            }
            try {
                $config = $config->withBrowseRoot((string) DirectoryBrowser::new($root)->root());
            } catch (DirectoryBrowserException $e) {
                throw new ServerConfigException(Lang::t('serve.browse_root.invalid', ['root' => $root, 'reason' => $e->getMessage()]));
            }
        }

        return $config;
    }

    /**
     * The parent pid a launch watches: `--parent-pid`, else
     * `CANDY_CODE_SERVER_PARENT_PID`, else none.
     *
     * @param array<string, string> $env
     *
     * @throws ServerConfigException when the value is not a pid
     */
    public static function parentPid(ParsedArgs $args, array $env): ?int
    {
        $flag = $args->subcommandFlags['--parent-pid'] ?? null;

        return ParentPidWatchdog::parse(\is_string($flag) ? $flag : ($env['CANDY_CODE_SERVER_PARENT_PID'] ?? null));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The state directory (null when there is none yet) and its discovery
     * record, or the exit code of a refusal: an unsafe directory is exit 2,
     * like `serve` itself refuses one.
     *
     * @param array<string, string> $env
     *
     * @return array{0: StateDir|null, 1: DiscoveryFile|null}|int
     */
    private static function locate(ParsedArgs $args, array $env): array|int
    {
        $path = self::stateDirPath($env);
        if ($path === '') {
            return NonInteractive::failUsage('candy-code serve: ' . Lang::t('cli.serve.no_home'), $args->outputFormat);
        }

        try {
            $state = StateDir::existing($path);
        } catch (\RuntimeException $e) {
            return NonInteractive::failUsage('candy-code serve: ' . $e->getMessage(), $args->outputFormat);
        }

        return [$state, $state === null ? null : DiscoveryFile::read($state)];
    }

    /** @param array<string, string> $env */
    private static function stateDirPath(array $env): string
    {
        return ServerConfig::stateDirFrom($env, self::defaultStateDir());
    }

    private static function defaultStateDir(): string
    {
        $home = HomeDirectory::owned();

        return $home === null ? '' : $home . '/.candy-code/server';
    }

    /**
     * A failed start: stderr, the `listening: false` document under JSON,
     * exit 1 — it ran and may succeed later.
     */
    private static function failStart(ParsedArgs $args, ServerConfig $config, string $reason): int
    {
        self::stderr('candy-code serve: ' . $reason);
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => ['listening' => false, 'address' => $config->bindUri(), 'reason' => $reason]]) . "\n";
        }

        return NonInteractive::EXIT_FAILURE;
    }

    /**
     * Print a management verb's answer: the document under JSON, else $text
     * (stdout on success, stderr on failure). Like `doctor`'s failing report,
     * a "no server" answer is a result, not a new `error.type`.
     *
     * @param array<string, mixed> $result
     */
    private static function answer(ParsedArgs $args, array $result, string $text, int $code): int
    {
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => $result]) . "\n";
        } elseif ($code === NonInteractive::EXIT_OK) {
            echo $text . "\n";
        } else {
            self::stderr($text);
        }

        return $code;
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
            'CANDY_CODE_SERVER_HOST',
            'CANDY_CODE_SERVER_PORT',
            'CANDY_CODE_SERVER_ALLOWED_ORIGINS',
            'CANDY_CODE_SERVER_ALLOWED_HOSTS',
            'CANDY_CODE_SERVER_ALLOWED_IPS',
            'CANDY_CODE_SERVER_WEB_ROOT',
            'CANDY_CODE_SERVER_TOKEN',
            'CANDY_CODE_SERVER_DIR',
            'CANDY_CODE_SERVER_PARENT_PID',
            'CANDY_CODE_PERMISSION_MODE',
        ] as $name) {
            $value = \getenv($name);
            if (\is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * The server's one stderr channel: startup lines, the request log and the
     * stop notice. Stderr alone, never the transcript seam — there is no
     * session to report into. A detached server's stderr IS its log file.
     */
    private static function stderr(string $text): void
    {
        \fwrite(\STDERR, $text . "\n");
    }
}
