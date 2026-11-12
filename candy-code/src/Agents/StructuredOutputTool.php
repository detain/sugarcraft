<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Protocol\Schema\SchemaValidator;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The terminal answer channel of a sub-agent that declares an `outputSchema`
 * (workflow-update P0-2): a per-run tool whose input schema IS the agent's
 * schema, so the answer is a tool call the harness validates instead of prose
 * a driver has to hope parses.
 *
 * WHY A TOOL AND NOT THE PROVIDER'S JSON MODE. A provider-native schema can
 * only constrain a reply that carries no tool calls (Runtime::jsonSchemaFor
 * applies it on a self-finishing request only), and the stages that most need
 * a verdict — reviewers, judges, verifiers — read code before they answer, so
 * the constraint fell off exactly where it mattered. A tool call survives any
 * number of earlier tool rounds.
 *
 * NOT A CATALOG BUILT-IN: there is no `#[BuiltInTool]` attribute, no launch
 * carries it, and no preset can grant or name it. {@see EngineExecutor} mints
 * one per run and appends it to that run's tool list. It is stateful — the
 * accepted answer is held on the instance — which is why it is never shared
 * between runs, and why it is not {@see \SugarCraft\Code\Tools\ParallelSafe}:
 * a forked call would record its answer in a child that then exits.
 * {@see \SugarCraft\Code\Runtime::gate()} lets it past the PreToolUse chain:
 * the call is the run's final answer, not an action on anything, and a
 * permission prompt for "may the agent answer?" would protect nothing while
 * stranding every non-interactive stage on an unanswerable question.
 *
 * Mirrors the reference harness's StructuredOutput terminal tool.
 */
final class StructuredOutputTool implements Tool
{
    public const NAME = 'StructuredOutput';

    /** The key a non-object schema is wrapped under — tool input must be an object. */
    public const WRAP_KEY = 'value';

    /**
     * The instruction appended to the agent's system prompt. Literal English,
     * like every other harness instruction a model reads.
     */
    public const INSTRUCTION = 'Your final answer is structured output. When you have finished any other work, '
        . 'end your run by calling the `' . self::NAME . '` tool exactly once, with your answer as its arguments; '
        . 'the arguments must match its input schema. Do not write the answer as plain text. If the call returns '
        . 'validation errors, correct the arguments and call it again. Your run ends when the call is accepted.';

    /** The one re-prompt a run that ended in plain text gets. */
    public const NUDGE = 'You ended your turn without calling `' . self::NAME . '`. '
        . 'Call `' . self::NAME . '` now with your final answer — nothing else is accepted as the result of this run.';

    private ?string $answer = null;

    private readonly bool $wrapped;

    /** @var array<string, mixed> */
    private readonly array $inputSchema;

    /**
     * @param array<string, mixed> $schema the agent's decoded outputSchema.
     *        The default (any object) exists only so the repo-wide Tool
     *        corpus can build one with no arguments; {@see EngineExecutor}
     *        always passes the agent's schema.
     */
    public function __construct(private readonly array $schema = ['type' => 'object'])
    {
        $this->wrapped = !self::isObjectSchema($schema);
        $this->inputSchema = $this->wrapped
            ? [
                'type' => 'object',
                'properties' => [self::WRAP_KEY => $schema],
                'required' => [self::WRAP_KEY],
                'additionalProperties' => false,
            ]
            : $schema;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Return your final answer as structured data. Call this exactly once, after any other tool use, '
            . 'with arguments matching the input schema'
            . ($this->wrapped ? ' (put the answer under "' . self::WRAP_KEY . '")' : '')
            . '. A call that does not match the schema is rejected with the reasons, and you call again; '
            . 'an accepted call ends your run.';
    }

    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    public function execute(array $args): ToolResult
    {
        $errors = $this->errors($args);
        if ($errors !== []) {
            return new ToolResult(
                toolCallId: '',
                content: "The arguments do not match the required output schema:\n- " . implode("\n- ", $errors)
                    . "\nCall " . self::NAME . ' again with corrected arguments.',
                isError: true,
            );
        }

        $this->answer = self::encode($this->wrapped ? $args[self::WRAP_KEY] : $args, !$this->wrapped);

        return new ToolResult(toolCallId: '', content: 'Accepted. Your run is complete; do not call any more tools.');
    }

    /** The accepted answer as a JSON document, or null while none has been accepted. */
    public function answer(): ?string
    {
        return $this->answer;
    }

    public function accepted(): bool
    {
        return $this->answer !== null;
    }

    /**
     * A plain-text reply that is ITSELF a valid answer — the whole reply is a
     * JSON document (optionally inside one ```json fence) that matches the
     * schema — as its JSON document; null otherwise. Prose never qualifies:
     * this exists so a model that answered with exactly the document is not
     * failed for skipping the call, never to accept anything less.
     */
    public function answerFromText(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/\A```(?:json)?\s*\n(.*)\n\s*```\z/s', $text, $fence) === 1) {
            $text = trim($fence[1]);
        }
        if ($text === '') {
            return null;
        }

        try {
            $value = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $errors = SchemaValidator::new(self::defs($this->schema))->errors($value, $this->schema);

        return $errors === [] ? self::encode($value, !$this->wrapped) : null;
    }

    /**
     * Every way $args breaks the input schema, as `path: expectation`.
     *
     * Checked with {@see SchemaValidator}, the validator the protocol layer
     * already trusts: `type` (incl. unions), `properties`, `required`,
     * `additionalProperties`, `items`, `enum`, `oneOf`, `$ref` into the
     * schema's own `$defs`, `maxItems`, `maxLength`, `minimum`, `maximum`.
     * Keywords outside that subset are not enforced.
     *
     * @param array<string, mixed> $args
     * @return list<string>
     */
    public function errors(array $args): array
    {
        return SchemaValidator::new(self::defs($this->schema))->errors($args, $this->inputSchema);
    }

    /** @param array<string, mixed> $schema */
    private static function isObjectSchema(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        return $type === 'object' || ($type === null && isset($schema['properties']) && \is_array($schema['properties']));
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private static function defs(array $schema): array
    {
        $defs = $schema['$defs'] ?? [];

        return \is_array($defs) ? $defs : [];
    }

    /**
     * JSON for the stage output. An object-typed answer that decoded to an
     * empty PHP array is written `{}`, not `[]`, so a reader that parses the
     * output gets the type the schema promised.
     */
    private static function encode(mixed $value, bool $asObject): string
    {
        if ($asObject && $value === []) {
            return '{}';
        }

        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }
}
