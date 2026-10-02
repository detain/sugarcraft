<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

/**
 * Optional capability of a {@see ToolCallParserInterface}: it can use the
 * offered tools' declared parameter types to type the values it recovers.
 *
 * A SEPARATE INTERFACE RATHER THAN A WIDENED ONE. `ToolCallParserInterface`
 * is a shipped strategy seam with three implementations, and only a parser
 * whose markup carries no type information
 * ({@see MinimaxXmlFallbackToolCallParser}, audit 15a A9) has any use for a
 * schema. Widening the seam would force a no-op onto
 * {@see OpenAiArrayToolCallParser}, whose server-decoded JSON is already
 * typed. The provider asks `instanceof ToolSchemaAware` - a question about a
 * CAPABILITY, not about which concrete parser it holds, so any future parser
 * opts in by implementing it and no call site names a concrete class.
 *
 * A decorator that holds a delegate parser (both fallbacks do) forwards the
 * types to that delegate when it, too, is schema-aware, so a stacked chain
 * like `DsmlToolCallParser::new(MinimaxXmlFallbackToolCallParser::new())`
 * types its inner MiniMax recovery as well.
 */
interface ToolSchemaAware
{
    /**
     * A copy of this parser that consults `$types` - immutable, so one
     * provider-held parser can serve concurrent requests offering different
     * tool sets without any request seeing another's schema.
     */
    public function withParameterTypes(ToolParameterTypes $types): static;
}
