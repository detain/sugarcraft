<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm\Sections;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\Capability\EndpointFamily;
use SugarCraft\Code\Media\ImageIngress;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Video\FrameLadder;
use SugarCraft\Code\Media\Video\JobClient;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tui\MediaForm\DisplayOverrides;
use SugarCraft\Code\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\MediaFormState;
use SugarCraft\Code\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;

/**
 * The Video tab (crush-media W5.6, plan §6.2/§6.5): the Wan2.2 / LTX-class
 * parameter rows landing in the generation popup.
 *
 * The rows fold onto the draft under EXACTLY the {@see
 * \SugarCraft\Code\Tools\BuiltIn\GenerateVideo} inputSchema wire names —
 * `num_frames`, `fps`, `cfg`, `cfg2`, `motion`, `first_frame_image` ride the
 * DTO's tolerated unknowns (§4.1), while `width`/`height`/`steps`/`seed` are
 * modelled DTO fields whose wire names the schema reuses, so they fold
 * through their own withers. The rows-to-schema parity is pinned by
 * VideoSectionTest; nothing here invents a knob (proven-negatives rule (e):
 * STG, teacache, distilled-variant and expert-boundary surfaces stay
 * descoped per plan Appendix B — reachable via the Override-settings tab).
 *
 * THE LADDER LAW: {@see FrameLadder} is the SSOT — the form consumes it and
 * never re-encodes rungs. The frames row keeps the user's RAW number on
 * screen (it is their shot length), while collect() SENDS SNAPPED (the plan's
 * documented choice) and the inert timing row surfaces the snap live, e.g.
 * "frames 100 is off the ladder - snapped up to 121 (+21) · 121 frames @
 * 24 fps ≈ 5.0s". The frames field's ceiling (257) equals the recorded
 * ladder's top rung, so the snap-up can never run off the end of the ladder
 * from form input; past-ceiling requests stay the tool's refusal business.
 *
 * THE KIND GATE: the tab exists only when the provider's `mediaKinds`
 * roster explicitly names 'video', resolved zero-dial from the live user
 * config. The TAB fails closed where the tool doors ({@see
 * \SugarCraft\Code\Tui\MediaForm\ImportPng}, GenerateVideo's capability
 * refusal) fail open — a refusal must not punish an un-declared host, but a
 * visible tab ADVERTISES the capability and its rows would then ride every
 * draft; the un-declared host still reaches the tool directly. Tests
 * override per-section with the explicit `$enabled` argument, never by
 * mutating config (Wave4Gate law). When hidden — and equally when visible
 * but never engaged, so a declared-video host loses nothing on its image
 * runs — collect() returns the draft UNTOUCHED: feature ABSENT or asleep,
 * not collapsed (the W3.7 draft-identity law, widened by the engagement
 * rule).
 *
 * t2v vs i2v is ONE tab (§6.5 row 1: "single tab, optional first-frame
 * slot"): the first-frame slot reuses the W4.1 {@see ImageIngress} door in
 * single-image mode — attaching replaces the slot — and its absence is
 * simply text-to-video. The ingress validates bytes (mime/size door) while
 * the WIRE carries the path string the schema declares; feeding those bytes
 * to a remote server is the multipart widening already recorded as a W7.7
 * residual, honestly not this row's business.
 *
 * The cfg2 row appears only when the dialect declares dual-CFG support
 * (`$dualCfg`); nothing declares it today, so the default is false and the
 * lane-b shell seam can pass a declared flag once a capability descriptor
 * carries one (§6.2 "double CFG (Wan)" is the recorded future).
 */
final class VideoSection implements Section, FieldValuedSection
{
    // Row keys; the ones that are NOT wire names are section-local display or
    // the picker/clear door rows (ImageSection's source-row law: payload
    // admissions stay out of preset hydration).
    public const string ROW_ATTACH = 'video_first_frame_attach';
    public const string ROW_FRAME = 'video_first_frame';
    public const string ROW_CLEAR = 'video_first_frame_clear';
    public const string ROW_FRAMES = 'num_frames';
    public const string ROW_TIMING = 'video_timing';
    public const string ROW_FPS = 'fps';
    public const string ROW_RESOLUTION = 'video_resolution';
    public const string ROW_STEPS = 'steps';
    public const string ROW_CFG = 'cfg';
    public const string ROW_CFG2 = 'cfg2';
    public const string ROW_MOTION = 'motion';
    public const string ROW_SEED = 'seed';

