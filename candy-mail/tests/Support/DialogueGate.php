<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests\Support;

use PHPUnit\Framework\SkippedWithMessageException;

/**
 * CM-60: forked-dialogue coverage is a hard gate in CI, an honest skip
 * locally.
 *
 * Every transport behaviour worth trusting (EHLO negotiation, STARTTLS
 * upgrade, DATA framing, the Resend HTTP conversation) is pinned against a
 * forked fake server. When pcntl is missing those tests skip - and a green
 * shard that silently skipped its security dialogue reads exactly like a
 * shard that ran it. The CANDY_MAIL_REQUIRE_DIALOGUE_TESTS environment flag
 * closes that hole for CI legs that promise the dialogues: with the flag on
 * (any boolean-true word), a missing pcntl fails the suite instead of
 * skipping it.
 *
 * Local exotics keep skipping: a loopback bind failure, a fork() that
 * returns -1 at runtime, or a missing ext-openssl certificate toolkit are
 * machine faults, not missing-extension faults - failing on them would
 * punish honest laptops without protecting the CI claim.
 */
final class DialogueGate
{
    /**
     * TEST-ONLY seam: forces the availability answer so both arms of the
     * gate can be pinned on a box that actually has pcntl (ext cannot be
     * unloaded at runtime). Null = consult the real extensions.
     */
    public static ?bool $assumeAvailable = null;

    public static function requireFork(): void
    {
        self::enforce(['pcntl_fork', 'pcntl_waitpid'], 'ext-pcntl');
    }

    public static function requireCurlAndFork(): void
    {
        self::enforce(['curl_init', 'pcntl_fork', 'pcntl_waitpid'], 'ext-curl or ext-pcntl');
    }

    /**
     * @param list<string> $functions  every runtime piece the dialogue needs
     * @param string       $label      how the skip/fail names the missing piece
     */
    private static function enforce(array $functions, string $label): void
    {
        $available = self::$assumeAvailable;
        if ($available === null) {
            $available = true;
            foreach ($functions as $fn) {
                if (!\function_exists($fn)) {
                    $available = false;
                    break;
                }
            }
        }
        if ($available) {
            return;
        }

        $raw = (string) ($_ENV['CANDY_MAIL_REQUIRE_DIALOGUE_TESTS'] ?? \getenv('CANDY_MAIL_REQUIRE_DIALOGUE_TESTS') ?: '');
        if (\filter_var($raw, \FILTER_VALIDATE_BOOLEAN) === true) {
            throw new \RuntimeException(\sprintf(
                'CANDY_MAIL_REQUIRE_DIALOGUE_TESTS is on, but %s is unavailable: dialogue coverage was promised and must fail, not skip',
                $label
            ));
        }

        throw new SkippedWithMessageException(\sprintf('%s unavailable', $label));
    }
}
