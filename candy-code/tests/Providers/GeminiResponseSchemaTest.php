<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\GeminiProvider;

/**
 * C-PRV-5.d (PRV-3-P1): WHICH structured-output field this wire names, and
 * when it names neither.
 *
 * The pair that used to leave here - the operator's raw JSON Schema under
 * `responseSchema` plus `responseMimeType` - is the request a modern family
 * answers with a 400: `responseSchema` is the OpenAPI-subset `Schema`
 * message, which knows no `$schema`, no `$defs` and no dollar-spelled
 * `additionalProperties` (it spells those dollar-less), and Google's
 * GenerationConfig takes the standard document on the OTHER field,
 * `responseJsonSchema`, where `response_schema` must then be omitted. Both
 * fields still REQUIRE the mime type beside them, so "the pair travels
 * together or not at all" survives the rename; what changed is which half
 * carries it, the subset sanitiser for the families that never received the
 * modern field, the door for a step that also declares tools, and the
 * degrade for a subset document that cannot be expressed.
 *
 * Every claim pinned here is a claim about the DOCUMENT this builder
 * produces - the repo holds no GCP credentials, so nothing was verified
 * against a live call. The sources are the vendored GenerationConfig
 * document under vendor/google/cloud-ai-platform and Google's published
 * reference pages, the same basis the arm's own epistemic note names.
 */
final class GeminiResponseSchemaTest extends TestCase
{
    /** The smallest body that parses as a successful unary answer. */
    private const ANSWER = [
        'candidates' => [
            ['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP'],
        ],
    ];

    /** A tool declaration's shape is irrelevant to the door; its presence is not. */
    private const ONE_TOOL = [
        ['name' => 'read_file', 'description' => 'Read one file', 'input_schema' => ['type' => 'object']],
    ];

    // -------------------------------------------------------------------------
    // The modern field, on every id that is not a KNOWN-pre-2.5 family
    // -------------------------------------------------------------------------

    public function testAStandardDocumentRidesTheModernFieldUnsanitisedOnAModernFamily(): void
    {
        // The whole point of `responseJsonSchema` is that the standard
        // document is NOT reduced: the keys the older arm cannot name are
        // exactly the keys this field is documented for.
        $document = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'additionalProperties' => false,
            'required' => ['answer'],
        ];

        $body = $this->unaryBodyFor('gemini-2.5-pro-001', $document);

