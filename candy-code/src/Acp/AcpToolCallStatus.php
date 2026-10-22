<?php

declare(strict_types=1);

namespace SugarCraft\Code\Acp;

/**
 * The ACP wire vocabulary for a tool-call's status (v4-F15).
 *
 * These four backing values are the literal strings the Agent Client
 * Protocol carries in a tool_call / tool_call_update frame's `status`
 * field — they are WIRE BYTES, not internal state, so they never change
 * spelling (the existing ACP prompt-stream and session-load pins assert
 * the serialized bytes, and they must stay green untouched). The
 * STATUS_* constants on {@see AcpUpdateMapper} remain the emitted
 * spelling for every caller, including the out-of-scope permission
 * bridge; a lockstep pin in AcpPromptStreamTest keeps constants and cases
 * one vocabulary.
 */
enum AcpToolCallStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
}
