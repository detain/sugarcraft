<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Query\Db\Flavor;

/**
 * PostgreSQL stub dashboard adapter.
 *
 * Returns a single "coming soon" notice widget indicating that
 * PostgreSQL dashboard widgets are not yet implemented.
 *
 * @see PostgresAdminProvider
 */
final class PostgresDashboardAdapter
{
    private const NOTICE_CAPTION = 'Postgres support coming';
    private const NOTICE_MESSAGE = 'Postgres dashboard widgets are not yet implemented. Help wanted!';
    private const NOTICE_COLOR = ['r' => 255, 'g' => 200, 'b' => 87];

    public function __construct(
        private readonly Flavor $flavor,
    ) {}

    /**
     * Create a new instance.
     */
    public static function new(Flavor $flavor): self
    {
        return new self($flavor);
    }

    /**
     * Build the Postgres dashboard widget list.
     *
     * Returns a single notice widget instead of real metrics.
     *
     * @return list<Widget>
     */
    public function buildPostgresDashboard(): array
    {
        $noticeMessage = self::NOTICE_MESSAGE;

        return [
            new Widget(
                caption: self::NOTICE_CAPTION,
                kind: WidgetRegistry::KIND_COUNTER,
                calc: new class($noticeMessage) {
                    public function __construct(
                        private readonly string $noticeMessage,
                    ) {}

                    public function compute(array $current, array $previous, float $elapsed): float|string {
                        return $this->noticeMessage;
                    }
                },
                format: '%s',
                color: self::NOTICE_COLOR,
                tooltip: 'PostgreSQL dashboard support is not yet implemented. Contributions welcome!',
                serverVarsKeys: null,
            ),
        ];
    }

    /**
     * True when the flavor is actually Postgres.
     */
    public function supportsFlavor(Flavor $flavor): bool
    {
        return $flavor === Flavor::Postgres;
    }
}
