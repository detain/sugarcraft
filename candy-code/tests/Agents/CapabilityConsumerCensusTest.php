<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function is_dir;
use function sort;
use function strlen;
use function substr;
use function substr_count;

/**
 * A capability manifest is DATA, not permission (RS-AGENT-C4 v1), and this
 * guard is the structural half of that promise: the only production files
 * allowed to speak the capability surface are the card itself, its store,
 * and the two command-surface files that render and route it. If some future
 * scheduler, executor, or grant check starts reading manifests to widen what
 * an agent may do, it must either join the allow-set on purpose (with its own
 * tests and a review) or go red here — silent capability creep is exactly the
 * failure this slice promises never to have.
 *
 * The census walks src/ (never tests/, so this file referencing the class is
 * not an offender), compares repo-relative spellings against a fixed roster,
 * and carries a positive control leg so an accidental rename that empties the
 * offender list cannot also silence the guard: every allow-set file must
 * actually speak the needle it is sanctioned for, or the roster names dead
 * code. The router's needle is the verb spelling (`capabilities`), not the
 * class token — routing reaches the surface through AgentStatusCommands, and
 * pinning the word it adds is what makes retiring the arm visible.
 */
final class CapabilityConsumerCensusTest extends TestCase
{
    /**
     * Repo-relative src spellings of the sanctioned capability files, each
     * mapped to a token its source must contain (staleness leg of the guard).
     *
     * @var array<string,string>
     */
    private const SANCTIONED = [
        'Agents/CapabilityCard.php' => 'CapabilityCard',
        'Agents/CapabilityCardStore.php' => 'CapabilityCard',
        'Host/Commands/AgentStatusCommands.php' => 'CapabilityCard',
        'Host/Commands/AgentsHostCommand.php' => 'capabilities',
    ];

    /** The offender token: a src file outside the roster referencing the card machinery by class name. */
    private const CONSUMER_NEEDLE = 'CapabilityCard';

    public function testNothingOutsideTheSanctionedFourConsumesCapabilityCards(): void
    {
        $srcRoot = dirname(__DIR__, 2) . '/src';
        $prefixLength = strlen($srcRoot) + 1;
        $offenders = [];

        foreach (self::phpFilesUnder($srcRoot) as $file) {
            $relative = substr($file, $prefixLength);
            if (isset(self::SANCTIONED[$relative])) {
                continue;
            }

            $source = file_get_contents($file);
            self::assertNotFalse($source, "{$relative} vanished mid-walk");
            if (substr_count($source, self::CONSUMER_NEEDLE) !== 0) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'capability manifests are discovery data; a new consumer must be adjudicated into the allow-set, never grown in silently');
    }

    public function testEverySanctionedConsumerActuallySpeaksItsNeedle(): void
    {
        $srcRoot = dirname(__DIR__, 2) . '/src';

        foreach (self::SANCTIONED as $relative => $needle) {
            $source = file_get_contents($srcRoot . '/' . $relative);
            self::assertNotFalse($source, "{$relative} is listed as a capability consumer but is unreadable");
            self::assertGreaterThan(
                0,
                substr_count($source, $needle),
                "{$relative} no longer mentions {$needle}; retire it from the allow-set in the same commit that drops it",
            );
        }
    }

    // ------------------------------------------------------------------ walker

    private static function phpFilesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException("{$directory} is not a directory; this guard cannot answer for it");
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($walk as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        if ($files === []) {
            throw new \RuntimeException("{$directory} holds no PHP files; this guard is scanning nothing");
        }

        sort($files);

        return $files;
    }
}
