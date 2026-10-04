<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Workspace\GitRunner;

/**
 * The repo-map pipeline, shared by the `RepoMap` tool (roadmap 5.5-4) and the
 * system prompt's {@see \SugarCraft\Crush\Context\SymbolMapBlock} (5.5-5), so
 * the two can never disagree about which files a map covers or how they are
 * read:
 *
 *  1. FILES ({@see codeFiles()}): `git ls-files --cached --others
 *     --exclude-standard` in the root, so `.gitignore` decides what is project
 *     code; symlinks skipped, every path re-resolved through {@see PathJail}.
 *  2. TAGS ({@see summaries()}): PHP through {@see PhpSymbolExtractor}, every
 *     other code file through {@see CtagsSymbolExtractor} when Universal Ctags
 *     is installed; both through the per-project {@see TagCache}, each row
 *     stamped with the producer that made it. Kept as per-file SUMMARIES —
 *     definition tags and reference counts — never as one object per
 *     reference.
 *  3. RANK + RENDER ({@see render()}): {@see SymbolGraph::fromSummaries()}
 *     (Aider's weights, aggregated), {@see RepoMapRenderer::fit()} to a token
 *     budget, source lines read through {@see sourceLines()}.
 *
 * Extraction can be bounded by a deadline ({@see summaries()}): whatever was
 * extracted before it stays cached, so a caller that runs out of time this
 * session finds the work done the next.
 */
final class RepoMapBuilder
{
    /** Extensions universal-ctags is asked about; anything else (docs, data, images) is not code. */
    public const CTAGS_EXTENSIONS = [
        'c', 'h', 'cc', 'cpp', 'cxx', 'hh', 'hpp', 'hxx', 'cs', 'go', 'java', 'kt', 'kts', 'scala',
        'rs', 'py', 'pyi', 'rb', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'swift', 'm', 'mm', 'lua',
        'pl', 'pm', 'sh', 'bash', 'zsh', 'ex', 'exs', 'erl', 'hrl', 'hs', 'ml', 'mli', 'clj', 'dart',
        'r', 'jl', 'zig', 'nim', 'vim', 'groovy', 'tcl', 'f90', 'f95', 'ada', 'adb', 'ads', 'el',
    ];

    /** Files tokenized between two checks of the deadline and two heartbeats. */
    private const CHECK_EVERY_FILES = 16;

    private ?bool $ctagsAvailable = null;

    private function __construct(
        private readonly string $rootReal,
        private readonly TagCache $cache,
        private readonly CtagsSymbolExtractor $ctags,
    ) {
    }

    /**
     * @param string $rootReal the resolved project root (realpath)
     */
    public static function new(string $rootReal, TagCache $cache, ?CtagsSymbolExtractor $ctags = null): self
    {
        return new self($rootReal, $cache, $ctags ?? CtagsSymbolExtractor::new());
    }

    public function root(): string
    {
        return $this->rootReal;
    }

    public function cache(): TagCache
    {
        return $this->cache;
    }

    /** Whether Universal Ctags with JSON output is installed (probed once). */
    public function ctagsAvailable(): bool
    {
        return $this->ctagsAvailable ??= $this->ctags->available();
    }

