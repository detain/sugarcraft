<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Parser;

use SugarCraft\AnsiParser\Parser\DebugHandler as LibDebugHandler;

/*
 * Deprecated façade for sugar-ansi-parser's {@see LibDebugHandler}
 * (2026-11-13, sap-20 vterm-leg): this fork existed only to bound the log
 * (100_000 rows + 4096-batch eviction). The lib class now carries that ring
 * shape as its `maxLogRows:` arm — same vocabulary, same constants, plus a
 * signalable `$dropped` counter and `resetLog()` — so the fork folds onto
 * it and the FQN stays alive for shipped consumers. BC note: the fork
 * bounded by default; the lib's default is unbounded (the fixture
 * ergonomics sap-20 ruled). Consumers wanting the old residency ceiling
 * pass `maxLogRows: DebugHandler::MAX_LOG_ROWS`. The alias smoke test is
 * DebugHandlerFacadeTest; this lib's own tests import the canonical FQN.
 */

class_alias(LibDebugHandler::class, 'SugarCraft\Vterm\Parser\DebugHandler');
