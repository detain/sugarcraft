<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Crush\Cli\Help;

/**
 * Byte-compatible codec for the A1111 'parameters' infotext block — the text
 * stable-diffusion-webui writes into PNG metadata and shows in its UI.
 *
 * Mirrors `modules/images.py::create_infotext` (emit) and
 * `modules/infotext_utils.py` + `infotext_key_and_value.py` (parse-back),
 * per plan_crush_media.md §3.5/§3.6.
 *
 * Layout: line 1 is the prompt, line 2 is `Negative prompt: …` (omitted when
 * the negative is empty), line 3 is the comma-separated parameter tail. Pairs
 * join with ", "; per §3.5 "any value containing `,` `\n` `:` is
 * `json.dumps`-quoted" — and un-quoted on parse, which makes the codec
 * round-trip safe against bodies that themselves look like parameter pairs.
 *
 * CONDITIONAL EMISSION follows the XSet law of the frozen DTOs: a field
 * appears only when explicitly set — unset is absent, and set-to-zero or
 * set-to-empty-string is present (`Seed: 0` round-trips; an unset seed never
 * appears). Fixed slot order is {@see InfotextFields::FIELDS}.
 *
 * Version policy (plan W1.6 review-brief): a parsed `Version:` key is replayed
 * byte-untouched — the backend's own stamp belongs to the backend. Our stamp
 * rides as an extra_generation_params-style pair `Sugar-crush: <ver>` spliced
 * immediately BEFORE `Version:`, sourced from Composer install metadata via
 * {@see Help::versionString()} so it never rots; a caller-supplied
 * 'Sugar-crush' extra wins verbatim, which is what makes replay byte-exact.
 *
 * parse() never throws: text without a recognisable parameter tail yields an
 * empty field map with the first line kept as the prompt, mirroring upstream's
 * `prompt_from_infotext` fall-through.
 */
final class Infotext
{
    /** Reserved parse-map keys for the prompt region (never emitted as pairs). */
    public const PROMPT_KEY = 'prompt';

    public const NEGATIVE_KEY = 'negative prompt';

    private const NEGATIVE_PREFIX = 'Negative prompt:';

    private function __construct()
    {
    }

    /**
     * Render the full A1111 parameters text for a params/request object.
     *
     * `$extra` is a Label=>value map of pairs the DTO does not model (Model,
     * VAE, Clip skip, ENSD, RNG, ControlNet rows, …) plus — for replay — any
     * canonical slot whose value should stand in where the DTO leaves the
     * field unset, and the reserved 'prompt'/'negative prompt' keys. DTO-set
     * values win the merge; leftover extras (labels outside the §3.5
     * skeleton) splice after the sampler-knob region and before the
     * `Sugar-crush`/`Version`/`User` bookends.
     *
     * @param array<string, mixed> $extra
     */
    public static function emit(
        GenerationParams|MediaRequest $params,
        array $extra = [],
        ?string $prompt = null,
    ): string {
        // Our provenance stamp unless the caller supplied one verbatim.
        if (!array_key_exists('Sugar-crush', $extra)) {
            $extra['Sugar-crush'] = Help::versionString();
        }
        $used = [];
        $resolve = static function (string $label) use (&$used, $params, $extra): ?string {
            $fromDto = self::dtoValue($label, $params);
            if ($fromDto !== null) {
                $used[$label] = true;

                return $fromDto;
            }
            if (array_key_exists($label, $extra)) {
                $used[$label] = true;
                $value = $extra[$label];

                return $value === null ? null : (string) $value;
            }

            return null;
        };
        // A1111 emits the Variation pair only when the strength is non-zero —
        // resolve both up front so neither can leak out singly.
        $strength = $resolve('Variation seed strength');
        $subseed = $resolve('Variation seed');
        $variationOn = $strength !== null && !(self::looksNumeric($strength) && (float) $strength === 0.0);

        $pairs = [];
        $bookends = [];
        foreach (array_keys(InfotextFields::FIELDS) as $label) {
            if ($label === 'Variation seed strength') {
                continue; // emitted as a pair from the 'Variation seed' arm
            }
            if ($label === 'Variation seed') {
                if ($variationOn && $subseed !== null) {
                    $pairs[] = 'Variation seed: ' . self::quoteValue($subseed);
                }
                if ($variationOn) {
                    $pairs[] = 'Variation seed strength: ' . self::quoteValue((string) $strength);
                }
                continue;
            }
            // §3.5: extra_generation_params splice BEFORE Version — so the
            // bookend trio is held back until the trailing extras are emitted.
            if ($label === 'Sugar-crush' || $label === 'Version' || $label === 'User') {
                $value = $resolve($label);
                if ($value !== null) {
                    $bookends[$label] = $label . ': ' . self::quoteValue($value);
                }
                continue;
            }
            $value = $resolve($label);
            if ($value === null || !self::gate($label, $value)) {
                continue;
            }
            $pairs[] = $label . ': ' . self::quoteValue($value);
        }
        foreach ($extra as $label => $value) {
            if (!is_string($label) || isset($used[$label])) {
                continue;
            }
            if ($label === self::PROMPT_KEY || $label === self::NEGATIVE_KEY) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[] = $label . ': ' . self::quoteValue((string) $value);
        }
        $pairs = [...$pairs, ...array_values($bookends)];

        return self::assemble(self::promptOf($params, $extra, $prompt), self::negativeOf($params, $extra), $pairs);
    }

