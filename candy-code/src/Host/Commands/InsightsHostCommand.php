<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\InsightsCommand;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * Gathers the insights report (C-SES-8.c, GAP-OBS-06) and writes it beside
 * the `/export` shelf: the spend grids come from the SAME shared
 * `rollupFacts()` read `/usage` uses (C-SES-9.a) with the window applied, so
 * the two pages cannot disagree about dollars; the session-shaped sections
 * fold from the roster the store can actually answer (`listSessionsWithMeta`,
 * window applied on `created_at`), so an import row, a forked branch and a
 * background child each land on the page as what they are.
 *
 * THE REPLY IS THE PAGE, THE FILE IS ITS MIRROR: every run returns the text
 * report plus the path it wrote; the HTML carries the same fact map through
 * the same fold. A write failure keeps the page in the reply and states why
 * no file landed — the measurement was already taken and must not be lost
 * with the copy.
 *
 * A junk window is answered with the usage line rather than a default: a
 * mistyped `insights 7x` must not silently print thirty days. A host without
 * a durable store says so and writes NOTHING — there is no partial truth to
 * file, and inventing a zero page would be exactly that.
 */
final class InsightsHostCommand implements HostCommand
{
    /** The default window: the last 30 whole UTC days, today included. */
    public const DEFAULT_WINDOW_DAYS = 30;

    /** Session rows are folded whole-roster; the LIMIT is a ceiling, not a page size. */
    private const ROSTER_CEILING = 100000;

    public function __construct(
        private readonly ?string $exportsDir = null,
    ) {
    }

    public function run(CommandContext $context, string $text): CommandResult
    {
        // The run contract hands the WHOLE line; the command word is not
        // trusted to be a known spelling (C-SES-8.d owns registration) — a
        // leading slash and the first word are dropped, everything after is
        // the argument.
        $argument = preg_match('/^\s*\/?\w+[\s:]?(.*)$/s', $text, $m) === 1 ? trim($m[1]) : '';
        $windowDays = self::DEFAULT_WINDOW_DAYS;
        foreach (preg_split('/\s+/', $argument, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (ctype_digit($token) && (int) $token > 0) {
                $windowDays = (int) $token;
                continue;
            }

            return CommandResult::reply($text, Lang::t('cmd.insights.usage'));
        }

        $store = $context->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            return CommandResult::reply($text, Lang::t('cmd.insights.unavailable', [
                'reason' => Lang::t('cmd.insights.no-store'),
            ]));
        }

        $spend = UsageHostCommand::rollupFacts($store, null, $windowDays, true);
        $facts = [
            'windowDays' => $windowDays,
            'sessions' => self::rosterRows($store, $windowDays),
            'spendDays' => $spend['days'],
            'spendModels' => $spend['models'],
            'spendReason' => $spend['reason'],
        ];

        $page = InsightsCommand::compose($facts);
        $html = InsightsCommand::composeHtml($facts);

        // The shelf LOCATION is part of the write that can fail: with a
        // default-constructed command it resolves through HomeDirectory::owned()
        // and refuses outright when no home can be vouched for. Resolving it
        // OUTSIDE the try discarded the composed page — the docblock's promise
        // is that a run which cannot file still replies with the page and
        // states why, so the resolution lives inside the guard.
        try {
            $directory = $this->exportsDir !== null ? rtrim($this->exportsDir, '/') : self::exportsDirectory();
            $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
            $target = $directory . '/insights-' . $stamp . '.html';
            AtomicFileWriter::write($target, $html, 0600);
        } catch (\Throwable $error) {
            return CommandResult::reply($text, $page . "\n" . Lang::t('cmd.insights.write-failed', [
                'reason' => Chat::reportField($error->getMessage()),
            ]));
        }

        return CommandResult::reply($text, $page . "\n" . Lang::t('cmd.insights.written', [
            'path' => Chat::reportField($target),
        ]));
    }

    private static function exportsDirectory(): string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            throw new \RuntimeException('No owned home directory — the insights file has nowhere to land.');
        }

        return rtrim($home, '/') . '/' . ShareCommand::EXPORTS_SUBDIR;
    }

    /**
     * The roster inside the window, folded to the row shape the report core
     * consumes. `created_at` is stored as a UTC `Y-m-d H:i:s` string, so the
     * window boundary is a plain string compare against the first whole day;
     * rows the store never stamped fall back to `updated_at`, and a row with
     * neither keeps its date empty — the completeness law prints what IS
     * known rather than guessing.
     *
     * @return list<array{date: string, kind: string, cwd: string, turns: int, unpriced: bool, imported: bool}>
     */
    private static function rosterRows(EnhancedSessionStore $store, int $windowDays): array
    {
        $firstDay = gmdate('Y-m-d', (int) (floor(time() / 86400) * 86400 - ($windowDays - 1) * 86400));
        $rows = [];
        foreach ($store->listSessionsWithMeta(self::ROSTER_CEILING) as $session) {
            $created = trim((string) ($session['created_at'] ?? ''));
            $date = $created !== '' ? substr($created, 0, 10) : substr(trim((string) ($session['updated_at'] ?? '')), 0, 10);
            if ($date !== '' && $date < $firstDay) {
                continue;
            }

            $cwd = trim((string) ($session['cwd'] ?? ''));
            $rows[] = [
                'date' => $date,
                'kind' => (string) ($session['kind'] ?? ''),
                'cwd' => $cwd === '' ? Lang::t('cmd.insights.no-directory') : $cwd,
                'turns' => (int) ($session['turns'] ?? 0),
                'unpriced' => (int) ($session['cost_unpriced'] ?? 0) === 1,
                'imported' => str_starts_with((string) ($session['provider'] ?? ''), 'import:'),
            ];
        }

        return $rows;
    }
}