        $this->assertSame($document, $body['generationConfig']['responseJsonSchema'], 'the operator document is forwarded whole');
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType'], 'the mime type is required beside this field, not optional');
        $this->assertArrayNotHasKey('responseSchema', $body['generationConfig'], 'the two arms are mutually exclusive - sending both is the 400 this step exists to stop');
    }

    public function testAnIdTheFamilyTableDoesNotKnowIsNotTreatedAsAnAncientFamily(): void
    {
        // A proxy's alias, a self-hosted served name, a `gemma` row: none of
        // them is a KNOWN pre-2.5 Gemini family, and inventing a legacy path
        // for an unknown id is how a modern deployment loses structured
        // output. Absence of an answer is not an answer of "older".
        foreach (['gemma-3-27b', 'my-alias-of-truth', 'glm-4.6'] as $served) {
            $body = $this->unaryBodyFor($served, ['type' => 'string']);

            $this->assertArrayHasKey('responseJsonSchema', $body['generationConfig'], "id {$served} is outside both families the table knows; it keeps the modern field");
            $this->assertArrayNotHasKey('responseSchema', $body['generationConfig']);
        }
    }

    // -------------------------------------------------------------------------
    // The subset arm, for the families that never received the modern field
    // -------------------------------------------------------------------------

    public function testAPreTwoFiveFamilyTakesTheSubsetArmWithTheForeignKeysStripped(): void
    {
        $document = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string', 'additionalProperties' => false],
            ],
            '$defs' => ['unused' => ['type' => 'string']],
            'additionalProperties' => false,
            'required' => ['answer'],
        ];

        $body = $this->unaryBodyFor('gemini-2.0-flash-001', $document);

        $this->assertArrayNotHasKey('responseJsonSchema', $body['generationConfig'], 'a family the table knows predates the field is not sent a field it never received');
        $this->assertSame([
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
        ], $body['generationConfig']['responseSchema'], 'only the three keys the subset has no spelling for come off');
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
    }

    public function testTheSanitiserWalksSubSchemaPositionsAndNeverAPropertySpelling(): void
    {
        // `properties` is a NAME -> sub-schema map: a name that happens to
        // read `$schema` is data the model must emit, while the document
        // UNDER it is a sub-schema and is walked. `items` is one sub-schema
        // or a tuple of them, and the branch lists hold one per element.
        $document = [
            'type' => 'object',
            'properties' => [
                '$schema' => ['type' => 'string', 'additionalProperties' => true],
                'row' => ['type' => 'array', 'items' => ['type' => 'object', '$defs' => ['x' => ['type' => 'string']]]],
            ],
            'prefixItems' => [['type' => 'integer', '$schema' => 'draft'], ['type' => 'string']],
            'anyOf' => [['type' => 'null'], ['type' => 'number', 'additionalProperties' => false]],
        ];

        $body = $this->unaryBodyFor('gemini-1.5-pro', $document);

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                '$schema' => ['type' => 'string'],
                'row' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'prefixItems' => [['type' => 'integer'], ['type' => 'string']],
            'anyOf' => [['type' => 'null'], ['type' => 'number']],
        ], $body['generationConfig']['responseSchema']);
    }

    public function testASubsetDocumentThatStillNamesAReferenceDegradesToPlainOutput(): void
    {
        // With `$defs` stripped the reference resolves against nothing, and
        // the subset's dollar-less `ref` is a translation this arm refuses to
        // invent. The established posture for a document the field cannot
        // carry is plain output - never a request the caller cannot act on.
        $document = [
            'type' => 'object',
            'properties' => ['answer' => ['$ref' => '#/$defs/Text']],
            '$defs' => ['Text' => ['type' => 'string']],
        ];

        $body = $this->unaryBodyFor('gemini-2.0-flash', $document);

        $this->assertArrayNotHasKey('responseSchema', $body['generationConfig']);
        $this->assertArrayNotHasKey('responseJsonSchema', $body['generationConfig']);
        $this->assertArrayNotHasKey('responseMimeType', $body['generationConfig'], 'an unexpressible document sends neither half, so no mime type alone either');
    }

    // -------------------------------------------------------------------------
    // The door: a step that also declares tools is not this wire's to constrain
    // -------------------------------------------------------------------------

    public function testAStepCarryingFunctionDeclarationsSendsNeitherTheSchemaNorTheMimeType(): void
    {
        // Runtime hands the schema and the tool list on the SAME request (the
        // self-finishing candidate step), and this wire deliberately carries
        // no `tools` key at all. Constraining the answer to a shape here
        // would force prose where the turn needs a tool call; asking again
        // once the turn can answer is AUT-4's terminal-answer step, another
        // area's to decide.
        $body = $this->unaryBodyFor('gemini-2.5-flash', ['type' => 'object'], tools: self::ONE_TOOL);

        $this->assertArrayNotHasKey('responseSchema', $body['generationConfig']);
        $this->assertArrayNotHasKey('responseJsonSchema', $body['generationConfig']);
        $this->assertArrayNotHasKey('responseMimeType', $body['generationConfig']);

        // The same request WITHOUT the declarations is constrained again -
        // the door is the tools, not the schema or the family.
        $relaxed = $this->unaryBodyFor('gemini-2.5-flash', ['type' => 'object']);
        $this->assertSame(['type' => 'object'], $relaxed['generationConfig']['responseJsonSchema']);

        // An empty list is no declaration (Runtime nulls one itself; other
        // callers are not obliged to).
        $noTools = $this->unaryBodyFor('gemini-2.5-flash', ['type' => 'object'], tools: []);
        $this->assertArrayHasKey('responseJsonSchema', $noTools['generationConfig']);
    }

    // -------------------------------------------------------------------------
    // Seam
    // -------------------------------------------------------------------------

    /**
     * Drives one unary `complete()` and hands back the document it built.
     *
     * The transport closure is the repo's standing seam for this wire (the
     * same shape {@see GeminiProviderTest} uses); naming the model per call
     * is the whole reason this file carries its own copy rather than a
     * sibling of that helper.
     *
     * @param array<mixed>|string $schema
     * @param ?array<mixed>       $tools
     * @return array<string, mixed>
     */
    private function unaryBodyFor(string $model, array|string $schema, ?array $tools = null): array
    {
        $built = null;

        $provider = new GeminiProvider(
            'gsk-c-prv-5-d',
            'https://gemini.invalid',
            $model,
            http: static function (string $endpoint, array $body) use (&$built): array {
                $built = $body;

                return self::ANSWER;
            },
        );

        $response = $provider->complete(new CompleteRequest(
            model: $model,
            messages: [new UserMessage('hi')],
            tools: $tools,
            jsonSchema: $schema,
        ));

        $this->assertSame('ok', $response->content, 'the fixture document is the one this wire parses');
        $this->assertIsArray($built);

        return $built;
    }
}
