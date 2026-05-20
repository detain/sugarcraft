<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

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
            '{"', '"}', '": ', 'null', 'true', 'false',
        ],
        'yaml' => [
            '---', ': ', '  - ', 'true', 'false', 'null',
        ],
        'markdown' => [
            '# ', '## ', '### ', '- ', '* ', '```',
            '[', '](', '![',
        ],
    ];

    /**
     * Detect language from content using shebang and content heuristics.
     */
    public static function detect(string $content): string
    {
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
            'sh' => 'bash',
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

        // Partial shebang match (e.g., #!/usr/bin/env python3)
        foreach (self::SHEBANG_MAP as $shebang => $lang) {
            if (str_contains($firstLine, basename(explode(' ', $shebang)[0] ?? ''))) {
                // More specific match wins
                if ($lang === 'bash' && str_contains($firstLine, 'env ') && str_contains($firstLine, 'bash')) {
                    return 'bash';
                }
            }
        }

        // Try to extract interpreter name
        if (preg_match('#^#!/usr/bin/env\s+(\w+)#', $firstLine, $matches)) {
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

        if (preg_match('#^#!/([^\s]+)/(\w+)#', $firstLine, $matches)) {
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
        $scores = [];

        foreach (self::CONTENT_SIGNATURES as $lang => $signatures) {
            $score = 0;
            foreach ($signatures as $sig) {
                if (str_contains($content, $sig)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$lang] = $score;
            }
        }

        if ($scores === []) {
            return 'text';
        }

        // Return language with highest score
        arsort($scores);
        return array_key_first($scores);
    }
}
