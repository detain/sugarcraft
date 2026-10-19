<?php

declare(strict_types=1);

namespace SugarCraft\Code;

/**
 * Type of file attachment.
 *
 * `Document` is the PDF family (CTX-2): bytes a wire carries as its own
 * document part, never as inlined text. `File` remains "text I can read to
 * the model"; routing a PDF down that road would inline binary as prose.
 */
enum AttachmentType
{
    case File;
    case Image;
    case Document;
}