<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

/**
 * Optional capability interface for renderers whose construction-time config
 * changes the encoded bytes beyond what {@see Renderer::name()} identifies
 * (D-04).
 *
 * The poster disk cache keys on (url, size, protocol|scale) — but two
 * SixelRenderers both named 'sixel' produce different bytes under different
 * dither settings, and two ChafaRenderers both named 'chafa' differ by their
 * CLI options. Folding {@see configFingerprint()} into the cache key makes
 * same-protocol / different-config mosaics un-cross-servable.
 *
 * Why a capability interface instead of a required `Renderer` method with a
 * `''` default: PHP interfaces cannot carry default implementations, so
 * declaring the method on {@see Renderer} would force a body into every
 * implementer (six renderers plus the tmux decorator) — churn that would
 * collide with concurrent work on those files. The empty-string default is
 * realised structurally at the one call site,
 * {@see \SugarCraft\Image\Mosaic::rendererConfigFingerprint()}, which
 * answers `''` for every renderer not implementing this interface, so their
 * cache keys stay byte-identical. Same posture as the pre-existing optional
 * {@see \SugarCraft\Image\AsyncRenderer} capability in this library.
 */
interface ConfigFingerprintProvider
{
    /**
     * Stable, human-debuggable encoding of every construction config that
     * changes the bytes this renderer emits for otherwise-identical input
     * (key=value segments joined with '|'). Two instances with equal
     * fingerprints must be byte-equivalent encoders; differing configs must
     * yield differing fingerprints.
     */
    public function configFingerprint(): string;
}
