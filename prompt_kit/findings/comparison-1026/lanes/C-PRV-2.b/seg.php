<?php
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
        ]), model: 'o3-mini'));
