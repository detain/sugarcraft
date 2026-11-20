<?php

declare(strict_types=1);

/**
 * depth-score.php — the upstream library and app depth scorer (plan_doc.md section 3.1).
 *
 * WHY IT READS GIT AND NOT THE WORKING TREE. docs/DEPTH-SCORES.md records the
 * run date and the tree it was scored on in its header. DepthScoreDriftTest
 * regenerates the table from exactly those two values, so the score has to be a
 * pure function of a named commit. Reading `git ls-tree` and `git cat-file` at
 * that commit keeps the regeneration independent of the checkout, the clock and
 * any untracked files on disk.
 *
 * WHY THE PORT IS EXACT. The score is a float sum taken in a fixed order, and the
 * table rounds it to one decimal. Python's round() rounds the exact binary value
 * half-to-even, while PHP's round() pre-rounds and rounds half away from zero, so
 * roundOneDecimal() works on the exact decimal expansion of the double instead.
 * Body files are read with universal newlines, so the byte count and every regex
 * see "\r\n" and "\r" as "\n", exactly as Python's text mode does.
 *
 * Usage:
 *   php tools/depth-score.php                                # HEAD, today, writes docs/DEPTH-SCORES.md
 *   php tools/depth-score.php --run-date=2026-11-20 --tree=origin/master
 *   php tools/depth-score.php --stdout                       # print the table instead of writing it
 *   php tools/depth-score.php --check                        # exit 1 when docs/DEPTH-SCORES.md is stale
 *   php tools/depth-score.php --help
 *
 * Exit codes: 0 success or fresh, 1 stale table under --check, 2 usage or git error.
 */
final class DepthScore
{
    /** Graded in candy-code/docs/ and docs/lib/candy-code.html instead. */
    private const EXCLUDED = ['candy-code'];

    /** Standard sections, each a case-insensitive pattern over h2/h3 text. */
    private const SECTIONS = [
        '/install|setup|get(?:ting)? started/iu',
        '/quick ?start|usage|how to use|basic use/iu',
        '/\bapi\b|key classes|reference|public surface|\bclasses\b/iu',
        '/\bconfig|\boptions?\b|settings|\bflags?\b|environment|env vars?/iu',
        '/\bkeys?\b|keyboard|keybind|key bind|keymap|controls|shortcut/iu',
        '/theme|styl|palette/iu',
        '/example|demo|showcase/iu',
        '/\btest(s|ing)?\b/iu',
        '/troubl|faq|gotcha|pitfall|problem/iu',
        '/upstream|mirror|charm|bubbletea|lipgloss|port of|inspired/iu',
        '/architect|internal|design|how it works|under the hood|pipeline|the model|the laws/iu',
        '/changelog|versioning|version|semver|release|stability/iu',
    ];

    /** The first class-like declaration on a source line, as the Python scorer matched it. */
    private const DECLARATION = '/^(?:final |abstract |readonly )*(class|interface|enum|trait)\s+(\w+)/u';

    /** Written by generate(), read back by parseHeader(). */
    private const HEADER_PATTERN = '/^Run date: (\d{4}-\d{2}-\d{2})\. Tree: `([0-9a-f]{7,40})`\./m';

    private const TABLE_ROW_PATTERN = '/^\| ([a-z0-9-]+) \|/m';