    /** §6.2 num_frames range row: "25–257" with 121 the shared Wan/LTX rung. */
    public const int FRAMES_MIN = 25;
    public const int FRAMES_MAX = 257;
    public const int DEFAULT_FRAMES = 121;

    /** §6.2 fps row: Wan 16/24, LTX 24–30 — the recorded typicals, no more. */
    public const array FPS_OPTIONS = ['16', '24', '25', '26', '27', '28', '29', '30'];
    public const string DEFAULT_FPS = '24';

    /**
     * §6.2 resolution-class row (480p/720p/1080p; the "580p" mention carries
     * no recorded pixel mapping and is dropped rather than invented). The
     * class pairs are the model-standard dimensions on the VAE multiple-of-8
     * law the image tab already cites; 480p rides Wan's recorded 832×480.
     *
     * @var array<string, array{0: int, 1: int}> label => [width, height]
     */
    public const array RESOLUTION_CLASSES = [
        '480p (832x480)' => [832, 480],
        '720p (1280x720)' => [1280, 720],
        '1080p (1920x1080)' => [1920, 1080],
    ];
    public const string DEFAULT_RESOLUTION = '720p (1280x720)';

    /** Steps lattice mirrors the image tab's §2.2 row (same knob family). */
    public const int STEPS_MIN = 1;
    public const int STEPS_MAX = 150;
    public const int DEFAULT_STEPS = 20;

    /** §6.2 double-CFG row: "defaults ≈4–6, sometimes cfg2 lower". */
    public const float CFG_MIN = 1.0;
    public const float CFG_MAX = 30.0;
    public const float DEFAULT_CFG = 5.0;
    public const float DEFAULT_CFG2 = 4.0;

    /** §6.2 motion row: "expose 0–1 motion magnitude"; 0.5 is the centre. */
    public const float MOTION_MIN = 0.0;
    public const float MOTION_MAX = 1.0;
    public const float DEFAULT_MOTION = 0.5;

    /** A1111 seed semantics unchanged (§6.2 "reuse the A1111 seed row"). */
    public const int SEED_MIN = -1;

    private function __construct(
        /** @var array<string, Field> row key => widget */
        private readonly array $fields,
        private readonly bool $enabled,
        private readonly bool $dualCfg,
        private readonly EndpointFamily $family,
        /** The admitted first frame; null until the picker commits one. */
        private readonly ?ImageIngress $frame,
        /** The path exactly as admitted — the wire value, not the bytes. */
        private readonly string $framePath,
        private readonly ?string $attachError,
        /** @var array<string, true> rows hidden by display overrides */
        private readonly array $hidden,
        /** @var array<string, true> rows the user (or a video preset) engaged */
        private readonly array $touched,
    ) {
    }

