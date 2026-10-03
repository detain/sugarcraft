<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * The model families sugar-crush treats differently (roadmap 5.10): the two
 * the SGLang provider already shapes its sampling, reasoning effort and
 * window around, plus MiniMax, which until now had no family test of its own.
 *
 * ONE FAMILY TEST PER FAMILY. DeepSeek-V4 and Qwen3.8 are decided by
 * {@see SglangProvider::isDeepSeekV4()} and {@see SglangProvider::isQwen3Next()}
 * — the predicates that already pick those families' sampling and window —
 * so the base prompt's per-family paragraph
 * ({@see \SugarCraft\Crush\Context\Sections\FamilyPrompt}) can never disagree
 * with the request shaping about what "DeepSeek-V4" means. The SGLang
 * served-model notice ({@see SglangProvider}'s `modelFamily()`) reads its
 * buckets from {@see of()} for the same reason.
 *
 * MiniMax is a case-insensitive `minimax` substring, the same shape as the two
 * SGLang tokens: the deployed ids carry an org prefix and a version suffix
 * (`MiniMaxAI/MiniMax-M2.7`), and the next release changes the suffix without
 * changing the family. It is deliberately the vendor name rather than one
 * generation, because nothing here keys a measured figure on it — only prompt
 * text, where an over-match costs a paragraph of advice, not a wrong number.
 *
 * The order of the arms matters only for an id that spells two families,
 * which no real id does; DeepSeek-V4 is checked first because it is the
 * family whose miss is the most expensive on the request side.
 *
 * This is a SugarCraft architecture type, not a port: no `Mirrors
 * charmbracelet/...` citation attaches to it.
 */
enum ModelFamily: string
{
    case DeepSeekV4 = 'deepseek-v4';
    case Qwen = 'qwen3.8';
    case MiniMax = 'minimax';
    case Other = 'other';

    /** The substring that names the MiniMax family, matched case-insensitively. */
    private const MINIMAX_TOKEN = 'minimax';

    /**
     * The family a model id belongs to. Every id no predicate claims is ONE
     * bucket, {@see Other}: two unknown ids carry the same behaviour, so a
     * spelling difference between them is not a family difference.
     */
    public static function of(string $model): self
    {
        return match (true) {
            SglangProvider::isDeepSeekV4($model) => self::DeepSeekV4,
            SglangProvider::isQwen3Next($model) => self::Qwen,
            str_contains(strtolower($model), self::MINIMAX_TOKEN) => self::MiniMax,
            default => self::Other,
        };
    }
}
