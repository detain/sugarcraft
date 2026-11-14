<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The settings tree and its help text are one contract (docs/SPEC.md §9.1's
 * surface discipline applied to config): every key the bundle reads must be
 * documented in `settings.channels.discord.help`, and every documented key
 * must be read somewhere. Both directions are asserted from live sources —
 * the test scans src/ for the uniform read idioms and parses the same blob
 * the chat UI shows, so neither side can drift silently.
 *
 * Recognized read idioms: whole-group `$cfg['x']` subscripts, leaf
 * `$<group>['x']` subscripts (the DiscordChannel face builder names its
 * locals after the groups), `nested('group', 'leaf', …)`, the `((array)
 * ($cfg['g'] ?? []))['leaf']` inline form, and the router's
 * `$this->policy['g']['leaf']` chain. Canonicalizations: the per-guild row
 * local is `$guild` (documented as guilds.rows.<field>), and the legacy
 * `access.accounts` fallback slot counts as the documented `accounts` tree.
 * A new read idiom must be added here — an unparsed form reads as zero drift
 * only until it is; that is the accepted limit of any grep-based guard.
 */
final class SettingsHelpKeyDriftTest extends TestCase
{
    /** Tree paths documented as brace groups, harvested from the help blob. */
    private const GROUPS = ['access', 'guilds', 'threads', 'transport', 'queue', 'outbound', 'media', 'approvals', 'slash'];

    public function testEveryReadConfigPathIsDocumentedAndEveryDocumentedPathIsRead(): void
    {
        $help = self::helpBlob();
        $documented = self::parseDocumentedPaths($help);
        $read = self::harvestReadPaths();

        self::assertSame(
            $documented,
            $read,
            'Settings reads and the help inventory have drifted apart. '
            . 'Read-but-undocumented: [' . implode(', ', array_diff(array_keys($read), array_keys($documented))) . '] · '
            . 'Documented-but-unread: [' . implode(', ', array_diff(array_keys($documented), array_keys($read))) . ']'
        );
    }

    public function testHelpBlobDocumentsTheStructuralTops(): void
    {
        $help = self::helpBlob();
        foreach (['enabled', 'intents.messageContent', 'accounts'] as $needle) {
            self::assertStringContainsString($needle, $help, "help blob lost the '$needle' entry");
        }
    }

    /** @return array<string, true> */
    private static function parseDocumentedPaths(string $help): array
    {
        // heads documented as bare dotted paths: the containing group is itself documented
        $doc = ['enabled' => true, 'intents' => true, 'intents.messageContent' => true, 'accounts' => true];
        if (preg_match_all('/(\w+) \{([^}]*)\}/', $help, $groups, PREG_SET_ORDER) === false) {
            self::markTestIncomplete('help blob has no brace groups to parse');
        }
        foreach ($groups as $one) {
            $group = $one[1];
            $doc[$group] = true; // the brace heading itself documents the subtree
            $items = array_values(array_filter(array_map('trim', explode(',', $one[2])), static fn (string $s): bool => $s !== ''));
            /** @var array{0:string,1:string,2:list<string>}|null $rowSpec */
            $rowSpec = null;
            foreach ($items as $item) {
                // 'rows per guild id with policy' + continuation items
                if ($rowSpec === null && preg_match('/^(\w+)\b.*\bwith\b(.*)$/', $item, $row) === 1) {
                    $rowSpec = [$group, $row[1], [trim((string) $row[2])]];
                    continue;
                }
                if ($rowSpec !== null) {
                    $rowSpec[2][] = $item;
                    continue;
                }
                $name = trim((string) preg_replace('/:.*$/', '', $item));
                $doc["$group.$name"] = true;
            }
            if ($rowSpec !== null) {
                [$g, $leaf, $subs] = $rowSpec;
                $doc["$g.$leaf"] = true;
                foreach ($subs as $sub) {
                    foreach (preg_split('/,|\band\b|・/', $sub) ?: [] as $part) {
                        $part = trim($part);
                        if ($part !== '') {
                            $doc["$g.$leaf.$part"] = true;
                        }
                    }
                }
            }
        }
        // every expected group must have appeared at all (as group.<leaf> keys)
        foreach (self::GROUPS as $group) {
            $seen = false;
            foreach (array_keys($doc) as $path) {
                if (str_starts_with((string) $path, "$group.")) {
                    $seen = true;
                    break;
                }
            }
            self::assertTrue($seen, "help blob lost the '$group' group entirely");
        }
        ksort($doc);

        return $doc;
    }

    /** @return array<string, true> */
    private static function harvestReadPaths(): array
    {
        $read = [];
        $note = static function (string $path) use (&$read): void {
            $read[$path] = true;
        };
        foreach (self::srcFiles() as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all("/\\\$cfg\['(\w+)'\]/", $src, $m)) {
                foreach ($m[1] as $top) {
                    $note((string) $top);
                }
            }
            if (preg_match_all("/\\\$(intents|transport|threads|access|guilds|outbound|media|approvals|slash|queue)\['(\w+)'\]/", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $one) {
                    $note("{$one[1]}.{$one[2]}");
                }
            }
            // per-guild row local: $guild[...] is documented under guilds.rows.*
            if (preg_match_all("/\\\$guild\['(\w+)'\]/", $src, $m)) {
                foreach ($m[1] as $leaf) {
                    $note("guilds.rows.$leaf");
                }
            }
            if (preg_match_all("/nested\('(\w+)', '(\w+)'/", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $one) {
                    $note("{$one[1]}.{$one[2]}");
                }
            }
            if (preg_match_all("/\\\$cfg\['(\w+)'\] \?\? \[\]\)\)\['(\w+)'\]/", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $one) {
                    $note("{$one[1]}.{$one[2]}");
                }
            }
            if (preg_match_all("/\\\$this->policy\['(\w+)'\]\['(\w+)'\]/", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $one) {
                    $note("{$one[1]}.{$one[2]}");
                }
            }
        }
        if (isset($read['access.accounts'])) {
            // legacy fallback slot for the documented accounts tree
            unset($read['access.accounts']);
            $read['accounts'] = true;
        }
        ksort($read);

        return $read;
    }

    private static function helpBlob(): string
    {
        // The host keyspace lives beside this bundle's dir in the monorepo —
        // resolved repo-relative so the guard answers on any checkout root
        // (CI runs at /home/runner/work/sugarcraft/sugarcraft, not the dev box).
        $hostLang = dirname(__DIR__, 3) . '/candy-code/lang/en.php';
        self::assertFileExists($hostLang, 'candy-code host catalog vanished from the monorepo checkout');
        $lang = (include $hostLang);
        self::assertIsArray($lang);
        $blob = $lang['settings.channels.discord.help'] ?? null;
        self::assertIsString($blob, 'settings.channels.discord.help vanished from the en catalog');

        return (string) $blob;
    }

    /** @return list<string> */
    private static function srcFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        self::assertNotEmpty($files, 'src census found nothing to scan');

        return $files;
    }
}
