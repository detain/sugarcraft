<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\StatusCommand;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Util\TokenTracker;

/**
 * `/status` (OBS-2): where this session stands, one screen, read-only.
 * {@see StatusCommand} owns the page and its escaping; this is its session
 * half — the live derivation of every fact from the SAME accessors the owning
 * surfaces use (`/context`'s token pair, `/budget`'s ledger sentence, the
 * hooks chain's own `inspect()`, the serve discovery file, the config stores),
 * so no row here can fork the surface that owns the number.
 *
 * The tracker and cap are handed in by the TUI's {@see \SugarCraft\Code\Chat}
 * handler (they are per-Chat state, not CommandContext state); a host without
 * them degrades only the live-spend row — the durable ledger (SES-1) still
 * answers from the store, which is the more honest figure across resumes
 * anyway.
 */
final class StatusHostCommand implements HostCommand
{
    public function __construct(
        private readonly ?TokenTracker $tracker = null,
        private readonly ?float $cap = null,
    ) {}

    public function run(CommandContext $context, string $text): CommandResult
    {
        return CommandResult::reply($text, StatusCommand::compose($this->facts($context)));
    }

    /**
     * @return array<string, mixed> the fact map {@see StatusCommand::compose()} parses
     */
    private function facts(CommandContext $context): array
    {
        $engine = $context->backend instanceof EngineBackend ? $context->backend : null;
        $row = $context->sessionId === null || $context->sessionStore === null
            ? null
            : $context->sessionStore->getSession($context->sessionId);

        $store = $context->sessionStore;
        $ledger = $store instanceof EnhancedSessionStore && $context->sessionId !== null
            ? $store->usageTotals($context->sessionId)
            : null;

        $facts = [
            'version' => \SugarCraft\Code\Cli\Help::version(),
            'configPath' => Bootstrap::userConfigPath(),
            'theme' => $this->theme(),
            'sessionId' => $context->sessionId,
            'sessionName' => \is_array($row) ? ($row['name'] ?? null) : null,
            'sessionTurns' => \is_array($row) && isset($row['turns']) ? (int) $row['turns'] : null,
            'cwd' => \is_array($row) ? ($row['cwd'] ?? null) : null,
            'branch' => \is_array($row) ? ($row['git_branch'] ?? null) : null,
            'provider' => $engine?->provider()->name(),
            'model' => $engine?->model(),
            'contextTokens' => $context->contextTokens,
            'contextTokenLimit' => $context->contextTokenLimit,
            'spend' => $this->tracker === null
                ? Lang::t('cmd.status.spend.untracked')
                : SpendLedger::new()->statusLine($this->tracker, $this->cap),
            'ledger' => $ledger,
            'mcpCount' => null,
            'mcpReason' => null,
            'hookCount' => null,
            'hookReason' => null,
            'serveState' => 'unmeasured',
            'serve' => null,
            'serveReason' => null,
        ];

        try {
            $facts['mcpCount'] = \count(Bootstrap::mcpServerInventory($context->root)['servers']);
        } catch (\Throwable $e) {
            $facts['mcpReason'] = $e->getMessage();
        }

        try {
            $chain = Bootstrap::hooks($context->permissionGate, $context->root)->inspect();
            $facts['hookCount'] = array_sum(array_map(\count(...), $chain['hooks']));
        } catch (\Throwable $e) {
            $facts['hookReason'] = $e->getMessage();
        }

        [$facts['serveState'], $facts['serve'], $facts['serveReason']] = $this->serve();

        return $facts;
    }

    /**
     * The theme name as the frame resolves it: the user-configured value when
     * one is set, and a stated default otherwise. A config that cannot be
     * read reports why rather than pretending to be unstyled.
     */
    private function theme(): string
    {
        try {
            $theme = Bootstrap::readUserConfig()['theme'] ?? null;
        } catch (\Throwable) {
            // The config store answers through a merge that reads every layer;
            // a layer this panel cannot read still has a name the launch's own
            // frame would say — it is the path, not the theme, that failed.
            return '(config unreadable)';
        }

        return \is_string($theme) && $theme !== '' ? $theme : 'dark (default)';
    }

    /**
     * The serve discovery ladder, following `candy-code serve`'s own state
     * directory resolution (CANDY_CODE_SERVER_DIR, else the private home's
     * `server/`). Three answers, one row: a live server, an honestly absent
     * discovery file (including a stale record whose pid is gone — the file
     * was read, no one is home), or a lookup that could not happen with the
     * reason.
     *
     * @return array{0: string, 1: ?array{url: string, pid: int, uptime: int, version: string}, 2: ?string}
     */
    private function serve(): array
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            return ['unmeasured', null, 'no private home directory this process can confirm is its own'];
        }

        $dir = getenv('CANDY_CODE_SERVER_DIR');
        $path = ServerConfig::stateDirFrom(
            \is_string($dir) ? ['CANDY_CODE_SERVER_DIR' => $dir] : [],
            $home . '/.candy-code/server',
        );
        $state = StateDir::existing($path);
        if ($state === null) {
            return ['absent', null, null];
        }

        $record = DiscoveryFile::read($state);
        if ($record === null || !$record->isLive()) {
            return ['absent', null, null];
        }

        return ['running', [
            'url' => $record->url,
            'pid' => $record->pid,
            'uptime' => $record->uptime(),
            'version' => $record->version,
        ], null];
    }
}