    /**
     * The code files git lists under the root, split into PHP files and ctags
     * files (each root-relative => absolute), in path order, capped at
     * $maxFiles — or the reason git could not list them.
     *
     * @return array{ok: bool, error: string, php: array<string, string>, other: array<string, string>, capped: bool}
     */
    public function codeFiles(int $maxFiles): array
    {
        $listing = GitRunner::new($this->rootReal)
            ->withInheritedGitEnv()
            ->withTimeout(GitRunner::DEFAULT_TIMEOUT_SECONDS)
            ->run('ls-files', '-z', '--cached', '--others', '--exclude-standard');
        if (!$listing['ok']) {
            return [
                'ok' => false,
                'error' => \trim($listing['stderr'] !== '' ? $listing['stderr'] : 'exit ' . $listing['exitCode']),
                'php' => [],
                'other' => [],
                'capped' => false,
            ];
        }

        $paths = \array_values(\array_unique(\array_filter(\explode("\0", $listing['stdout']), static fn (string $p): bool => $p !== '')));
        \sort($paths, \SORT_STRING);
        $withCtags = $this->ctagsAvailable();

        $php = [];
        $other = [];
        $capped = false;
        foreach ($paths as $rel) {
            $ext = \strtolower(\pathinfo($rel, \PATHINFO_EXTENSION));
            $isPhp = $ext === 'php';
            if (!$isPhp && !($withCtags && \in_array($ext, self::CTAGS_EXTENSIONS, true))) {
                continue;
            }
            if (\count($php) + \count($other) >= $maxFiles) {
                $capped = true;

                break;
            }

            $candidate = $this->rootReal . '/' . $rel;
            if (\is_link($candidate)) {
                continue;
            }
            $abs = PathJail::resolve($this->rootReal, $rel);
            if ($abs === null || !\is_file($abs)) {
                continue;
            }

            if ($isPhp) {
                $php[$rel] = $abs;
            } else {
                $other[$rel] = $abs;
            }
        }

        return ['ok' => true, 'error' => '', 'php' => $php, 'other' => $other, 'capped' => $capped];
    }

    /**
     * Each file's mtime, size and the producer that would tag it — what a
     * cached map of these files depends on. A file that cannot be stat'ed is
     * left out.
     *
     * @param array<string, string> $php
     * @param array<string, string> $other
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public function stamps(array $php, array $other): array
    {
        $ctagsStamp = $other === [] ? '' : $this->ctags->cacheStamp();
        $stamps = [];
        foreach ([[$php, TagCache::PHP_PRODUCER], [$other, $ctagsStamp]] as [$files, $producer]) {
            foreach ($files as $rel => $abs) {
                \clearstatcache(true, $abs);
                $stat = @\stat($abs);
                if ($stat !== false) {
                    $stamps[(string) $rel] = [(int) $stat['mtime'], (int) $stat['size'], $producer];
                }
            }
        }
        \ksort($stamps, \SORT_STRING);

        return $stamps;
    }

    /**
     * Per-file summaries — definition tags and reference counts — from the
     * cache, extracting and storing whatever it misses. Stops extracting once
     * $deadline (an `hrtime(true)` nanosecond instant) has passed: what was
     * done is cached, `complete` is false, and the summaries cover only the
     * files that had them.
     *
     * @param array<string, string> $php
     * @param array<string, string> $other
     * @param (\Closure(): void)|null $beat struck as the work proceeds
     * @return array{definitions: array<string, list<Tag>>, references: array<string, array<string, int>>, complete: bool}
     */
    public function summaries(array $php, array $other, ?\Closure $beat = null, ?int $deadline = null): array
    {
        $beat ??= static function (): void {
        };
        $stamps = $this->stamps($php, $other);
        $definitions = [];
        $references = [];
        $complete = true;

        $phpExtractor = PhpSymbolExtractor::new();
        $misses = 0;
        $this->cache->transaction(function () use ($php, $stamps, $phpExtractor, $beat, $deadline, &$definitions, &$references, &$complete, &$misses): void {
            foreach ($php as $rel => $abs) {
                if (!isset($stamps[$rel])) {
                    $this->cache->forget($rel);

                    continue;
                }
                [$mtime, $size, $producer] = $stamps[$rel];
                $summary = $this->cache->summary($rel, $mtime, $size, $producer);
                if ($summary === null) {
                    if ($deadline !== null && $misses % self::CHECK_EVERY_FILES === 0 && \hrtime(true) > $deadline) {
                        $complete = false;

                        continue;
                    }
                    ++$misses;
                    $tags = $phpExtractor->extractFile($abs, $rel);
                    $this->cache->put($rel, $mtime, $size, $tags, $producer);
                    $summary = self::summarize($tags);
                    if ($misses % self::CHECK_EVERY_FILES === 0) {
                        $beat();
                    }
                }
                if ($summary !== null) {
                    $definitions[$rel] = $summary['definitions'];
                    $references[$rel] = $summary['references'];
                }
            }
        });

        // The ctags misses go in one batched run each; a file the extractor
        // did not answer for (a cut-off batch) is mapped from nothing this
        // time and not cached.
        $ctagsMisses = [];
        foreach ($other as $rel => $abs) {
            if (!isset($stamps[$rel])) {
                continue;
            }
            [$mtime, $size, $producer] = $stamps[$rel];
            $summary = $this->cache->summary($rel, $mtime, $size, $producer);
            if ($summary === null) {
                $ctagsMisses[$rel] = $abs;
            } else {
                $definitions[$rel] = $summary['definitions'];
                $references[$rel] = $summary['references'];
            }
        }
        if ($ctagsMisses !== [] && $deadline !== null && \hrtime(true) > $deadline) {
            $complete = false;
            $ctagsMisses = [];
        }
        $extracted = $this->ctags->extractFiles($ctagsMisses);
        $this->cache->transaction(function () use ($extracted, $stamps, &$definitions, &$references): void {
            foreach ($extracted as $rel => $tags) {
                [$mtime, $size, $producer] = $stamps[$rel];
                $this->cache->put($rel, $mtime, $size, $tags, $producer);
                $summary = self::summarize($tags);
                $definitions[$rel] = $summary['definitions'];
                $references[$rel] = $summary['references'];
            }
        });
        $beat();

        if ($complete) {
            $this->cache->prune(\array_keys($php + $other));
        }

        return ['definitions' => $definitions, 'references' => $references, 'complete' => $complete];
    }

