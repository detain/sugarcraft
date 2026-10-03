<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Sections;

use SugarCraft\Crush\Providers\ModelFamily;

/**
 * The per-model-family paragraph of the base identity prompt (roadmap 5.10):
 * a short note for the families sugar-crush is run against — DeepSeek-V4,
 * Qwen3.8 and MiniMax — plus Aider's two end-of-prompt reminders, `lazy` and
 * `overeager`, switched on per family.
 *
 * NOT A SLOT OF ITS OWN. {@see \SugarCraft\Crush\Runtime::basePrompt()} folds
 * this text into slot 1, at the end of its "# Acting vs. asking" section,
 * so the prompt keeps its eleven slots (a twelfth would move ARCHITECTURE.md,
 * PROMPT_ENGINEERING.md and ArchitectureAssemblyOrderTest for no gain): the
 * paragraph is base-identity voice, author bytes with no untrusted input,
 * exactly like the heredoc around it. {@see ModelFamily::Other} — every
 * frontier model, every unknown id, the offline echo provider — renders
 * nothing, so the base those models see is byte-identical to the base before
 * this class existed.
 *
 * CACHE STABILITY. The bytes are a pure function of the family, and the
 * family is resolved once per session and model
 * ({@see \SugarCraft\Crush\Runtime::basePrompt()} memoises it), so the
 * paragraph never moves inside a session's cached prefix.
 *
 * WHY THESE FLAGS. Aider ships `lazy_prompt` ("You NEVER leave comments
 * describing code without implementing it!") and `overeager_prompt` ("Do
 * what they ask, but no more") and turns them on per model in
 * `model-settings.yml`. The texts here say the same two things in this
 * project's register — reasons rather than emphasis, the §4.7 rule
 * {@see MaximsSection} documents — and all three named families carry both,
 * because the open-weight agentic models this harness drives are the ones
 * Aider most often flags. That is a chosen default, not a measurement on
 * this deployment: a family whose transcripts show it does not need one is
 * the place to flip a flag in {@see of()}, and the flags are public so the
 * choice is testable rather than buried in prose.
 *
 * WHY THE FAMILY LEADS ARE PHRASED AS ADVICE. The base prompt's standard
 * (see `Runtime::basePrompt()`'s comment) is that every clause names the code
 * that makes it true. A family lead therefore never asserts a wire fact that
 * depends on configuration: DeepSeek-V4 and MiniMax can write a tool call as
 * text (DSML, `<minimax:tool_call>`), and whether such text runs depends on
 * the `toolCallParser` the operator armed, so the lead says a text call "is
 * not guaranteed to run" — true under every configuration — rather than
 * either "is ignored" or "is recovered".
 *
 * This is a SugarCraft architecture section, not a port: no `Mirrors
 * charmbracelet/...` citation attaches to it.
 */
final readonly class FamilyPrompt
{
    /** Aider's `lazy_prompt`, restated: write the whole change. */
    public const LAZY = "Write the whole change. A comment standing in for code, or an ellipsis in\n"
        . "place of lines you did not write, hands the user unfinished work that reads\n"
        . 'as finished.';

    /** Aider's `overeager_prompt`, restated: stay inside the request. */
    public const OVEREAGER = "Keep to the scope of the request: do what was asked and stop there. Leave\n"
        . "code outside the change as it was — no unrequested refactors, reformatting\n"
        . 'or fixes — and mention what you noticed instead of changing it.';

    /** The lead for the two families with a textual tool-call syntax of their own. */
    private const TEXT_TOOL_CALL_LEAD = "Make each tool call through the tool-calling interface, with its arguments\n"
        . "as the tool's schema names them. A call written into your reply as text is\n"
        . 'not guaranteed to run.';

    /** The Qwen3.8 lead: a hybrid-thinking model spends its budget per step. */
    private const QWEN_LEAD = "Match the depth of your reasoning to the step in front of you: reading a\n"
        . "file or making a one-line edit needs little deliberation, and a design\n"
        . 'decision needs more.';

    private function __construct(
        public ModelFamily $family,
        public string $lead,
        public bool $lazy,
        public bool $overeager,
    ) {
    }

    /** The paragraph for `$family`; {@see ModelFamily::Other} renders nothing. */
    public static function of(ModelFamily $family): self
    {
        return match ($family) {
            ModelFamily::DeepSeekV4 => new self($family, self::TEXT_TOOL_CALL_LEAD, lazy: true, overeager: true),
            ModelFamily::Qwen => new self($family, self::QWEN_LEAD, lazy: true, overeager: true),
            ModelFamily::MiniMax => new self($family, self::TEXT_TOOL_CALL_LEAD, lazy: true, overeager: true),
            ModelFamily::Other => new self($family, '', lazy: false, overeager: false),
        };
    }

    /**
     * The paragraphs, joined by one blank line, with no leading or trailing
     * separator (the base prompt owns those); '' when the family has nothing
     * to add.
     */
    public function render(): string
    {
        $parts = array_values(array_filter(
            [$this->lead, $this->lazy ? self::LAZY : '', $this->overeager ? self::OVEREAGER : ''],
            static fn (string $part): bool => $part !== '',
        ));

        return implode("\n\n", $parts);
    }
}
