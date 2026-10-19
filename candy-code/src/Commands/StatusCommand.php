<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;

/**
 * Implements the `/status` command — where this session stands, read off the
 * app's own stores in one screen.
 *
 * Usage:
 *   /status  — the launch's facts: version, session, model, context, spend,
 *              config, MCP, hooks, serve
 *
 * WHY IT EXISTS. Every fact here already has a surface: `/context` measures
 * the window, `/budget` reports the spend, `/hooks` the chain, `candy-code
 * serve` discovery, `/settings` the config. None of them answers "what am I
 * looking at right now" without four commands, and the status bar only shows
 * two of the numbers at once. `/status` is the read-only restatement — it
 * asks nothing, sends nothing, and derives every row from the SAME accessors
 * the owning surface uses, so the two can never fork (the rule
 * {@see \SugarCraft\Code\Host\Commands\ContextHostCommand} states for the
 * wire panel and this page inherits from every other row).
 *
 * THE COMPLETENESS LAW IT RIDES (from `/notices`, whose test template this
 * mirrors): every section header and every row prints ALWAYS — a count, a
 * value, or a stated `not measured` with its reason. Silence is never an
 * answer, so the reader learns what the panel could not see instead of
 * wondering whether it forgot to say. It also makes the screen's line budget
 * a constant, which is what lets `StatusCommandTest` count lines instead of
 * scanning for residue.
 *
 * Foreign strings — session names are model-influenced, directories and
 * branches come from on-disk state, the serve record is read off a file —
 * go through {@see Chat::reportField()}, the same discipline
 * `permissionsReport()` and `NoticesCommand` adopted after their line-forgery
 * findings. One hostile directory name must not be able to fabricate a
 * section header.
 *
 * @mirrors charmbracelet/crush status panel (OBS-2)
 */
final class StatusCommand
{
    /**
     * Every fact row the page carries, in render order. compose() refuses
     * (rather than defaults) any missing key: the fixed row budget doubles as
     * the completeness contract, and a silently-absent row is exactly the
     * failure mode the page exists to prevent.
     */
    private const REQUIRED_FACTS = [
        'version', 'configPath', 'theme',
        'sessionId', 'sessionName', 'sessionTurns', 'cwd', 'branch',
        'provider', 'model', 'contextTokens', 'contextTokenLimit',
        'spend', 'ledger',
        'mcpCount', 'mcpReason', 'hookCount', 'hookReason',
        'serveState', 'serve', 'serveReason',
    ];

    private const SERVE_STATES = ['running', 'absent', 'unmeasured'];