    /**
     * @param ?array<array-key, mixed> $settings  injected config map for the
     *        kind door (tests); null reads the live user config zero-dial.
     */
    public static function forDraft(
        MediaRequest $draft,
        ?bool $enabled = null,
        ?EndpointFamily $family = null,
        ?bool $dualCfg = null,
        ?array $settings = null,
    ): self {
        $enabled ??= self::videoKindEnabled($settings);
        $family ??= JobClient::DEFAULT_FAMILY;

        $fields = [];
        $fields[self::ROW_ATTACH] = self::pickerField(getcwd() ?: '.');
        $fields[self::ROW_FRAMES] = NumberField::new(self::ROW_FRAMES, self::FRAMES_MIN, self::FRAMES_MAX, 1, self::DEFAULT_FRAMES)
            ->withTitle(Lang::t(GenFormKeys::VIDEO_FRAMES));
        $fields[self::ROW_FPS] = Select::new(self::ROW_FPS)
            ->withOptions(...self::FPS_OPTIONS)
            ->withSelected(self::DEFAULT_FPS)
            ->withTitle(Lang::t(GenFormKeys::VIDEO_FPS));
        $fields[self::ROW_RESOLUTION] = Select::new(self::ROW_RESOLUTION)
            ->withOptions(...array_keys(self::RESOLUTION_CLASSES))
            ->withSelected(self::DEFAULT_RESOLUTION)
            ->withTitle(Lang::t(GenFormKeys::VIDEO_RESOLUTION));
        $fields[self::ROW_STEPS] = NumberField::new(self::ROW_STEPS, self::STEPS_MIN, self::STEPS_MAX, 1, $draft->steps() ?? self::DEFAULT_STEPS)
            ->withTitle(Lang::t(GenFormKeys::FIELD_SAMPLING_STEPS));
        $fields[self::ROW_CFG] = Slider::new(self::ROW_CFG, self::CFG_MIN, self::CFG_MAX, 0.5, self::DEFAULT_CFG)
            ->withTitle(Lang::t(GenFormKeys::VIDEO_CFG));
        if ($dualCfg ?? false) {
            $fields[self::ROW_CFG2] = Slider::new(self::ROW_CFG2, self::CFG_MIN, self::CFG_MAX, 0.5, self::DEFAULT_CFG2)
                ->withTitle(Lang::t(GenFormKeys::VIDEO_CFG2));
        }
        $fields[self::ROW_MOTION] = Slider::new(self::ROW_MOTION, self::MOTION_MIN, self::MOTION_MAX, 0.05, self::DEFAULT_MOTION)
            ->withTitle(Lang::t(GenFormKeys::VIDEO_MOTION));
        $fields[self::ROW_SEED] = NumberField::new(self::ROW_SEED, self::SEED_MIN, PHP_INT_MAX - 1, 1, $draft->seed() ?? -1)
            ->withTitle(Lang::t(GenFormKeys::ROW_SEED));

        return new self($fields, $enabled, $dualCfg ?? false, $family, null, '', null, [], []);
    }

