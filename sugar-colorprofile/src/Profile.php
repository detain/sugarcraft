<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

/**
 * @deprecated — {@see ColorProfile} is the real enum; this name no longer
 * exists as its own type — it is a class_alias onto ColorProfile, the single
 * consolidated color-depth
 * vocabulary (renderer and detection vocabularies carried the same five wire
 * values under drifting case spellings; PHP enums cannot alias cases, so the
 * unification is done by aliasing the TYPE and the two SCREAMING case names
 * via deprecated constants). Every old `Profile::X` spelling keeps resolving:
 * TrueColor/Ascii/NoTTY are real cases; ANSI256/ANSI are deprecated constant
 * aliases. New code must name ColorProfile directly.
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
class_alias(ColorProfile::class, Profile::class);
