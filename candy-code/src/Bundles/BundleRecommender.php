<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

/**
 * Offline, announce-only "you could adopt these" over a marketplace catalog.
 *
 * CC-new #12 (plan_comparison.md §3, C-EXT-4.a). The whole mechanism is a
 * vocabulary match: the project root is LISTED one level to see which
 * manifest signals exist (composer.json, package.json, go.mod, Dockerfile,
 * Terraform `*.tf` files), each signal contributes a word set, and catalog
 * {@see MarketplaceEntry} keywords equal to one of those words score the
 * entry. At most {@see MAX_RECOMMENDATIONS} bundles are reported, ties broken
 * by name so two identical trees recommend identically, and entries already
 * installed never appear — recommending what a machine already has is noise.
 *
 * WHAT IT DELIBERATELY IS NOT, because the design's floor is stated on the
 * box: this class never installs, never fetches, and never asks anybody.
 * {@see recommend()} returns values; the announce surface decides whether a
 * line is printed, and the ONLY way a bundle gets installed through this
 * family is the explicit verb behind {@see MarketplaceRegistry::installGate()}
 * — which this class does not call, because a recommendation that could
 * install something is not a recommendation. There is no model, no network,
 * and therefore no timer anywhere in here: matching runs against a directory
 * listing, so the plan's no-blanket-timeout rule (E646) has nothing to bite
 * and nothing here may grow one.
 *
 * The match is lexical on keywords, per the design's "search is lexical"
 * ruling: no embeddings, no fuzzy tier, and no surprise that a keyword nobody
 * wrote never fires.
 */
final class BundleRecommender
{
    /** The design's cap: a launch line may not become a wall of text. */
    public const MAX_RECOMMENDATIONS = 3;

    /** The extension-shaped signal's words; the file itself is `*.tf`. */
    public const TERRAFORM_SUFFIX = '.tf';

    /**
     * Signal file => the words it vouches for. A keyword counts once per
     * signal it appears in, so a `php`-and-`laravel` entry outranks a plain
     * `php` one on the same tree.
     *
     * @var array<string, list<string>>
     */
    private const SIGNAL_WORDS = [
        'composer.json' => ['php', 'composer', 'laravel', 'symfony', 'wordpress'],
        'package.json' => ['js', 'javascript', 'node', 'typescript', 'npm', 'react', 'vue', 'frontend'],
        'go.mod' => ['go', 'golang', 'grpc'],
        'Dockerfile' => ['docker', 'container', 'containers', 'deploy', 'devops'],
        self::TERRAFORM_SUFFIX => ['terraform', 'infrastructure', 'iac', 'aws', 'gcp', 'azure', 'provider'],
    ];

    /**
     * @param list<MarketplaceEntry> $catalog entries from ADDED marketplaces (the caller's joined view)
     * @param list<string> $installedNames names already installed, excluded from output
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $catalog = [],
        private readonly array $installedNames = [],
    ) {
    }

    /**
     * The matching bundles, best score first, name-breaking ties, capped at
     * {@see MAX_RECOMMENDATIONS}. Calling it twice answers the same; nothing
     * in this type has a side effect.
     *
     * @return list<MarketplaceEntry>
     */
    public function recommend(): array
    {
        $signals = $this->signals();

        if ($signals === []) {
            return [];
        }

        $scored = [];

        foreach ($this->catalog as $entry) {
            if (in_array($entry->name, $this->installedNames, true) || $entry->keywords === []) {
                continue;
            }

            $score = 0;

            foreach ($entry->keywords as $keyword) {
                foreach ($signals as $words) {
                    if (in_array($keyword, $words, true)) {
                        ++$score;

                        continue 2;
                    }
                }
            }

            if ($score > 0) {
                $scored[] = [$score, $entry->name, $entry];
            }
        }

        usort(
            $scored,
            static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]],
        );

        return array_map(
            static fn (array $row): MarketplaceEntry => $row[2],
            array_slice($scored, 0, self::MAX_RECOMMENDATIONS),
        );
    }

    /**
     * Which signals the root answers with, as word sets. One level deep,
     * names only: {@see \scandir} here lists names and reads no content, so
     * the read census carries this sink as NAMES_ONLY and the gate for any
     * later content read stays where that read happens.
     *
     * @return list<list<string>>
     */
    private function signals(): array
    {
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            return [];
        }

        $names = @scandir($this->projectRoot);

        if ($names === false) {
            return [];
        }

        $signals = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $key = str_ends_with($name, self::TERRAFORM_SUFFIX)
                ? self::TERRAFORM_SUFFIX
                : (isset(self::SIGNAL_WORDS[$name]) ? $name : null);

            if ($key !== null && !in_array(self::SIGNAL_WORDS[$key], $signals, true)) {
                $signals[] = self::SIGNAL_WORDS[$key];
            }
        }

        return $signals;
    }
}