    public function key(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_VIDEO);
    }

    /** The kind gate: a video-less host does not get the tab at all. */
    public function visible(MediaFormState $state): bool
    {
        return $this->enabled;
    }

    /** @return list<string> */
    public function rows(): array
    {
        return array_map($this->rowLabel(...), $this->lattice());
    }

    public function fieldAt(int $row): ?Field
    {
        $key = $this->lattice()[$row] ?? null;

        return $key === null ? null : ($this->fields[$key] ?? null);
    }

    public function withField(Field $field): Section
    {
        $key = $field->key();
        if (!\in_array($key, $this->lattice(), true)) {
            return $this;
        }

        return match ($key) {
            self::ROW_ATTACH => $field instanceof FilePicker && $field->value() !== null
                ? $this->takePath((string) $field->value())
                : $this,
            self::ROW_CLEAR => $this->mutate(
                fields: [
                    self::ROW_CLEAR => Confirm::new($key, false)->withTitle(Lang::t(GenFormKeys::VIDEO_CLEAR_FIRST_FRAME)),
                    self::ROW_ATTACH => self::pickerField(getcwd() ?: '.'),
                ],
                frame: null,
                framePath: '',
                attachError: null,
            ),
            self::ROW_FRAME, self::ROW_TIMING => $this,
            default => $this->mutate(fields: [$key => $field], touched: $this->touched + [$key => true]),
        };
    }

    public function valueAt(int $row): string
    {
        $key = $this->lattice()[$row] ?? '';

        return match ($key) {
            self::ROW_ATTACH => $this->attachError !== null
                ? '! ' . $this->attachError
                : ($this->frame?->name() ?? ''),
            self::ROW_FRAME => $this->frame?->dimensions() ?? '',
            self::ROW_TIMING => $this->timingNote(),
            self::ROW_FPS, self::ROW_RESOLUTION => (string) ($this->fields[$key] ?? Select::new($key))->value(),
            self::ROW_CLEAR => '',
            default => $this->numericCell($key),
        };
    }

    /**
     * Fold the tab into the draft under GenerateVideo's wire names. Hidden,
     * the draft passes through untouched (W3.7 draft-identity law); visible
     * but never ENGAGED, likewise — the tab's mere presence must not stamp
     * video defaults (or the resolution's width/height) onto an image run,
     * so an untouched tab collects as identity too. Once any row was edited
     * (or a frame admitted, or a video preset hydrated), the frames value is
     * snapped on the way out (SEND SNAPPED) and every other row lands
     * verbatim.
     */
    public function collect(MediaRequest $draft): MediaRequest
    {
        if (!$this->enabled || ($this->touched === [] && $this->frame === null)) {
            return $draft;
        }

        // §5.5 hidden-row law: an override-hidden row drops from render AND
        // collect, so a hidden knob never rides the wire.
        if (!isset($this->hidden[self::ROW_SEED])) {
            $draft = $draft->withSeed($this->intValue(self::ROW_SEED, -1));
        }

        if (!isset($this->hidden[self::ROW_STEPS])) {
            $draft = $draft->withSteps($this->intValue(self::ROW_STEPS, self::DEFAULT_STEPS));
        }

        if (!isset($this->hidden[self::ROW_RESOLUTION])) {
            [$width, $height] = self::RESOLUTION_CLASSES[$this->selectedLabel(self::ROW_RESOLUTION, self::DEFAULT_RESOLUTION)]
                ?? self::RESOLUTION_CLASSES[self::DEFAULT_RESOLUTION];
            $draft = $draft->withWidth($width)->withHeight($height);
        }

        if (!isset($this->hidden[self::ROW_FRAMES])) {
            $draft = $draft->withUnknown('num_frames', FrameLadder::snap(
                $this->intValue(self::ROW_FRAMES, self::DEFAULT_FRAMES),
                $this->family,
            )->frames);
        }

        if (!isset($this->hidden[self::ROW_FPS])) {
            $draft = $draft->withUnknown('fps', (int) $this->selectedLabel(self::ROW_FPS, self::DEFAULT_FPS));
        }

        if (!isset($this->hidden[self::ROW_CFG])) {
            $draft = $draft->withUnknown('cfg', $this->floatValue(self::ROW_CFG, self::DEFAULT_CFG));
        }

        if ($this->dualCfg && !isset($this->hidden[self::ROW_CFG2])) {
            $draft = $draft->withUnknown('cfg2', $this->floatValue(self::ROW_CFG2, self::DEFAULT_CFG2));
        }

        if (!isset($this->hidden[self::ROW_MOTION])) {
            $draft = $draft->withUnknown('motion', $this->floatValue(self::ROW_MOTION, self::DEFAULT_MOTION));
        }

        if ($this->frame !== null && $this->framePath !== '' && !isset($this->hidden[self::ROW_ATTACH])) {
            $draft = $draft->withUnknown('first_frame_image', $this->framePath);
        }

        return $draft;
    }

    /** @return array<string, mixed> wire-keyed scalars for preset hydration */
    public function fieldValues(): array
    {
        if (!$this->enabled) {
            // An absent feature is unrepresented in preset/clipboard bundles,
            // exactly as its collect() returns the draft untouched.
            return [];
        }

        $values = [
            self::ROW_FRAMES => $this->fields[self::ROW_FRAMES]?->value(),
            self::ROW_FPS => $this->fields[self::ROW_FPS]?->value(),
            self::ROW_RESOLUTION => $this->fields[self::ROW_RESOLUTION]?->value(),
            self::ROW_STEPS => $this->fields[self::ROW_STEPS]?->value(),
            self::ROW_CFG => $this->fields[self::ROW_CFG]?->value(),
            self::ROW_MOTION => $this->fields[self::ROW_MOTION]?->value(),
            self::ROW_SEED => $this->fields[self::ROW_SEED]?->value(),
        ];

        if ($this->dualCfg) {
            $values[self::ROW_CFG2] = $this->fields[self::ROW_CFG2]?->value();
        }

        // The first-frame ADMISSION stays section-local on ImageSection's
        // source-row law: presets and clipboard bundles never carry an image
        // path the next host may not have.
        unset($values[self::ROW_TIMING]);

        foreach ($this->hidden as $key => $ignored) {
            unset($values[$key]);
        }

        return $values;
    }

    public function withFieldValues(array $values): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $fields = $this->fields;
        $hidden = $this->hidden;
        // A bundle that names video rows IS a video preset: hydration
        // engages the tab the same way an edit does.
        $touched = $this->touched;
        foreach ([self::ROW_FRAMES, self::ROW_STEPS, self::ROW_SEED] as $key) {
            $raw = $values[$key] ?? null;
            if (!isset($hidden[$key]) && \is_numeric($raw) && $fields[$key] ?? null instanceof NumberField) {
                /** @var NumberField $field */
                $field = $fields[$key];
                $fields[$key] = $field->withValue((int) $raw);
                $touched[$key] = true;
            }
        }

        foreach ([self::ROW_CFG, self::ROW_MOTION] as $key) {
            $raw = $values[$key] ?? null;
            if (!isset($hidden[$key]) && \is_numeric($raw) && $fields[$key] ?? null instanceof Slider) {
                /** @var Slider $field */
                $field = $fields[$key];
                $fields[$key] = $field->withValue((float) $raw);
                $touched[$key] = true;
            }
        }

        if ($this->dualCfg && !isset($hidden[self::ROW_CFG2])) {
            $raw = $values[self::ROW_CFG2] ?? null;
            $field = $fields[self::ROW_CFG2] ?? null;
            if (\is_numeric($raw) && $field instanceof Slider) {
                $fields[self::ROW_CFG2] = $field->withValue((float) $raw);
                $touched[self::ROW_CFG2] = true;
            }
        }

        foreach ([self::ROW_FPS => self::FPS_OPTIONS, self::ROW_RESOLUTION => array_keys(self::RESOLUTION_CLASSES)] as $key => $options) {
            $raw = $values[$key] ?? null;
            $field = $fields[$key] ?? null;
            if (!isset($hidden[$key]) && \is_string($raw) && \in_array($raw, $options, true) && $field instanceof Select) {
                $fields[$key] = $field->withSelected($raw);
                $touched[$key] = true;
            }
        }

        return $this->mutate(fields: $fields, touched: $touched);
    }

    /**
     * The ui-config display-override analog (§5.5) over this tab's rows:
     * `<Label>/visible: false` drops a row from render AND collect, and a
     * numeric `<Label>/value` re-seeds the widget. Tolerance parity: ids that
     * name nothing here are silently ignored.
     */
    public function withDisplayOverrides(array $overrides): self
    {
        $hidden = $this->hidden;
        $fields = $this->fields;

        foreach ($this->lattice() as $key) {
            if (!isset($this->fields[$key]) || \in_array($key, [self::ROW_TIMING, self::ROW_FRAME], true)) {
                continue;
            }

            $element = 'video/' . $this->rowLabel($key);
            $visible = $overrides[$element . '/' . DisplayOverrides::PROP_VISIBLE] ?? null;
            if ($visible === false) {
                $hidden[$key] = true;
            }

            $value = $overrides[$element . '/' . DisplayOverrides::PROP_VALUE] ?? null;
            $field = $fields[$key];
            if (\is_numeric($value) && $field instanceof NumberField) {
                $fields[$key] = $field->withValue((int) $value);
            } elseif (\is_numeric($value) && $field instanceof Slider) {
                $fields[$key] = $field->withValue((float) $value);
            } elseif (\is_string($value) && $field instanceof Select) {
                $options = $key === self::ROW_FPS ? self::FPS_OPTIONS : array_keys(self::RESOLUTION_CLASSES);
                if (\in_array($value, $options, true)) {
                    $fields[$key] = $field->withSelected($value);
                }
            }
        }

        return $this->hidden === $hidden && $fields === $this->fields
            ? $this
            : $this->mutate(fields: $fields, hidden: $hidden);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * The kind door, zero-dial by construction and FAIL-CLOSED for the tab:
     * the strip gains a Video tab only when the provider's `mediaKinds`
     * roster explicitly names 'video' — the brief's literal "visible only
     * when mediaKinds includes video". This is deliberately the inverse of
     * the tool-side doors ({@see \SugarCraft\Code\Tui\MediaForm\ImportPng}
     * and GenerateVideo's capability refusal), which fail OPEN because a
     * refusal at the entry point must not punish an un-declared host; a tab
     * advertises a capability, and advertising one nobody declared would
     * stamp its rows (and their resolution) onto every image run's draft.
     * An un-declared host still reaches video via the tool itself.
     *
     * @param ?array<array-key, mixed> $settings
     */
    public static function videoKindEnabled(?array $settings = null): bool
    {
        $live = $settings ?? self::liveSettings();

        return \in_array('video', ProviderFactory::configuredMediaKinds($live['mediaKinds'] ?? null), true);
    }

    /** @return array<array-key, mixed> */
    private static function liveSettings(): array
    {
        try {
            return Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> display/zone order, gated and hidden rows cut */
    private function lattice(): array
    {
        $keys = [self::ROW_ATTACH];
        if ($this->frame !== null && !isset($this->hidden[self::ROW_ATTACH])) {
            // The slot and its clear row are satellites of the attach row:
            // hiding attach also hides the only way to drop the frame again.
            $keys[] = self::ROW_FRAME;
            $keys[] = self::ROW_CLEAR;
        }

        $keys[] = self::ROW_FRAMES;
        $keys[] = self::ROW_TIMING;
        $keys[] = self::ROW_FPS;
        $keys[] = self::ROW_RESOLUTION;
        $keys[] = self::ROW_STEPS;
        $keys[] = self::ROW_CFG;
        if ($this->dualCfg) {
            $keys[] = self::ROW_CFG2;
        }

        $keys[] = self::ROW_MOTION;
        $keys[] = self::ROW_SEED;

        $hidden = $this->hidden;

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => !isset($hidden[$key]),
        ));
    }

    private function timingNote(): string
    {
        $requested = $this->intValue(self::ROW_FRAMES, self::DEFAULT_FRAMES);
        $fps = (int) $this->selectedLabel(self::ROW_FPS, self::DEFAULT_FPS);

        $parts = [];
        try {
            $ladder = FrameLadder::snap($requested, $this->family);
            if ($ladder->diff > 0) {
                $parts[] = Lang::t(GenFormKeys::VIDEO_SNAP, [
                    'requested' => (string) $ladder->requested,
                    'frames' => (string) $ladder->frames,
                    'diff' => (string) $ladder->diff,
                ]);
            }

            $seconds = number_format($ladder->frames / max(1, $fps), 1);
            $parts[] = Lang::t(GenFormKeys::VIDEO_TIMING, [
                'frames' => (string) $ladder->frames,
                'fps' => (string) $fps,
                'seconds' => $seconds,
            ]);
        } catch (\InvalidArgumentException) {
            // Off-ladder requests cannot come from the clamped row today;
            // should a future ladder ceiling ever sit under the field's, the
            // note stays honest about what will be asked for.
            $parts[] = Lang::t(GenFormKeys::VIDEO_TIMING, [
                'frames' => (string) $requested,
                'fps' => (string) $fps,
                'seconds' => number_format($requested / max(1, $fps), 1),
            ]);
        }

        return implode(' · ', $parts);
    }

    /**
     * A committed picker is a finished browse: validate through the W4.1
     * ingress (single-image mode — the new admission REPLACES the slot) and
     * keep the path for the wire; a refusal lands on the row and resets the
     * picker, exactly the ImageSection shape.
     */
    private function takePath(string $path): self
    {
        try {
            $ingress = ImageIngress::fromPath($path);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            return $this->mutate(
                fields: [self::ROW_ATTACH => self::pickerField(dirname($path))],
                attachError: $error->getMessage(),
            );
        }

        return $this->mutate(
            fields: [
                self::ROW_ATTACH => self::pickerField(dirname($path)),
                self::ROW_CLEAR => Confirm::new(self::ROW_CLEAR, false)
                    ->withTitle(Lang::t(GenFormKeys::VIDEO_CLEAR_FIRST_FRAME)),
            ],
            frame: $ingress,
            framePath: $path,
            attachError: null,
            touched: $this->touched + [self::ROW_ATTACH => true],
        );
    }

    private static function pickerField(string $cwd): FilePicker
    {
        return FilePicker::new(self::ROW_ATTACH, $cwd)
            ->exts(ImageIngress::EXTENSIONS)
            ->dirAllowed(false)
            ->fileAllowed(true)
            ->title(Lang::t(GenFormKeys::VIDEO_FIRST_FRAME));
    }

    private function rowLabel(string $key): string
    {
        return match ($key) {
            self::ROW_ATTACH, self::ROW_FRAME => Lang::t(GenFormKeys::VIDEO_FIRST_FRAME),
            self::ROW_CLEAR => Lang::t(GenFormKeys::VIDEO_CLEAR_FIRST_FRAME),
            self::ROW_FRAMES => Lang::t(GenFormKeys::VIDEO_FRAMES),
            self::ROW_TIMING => '',
            self::ROW_FPS => Lang::t(GenFormKeys::VIDEO_FPS),
            self::ROW_RESOLUTION => Lang::t(GenFormKeys::VIDEO_RESOLUTION),
            self::ROW_STEPS => Lang::t(GenFormKeys::FIELD_SAMPLING_STEPS),
            self::ROW_CFG => Lang::t(GenFormKeys::VIDEO_CFG),
            self::ROW_CFG2 => Lang::t(GenFormKeys::VIDEO_CFG2),
            self::ROW_MOTION => Lang::t(GenFormKeys::VIDEO_MOTION),
            self::ROW_SEED => Lang::t(GenFormKeys::ROW_SEED),
            default => $key,
        };
    }

    private function numericCell(string $key): string
    {
        $value = $this->fields[$key]?->value();
        if ($value === null) {
            return Lang::t(GenFormKeys::NOT_SET);
        }

        return \is_float($value) ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : (string) $value;
    }

    private function intValue(string $key, int $fallback): int
    {
        $value = $this->fields[$key]?->value();

        return \is_numeric($value) ? (int) $value : $fallback;
    }

    private function floatValue(string $key, float $fallback): float
    {
        $value = $this->fields[$key]?->value();

        return \is_numeric($value) ? (float) $value : $fallback;
    }

    private function selectedLabel(string $key, string $fallback): string
    {
        $value = $this->fields[$key]?->value();

        return \is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * Immutable derive. The nullable admissions use the house 'SENTINEL'
     * default so "clear it" (null) and "leave it" stay distinguishable; the
     * field map MERGES, so a partial admission/reset never strands the
     * untouched rows (RegistrySection's rebuild law).
     *
     * @param ?array<string, Field>  $fields
     * @param ?array<string, true>   $hidden
     */
    private function mutate(
        ?array $fields = null,
        mixed $frame = 'SENTINEL',
        mixed $framePath = 'SENTINEL',
        mixed $attachError = 'SENTINEL',
        ?array $hidden = null,
        ?array $touched = null,
    ): self {
        return new self(
            $fields === null ? $this->fields : array_merge($this->fields, $fields),
            $this->enabled,
            $this->dualCfg,
            $this->family,
            $frame === 'SENTINEL' ? $this->frame : $frame,
            $framePath === 'SENTINEL' ? $this->framePath : $framePath,
            $attachError === 'SENTINEL' ? $this->attachError : $attachError,
            $hidden ?? $this->hidden,
            $touched ?? $this->touched,
        );
    }
}
