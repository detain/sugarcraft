<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

/**
 * Thrown into a parked {@see \SugarCraft\Core\Util\Semaphore} waiter when the
 * pool is retired before its permit could be granted.
 *
 * Promotion-born rather than moved: the phlix-console-client original rejected
 * parked waiters with a bare `\RuntimeException`, so this named type is part of
 * what the promotion added, not a copy of something the app already had.
 *
 * The reason this exists at all is discrimination: a promise returned by
 * `Semaphore::run()` rejects either because the *task* failed or because the
 * *pool* went away underneath it, and a caller must be able to tell those apart
 * by `instanceof`. Matching on the message is not an option, because the message
 * comes from `Lang::t()` and changes with the user's locale.
 *
 * Extends `\RuntimeException` rather than the neighbouring
 * {@see RuntimeException} on purpose: that one descends from `\Exception`, so
 * promoting it would silently drop shutdown rejections out of every caller's
 * existing `catch (\RuntimeException)` — the same BC argument that makes
 * `candy-async`'s `OperationCancelledException` extend `\RuntimeException`.
 *
 * A caller who asks an *already* closed pool for a permit gets
 * `\LogicException` instead: that call is a programming error at the call site,
 * while this rejection describes work that was legitimately submitted and then
 * orphaned by teardown.
 */
final class SemaphoreClosedException extends \RuntimeException
{
}