    /**
     * Renders the whole committed document for one commit and run date.
     *
     * @param string $root    repository root (the git directory is read from here)
     * @param string $commit  any commit-ish; it is resolved before the tree is read
     * @param string $runDate YYYY-MM-DD, written into the header and nowhere else
     */
    public static function generate(string $root, string $commit, string $runDate): string
    {
        self::assertDate($runDate);
        $sha = self::resolveCommit($root, $commit);
        $files = self::listTree($root, $sha);
        $slugs = self::roster($files);

        $sourcesBySlug = [];
        $examplesBySlug = [];
        $wanted = [];
        foreach ($slugs as $slug) {
            $sources = [];
            $examples = 0;
            foreach ($files as $path => $entry) {
                if (str_starts_with($path, "$slug/src/") && self::isVisiblePhp($path, "$slug/src/")) {
                    $sources[] = ['size' => $entry['size'], 'sha' => $entry['sha']];
                    $wanted[] = $entry['sha'];
                } elseif (str_starts_with($path, "$slug/examples/") && str_ends_with($path, '.php')) {
                    $examples++;
                }
            }
            $sourcesBySlug[$slug] = $sources;
            $examplesBySlug[$slug] = $examples;
            $wanted[] = $files["docs/_data/$slug.body.html"]['sha'];
        }

        $blobs = self::readBlobs($root, $wanted);

        $rows = [];
        foreach ($slugs as $slug) {
            $sources = [];
            foreach ($sourcesBySlug[$slug] as $src) {
                $sources[] = ['size' => $src['size'], 'text' => $blobs[$src['sha']]];
            }
            $body = $blobs[$files["docs/_data/$slug.body.html"]['sha']];
            $rows[] = self::measure($slug, $body, $sources, $examplesBySlug[$slug]);
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = $b['score'] <=> $a['score'];

            return $cmp !== 0 ? $cmp : strcmp($a['slug'], $b['slug']);
        });

