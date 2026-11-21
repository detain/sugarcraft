<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol;

/**
 * The candy-code.v1 serve protocol's wire constants.
 *
 * The values here are the wire contract. candy-code keeps its own constants
 * with the same values until it delegates to this class; both sides must
 * change together.
 */
final class Protocol
{
    /** The protocol MAJOR a server reports and a client negotiates. */
    public const MAJOR = 1;

    /** The one WebSocket subprotocol the server speaks. */
    public const SUBPROTOCOL = 'candy-code.v1';

    /** A non-browser client may carry its token as `candy-code.auth.<token>`. */
    public const AUTH_SUBPROTOCOL_PREFIX = 'candy-code.auth.';

    /** The largest message the server sends; a larger answer is refused. */
    public const MAX_SERVER_FRAME_BYTES = 4_194_304;

    /**
     * How long a pending permission question stays answerable; equal to the
     * host's approval expiry, so a client shows the deadline the server enforces.
     */
    public const ASK_EXPIRY_SECONDS = 1800;
}