    /**
     * The page built from GIVEN facts rather than live stores — pure, so the
     * panel can be tested against exactly the shapes the host produces
     * without racing process-global config state or the filesystem.
     *
     * @param array<string, mixed> $facts the keys of {@see self::REQUIRED_FACTS};
     *                                    nullable values render the section's
     *                                    stated `not measured` sentinel:
     *                                    - version/configPath/theme: strings
     *                                    - sessionId/sessionName/cwd/branch/provider/model: ?string
     *                                    - sessionTurns/contextTokens/contextTokenLimit/mcpCount/hookCount: ?int
     *                                    - spend: the rendered tracker row (never null)
     *                                    - ledger: ?array{calls: int, totalTokens: int, costUsd: float, unpriced: bool}
     *                                    - mcpReason/hookReason/serveReason: ?string
     *                                    - serveState: one of {@see self::SERVE_STATES}
     *                                    - serve: ?array{url: string, pid: int, uptime: int, version: string}
     */
    public static function compose(array $facts): string
    {
        foreach (self::REQUIRED_FACTS as $key) {
            if (!\array_key_exists($key, $facts)) {
                throw new \LogicException('StatusCommand::compose() is missing the ' . $key . ' fact — every row prints always, so every fact must be passed.');
            }
        }
        if (!\in_array($facts['serveState'], self::SERVE_STATES, true)) {
            throw new \LogicException('StatusCommand::compose() serveState must be one of: ' . implode(', ', self::SERVE_STATES));
        }

        $lines = [
            Lang::t('cmd.status.title'),
            Lang::t('cmd.status.subtitle'),
            '',
            Lang::t('cmd.status.app'),
            self::row('cmd.status.version', (string) $facts['version']),
            self::row('cmd.status.config', (string) $facts['configPath']),
            self::row('cmd.status.theme', (string) $facts['theme']),
            '',
            Lang::t('cmd.status.session'),
            self::maybe('cmd.status.id', 'cmd.status.id.unminted', $facts['sessionId']),
            self::maybe('cmd.status.name', 'cmd.status.name.unrecorded', $facts['sessionName']),
            self::maybeNumber('cmd.status.turns', 'cmd.status.turns.unrecorded', $facts['sessionTurns']),
            self::maybe('cmd.status.directory', 'cmd.status.directory.unrecorded', $facts['cwd']),
            self::maybe('cmd.status.branch', 'cmd.status.branch.unrecorded', $facts['branch']),
            '',
            Lang::t('cmd.status.model'),
            self::maybe('cmd.status.provider', 'cmd.status.provider.unmeasured', $facts['provider']),
            self::maybe('cmd.status.model.name', 'cmd.status.model.unmeasured', $facts['model']),
        ];

        $used = $facts['contextTokens'];
        $window = $facts['contextTokenLimit'];
        $lines[] = '  ' . (\is_int($used) && \is_int($window) && $window > 0
            ? Lang::t('cmd.status.context', [
                'used' => self::tokens($used),
                'window' => self::tokens($window),
                'percent' => (string) (int) round($used / $window * 100),
            ])
            : Lang::t('cmd.status.context.unmeasured'));

        $lines[] = '';
        $lines[] = Lang::t('cmd.status.spend');
        $lines[] = '  ' . Chat::reportField((string) $facts['spend']);
        $ledger = $facts['ledger'];
        $lines[] = '  ' . (\is_array($ledger)
            ? Lang::t('cmd.status.ledger', [
                'calls' => self::tokens((int) $ledger['calls']),
                'tokens' => self::tokens((int) $ledger['totalTokens']),
                // The unpriced-lower-bound law (SES-1): the SAME `≥` binding
                // SpendLedger::boundAmount() puts on every dollar surface.
                'amount' => ($ledger['unpriced'] ? '≥$' : '$') . sprintf('%.4f', (float) $ledger['costUsd']),
            ])
            : Lang::t('cmd.status.ledger.none'));

        $lines[] = '';
        $lines[] = Lang::t('cmd.status.ecosystem');
        $lines[] = '  ' . (\is_int($facts['mcpCount'])
            ? Lang::t('cmd.status.mcp', ['count' => (string) $facts['mcpCount']])
            : self::unmeasured('cmd.status.mcp.unmeasured', $facts['mcpReason']));
        $lines[] = '  ' . (\is_int($facts['hookCount'])
            ? Lang::t('cmd.status.hooks', ['count' => (string) $facts['hookCount']])
            : self::unmeasured('cmd.status.hooks.unmeasured', $facts['hookReason']));
        $lines[] = '  ' . self::serveRow($facts);

        return implode("\n", $lines);
    }

    /**
     * A string fact, or the row's stated unrecorded sentinel.
     */
    private static function maybe(string $key, string $unrecordedKey, ?string $value): string
    {
        return $value === null || $value === ''
            ? '  ' . Lang::t($unrecordedKey)
            : self::row($key, $value);
    }

    private static function maybeNumber(string $key, string $unrecordedKey, ?int $value): string
    {
        return '  ' . ($value === null
            ? Lang::t($unrecordedKey)
            : Lang::t($key, ['count' => (string) $value]));
    }

    /**
     * One `Label: value` row; the value is foreign text and enters escaped.
     */
    private static function row(string $key, string $value): string
    {
        return '  ' . Lang::t($key, ['value' => Chat::reportField($value)]);
    }

    private static function unmeasured(string $key, ?string $reason): string
    {
        return Lang::t($key, [
            'reason' => $reason === null || $reason === '' ? '' : Chat::reportField(' — ' . $reason),
        ]);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function serveRow(array $facts): string
    {
        $serve = $facts['serve'];
        if ($facts['serveState'] === 'running' && \is_array($serve)) {
            $uptime = (int) $serve['uptime'];
            return Lang::t('cmd.status.serve', [
                'url' => Chat::reportField((string) $serve['url']),
                'pid' => (string) (int) $serve['pid'],
                'version' => Chat::reportField((string) $serve['version']),
                'uptime' => sprintf('%dh%02dm', intdiv($uptime, 3600), intdiv($uptime % 3600, 60)),
            ]);
        }

        return '  ' . Lang::t($facts['serveState'] === 'absent' ? 'cmd.status.serve.none' : 'cmd.status.serve.unmeasured', [
            'reason' => Chat::reportField((string) ($facts['serveReason'] ?? '')),
        ]);
    }

    private static function tokens(int $count): string
    {
        return number_format($count);
    }
}
