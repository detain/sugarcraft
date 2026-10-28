<?php declare(strict_types=1);
final class T { function a(): void {
    {
        $provider = $this->reasoningArmProvider(
            [new GuzzleResponse(200, [], json_encode($this->reasoningEmptyDoc(), JSON_THROW_ON_ERROR))],
            model: 'o3-mini',
        );

        $provider->complete($this->reasoningArmRequest([
            new UserMessage('go'),
            new AssistantMessage(
                'answered',
                [new ToolCall('call_r1', 'Read', ['path' => '/tmp/x'])],
                thinkingBlocks: [
                    // Own tagged item — replays.
                    ['type' => self::ITEM_TAG, 'id' => 'rs_1', 'encrypted_content' => 'enc_replayable'],
                    // The OTHER family's signed shape in a shared store —
                    // skipped on this arm, not forwarded.
                    ['type' => 'thinking', 'thinking' => 'foreign', 'signature' => 'sig'],
                    // Unreplayable halves — skipped.
                    ['type' => self::ITEM_TAG, 'id' => 'rs_x'],
                    ['type' => self::ITEM_TAG, 'encrypted_content' => ''],
                ],
            ),
        ]), model: 'o3-mini')));

        $input = $this->capturedReasoningBody()['input'];

        $this->assertSame('go', $input[0]['content'][0]['text'], 'the user turn ahead is untouched');
        // Generation order: the endpoint saw reasoning FIRST, then the call,
        // then the message — the replay re-presents that order as SIBLING
        // items or the encrypted reference breaks.
        $this->assertSame(['reasoning', 'function_call', 'message'], array_column(array_slice($input, 1, 3), 'type'));
        $this->assertSame([
            'type' => 'reasoning',
            'id' => 'rs_1',
            'encrypted_content' => 'enc_replayable',
        ], $input[1]);
        $this->assertSame([
            'type' => 'function_call',
            'call_id' => 'call_r1',
            'name' => 'Read',
            'arguments' => '{"path":"/tmp/x"}',
        ], $input[2]);
        $this->assertSame([
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'answered']],
        ], $input[3]);
    }


}
}
