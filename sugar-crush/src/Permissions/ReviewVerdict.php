<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * What {@see ExecReviewer} decided about one flagged call (roadmap 5.11-2):
 * the decision, the risk it saw, and one sentence why.
 *
 * Parsed STRICTLY ({@see parse()}): the reviewer is a cheap model reading
 * text an attacker may have written, so anything other than the one JSON
 * object it was asked for — prose around it, an unknown decision or risk, an
 * `allow` claimed at high or unknown risk — is not a verdict, and the gate
 * treats "no verdict" as {@see PermissionDecision::Ask}. A reviewer that
 * cannot answer, or answers wrongly, puts the question to the person; it
 * never waves the call through.
 */
final readonly class ReviewVerdict
{
    public const RISKS = ['low', 'medium', 'high', 'unknown'];

    /** Longest rationale kept: it is shown in a one-line prompt. */
    public const MAX_RATIONALE = 200;

    private function __construct(
        public PermissionDecision $decision,
        public string $risk,
        public string $rationale,
    ) {
    }

    public static function new(PermissionDecision $decision, string $risk, string $rationale): self
    {
        if (!\in_array($risk, self::RISKS, true)) {
            throw new \InvalidArgumentException("unknown risk '{$risk}'");
        }

        return new self($decision, $risk, self::clip($rationale));
    }

    /**
     * The verdict a failed or unreadable review stands for: ask the person,
     * saying why the reviewer could not decide.
     */
    public static function unavailable(string $why): self
    {
        return new self(PermissionDecision::Ask, 'unknown', self::clip('the reviewer could not decide: ' . $why));
    }

    /**
     * The reviewer's reply as a verdict, or null when it is not exactly the
     * JSON object it was asked for. A reply wrapped in one ```json fence is
     * accepted — models add one unasked — and nothing else around it is.
     */
    public static function parse(string $raw): ?self
    {
        $text = trim($raw);
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/is', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        try {
            $data = json_decode($text, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) || array_is_list($data)) {
            return null;
        }

        $decision = match ($data['decision'] ?? null) {
            'allow' => PermissionDecision::Allow,
            'deny' => PermissionDecision::Deny,
            'ask' => PermissionDecision::Ask,
            default => null,
        };
        $risk = $data['risk'] ?? null;
        $rationale = $data['rationale'] ?? '';
        if ($decision === null || !\is_string($risk) || !\in_array($risk, self::RISKS, true) || !\is_string($rationale)) {
            return null;
        }

        // The prompt's own consistency rule: an allow is only credible at low
        // or medium risk. A reviewer that says "allow" and "high" in one
        // breath has not decided anything a gate should act on.
        if ($decision === PermissionDecision::Allow && !\in_array($risk, ['low', 'medium'], true)) {
            return null;
        }

        return new self($decision, $risk, self::clip($rationale));
    }

    /** One line naming the verdict, for the prompt or refusal the user sees. */
    public function describe(): string
    {
        $word = match ($this->decision) {
            PermissionDecision::Allow => 'allowed',
            PermissionDecision::Deny => 'denied',
            PermissionDecision::Ask => 'asked',
        };

        return "auto reviewer {$word} it ({$this->risk} risk)" . ($this->rationale === '' ? '' : ': ' . $this->rationale);
    }

    private static function clip(string $text): string
    {
        // Model-written, and shown in the permission prompt: whitespace runs
        // fold to one space and control characters (an ESC forging SGR) go.
        $line = trim(preg_replace(['/\s+/u', '/[\x00-\x1F\x7F\x{80}-\x{9F}]/u'], [' ', ''], $text) ?? '');

        return mb_strlen($line) > self::MAX_RATIONALE ? mb_substr($line, 0, self::MAX_RATIONALE - 1) . '…' : $line;
    }
}
