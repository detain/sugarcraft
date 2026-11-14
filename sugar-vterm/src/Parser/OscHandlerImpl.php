<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Parser;

/*
 * Deprecated façade for {@see PenForwardingOscHandler} (2026-11-13, sap-19
 * vterm-leg): the `*Impl` suffix named a shape, not a behavior — the class
 * records the window title AND forwards OSC 8 links to the renderer pen.
 * The FQN stays alive via this alias so shipped consumers do not break;
 * new code spells the behavior name. This class is deliberately NOT an
 * alias onto sugar-ansi-parser's RecordingOscHandler: the pen-sink wiring
 * and typed Hyperlink objects are vterm behavior the lib class must not
 * absorb (the pre-fold diff the sap-19 parser-leg disclosed). The alias
 * smoke test is PenForwardingOscHandlerTest::testOldNameIsTheSameClass.
 */

class_alias(PenForwardingOscHandler::class, 'SugarCraft\Vterm\Parser\OscHandlerImpl');
