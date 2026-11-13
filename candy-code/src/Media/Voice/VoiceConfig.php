<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Voice;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Support\Log;

/**
 * The voice family's settings snapshot (C-TUI-6.l, GAP-MOD-23).
 *
 * ONE READ of the layered settings at the moment the recorder arms or a take
 * is transcribed — never a launch-time cache: the ruling answered 2026-10-28
 * keeps voice OFF until a USER-tier `voice.baseUrl` exists, so an answer that
 * went stale at launch could either dial a removed endpoint or ignore a
 * configured one. {@see Bootstrap::readUserConfig()} is fail-soft to the empty
 * array exactly like `Sd\Client::settings()`: an unreadable settings file
 * costs the user the voice feature, never a hard failure mid-keystroke.
 *
 * THE TIER IS THE SECURITY BOUNDARY, not a scoping shortcut: `voice.baseUrl`
 * decides where a spoken recording EGRESSS to, which is the direction a
 * checked-out repository must not steer. The rows are user-tier only by their
 * schema definitions (never listed in
 * {@see \SugarCraft\Code\Config\LayeredSettings::PROJECT_TIER_KEYS}), so the
 * merge itself drops any project attempt before this class ever sees it.
 *
 * There is deliberately NO env binding on these rows: the sd family gained
 * `CANDY_CODE_SD_BASE_URL` because media generation is scripted from CI;
 * voice takes are an interactive act, and every extra env key is one more
 * {@see \SugarCraft\Code\Tests\Config\EnvRosterDriftTest} row the operator
 * must remember. A follow-up that wants scripted transcription adds the
 * binding, the roster row and the docs in the same step.
 */
final class VoiceConfig
{
    /** The OpenAI-compatible endpoint's default model id. */
    public const DEFAULT_MODEL = 'whisper-1';

    /** {@see \SugarCraft\Code\Config\Settings\Definitions\MediaSettings}' `voice.maxSeconds` range, restated as reads clamp to it. */
    public const MIN_SECONDS = 5;
    public const MAX_SECONDS = 600;
    public const DEFAULT_MAX_SECONDS = 120;

    private static ?string $readFailure = null;

    private function __construct(
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly ?string $language,
        public readonly int $maxSeconds,
    ) {
    }

    /**
     * Snapshot the five `voice.*` rows out of the merged layered settings.
     *
     * @param array<string, mixed>|null $settings explicit layer to read (tests); null reads the live config
     */
    public static function fromSettings(?array $settings = null): self
    {
        $settings ??= self::readSettings();

        $baseUrl = \is_string($settings['voice.baseUrl'] ?? null) ? trim($settings['voice.baseUrl']) : '';
        $apiKey = \is_string($settings['voice.apiKey'] ?? null) ? trim($settings['voice.apiKey']) : '';
        $modelRaw = \is_string($settings['voice.model'] ?? null) ? trim($settings['voice.model']) : '';
        $languageRaw = \is_string($settings['voice.language'] ?? null) ? trim($settings['voice.language']) : '';

        $maxSeconds = self::DEFAULT_MAX_SECONDS;
        $maxRaw = $settings['voice.maxSeconds'] ?? null;
        if (\is_int($maxRaw) || (\is_string($maxRaw) && preg_match('/^-?\d+$/', $maxRaw) === 1)) {
            $maxSeconds = (int) $maxRaw;
        }
        // Clamp, never trust the file: the schema validator bounds writes, but
        // a hand-edited config reaches the recorder cap — the SELF-STOP budget
        // of a child process — through this reader.
        $maxSeconds = max(self::MIN_SECONDS, min(self::MAX_SECONDS, $maxSeconds));

        return new self(
            $baseUrl,
            $apiKey,
            $modelRaw === '' ? self::DEFAULT_MODEL : $modelRaw,
            $languageRaw === '' ? null : $languageRaw,
            $maxSeconds,
        );
    }

    /** The all-off shape: no endpoint, nothing to dial. */
    public static function off(): self
    {
        return new self('', '', self::DEFAULT_MODEL, null, self::DEFAULT_MAX_SECONDS);
    }

    /** Voice is on exactly when a user-tier endpoint exists — the ruling's one law. */
    public function enabled(): bool
    {
        return $this->baseUrl !== '';
    }

    /**
     * The transcription route on THIS base. Built by rtrim+concat, not via a
     * Guzzle `base_uri` with a leading-slash path, which would silently drop a
     * `/v1` suffix the user spelled into the base.
     */
    public function transcriptionsUrl(): string
    {
        return \rtrim($this->baseUrl, '/') . '/audio/transcriptions';
    }

    /**
     * The reason the settings could not be read, or null while they could (or
     * the file simply does not exist). Latched per process: the first failure
     * is kept and logged once, repeated reads neither re-log nor overwrite it.
     */
    public static function readFailure(): ?string
    {
        return self::$readFailure;
    }

    /** @internal test seam: forget the latched failure so the next read may record again. */
    public static function resetReadFailure(): void
    {
        self::$readFailure = null;
    }

    /**
     * FAIL-SOFT, BUT NOT SILENT (candy-code-content-009, same fail direction as
     * {@see \SugarCraft\Code\Media\Sd\Client}'s `settings()`): this reader runs
     * mid-keystroke (recorder arm, take transcription), where a message has no
     * surface to land on, so an unreadable settings file reads as "voice off"
     * — the safe direction, since voice is OFF until a user-tier
     * `voice.baseUrl` exists. A file that is ABSENT is the normal unconfigured
     * state and says nothing. A file that is PRESENT but throws on read, or does
     * not decode to a JSON object, records its reason ONCE per process into
     * {@see readFailure()} and onto the app log, so "why is voice off?" has an
     * answer without spamming every keystroke.
     *
     * @return array<string, mixed>
     */
    private static function readSettings(): array
    {
        try {
            $settings = Bootstrap::readUserConfig();
        } catch (\Throwable $e) {
            self::recordFailure($e::class . ': ' . $e->getMessage());

            return [];
        }

        if (self::$readFailure === null) {
            self::probeUserFile();
        }

        return $settings;
    }

    /** Bootstrap's reader folds a corrupt file into [] silently; detect that here, once. */
    private static function probeUserFile(): void
    {
        $path = Bootstrap::userConfigPath();
        if (!\is_file($path)) {
            return;
        }

        $contents = @\file_get_contents($path);
        if ($contents === false) {
            self::recordFailure($path . ' is not readable');

            return;
        }

        if (!\is_array(\json_decode($contents, true))) {
            self::recordFailure($path . ' is not a valid JSON object');
        }
    }

    private static function recordFailure(string $reason): void
    {
        if (self::$readFailure !== null) {
            return;
        }

        self::$readFailure = $reason;
        Log::channel(Log::APP, 'voice settings unreadable (' . $reason . '); voice stays off until the file is repaired');
    }
}