    /**
     * One file's tags as {@see TagCache::summary()} shapes them.
     *
     * @param list<Tag> $tags
     * @return array{definitions: list<Tag>, references: array<string, int>}
     */
    private static function summarize(array $tags): array
    {
        $definitions = [];
        $references = [];
        foreach ($tags as $tag) {
            if ($tag->isDefinition()) {
                $definitions[] = $tag;
            } else {
                $references[$tag->name] = ($references[$tag->name] ?? 0) + 1;
            }
        }

        return ['definitions' => $definitions, 'references' => $references];
    }

    /**
     * The ranked map of the summarised files, rendered to at most $maxTokens.
     *
     * @param array<string, list<Tag>>          $definitions
     * @param array<string, array<string, int>> $references
     * @param list<string>                      $focusFiles
     * @param list<string>                      $mentionedFiles
     * @param list<string>                      $mentionedIdents
     */
    public function render(
        array $definitions,
        array $references,
        int $maxTokens,
        array $focusFiles = [],
        array $mentionedFiles = [],
        array $mentionedIdents = [],
    ): string {
        $graph = SymbolGraph::fromSummaries($definitions, $references, $focusFiles, $mentionedFiles, $mentionedIdents);
        $rootReal = $this->rootReal;

        return RepoMapRenderer::new(
            static fn (string $rel): array => self::sourceLines($rootReal, $rel),
            $definitions,
        )->fit($graph->rankedEntries(), $maxTokens);
    }

    /**
     * The renderer's line source (the W2-j carry-over): CONTAINED — the path
     * is re-resolved through {@see PathJail} against the root, and a symlink
     * is refused — and SIZE-BOUNDED — a file over
     * {@see PhpSymbolExtractor::MAX_FILE_BYTES} supplies no lines, so its
     * outline falls back to `type name`. The renderer is only ever handed
     * paths from this builder's own listing; the checks hold anyway, because
     * a closure is a door anyone holding it can walk through.
     *
     * @return list<string>
     */
    public static function sourceLines(string $rootReal, string $rel): array
    {
        if (\is_link($rootReal . '/' . $rel)) {
            return [];
        }
        $abs = PathJail::resolve($rootReal, $rel);
        if ($abs === null || !\is_file($abs)) {
            return [];
        }
        $size = @\filesize($abs);
        if ($size === false || $size > PhpSymbolExtractor::MAX_FILE_BYTES) {
            return [];
        }
        $source = @\file_get_contents($abs);

        return $source === false ? [] : \explode("\n", \str_replace("\r\n", "\n", $source));
    }
}