    /**
     * Parse A1111 parameters text into a flat map: the reserved 'prompt' /
     * 'negative prompt' keys plus one entry per parameter pair, keyed by
     * CANONICAL label (LEGACY spellings folded onto it), values un-quoted
     * strings.
     *
     * Comments (`#…` to EOL) are stripped from the prompt region by default,
     * matching upstream's enable_prompt_comments behaviour; the parameter
     * region needs no stripping because §3.5 quoting makes '#' inert inside
     * quoted values and bare A1111 values never carry it.
     *
     * @return array<string, string>
     */
    public static function parse(string $text, bool $stripComments = true): array
    {
        $text = rtrim($text, "\r\n");
        if (trim($text) === '') {
            return [self::PROMPT_KEY => '', self::NEGATIVE_KEY => ''];
        }
        $lines = explode("\n", $text);
        $paramIndex = null;
        foreach ($lines as $i => $line) {
            if (self::startsParamLine($line)) {
                $paramIndex = $i; // last anchor wins: prompt bodies may mimic pairs
            }
        }
        if ($paramIndex === null) {
            return [
                self::PROMPT_KEY => self::stripComment($lines[0], $stripComments),
                self::NEGATIVE_KEY => '',
            ];
        }
        $fields = [];
        foreach (self::splitPairs($lines[$paramIndex]) as [$label, $value]) {
            $fields[InfotextFields::canonicalLabel($label)] = $value;
        }
        $prompt = [];
        $negative = [];
        $inNegative = false;
        for ($i = 0; $i < $paramIndex; $i++) {
            $line = $lines[$i];
            if (!$inNegative && str_starts_with($line, self::NEGATIVE_PREFIX)) {
                $inNegative = true;
                $negative[] = ltrim(substr($line, strlen(self::NEGATIVE_PREFIX)));
                continue;
            }
            if ($inNegative) {
                $negative[] = self::stripComment($line, $stripComments);
            } else {
                $prompt[] = self::stripComment($line, $stripComments);
            }
        }

        return [
            self::PROMPT_KEY => implode("\n", $prompt),
            self::NEGATIVE_KEY => implode("\n", $negative),
        ] + $fields;
    }

    /**
     * Load a parsed infotext map onto a MediaRequest through its withers.
     *
     * OnlyUnset law (plan W1.6 step 5): with `$onlyUnset` the request's own
     * explicit decisions always win — a field already set (and `Seed: 0`
     * counts as SET under the XSet sentinel law) is never overwritten by
     * pasted text. Values the frozen DTOs have no home for are preserved
     * verbatim under {@see MediaRequest::withUnknown()} so nothing parsed is
     * lost; malformed numerics are skipped rather than poisoning the request.
     *
     * @param array<string, string> $parsed output of {@see parse()}
     */
    public static function applyTo(MediaRequest $request, array $parsed, bool $onlyUnset = true): MediaRequest
    {
        $explicitScheduler = isset($parsed['Schedule type']);
        foreach ($parsed as $key => $raw) {
            $key = (string) $key;
            $raw = (string) $raw;
            if ($key === self::PROMPT_KEY) {
                if ($raw !== '') {
                    $request = self::apply($request, 'prompt', $onlyUnset, fn () => $request->withPrompt($raw));
                }
                continue;
            }
            if ($key === self::NEGATIVE_KEY) {
                if ($raw !== '') {
                    $request = self::apply($request, 'negativePrompt', $onlyUnset, fn () => $request->withNegativePrompt($raw));
                }
                continue;
            }
            $request = self::applyPair($request, $key, $raw, $onlyUnset, $explicitScheduler);
        }

        return $request;
    }