        return self::render($rows, $runDate, self::shortSha($root, $sha));
    }

    /**
     * @return array{runDate: string, tree: string} values the committed header was written with
     */
    public static function parseHeader(string $markdown): array
    {
        if (preg_match(self::HEADER_PATTERN, $markdown, $m) !== 1) {
            throw new \RuntimeException('depth-score: the document has no "Run date ... Tree `...`" header line.');
        }

        return ['runDate' => $m[1], 'tree' => $m[2]];
    }

    /**
     * Slugs whose rows differ between two rendered documents, plus "header" when the
     * lines above the table differ. An empty list means the two documents agree.
     *
     * @return list<string>
     */
    public static function staleRows(string $committed, string $generated): array
    {
        $stale = [];
        if (self::headerOf($committed) !== self::headerOf($generated)) {
            $stale[] = 'header';
        }
        $have = self::rowsOf($committed);
        $want = self::rowsOf($generated);
        foreach (array_unique(array_merge(array_keys($have), array_keys($want))) as $slug) {
            if (($have[$slug] ?? null) !== ($want[$slug] ?? null)) {
                $stale[] = (string) $slug;
            }
        }
        sort($stale, SORT_STRING);

        return $stale;
    }

    /**
     * Rounds half-to-even on the exact binary value, as Python's round(x, 1) does.
     * sprintf('%.53f') prints the exact decimal expansion of any double in the score
     * range, so the tie test and the digit choice are exact.
     */
    public static function roundOneDecimal(float $value): float
    {
        if ($value < 0.0) {
            throw new \InvalidArgumentException('depth-score: negative scores are not defined.');
        }
        [$int, $frac] = explode('.', sprintf('%.53f', $value));
        $tenths = (int) $frac[0];
        $next = $frac[1];
        $up = $next > '5'
            || ($next === '5' && (trim(substr($frac, 2), '0') !== '' || $tenths % 2 === 1));
        $units = ((int) $int) * 10 + $tenths + ($up ? 1 : 0);

        return (float) $units / 10.0;
    }

    /**
     * @param list<array{size: int, text: string}> $sources PHP sources under src/
     * @return array{slug: string, type: string, body_bytes: int, h2: int, h3: int, pre: int,
     *     sections: int, class_cov: float, examples: int, vhs: int, score: float, grade: string}
     */
    private static function measure(string $slug, string $rawBody, array $sources, int $examples): array
    {
        $body = self::normalizeNewlines($rawBody);
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new \RuntimeException("depth-score: $slug body is not valid UTF-8.");
        }
        $bodyBytes = strlen($body);
        $h2 = (int) preg_match_all('/<h2\b/iu', $body);
        $h3 = (int) preg_match_all('/<h3\b/iu', $body);
        $pre = (int) preg_match_all('/<pre\b/iu', $body);
        $vhs = substr_count($body, '.vhs/');

        preg_match_all('#<h([23])[^>]*>(.*?)</h\1>#siu', $body, $hits, PREG_SET_ORDER);
        $headings = [];
        foreach ($hits as $hit) {
            $headings[] = self::plainText($hit[2]);
        }
        $sections = 0;
        foreach (self::SECTIONS as $rx) {
            foreach ($headings as $heading) {
                if (preg_match($rx, $heading) === 1) {
                    $sections++;

                    break;
                }
            }
        }

        $decls = [];
        foreach ($sources as $src) {
            $name = self::firstDeclaration($src['text']);
            if ($name !== null) {
                $decls[] = ['size' => $src['size'], 'name' => $name];
            }
        }
        usort($decls, static function (array $a, array $b): int {
            $cmp = $b['size'] <=> $a['size'];

            return $cmp !== 0 ? $cmp : strcmp($b['name'], $a['name']);
        });
        $top = array_map(static fn (array $d): string => $d['name'], array_slice($decls, 0, 10));
        $found = 0;
        foreach ($top as $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/u', $body) === 1) {
                $found++;
            }
        }
        $classCov = $top === [] ? 0.0 : self::roundOneDecimal((float) (100 * $found) / count($top));

        $score = 0.0;
        if ($bodyBytes > 0) {
            $s = (float) (40 * $sections) / 12;
            $s += 20 * ($classCov / 100.0);
            $s += 15 * min($pre, 8) / 8;
            $s += 10 * min($h2 + $h3, 15) / 15;
            $s += 10 * min($bodyBytes, 12000) / 12000;
            $s += 5 * min($examples + $vhs, 3) / 3;
            $score = self::roundOneDecimal($s);
        }

        return [
            'slug' => $slug,
            'type' => str_starts_with($slug, 'sugar-') ? 'library' : 'app',
            'body_bytes' => $bodyBytes,
            'h2' => $h2,
            'h3' => $h3,
            'pre' => $pre,
            'sections' => $sections,
            'class_cov' => $classCov,
            'examples' => $examples,
            'vhs' => $vhs,
            'score' => $score,
            'grade' => self::grade($score),
        ];
    }

    private static function grade(float $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 55 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * @param list<array<string, mixed>> $rows already sorted by score then slug
     */
    private static function render(array $rows, string $runDate, string $tree): string
    {
        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
        $libs = 0;
        $apps = 0;
        foreach ($rows as $row) {
            $counts[$row['grade']]++;
            $row['type'] === 'library' ? $libs++ : $apps++;
        }
        $total = count($rows);

        $lines = [];
        $lines[] = '# Upstream library and app depth scores';
        $lines[] = '';
        $lines[] = "Run date: $runDate. Tree: `$tree`. Slugs: $total ($libs libraries, $apps apps); `candy-code` is excluded because its docs are graded in `candy-code/docs/` and the public `docs/lib/candy-code.html` page. A slug with no `docs/_data/<slug>.body.html` (such as `candy-discord`) is not graded.";
        $lines[] = '';
        $lines[] = 'Score is the 0 to 100 depth heuristic from `plan_doc.md` section 3.1: 40 for the 12 standard sections present, 20 for top-10 class-name coverage, 15 for code blocks, 10 for h2 and h3 count, 10 for body size (capped at 12000 bytes), and 5 for examples and VHS references. Grades: A at 85 or more, B at 70, C at 55, D at 40, F below 40.';
        $lines[] = '';
        $lines[] = 'The score measures structure and coverage. It does not judge accuracy or prose quality, so a high score still needs a manual read before a page is called finished.';
        $lines[] = '';
        $lines[] = "Grade counts: A {$counts['A']}, B {$counts['B']}, C {$counts['C']}, D {$counts['D']}, F {$counts['F']}.";
        $lines[] = '';
        $lines[] = '| Slug | Type | Score | Grade | Run date |';
        $lines[] = '|---|---|---:|:---:|---|';
        foreach ($rows as $row) {
            $lines[] = sprintf('| %s | %s | %.1f | %s | %s |', $row['slug'], $row['type'], $row['score'], $row['grade'], $runDate);
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return array{string, string} run date and tree from the header, or ['', ''] when absent
     */
    private static function headerOf(string $markdown): array
    {
        if (preg_match(self::HEADER_PATTERN, $markdown, $m) !== 1) {
            return ['', ''];
        }

        return [$m[1], $m[2]];
    }

    /**
     * @return array<string, string> slug => its full table line
     */
    private static function rowsOf(string $markdown): array
    {
        $rows = [];
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match(self::TABLE_ROW_PATTERN, $line, $m) === 1) {
                $rows[$m[1]] = $line;
            }
        }

        return $rows;
    }

    /**
     * Every sugar-* and candy-* slug with a docs/_data body, minus EXCLUDED.
     *
     * @param array<string, array{sha: string, size: int}> $files
     * @return list<string>
     */
    private static function roster(array $files): array
    {
        $slugs = [];
        foreach (array_keys($files) as $path) {
            if (preg_match('#^docs/_data/((?:sugar|candy)-[a-z0-9-]+)\.body\.html$#', $path, $m) === 1
                && !in_array($m[1], self::EXCLUDED, true)) {
                $slugs[] = $m[1];
            }
        }
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * A file the Python scorer's glob("src/**\/*.php", recursive=True) returns: no path
     * component may start with a dot, because glob skips hidden names.
     */
    private static function isVisiblePhp(string $path, string $prefix): bool
    {
        if (!str_ends_with($path, '.php')) {
            return false;
        }
        foreach (explode('/', substr($path, strlen($prefix))) as $part) {
            if ($part === '' || $part[0] === '.') {
                return false;
            }
        }

        return true;
    }

    private static function firstDeclaration(string $source): ?string
    {
        foreach (self::lines(self::normalizeNewlines($source)) as $line) {
            if (preg_match(self::DECLARATION, $line, $m) === 1) {
                return $m[2];
            }
        }

        return null;
    }

    /**
     * Python's str.splitlines(): the line breaks it recognizes, not just "\n".
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        return preg_split('/\n|\x0b|\x0c|\x1c|\x1d|\x1e|\x{85}|\x{2028}|\x{2029}/u', $text) ?: [];
    }

    /** Python's universal-newline read: "\r\n" and a lone "\r" both become "\n". */
    private static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** Mirrors the Python strip_tags(): tags become spaces, entities decode, ends trimmed. */
    private static function plainText(string $html): string
    {
        $text = (string) preg_replace('/<[^>]+>/u', ' ', $html);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function resolveCommit(string $root, string $ref): string
    {
        return trim(self::git($root, ['rev-parse', '--verify', '--quiet', $ref . '^{commit}']));
    }

    private static function shortSha(string $root, string $sha): string
    {
        return trim(self::git($root, ['rev-parse', '--short=9', $sha]));
    }

    /**
     * @return array<string, array{sha: string, size: int}> path => blob, for every blob in the tree
     */
    private static function listTree(string $root, string $commit): array
    {
        $out = self::git($root, ['ls-tree', '-r', '-l', '-z', '--full-tree', $commit]);
        $files = [];
        foreach (explode("\0", $out) as $record) {
            if ($record === '') {
                continue;
            }
            if (preg_match('/^(\d{6}) (blob|tree|commit) ([0-9a-f]{40})\s+(\d+|-)\t(.*)$/s', $record, $m) !== 1) {
                throw new \RuntimeException('depth-score: unexpected ls-tree record.');
            }
            if ($m[2] === 'blob') {
                $files[$m[5]] = ['sha' => $m[3], 'size' => (int) $m[4]];
            }
        }

        return $files;
    }

    /**
     * Reads blobs in one `git cat-file --batch` run. The requests go through a temp file
     * rather than a pipe, so a large reply cannot deadlock against a half-written request.
     *
     * @param list<string> $shas
     * @return array<string, string> sha => content
     */
    private static function readBlobs(string $root, array $shas): array
    {
        $shas = array_values(array_unique($shas));
        if ($shas === []) {
            return [];
        }
        $request = tempnam(sys_get_temp_dir(), 'depth-score-');
        if ($request === false) {
            throw new \RuntimeException('depth-score: cannot create a temporary request file.');
        }
        try {
            file_put_contents($request, implode("\n", $shas) . "\n");
            $proc = proc_open(
                ['git', '-C', $root, 'cat-file', '--batch'],
                [0 => ['file', $request, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($proc)) {
                throw new \RuntimeException('depth-score: cannot start git cat-file.');
            }
            $content = [];
            while (($header = fgets($pipes[1])) !== false) {
                $parts = explode(' ', rtrim($header, "\n"));
                if (count($parts) !== 3 || $parts[1] !== 'blob') {
                    throw new \RuntimeException("depth-score: unexpected cat-file header '" . rtrim($header, "\n") . "'.");
                }
                $size = (int) $parts[2];
                $buf = '';
                while (strlen($buf) < $size) {
                    $chunk = fread($pipes[1], $size - strlen($buf));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('depth-score: cat-file output ended early.');
                    }
                    $buf .= $chunk;
                }
                fread($pipes[1], 1);
                $content[$parts[0]] = $buf;
            }
            fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code !== 0 || count($content) !== count($shas)) {
                throw new \RuntimeException('depth-score: git cat-file failed: ' . trim((string) $err));
            }

            return $content;
        } finally {
            @unlink($request);
        }
    }

    /** @throws \RuntimeException when git exits non-zero */
    private static function git(string $root, array $args): string
    {
        $proc = proc_open(
            array_merge(['git', '-C', $root], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('depth-score: cannot start git.');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new \RuntimeException('depth-score: git ' . implode(' ', $args) . " failed: " . trim((string) $err));
        }

        return (string) $out;
    }

    private static function assertDate(string $date): void
    {
        $parts = [];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \InvalidArgumentException("depth-score: run date must be YYYY-MM-DD, got '$date'.");
        }
    }

    private static function usage(): string
    {
        return <<<'TXT'
        Usage: php tools/depth-score.php [--run-date=YYYY-MM-DD] [--tree=<commit>] [--out=PATH | --stdout | --check]

          --run-date=DATE  run date written to the header (default: today)
          --tree=COMMIT    commit whose tree is scored (default: HEAD)
          --out=PATH       file to write (default: docs/DEPTH-SCORES.md)
          --stdout         print the table instead of writing it
          --check          exit 1 when the file at --out is not what the scorer produces

        TXT;
    }

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $root = \dirname(__DIR__);
        $runDate = date('Y-m-d');
        $tree = 'HEAD';
        $out = $root . '/docs/DEPTH-SCORES.md';
        $stdout = false;
        $check = false;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--help') {
                echo self::usage();

                return 0;
            } elseif (str_starts_with($arg, '--run-date=')) {
                $runDate = substr($arg, strlen('--run-date='));
            } elseif (str_starts_with($arg, '--tree=')) {
                $tree = substr($arg, strlen('--tree='));
            } elseif (str_starts_with($arg, '--out=')) {
                $out = substr($arg, strlen('--out='));
            } elseif ($arg === '--stdout') {
                $stdout = true;
            } elseif ($arg === '--check') {
                $check = true;
            } else {
                fwrite(STDERR, "depth-score: unknown argument '$arg'\n" . self::usage());

                return 2;
            }
        }

        try {
            $document = self::generate($root, $tree, $runDate);
            if ($stdout) {
                echo $document;

                return 0;
            }
            if ($check) {
                $committed = is_file($out) ? (string) file_get_contents($out) : '';
                $stale = self::staleRows($committed, $document);
                if ($stale === []) {
                    echo "depth-score: $out is current.\n";

                    return 0;
                }
                fwrite(STDERR, 'depth-score: ' . $out . ' is stale for: ' . implode(', ', $stale) . "\n");

                return 1;
            }
            file_put_contents($out, $document);
            echo "depth-score: wrote $out\n";

            return 0;
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 2;
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(DepthScore::main($argv));
}
