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
        'stale_cleanup_skipped', 'cache_break',
    ];

    public function testEnglishRendersPlaceholders(): void
    {
        $out = Lang::t('agents.notice.worktree_add_failed', ['agent' => 'a1', 'code' => 128, 'output' => 'boom']);
        self::assertSame('WorktreeManager: git worktree add failed for agent "a1" — exit 128: boom', $out);

        $cache = Lang::t('agents.notice.cache_break', ['count' => 2, 'from' => 90, 'to' => 10]);
        self::assertStringContainsString('cached share 90% → 10%', $cache);
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
}
