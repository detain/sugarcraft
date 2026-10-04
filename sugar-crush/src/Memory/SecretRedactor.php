<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * Finds credentials in free text and blanks them out (roadmap 5.2).
 *
 * Auto-memory sends a slice of the conversation to a model and writes what
 * comes back into files that are re-injected into every later prompt — and,
 * for a project note, into the repository's git-visible
 * `.sugar-crush/memory/`. A token pasted into the chat must survive neither
 * trip, so {@see AutoMemoryConsolidator} redacts the transcript before the
 * request and refuses (rather than redacts) any proposed note that still
 * {@see containsSecret()}: a note with a hole where its point was is not worth
 * keeping.
 *
 * The patterns follow Kilo's `MemoryRedact`: well-known key prefixes (OpenAI /
 * Anthropic `sk-`, GitHub `gh?_` and `github_pat_`, Google `AIza`, Slack
 * `xox?-`, AWS `AKIA`/`ASIA`, GitLab `glpat-`, npm, Hugging Face, Stripe live
 * keys), JWTs, `Bearer` tokens, PEM private-key blocks, the password in a
 * URL's userinfo (`scheme://user:pass@host`; an `scp`-style `git@host:path`
 * has no password and is left alone), and `password=` / `api_key:` style
 * assignments whose value looks random enough to be a real secret. It is a
 * heuristic: it errs towards redacting, and a novel secret format can still
 * get through, which is why the request also goes to the user's own provider
 * only and the notes stay owner-only on disk.
 */
final class SecretRedactor
{
    public const MARKER = '[REDACTED]';

    /**
     * Whole-match patterns: the entire match is the secret.
     *
     * @var list<string>
     */
    private const TOKEN_PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|\z)/s',
        '/\bsk-(?:ant-|proj-)?[A-Za-z0-9_-]{16,}/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/\bAIza[0-9A-Za-z_-]{30,}/',
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\bglpat-[A-Za-z0-9_-]{20,}/',
        '/\bnpm_[A-Za-z0-9]{36}\b/',
        '/\bhf_[A-Za-z0-9]{30,}/',
        '/\b[rs]k_live_[0-9A-Za-z]{16,}/',
        '/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
    ];

    /** `Bearer <token>`: the scheme word stays, the token goes. */
    private const BEARER_PATTERN = '/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]{16,}/i';

    /** `scheme://user:password@`: the user stays, the password goes. */
    private const USERINFO_PATTERN = '#\b([a-z][a-z0-9+.-]*://[^\s/:@]+:)[^\s/@]+(@)#i';

    /**
     * `password = value`, `"api_key": "value"`, `export TOKEN=value`: the key
     * stays, a value that passes {@see looksRandom()} goes.
     */
    private const ASSIGNMENT_PATTERN = '/((?:\b|_)(?:pass(?:word|wd)?|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|auth[_-]?token|credentials?)\b["\']?\s*[:=]\s*["\']?)([^\s"\',;]{8,})/i';

    /** Shannon entropy, bits per character, a value must reach to count as random. */
    private const MIN_ENTROPY = 3.0;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /** $text with every secret it finds replaced by {@see MARKER}. */
    public function redact(string $text): string
    {
        foreach (self::TOKEN_PATTERNS as $pattern) {
            $text = preg_replace($pattern, self::MARKER, $text) ?? $text;
        }

        $text = preg_replace(self::BEARER_PATTERN, '$1' . self::MARKER, $text) ?? $text;
        $text = preg_replace(self::USERINFO_PATTERN, '$1' . self::MARKER . '$2', $text) ?? $text;

        return preg_replace_callback(
            self::ASSIGNMENT_PATTERN,
            static fn(array $m): string => self::looksRandom($m[2]) ? $m[1] . self::MARKER : $m[0],
            $text,
        ) ?? $text;
    }

    /** Whether {@see redact()} would change $text. */
    public function containsSecret(string $text): bool
    {
        return $this->redact($text) !== $text;
    }

    /**
     * Whether an assigned value is a credential rather than a placeholder or a
     * reference: not already redacted, not a variable (`$TOKEN`, `${TOKEN}`,
     * `%TOKEN%`), not an `<angle placeholder>`, and random enough by entropy.
     */
    private static function looksRandom(string $value): bool
    {
        if ($value === self::MARKER || str_contains($value, self::MARKER)
            || preg_match('/^(?:\$\{?[A-Za-z_]|%[A-Za-z_]|<)/', $value) === 1) {
            return false;
        }

        $length = strlen($value);
        $entropy = 0.0;
        foreach (count_chars($value, 1) as $count) {
            $p = $count / $length;
            $entropy -= $p * log($p, 2);
        }

        return $entropy >= self::MIN_ENTROPY;
    }
}
