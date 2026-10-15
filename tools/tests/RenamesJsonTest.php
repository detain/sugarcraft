<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Pins the invariants of `tools/renames.json` (plan_rename.md step 0.2).
 *
 * The file is the single source of truth the rename tool, the residue checker,
 * the org-sync workflow and the docs redirects all read, so the cheap structural
 * claims the plan makes about it must fail loud at commit time rather than mid
 * batch in some later phase.
 *
 * SCOPE OF EACH ASSERTION, as the plan words it. The directory-shape and
 * uniqueness rules are stated for `rename` entries only — `merge`/`fold`/`create`
 * entries deliberately describe where an old name went or a new one will come
 * from, and are exempt there. The prefix law is likewise a law over the rename
 * map; the plan carves out exactly one named exception, `candy-code-web`, an
 * "asset bundle shipped with candy-code" that is a lib by kind but carries the
 * app's name (plan Phase 12 #2). The `status` enum is checked over every entry,
 * the merge/fold/create rows included, because the sync workflow and the
 * residue checker branch on it regardless of kind.
 */
final class RenamesJsonTest extends TestCase
{
    private const RENAMES_PATH = __DIR__ . '/../renames.json';

    private const KIND_RENAME = 'rename';

    /** The four lifecycle values the plan defines; `pending` is the birth state. */
    private const STATUSES = ['pending', 'renamed', 'remote-done', 'packagist-done'];

    /**
     * The one new slug the plan names as exempt from the candy-/sugar- prefix
     * law: a lib by kind that carries its app's name (plan Phase 12 #2,
     * "asset bundle shipped with candy-code").
     */
    private const PREFIX_EXEMPTIONS = [
        'candy-code-web' => 'asset bundle shipped with candy-code',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(): array
    {
        $decoded = json_decode((string) file_get_contents(self::RENAMES_PATH), true);

        // Every later test reads through here, so a malformed document must name
        // the file, not surface as a null-operand error in whoever ran first.
        self::assertIsArray($decoded, 'tools/renames.json must decode to an object');
        self::assertArrayHasKey('entries', $decoded, 'tools/renames.json must carry an "entries" array');
        self::assertIsList($decoded['entries'], 'tools/renames.json "entries" must be a list');

        return $decoded['entries'];
    }

    public function testTheFileIsAValidJsonDocument(): void
    {
        self::assertFileExists(self::RENAMES_PATH);

        $raw = file_get_contents(self::RENAMES_PATH);
        self::assertIsString($raw, 'tools/renames.json must be readable');

        json_decode($raw, true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), 'tools/renames.json must parse as JSON: ' . json_last_error_msg());
    }

    public function testEveryRenameEntryHasExactlyOneSideOnDisk(): void
    {
        $root = \dirname(__DIR__, 2);
        $violations = [];

        foreach (self::entries() as $entry) {
            if (($entry['kind'] ?? null) !== self::KIND_RENAME) {
                continue;
            }

            $old = (string) $entry['old'];
            $new = (string) $entry['new'];
            $oldExists = is_dir($root . '/' . $old);
            $newExists = is_dir($root . '/' . $new);

            if ($oldExists === $newExists) {
                $violations[] = sprintf(
                    '%s -> %s: old dir %s, new dir %s; exactly one must exist',
                    $old,
                    $new,
                    $oldExists ? 'exists' : 'is absent',
                    $newExists ? 'exists' : 'is absent'
                );
            }
        }

        self::assertSame([], $violations, "rename entries must sit with exactly one side on disk:\n" . implode("\n", $violations));
    }

    public function testNoTwoRenameEntriesShareANewSlug(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (self::entries() as $entry) {
            if (($entry['kind'] ?? null) !== self::KIND_RENAME) {
                continue;
            }

            $new = (string) $entry['new'];
            $seen[$new] = ($seen[$new] ?? 0) + 1;
        }

        foreach ($seen as $new => $count) {
            if ($count > 1) {
                $duplicates[] = $new . ' used by ' . $count . ' rename entries';
            }
        }

        self::assertSame([], $duplicates, 'every rename target slug must be unique: ' . implode('; ', $duplicates));
    }

    public function testNewSlugsCarryTheirKindPrefix(): void
    {
        $expected = ['app' => 'candy-', 'lib' => 'sugar-'];
        $violations = [];

        foreach (self::entries() as $entry) {
            if (($entry['kind'] ?? null) !== self::KIND_RENAME) {
                continue;
            }

            $new = (string) $entry['new'];
            if (isset(self::PREFIX_EXEMPTIONS[$new])) {
                continue;
            }

            $type = (string) $entry['type'];
            $prefix = $expected[$type] ?? null;
            self::assertNotNull($prefix, "entry for {$new} has type \"{$type}\" outside {app, lib}");

            if (!str_starts_with($new, (string) $prefix)) {
                $violations[] = sprintf('%s is type %s but does not start with %s', $new, $type, (string) $prefix);
            }
        }

        self::assertSame([], $violations, 'new slugs must carry their kind prefix: ' . implode('; ', $violations));
    }

    public function testEveryStatusIsOneOfTheFourLifecycleValues(): void
    {
        $violations = [];

        foreach (self::entries() as $entry) {
            $status = $entry['status'] ?? null;
            if (!\is_string($status) || !\in_array($status, self::STATUSES, true)) {
                $violations[] = sprintf('%s -> %s: status %s', (string) ($entry['old'] ?? '(new)'), (string) $entry['new'], var_export($status, true));
            }
        }

        self::assertSame([], $violations, 'status must be one of pending|renamed|remote-done|packagist-done: ' . implode('; ', $violations));
    }
}
