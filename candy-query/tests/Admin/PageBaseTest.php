<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * Tests for PageBase lifecycle.
 */
final class PageBaseTest extends TestCase
{
    public function testPageBaseImplementsModel(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $page = new class($ctx) extends PageBase {
            protected function validate(): bool
            {
                return true;
            }

            protected function build(): string
            {
                return 'ok';
            }
        };

        $this->assertInstanceOf(\SugarCraft\Core\Model::class, $page);
    }

    public function testViewCallsBuildWhenValidated(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $page = new class($ctx) extends PageBase {
            protected function validate(): bool
            {
                return true;
            }

            protected function build(): string
            {
                return 'built content';
            }
        };

        $this->assertSame('built content', $page->view());
    }

    public function testViewCallsErrorScreenWhenValidationFails(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $page = new class($ctx) extends PageBase {
            protected function validate(): bool
            {
                $this->errorMessage = 'validation failed';
                return false;
            }

            protected function build(): string
            {
                return 'built content';
            }

            protected function errorScreen(): string
            {
                return 'Error: ' . $this->errorMessage;
            }
        };

        $this->assertSame('Error: validation failed', $page->view());
    }

    public function testRefreshCallsContextRefresh(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->expects($this->once())->method('refresh');

        $page = new class($ctx) extends PageBase {
            protected function validate(): bool
            {
                return true;
            }

            protected function build(): string
            {
                return 'content';
            }
        };

        $page->refresh();
    }
}
