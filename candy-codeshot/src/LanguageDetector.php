<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

/**
 * Heuristic language detector for source code snippets.
 *
 * Detects language based on:
 * - Shebang line (#!/path/to/lang)
 * - File extension (when filename is available)
 * - Content heuristics (structural patterns unique to each language)
 */
final class LanguageDetector
{
    private const SHEBANG_MAP = [
        '#!/bin/sh'   => 'sh',
        '#!/bin/bash' => 'bash',
        '#!/usr/bin/env bash' => 'bash',
        '#!/usr/bin/env node' => 'javascript',
        '#!/usr/bin/env python' => 'python',
        '#!/usr/bin/env python3' => 'python',
        '#!/usr/bin/env ruby' => 'ruby',
        '#!/usr/bin/env php' => 'php',
        '#!/usr/bin/env perl' => 'perl',
    ];

    private const SIGNATURE_PRIORITY = [
        'php', 'python', 'javascript', 'ruby', 'bash', 'sql',
        'html', 'css', 'json', 'yaml', 'markdown',
    ];

    /**
     * Bytes of the head a content scan reads. Structural signatures cluster at
     * the top of a file (the shebang is handled separately above), so a full
     * megabyte scan per signature was pure waste (-044, PERF-07).
     */
    private const CONTENT_SCAN_BYTES = 8192;

    private const CONTENT_SIGNATURES = [
        'php' => [
            '<?php',
            'namespace ',
            'use SugarCraft\\',
            'final class ',
            'declare(strict_types=1)',
        ],
        'javascript' => [
            'const ', 'let ', 'var ', 'function ', '=> ',
            'console.log', 'require(', 'import ',
        ],
        'python' => [
            'def ', 'class ', 'import ', 'from ', 'if __name__',
            'print(', 'self.', 'elif ', '    #',
        ],
        'bash' => [
            'if [', 'fi', 'then', 'echo ', 'export ',
            'done', 'while ', 'case ', 'esac',
        ],
        'ruby' => [
            'def ', 'end', 'class ', 'module ', 'puts ',
            'require ', 'attr_accessor', 'do |',
        ],
        'sql' => [
            'SELECT ', 'FROM ', 'WHERE ', 'INSERT INTO',
            'UPDATE ', 'DELETE FROM', 'CREATE TABLE',
        ],
        'html' => [
            '<html', '<head>', '<body>', '<div', '<span',
            '<script', '<style', '<!DOCTYPE',
        ],
        'css' => [
            'body {', 'color:', 'background:', 'margin:',
            'padding:', '.class', '#id', 'font-',
        ],
        'json' => [
            '{"', '"}', '": ',
        ],
        'yaml' => [
            '---', ': ', '  - ',
        ],
        'markdown' => [
            '# ', '## ', '### ', '- ', '* ', '```',
            '](', '![',
        ],
    ];

    /**
     * Detect language from content using shebang and content heuristics.
     */
    public static function detect(string $content): string
    {
        if (strlen($content) > 1_000_000) {
            return 'text';
        }
        $content = ltrim($content);

        // Check shebang first
        $shebang = self::detectFromShebang($content);
        if ($shebang !== null) {
            return $shebang;
        }

        // Content-based detection
        return self::detectFromContent($content);
    }