    // ------------------------------------------------------------------ emit

    private static function promptOf(GenerationParams|MediaRequest $params, array $extra, ?string $explicit): string
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if ($params instanceof MediaRequest && $params->prompt() !== null) {
            return $params->prompt();
        }

        return (string) ($extra[self::PROMPT_KEY] ?? '');
    }

    private static function negativeOf(GenerationParams|MediaRequest $params, array $extra): string
    {
        $fromDto = $params->negativePrompt();
        if ($fromDto !== null) {
            return $fromDto;
        }

        return (string) ($extra[self::NEGATIVE_KEY] ?? '');
    }

    /**
     * @param list<string> $pairs
     */
    private static function assemble(string $prompt, string $negative, array $pairs): string
    {
        $body = $prompt;
        if ($negative !== '') {
            $body .= ($body === '' ? '' : "\n") . self::NEGATIVE_PREFIX . ' ' . $negative;
        }
        $paramLine = implode(', ', $pairs);
        if ($paramLine === '') {
            return $body;
        }

        return $body === '' ? $paramLine : $body . "\n" . $paramLine;
    }

    /** CONDITIONAL slots beyond XSet-absence: Tiling rides only when True. */
    private static function gate(string $label, string $value): bool
    {
        return match ($label) {
            'Tiling' => strtolower($value) === 'true',
            default => true,
        };
    }

    /** Value of a canonical slot from the DTO, respecting the XSet law. */
    private static function dtoValue(string $label, GenerationParams|MediaRequest $params): ?string
    {
        $text = static function (?string $value): ?string {
            return $value;
        };
        $flag = static function (?bool $value): ?string {
            return $value === null ? null : ($value ? 'True' : 'False');
        };
        $num = static function (int|float|null $value): ?string {
            return $value === null ? null : (string) $value;
        };
        // (label, prop, reader) triples; PHP numeric stringification matches
        // the A1111 %g spellings the goldens carry (7.0 renders '7').
        return match ($label) {
            'Steps' => self::read($params, 'steps', $num, fn () => $params->steps()),
            'Sampler' => self::read($params, 'samplerName', $text, fn () => $params->samplerName()),
            'Schedule type' => self::read($params, 'scheduler', $text, fn () => $params->scheduler()),
            'CFG scale' => self::read($params, 'cfgScale', $num, fn () => $params->cfgScale()),
            'Image CFG scale' => self::read($params, 'imageCfgScale', $num, fn () => $params->imageCfgScale()),
            'Seed' => self::read($params, 'seed', $num, fn () => $params->seed()),
            'Face restoration' => self::read($params, 'restoreFaces', $flag, fn () => $params->restoreFaces()),
            'Size' => self::both($params, 'width', 'height')
                ? $params->width() . 'x' . $params->height()
                : null,
            'Variation seed' => self::read($params, 'subseed', $num, fn () => $params->subseed()),
            'Variation seed strength' => self::read($params, 'subseedStrength', $num, fn () => $params->subseedStrength()),
            'Seed resize from' => self::both($params, 'seedResizeFromH', 'seedResizeFromW')
                ? $params->seedResizeFromH() . 'x' . $params->seedResizeFromW()
                : null,
            'Denoising strength' => self::read($params, 'denoisingStrength', $num, fn () => $params->denoisingStrength()),
            'Token merging ratio' => self::read($params, 'tokenMergingRatio', $num, fn () => $params->tokenMergingRatio()),
            'Token merging ratio hr' => self::read($params, 'tokenMergingRatioHr', $num, fn () => $params->tokenMergingRatioHr()),
            'Tiling' => self::read($params, 'tiling', $flag, fn () => $params->tiling()),
            'Eta' => self::read($params, 'eta', $num, fn () => $params->eta()),
            'Sigma churn' => self::read($params, 'sChurn', $num, fn () => $params->sChurn()),
            'Schedule max sigma' => self::read($params, 'sTmax', $num, fn () => $params->sTmax()),
            'Schedule min sigma' => self::read($params, 'sTmin', $num, fn () => $params->sTmin()),
            'Sigma noise' => self::read($params, 'sNoise', $num, fn () => $params->sNoise()),
            'NGMS' => self::read($params, 'sMinUncond', $num, fn () => $params->sMinUncond()),
            default => null,
        };
    }

    /**
     * Read one DTO slot only when explicitly set. MediaRequest::isSet speaks
     * wire names, so its XSet check goes through propIsSet(); GenerationParams
     * isSet() already speaks camel props.
     *
     * @param callable(mixed): ?string $cast
     * @param callable(): mixed $read
     */
    private static function read(GenerationParams|MediaRequest $params, string $prop, callable $cast, callable $read): ?string
    {
        $isSet = $params instanceof MediaRequest
            ? $params->propIsSet($prop)
            : $params->isSet($prop);

        return $isSet ? $cast($read()) : null;
    }

    private static function both(GenerationParams|MediaRequest $params, string $a, string $b): bool
    {
        $set = static fn (string $prop): bool => $params instanceof MediaRequest
            ? $params->propIsSet($prop)
            : $params->isSet($prop);

        return $set($a) && $set($b);
    }

    // ----------------------------------------------------------------- parse

    /**
     * Does this line carry a parameter pair? Anchors on a known label either
     * at line start or at a ', ' pair boundary — a parameter line may open
     * with a non-canonical stray label ('Time model: …, Steps: 3') as long as
     * one canonical label rides it.
     */
    private static function startsParamLine(string $line): bool
    {
        foreach (InfotextFields::STARTERS as $starter) {
            if (str_starts_with($line, $starter . ':') || str_contains($line, ', ' . $starter . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split the parameter line into [label, value] pairs at depth-0 ', '
     * separators — commas inside <angle>/[square] groups (lora weight tags,
     * 'HAT [ESRGAN, 4x]' model names) and inside JSON-quoted values do NOT
     * split. Each pair then splits label from value at its FIRST ': '
     * (fallback first ':'), so values keep their own colons.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function splitPairs(string $line): array
    {
        $tokens = [];
        $token = '';
        $depth = 0;
        $inQuote = false;
        $escaped = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($inQuote) {
                $token .= $ch;
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inQuote = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inQuote = true;
                $token .= $ch;
                continue;
            }
            if ($ch === '<' || $ch === '[') {
                $depth++;
                $token .= $ch;
                continue;
            }
            if ($ch === '>' || $ch === ']') {
                $depth = max(0, $depth - 1);
                $token .= $ch;
                continue;
            }
            if ($ch === ',' && $depth === 0 && ($line[$i + 1] ?? '') === ' ') {
                $tokens[] = $token;
                $token = '';
                $i++; // consume the space of the ', ' separator
                continue;
            }
            $token .= $ch;
        }
        $tokens[] = $token;
        $pairs = [];
        foreach ($tokens as $candidate) {
            $pair = self::splitPair($candidate);
            if ($pair !== null) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function splitPair(string $token): ?array
    {
        $pos = strpos($token, ': ');
        $gap = 2;
        if ($pos === false) {
            $pos = strpos($token, ':');
            $gap = 1;
        }
        if ($pos === false || $pos === 0) {
            return null; // no usable separator -> dropped upstream-style
        }
        $label = trim(substr($token, 0, $pos));
        $value = trim(substr($token, $pos + $gap));
        if ($label === '') {
            return null;
        }
        if (str_starts_with($value, '"')) {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) {
                $value = $decoded;
            }
        }

        return [$label, $value];
    }

    private static function stripComment(string $line, bool $strip): string
    {
        if (!$strip) {
            return rtrim($line, "\r");
        }
        $pos = strpos($line, '#');

        return rtrim($pos === false ? $line : substr($line, 0, $pos));
    }

    // --------------------------------------------------------------- applyTo

    private static function apply(MediaRequest $request, string $prop, bool $onlyUnset, \Closure $setter): MediaRequest
    {
        if ($onlyUnset && $request->propIsSet($prop)) {
            return $request;
        }

        return $setter();
    }

    private static function applyPair(MediaRequest $request, string $label, string $raw, bool $onlyUnset, bool $explicitScheduler): MediaRequest
    {
        $field = InfotextFields::fieldFor($label);
        if ($field === null) {
            // Unmodelled stray ('ControlNet 0', 'Face restoration model', …):
            // preserved verbatim under a slug of its own label.
            return self::storeUnknown($request, self::slug($label), $raw);
        }
        return match ($field) {
            'steps' => self::int($request, 'steps', $raw, $onlyUnset, fn (int $n) => $request->withSteps($n)),
            'sampler_name' => self::sampler($request, $raw, $onlyUnset, $explicitScheduler),
            'scheduler' => self::text($request, 'scheduler', $raw, $onlyUnset),
            'cfg_scale' => self::float($request, 'cfgScale', $raw, $onlyUnset, fn (float $f) => $request->withCfgScale($f)),
            'image_cfg_scale' => self::float($request, 'imageCfgScale', $raw, $onlyUnset, fn (float $f) => $request->withImageCfgScale($f)),
            'seed' => self::int($request, 'seed', $raw, $onlyUnset, fn (int $n) => $request->withSeed($n)),
            'restore_faces' => self::flag($request, 'restoreFaces', $raw, $onlyUnset, fn (bool $b) => $request->withRestoreFaces($b), 'face_restoration_model'),
            'size' => self::size($request, $raw, $onlyUnset),
            'subseed' => self::int($request, 'subseed', $raw, $onlyUnset, fn (int $n) => $request->withSubseed($n)),
            'subseed_strength' => self::float($request, 'subseedStrength', $raw, $onlyUnset, fn (float $f) => $request->withSubseedStrength($f)),
            'seed_resize_from' => self::resizeFrom($request, $raw, $onlyUnset),
            'denoising_strength' => self::float($request, 'denoisingStrength', $raw, $onlyUnset, fn (float $f) => $request->withDenoisingStrength($f)),
            'token_merging_ratio' => self::float($request, 'tokenMergingRatio', $raw, $onlyUnset, fn (float $f) => $request->withTokenMergingRatio($f)),
            'token_merging_ratio_hr' => self::float($request, 'tokenMergingRatioHr', $raw, $onlyUnset, fn (float $f) => $request->withTokenMergingRatioHr($f)),
            'tiling' => self::flag($request, 'tiling', $raw, $onlyUnset, fn (bool $b) => $request->withTiling($b), null),
            'eta' => self::float($request, 'eta', $raw, $onlyUnset, fn (float $f) => $request->withEta($f)),
            's_churn' => self::float($request, 'sChurn', $raw, $onlyUnset, fn (float $f) => $request->withSChurn($f)),
            's_tmax' => self::float($request, 'sTmax', $raw, $onlyUnset, fn (float $f) => $request->withSTmax($f)),
            's_tmin' => self::float($request, 'sTmin', $raw, $onlyUnset, fn (float $f) => $request->withSTmin($f)),
            's_noise' => self::float($request, 'sNoise', $raw, $onlyUnset, fn (float $f) => $request->withSNoise($f)),
            's_min_uncond' => self::float($request, 'sMinUncond', $raw, $onlyUnset, fn (float $f) => $request->withSMinUncond($f)),
            'hr_scale' => self::float($request, 'hrScale', $raw, $onlyUnset, fn (float $f) => $request->withHrScale($f)),
            'hr_upscaler' => self::text($request, 'hrUpscaler', $raw, $onlyUnset),
            'hr_second_pass_steps' => self::int($request, 'hrSecondPassSteps', $raw, $onlyUnset, fn (int $n) => $request->withHrSecondPassSteps($n)),
            'firstphase_width' => self::int($request, 'firstphaseWidth', $raw, $onlyUnset, fn (int $n) => $request->withFirstphaseWidth($n)),
            'firstphase_height' => self::int($request, 'firstphaseHeight', $raw, $onlyUnset, fn (int $n) => $request->withFirstphaseHeight($n)),
            'styles' => self::styles($request, $raw, $onlyUnset),
            default => self::storeUnknown($request, $field, $raw),
        };
    }

    private static function int(MediaRequest $request, string $prop, string $raw, bool $onlyUnset, \Closure $setter): MediaRequest
    {
        if (!self::looksNumeric($raw)) {
            return $request;
        }

        return self::apply($request, $prop, $onlyUnset, fn () => $setter((int) $raw));
    }

    private static function float(MediaRequest $request, string $prop, string $raw, bool $onlyUnset, \Closure $setter): MediaRequest
    {
        if (!self::looksNumeric($raw)) {
            return $request;
        }

        return self::apply($request, $prop, $onlyUnset, fn () => $setter((float) $raw));
    }

    /**
     * Bool slot with a legacy escape hatch: a non-boolean value ('Face
     * restoration: CodeFormer') is a pre-1.6 model name, preserved under
     * $legacyKey when one is given and dropped when the slot has no such
     * history.
     */
    private static function flag(MediaRequest $request, string $prop, string $raw, bool $onlyUnset, \Closure $setter, ?string $legacyKey): MediaRequest
    {
        $lower = strtolower($raw);
        if ($lower === 'true' || $lower === 'false') {
            return self::apply($request, $prop, $onlyUnset, fn () => $setter($lower === 'true'));
        }
        if ($legacyKey === null) {
            return $request;
        }

        return self::storeUnknown($request, $legacyKey, $raw);
    }

    private static function text(MediaRequest $request, string $prop, string $raw, bool $onlyUnset): MediaRequest
    {
        $with = 'with' . ucfirst($prop);

        return self::apply($request, $prop, $onlyUnset, fn () => $request->{$with}($raw));
    }

    /**
     * Legacy combined sampler names ('DPM++ 2M Karras') split into sampler +
     * scheduler exactly like upstream's sampler-schedule fold; an explicit
     * 'Schedule type:' pair in the same text owns the scheduler instead.
     */
    private static function sampler(MediaRequest $request, string $raw, bool $onlyUnset, bool $explicitScheduler): MediaRequest
    {
        $name = $raw;
        $suffix = null;
        if (!$explicitScheduler) {
            foreach (InfotextFields::SCHEDULE_SUFFIXES as $candidate) {
                if (str_ends_with($raw, ' ' . $candidate)) {
                    $name = substr($raw, 0, -strlen($candidate) - 1);
                    $suffix = $candidate;
                    break;
                }
            }
        }
        $request = self::apply($request, 'samplerName', $onlyUnset, fn () => $request->withSamplerName($name));
        if ($suffix === null) {
            return $request;
        }

        return self::apply($request, 'scheduler', $onlyUnset, fn () => $request->withScheduler($suffix));
    }

    private static function size(MediaRequest $request, string $raw, bool $onlyUnset): MediaRequest
    {
        if (!preg_match('/^(\d+)x(\d+)$/', $raw, $m)) {
            return $request;
        }
        $request = self::apply($request, 'width', $onlyUnset, fn () => $request->withWidth((int) $m[1]));

        return self::apply($request, 'height', $onlyUnset, fn () => $request->withHeight((int) $m[2]));
    }

    private static function resizeFrom(MediaRequest $request, string $raw, bool $onlyUnset): MediaRequest
    {
        if (!preg_match('/^(-?\d+)x(-?\d+)$/', $raw, $m)) {
            return $request;
        }
        $request = self::apply($request, 'seedResizeFromH', $onlyUnset, fn () => $request->withSeedResizeFromH((int) $m[1]));

        return self::apply($request, 'seedResizeFromW', $onlyUnset, fn () => $request->withSeedResizeFromW((int) $m[2]));
    }

    private static function styles(MediaRequest $request, string $raw, bool $onlyUnset): MediaRequest
    {
        $decoded = json_decode($raw, true);
        $styles = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [$raw];

        return self::apply($request, 'styles', $onlyUnset, fn () => $request->withStyles($styles));
    }

    private static function storeUnknown(MediaRequest $request, string $key, string $raw): MediaRequest
    {
        if ($key === '' || array_key_exists($key, $request->unknownKeys())) {
            return $request;
        }
        // withUnknown refuses modelled wire names — every route above already
        // owns those, so anything reaching here is a genuine extension key.
        return $request->withUnknown($key, $raw);
    }

    private static function slug(string $label): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($label)));

        return trim((string) $slug, '_');
    }

    private static function looksNumeric(string $raw): bool
    {
        return is_numeric(trim($raw));
    }

    // --------------------------------------------------------------- quoting

    /**
     * §3.5 quoting law: any value containing `,` `:` or a newline is
     * `json.dumps`-quoted (round-trip safe). json_encode with ensure_ascii
     * parity matches Python's json.dumps output for strings byte-for-byte.
     */
    private static function quoteValue(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, ':') || str_contains($value, "\n")) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }
}
