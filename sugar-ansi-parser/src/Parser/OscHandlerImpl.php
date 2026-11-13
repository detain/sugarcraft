<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/*
 * Deprecated façade (finding 029, gate G4). The class was renamed to
 * {@see RecordingOscHandler} — a bare name for what it does (record the
 * window title and the active OSC 8 hyperlink) instead of the shape-only
 * `*Impl` suffix. This file keeps the old FQN alive: existing consumers
 * (`new OscHandlerImpl()`, `: OscHandlerImpl` type hints) resolve to the
 * SAME canonical class through the alias, so there is no behavioral fork
 * and no copied test suite — the smoke test lives in
 * `RecordingOscHandlerTest::testOldNameIsTheSameClass`. New code must name
 * RecordingOscHandler directly.
 */
class_alias(RecordingOscHandler::class, 'SugarCraft\AnsiParser\Parser\OscHandlerImpl');