    /**
     * Detect language from filename using extension.
     */
    public static function detectFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'php' => 'php',
            'js', 'mjs', 'cjs' => 'javascript',
            'ts', 'tsx' => 'typescript',
            'py', 'pyw' => 'python',
            'rb' => 'ruby',
            'sh', 'bash' => 'bash',
            'zsh' => 'zsh',
            'fish' => 'fish',
            'ps1' => 'powershell',
            'sql' => 'sql',
            'html', 'htm' => 'html',
            'css', 'scss', 'sass', 'less' => 'css',
            'json' => 'json',
            'yaml', 'yml' => 'yaml',
            'md', 'markdown' => 'markdown',
            'xml' => 'xml',
            'go' => 'go',
            'rs' => 'rust',
            'c', 'h' => 'c',
            'cpp', 'cc', 'cxx', 'hpp' => 'cpp',
            'java' => 'java',
            'cs' => 'csharp',
            'swift' => 'swift',
            'kt', 'kts' => 'kotlin',
            'scala' => 'scala',
            'r' => 'r',
            'lua' => 'lua',
            'perl', 'pl', 'pm' => 'perl',
            'tcl' => 'tcl',
            'ex', 'exs' => 'elixir',
            'erl' => 'erlang',
            'hs' => 'haskell',
            'clj', 'cljs' => 'clojure',
            'ml', 'mli' => 'ocaml',
            'jl' => 'julia',
            default => 'text',
        };
    }

    private static function detectFromShebang(string $content): ?string
    {
        if (!str_starts_with($content, '#!')) {
            return null;
        }

        $firstLine = strtok($content, "\n");
        if ($firstLine === false) {
            return null;
        }

        $firstLine = rtrim($firstLine);

        // Exact shebang match
        if (isset(self::SHEBANG_MAP[$firstLine])) {
            return self::SHEBANG_MAP[$firstLine];
        }

        // Try to extract interpreter name.
        //
        // E739: these two patterns used to be `#`-delimited, but a shebang *is* a literal
        // `#`, so the delimiter closed at `#!/` and the trailing `!` parsed as a modifier —
        // preg_match() warned "Unknown modifier '!'" and returned false, which meant the
        // whole arm below never ran and env-form shebangs silently fell through to content
        // scoring. `~` cannot collide with anything a shebang line contains.
        if (preg_match('~^#!/usr/bin/env\s+(\w+)~', $firstLine, $matches)) {
            $interp = $matches[1];
            return match ($interp) {
                'node', 'nodejs' => 'javascript',
                'python', 'python3', 'python2' => 'python',
                'ruby', 'rbenv' => 'ruby',
                'php' => 'php',
                'perl', 'perl5' => 'perl',
                'bash', 'sh', 'dash', 'zsh', 'fish' => 'bash',
                default => 'text',
            };
        }

        // Same E739 delimiter defect: direct-path shebangs (`#!/usr/local/bin/python3`) are
        // not in SHEBANG_MAP, and this arm was the only thing that could ever name them.
        if (preg_match('~^#!/([^\s]+)/(\w+)~', $firstLine, $matches)) {
            $interpreter = $matches[2];
            return match ($interpreter) {
                'php' => 'php',
                'python', 'python3' => 'python',
                'node' => 'javascript',
                'ruby' => 'ruby',
                'perl' => 'perl',
                default => 'text',
            };
        }

        return null;
    }

    private static function detectFromContent(string $content): string
    {
        // -044 (PERF-07): signatures live at the head of a real file; scanning
        // a whole megabyte per signature was pure waste. Cap the haystack.
        $haystack = substr($content, 0, self::CONTENT_SCAN_BYTES);
        $scores = [];

        foreach (self::CONTENT_SIGNATURES as $lang => $signatures) {
            $score = 0;
            foreach ($signatures as $sig) {
                // -012 (PHP-CS-03): match at word boundaries, not raw substring.
                // A bare str_contains let `fi` score bash inside "con-FI-g" (so
                // `config: true` won the priority tiebreak as bash) and let a
                // single `[` flag markdown for "[a, b, c]". signaturePattern()
                // guards the alnum edges of every token; repeated signatures
                // (a `<?php<?php…` blob) still count, one hit per occurrence.
                $score += (int) preg_match_all(self::signaturePattern($sig), $haystack);
            }
            if ($score > 0) {
                $scores[$lang] = $score;
            }
        }

        if ($scores === []) {
            return 'text';
        }

        arsort($scores);
        $top = array_key_first($scores);
        $topScore = $scores[$top];

        // Tie-break by fixed priority order
        foreach (self::SIGNATURE_PRIORITY as $lang) {
            if (isset($scores[$lang]) && $scores[$lang] === $topScore) {
                return $lang;
            }
        }

        return $top;
    }

    /** @var array<string,string> */
    private static array $patternCache = [];

    /**
     * Literal regex for a content signature, with lookarounds added only on the
     * edges that are word characters. `fi` -> `(?<!\w)fi(?!\w)` (so it stops
     * matching inside "config"); punctuation-only tokens like `: `, `[` or `  - `
     * have no alnum edges and stay verbatim. This is the "line-start/word-boundary"
     * arm chosen for -012; the bare-`[` markdown token was the one edge case word
     * boundaries cannot guard (it IS punctuation), so it was dropped from the
     * signature list rather than trusted.
     */
    private static function signaturePattern(string $sig): string
    {
        return self::$patternCache[$sig] ??= '/'
            . (preg_match('/^\w/', $sig) === 1 ? '(?<!\w)' : '')
            . preg_quote($sig, '/')
            . (preg_match('/\w$/', $sig) === 1 ? '(?!\w)' : '')
            . '/';
    }
}
