<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

/**
 * The specific condition encountered when fetching replica status.
 *
 * Allows the UI to render distinct messages for each state:
 * - Configured: rows were returned (replication is set up)
 * - NotConfigured: query returned empty (no replica configured)
 * - PermissionDenied: error 1227 — user lacks REPLICATION CLIENT privilege
 * - Error: unexpected server error
 *
 * @see Mirrors mysql-workbench/wb_admin_replication
 */
enum ReplicaStatusKind
{
    /**
     * Rows were returned — at least one replica/channel is configured.
     */
    case Configured;

    /**
     * Query returned no rows — no replica is configured on this server.
     */
    case NotConfigured;

    /**
     * Error 1227: the user does not have REPLICATION CLIENT privilege.
     */
    case PermissionDenied;

    /**
     * An unexpected error occurred (network, server gone, etc.).
     */
    case Error;
}
