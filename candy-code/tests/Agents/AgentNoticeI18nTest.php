<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;

/**
 * S-A01: the agents notice channel is minted as `agents.notice.*` keys, not
 * authored English at the call sites. Pins the family's placeholders in `en`
 * and that every locale carries every key.
 */
final class AgentNoticeI18nTest extends TestCase
{
    private const KEYS = [
        'grant_narrowed', 'child_not_stored', 'worktree_add_failed', 'worktree_remove_failed',
        'include_file_refused', 'anchor_repo_root', 'anchor_cwd', 'include_pattern_refused',
        'stale_cleanup_skipped', 'cache_break', 'grant_narrowed.detail', 'cache_break.detail',
        'include_file_refused.detail', 'stale_cleanup_skipped.detail',
    ];

    /** One voice: lead notices are lowercase subject-first (a verbatim identifier may lead) and a sentence, not a paragraph. */
    private const LEADS = [
        'grant_narrowed', 'child_not_stored', 'worktree_add_failed', 'worktree_remove_failed',
        'include_file_refused', 'include_pattern_refused', 'stale_cleanup_skipped', 'cache_break',
    ];

    public function testEnglishRendersPlaceholders(): void
    {
        $out = Lang::t('agents.notice.worktree_add_failed', ['agent' => 'a1', 'code' => 128, 'output' => 'boom']);
        self::assertSame('git worktree add failed for agent "a1" (exit 128): boom', $out);

        $cache = Lang::t('agents.notice.cache_break', ['count' => 2, 'from' => 90, 'to' => 10]);
        self::assertStringContainsString('(90% → 10%)', $cache);
    }

    public function testEveryLocaleCarriesTheWholeFamily(): void
    {
        foreach (glob(__DIR__ . '/../../lang/*.php') ?: [] as $file) {
            $catalogue = require $file;
            foreach (self::KEYS as $key) {
                self::assertArrayHasKey('agents.notice.' . $key, $catalogue, basename($file));
            }
        }
    }

    public function testEnglishLeadsAreShortAndLowercaseSubjectFirst(): void
    {
        $en = require __DIR__ . '/../../lang/en.php';
        foreach (self::LEADS as $key) {
            $lead = $en['agents.notice.' . $key];
            self::assertMatchesRegularExpression('/^[a-z.]/', $lead, $key . ' leads with a lowercase subject');
            // The tail after a trailing {placeholder} is foreign text; count the authored words only.
            $authored = \str_replace(' → ', ' ', (string) \preg_replace('/:? ?\{(?:output|reason)\}$/', '', $lead));
            self::assertLessThanOrEqual(
                12,
                \count(\preg_split('/\s+/', \trim((string) $authored)) ?: []),
                $key . ' lead is a lead, not a paragraph',
            );
        }
    }

    public function testTheShortLeadCarriesTheDetailInTheCompleteRecordOnly(): void
    {
        $out = \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::using(
            \SugarCraft\Code\Diagnostics\NoticeSink::new(),
            static function (): string {
                $log = \tempnam(\sys_get_temp_dir(), 'notice');
                $previous = \ini_set('error_log', (string) $log);
                try {
                    \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::warn('LEAD.', 'DETAIL.');

                    return (string) \file_get_contents((string) $log);
                } finally {
                    \ini_set('error_log', (string) $previous);
                    @\unlink((string) $log);
                }
            },
        );
        self::assertStringContainsString('LEAD. DETAIL.', $out);
    }
}
