<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session\Import;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Session\Import\CandyCodeExportReader;
use SugarCraft\Code\Session\Import\ClaudeCodeTranscriptReader;
use SugarCraft\Code\Session\Import\CodexTranscriptReader;
use SugarCraft\Code\Session\Import\ImportRefusedException;
use SugarCraft\Code\Session\Import\OpenCodeExportReader;

/**
 * C-SES-7.a — one fixture set per import format, pinned to the producer
 * version named in each reader's docblock. The three laws every reader
 * shares are asserted per format: foreign tool activity flattens to text
 * rows that carry nothing replayable, every text passes the secret
 * redactor, and a drifted shape raises the typed refusal instead of
 * guessing.
 */
final class TranscriptReadersTest extends TestCase
{
    private const ROOMY_ITEM_BOUND = 1000;

    public function testCandyCodeExportRoundTripsItsOwnToolRows(): void
    {
        $raw = json_encode([
            ['role' => 'user', 'content' => 'crush hello'],
            ['role' => 'assistant', 'content' => 'running', 'tool_calls' => [
                ['name' => 'Read', 'arguments' => ['path' => 'notes.txt'], 'id' => 't1'],
            ]],
            ['role' => 'assistant', 'content' => 'done', 'tool_results' => [
                ['role' => 'tool', 'tool_call_id' => 't1', 'name' => 'Read', 'content' => 'file body', 'is_error' => false],
            ]],
            ['role' => 'system', 'content' => 'plain system line'],
        ]);

        $out = CandyCodeExportReader::read($raw, SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $this->assertCount(4, $out['messages']);
        $this->assertNull($out['title']);
        [$user, $caller, $carrier, $system] = $out['messages'];
        $this->assertSame('crush hello', $user->content);
        $this->assertSame('Read', $caller->toolCalls[0]->name);
        $this->assertSame(['path' => 'notes.txt'], $caller->toolCalls[0]->arguments);
        $this->assertSame('file body', $carrier->toolResults[0]->result);
        $this->assertNull($carrier->toolResults[0]->error);
        $this->assertFalse($system->uiOnly, 'an own-format system row stays a system row');
    }

    public function testCandyCodeErrorResultSplitsBackIntoTheErrorArm(): void
    {
        $raw = json_encode([[
            'role' => 'assistant',
            'content' => 'failed',
            'tool_results' => [
                ['role' => 'tool', 'tool_call_id' => 't9', 'name' => 'Bash', 'content' => 'exit 2', 'is_error' => true],
            ],
        ]]);

        $out = CandyCodeExportReader::read($raw, SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $this->assertSame('exit 2', $out['messages'][0]->toolResults[0]->error);
        $this->assertSame('', $out['messages'][0]->toolResults[0]->result);
    }

    public function testCandyCodeRefusesRowsItDoesNotRecognise(): void
    {
        $this->expectException(ImportRefusedException::class);
        CandyCodeExportReader::read('[{"role":"tool","content":"x"}]', SecretRedactor::new(), self::ROOMY_ITEM_BOUND);
    }

    public function testCandyCodeRefusesPastTheItemBound(): void
    {
        $raw = json_encode([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
        ]);

        $this->expectException(ImportRefusedException::class);
        CandyCodeExportReader::read($raw, SecretRedactor::new(), 2);
    }

    public function testClaudeCodeFlattensToolRowsAndSkipsItsBookkeepingFamily(): void
    {
        $out = ClaudeCodeTranscriptReader::read($this->claudeCodeFixture(), SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $roles = array_map(static fn(Message $m): string => $m->role->value, $out['messages']);
        $this->assertSame(['user', 'assistant', 'system', 'system', 'system'], $roles);
        $this->assertSame('plain hello', $out['messages'][0]->content);
        $this->assertSame('I will run a thing', $out['messages'][1]->content);

        $call = $out['messages'][2];
        $this->assertTrue($call->uiOnly, 'flattened tool activity rides the untrusted notice channel');
        $this->assertStringStartsWith('[Tool call] Bash ', $call->content);
        $result = $out['messages'][3];
        $this->assertSame('[Tool result] done 42', $result->content);
        $this->assertSame('a system notice', $out['messages'][4]->content);

        foreach ($out['messages'] as $message) {
            $this->assertSame([], $message->toolCalls, 'no imported row may carry a replayable call');
            $this->assertSame([], $message->toolResults, 'no imported row may carry a replayable result');
        }
    }

    public function testClaudeCodeRedactsSecretsItMeets(): void
    {
        $out = ClaudeCodeTranscriptReader::read($this->claudeCodeFixture(), SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $this->assertStringContainsString('[REDACTED]', $out['messages'][2]->content);
        $this->assertStringNotContainsString('sk-FAKEFAKEFAKEFAKE', $out['messages'][2]->content);
    }

    public function testClaudeCodeCarriesItsTimestamps(): void
    {
        $out = ClaudeCodeTranscriptReader::read($this->claudeCodeFixture(), SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $this->assertSame(1759320000, $out['messages'][0]->createdAt);
    }

    public function testClaudeCodeRefusesAnUnknownEnvelopeType(): void
    {
        $this->expectException(ImportRefusedException::class);
        ClaudeCodeTranscriptReader::read(
            '{"type":"vibe-check","message":{"role":"user","content":"x"}}',
            SecretRedactor::new(),
            self::ROOMY_ITEM_BOUND,
        );
    }

    public function testClaudeCodeRefusesAnUnknownContentBlock(): void
    {
        $this->expectException(ImportRefusedException::class);
        ClaudeCodeTranscriptReader::read(
            '{"type":"assistant","message":{"role":"assistant","content":[{"type":"hologram","data":1}]}}',
            SecretRedactor::new(),
            self::ROOMY_ITEM_BOUND,
        );
    }

    public function testCodexSkipsItsEventStreamAndFlattensFunctionActivity(): void
    {
        $out = CodexTranscriptReader::read($this->codexFixture(), SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $roles = array_map(static fn(Message $m): string => $m->role->value, $out['messages']);
        $this->assertSame(['user', 'assistant', 'system', 'system'], $roles);
        $this->assertSame('codex hello', $out['messages'][0]->content);
        $this->assertStringContainsString('[REDACTED]', $out['messages'][1]->content);
        $this->assertStringStartsWith('[Tool call] shell ', $out['messages'][2]->content);
        $this->assertTrue($out['messages'][2]->uiOnly);
        $this->assertSame('[Tool result] listing done', $out['messages'][3]->content);
        foreach ($out['messages'] as $message) {
            $this->assertSame([], $message->toolCalls);
            $this->assertSame([], $message->toolResults);
        }
    }

    public function testCodexRefusesAnUnknownResponseItem(): void
    {
        $this->expectException(ImportRefusedException::class);
        CodexTranscriptReader::read(
            '{"timestamp":"2026-10-01T12:00:00+00:00","type":"response_item","payload":{"type":"web_search_call","query":"x"}}',
            SecretRedactor::new(),
            self::ROOMY_ITEM_BOUND,
        );
    }

    public function testOpenCodeReadsInfoPartsPairsAndNamesItsTitle(): void
    {
        $out = OpenCodeExportReader::read($this->openCodeFixture(), SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $this->assertSame('my session', $out['title']);
        $this->assertCount(3, $out['messages']);
        $this->assertSame('opencode hello', $out['messages'][0]->content);
        $this->assertSame('I use tools', $out['messages'][1]->content);
        $this->assertTrue($out['messages'][2]->uiOnly);
        $this->assertStringStartsWith('[Tool call] bash ', $out['messages'][2]->content);
        $this->assertStringNotContainsString('AKIAFAKEFAKEFAKEFAKE', $out['messages'][2]->content);
        $this->assertStringNotContainsString('never typed', $out['messages'][0]->content, 'synthetic parts do not land');
        foreach ($out['messages'] as $message) {
            $this->assertSame([], $message->toolCalls);
            $this->assertSame([], $message->toolResults);
        }
    }

    public function testOpenCodeIgnoresTheIgnoredFlagEntirely(): void
    {
        $out = OpenCodeExportReader::read($this->openCodeFixture(), SecretRedactor::new(), self::ROOMY_ITEM_BOUND);

        $joined = implode("\n", array_map(static fn(Message $m): string => $m->content, $out['messages']));
        $this->assertStringNotContainsString('dropped from import', $joined);
    }

    public function testOpenCodeRefusesAnUnknownPartType(): void
    {
        $this->expectException(ImportRefusedException::class);
        OpenCodeExportReader::read(json_encode([
            'info' => ['title' => 't'],
            'messages' => [[
                'info' => ['role' => 'user'],
                'parts' => [['type' => 'hologram']],
            ]],
        ]), SecretRedactor::new(), self::ROOMY_ITEM_BOUND);
    }

    private function claudeCodeFixture(): string
    {
        // Pinned against Claude Code 2.1.x project JSONL.
        $lines = [
            '{"type":"user","timestamp":"2025-10-01T12:00:00.000Z","sessionId":"s1","message":{"role":"user","content":"plain hello"}}',
            '{"type":"assistant","timestamp":"2025-10-01T12:00:05.000Z","message":{"role":"assistant","content":[{"type":"text","text":"I will run a thing"},{"type":"tool_use","name":"Bash","input":{"command":"echo sk-FAKEFAKEFAKEFAKE"},"id":"toolu_1"}]}}',
            '{"type":"user","timestamp":"2025-10-01T12:00:06.000Z","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_1","content":[{"type":"text","text":"done 42"}]}]}}',
            '{"type":"system","timestamp":"2025-10-01T12:00:07.000Z","content":"a system notice"}',
            '{"type":"file-history-snapshot","snapshot":{"files":[]}}',
            '{"type":"assistant","isSidechain":true,"timestamp":"2025-10-01T12:00:08.000Z","message":{"role":"assistant","content":"hidden subagent chatter"}}',
            '{"type":"user","isMeta":true,"timestamp":"2025-10-01T12:00:09.000Z","message":{"role":"user","content":"meta injected context"}}',
            '{"type":"cost-state","totalCostUSD":1.25}',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function codexFixture(): string
    {
        // Pinned against the Codex CLI rollout JSONL shape (cli_version 0.46.x).
        $lines = [
            '{"timestamp":"2025-10-01T12:00:00+00:00","type":"session_meta","payload":{"id":"019abc","cwd":"/tmp/x","cli_version":"0.46.0"}}',
            '{"timestamp":"2025-10-01T12:00:01+00:00","type":"turn_context","payload":{"model":"gpt-5"}}',
            '{"timestamp":"2025-10-01T12:00:02+00:00","type":"event_msg","payload":{"type":"token_count","total_token_usage":{"input_tokens":9}}}',
            '{"timestamp":"2025-10-01T12:00:03+00:00","type":"response_item","payload":{"type":"message","role":"user","content":[{"type":"input_text","text":"codex hello"}]}}',
            '{"timestamp":"2025-10-01T12:00:04+00:00","type":"response_item","payload":{"type":"message","role":"assistant","content":[{"type":"output_text","text":"ran it with ghp_FAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE"}]}}',
            '{"timestamp":"2025-10-01T12:00:05+00:00","type":"response_item","payload":{"type":"function_call","name":"shell","arguments":"{\"cmd\":\"ls\"}","call_id":"c1"}}',
            '{"timestamp":"2025-10-01T12:00:06+00:00","type":"response_item","payload":{"type":"function_call_output","call_id":"c1","output":"listing done"}}',
            '{"timestamp":"2025-10-01T12:00:07+00:00","type":"response_item","payload":{"type":"reasoning","summary":[]}}',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function openCodeFixture(): string
    {
        // Pinned against the OpenCode 2026.x export JSON (info/messages/parts).
        return json_encode([
            'info' => ['id' => 'ses_abc', 'title' => 'my session', 'time' => ['created' => 1759320000000]],
            'messages' => [
                [
                    'info' => ['role' => 'user', 'time' => ['created' => 1759320001000]],
                    'parts' => [
                        ['type' => 'step-start'],
                        ['type' => 'text', 'text' => 'opencode hello'],
                        ['type' => 'text', 'text' => 'injected never typed context', 'synthetic' => 1],
                    ],
                ],
                [
                    'info' => ['role' => 'assistant', 'time' => ['created' => 1759320002000]],
                    'parts' => [
                        ['type' => 'text', 'text' => 'I use tools'],
                        ['type' => 'tool', 'tool' => 'bash', 'state' => ['status' => 'completed', 'output' => 'hi AKIAFAKEFAKEFAKEFAKE']],
                    ],
                ],
                [
                    'info' => ['role' => 'assistant', 'time' => ['created' => 1759320003000]],
                    'parts' => [
                        ['type' => 'text', 'text' => 'dropped from import', 'ignored' => 1],
                    ],
                ],
            ],
        ]);
    }
}
