<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Video\FrameLadder;
use SugarCraft\Code\Tui\MediaForm\Enum\Schedulers;
use SugarCraft\Code\Tui\MediaForm\Enum\Samplers;
use SugarCraft\Code\Tui\MediaForm\FieldSpec;
use SugarCraft\Code\Tui\MediaForm\Sections\VideoSection;

/**
 * The W7.1 knob-parity sweep, frozen as a permanent drift guard
 * (plan_crush_media §W7.1: "turning the sweep into a PERMANENT drift guard is
 * the recommended shape"). Every row of the plan's Appendix B catalog carries
 * a disposition HERE, and the FieldSpec registry's live census must equal the
 * covered set exactly — a control can neither go missing silently nor appear
 * without a ledger entry.
 *
 * The meta-oracle is plan_crush_media Appendix B (rendered from crush_media
 * §2.1–§2.10); conflict rulings C-1 (four masked-content values), C-3 (ENSD
 * 0), C-4 (uni_pc_order 1–50) and the documented divergences (refiner
 * switch-at lattice over §2.2's loose prose; the one-field-two-contexts
 * denoising_strength pair; the single mask_blur row) are applied, matching
 * the tables.
 *
 * Disposition kinds:
 *  - covered   a FieldSpec row exists (coordinate "group:key")
 *  - section   owned by a section-local door row, not the wire registry
 *              (attach/clear, seed actions, preview Note, presets, overrides)
 *  - ui        a button/chrome affordance, never a wire row
 *  - override  reachable through the W3.8 override-settings editor
 *  - generic   reachable through the W3.6 scripts/alwayson namespace
 *  - client    client-internal DTO field or module, deliberately no UI row
 *  - descoped  ruled out (permanent or v1), pinned ABSENT below
 *  - video     the W5.6 VideoSection tab (FrameLadder is the SSOT)
 *  - seam      a known open gap reported to the orchestrator, asserted as
 *              current-truth absence only — never as satisfied parity
 */
final class FieldSpecParityTest extends TestCase
{
    /**
     * The 90-row Appendix B ledger, dispositioned. `pins` are wire names the
     * row's ruling makes observable: for covered rows they are the registry
     * coordinates; for the others, plain wire names asserted against the
     * registry/DTO as the kind demands.
     *
     * @var array<int, array{0: string, 1: list<string>, 2: string}>
     */
    private const LEDGER = [
        1 => ['covered', ['core:prompt'], '§2.1 Prompt textarea'],
        2 => ['covered', ['core:negative_prompt'], '§2.1 Negative prompt'],
        3 => ['covered', ['core:styles'], '§2.1 Styles (Default collapses to unset)'],
        4 => ['descoped', [], 'token counter is server-side CLIP; /internal/token-count backlog'],
        5 => ['covered', ['core:width', 'core:height'], '§2.1 Width/Height 64–2048/8 · 512'],
        6 => ['ui', [], '⇅ swap is a key action, no wire of its own'],
        7 => ['covered', ['core:steps'], '§2.1 Sampling steps 1–150/1 · 20'],
        8 => ['covered', ['core:cfg_scale'], '§2.1 CFG Scale 1–30/.5 · 7'],
        9 => ['covered', ['core:sampler_name'], '§2.9 roster; legacy "X Karras" splits at collect'],
        10 => ['covered', ['core:scheduler'], '§2.9 twelve-entry roster'],
        11 => ['covered', ['core:seed'], '§2.3 int64 seed, −1 default'],
        12 => ['section', ['tui.genform.seed.action_randomize'], '🎲/♻ are SeedSection action rows (W3.5)'],
        13 => ['covered', ['core:n_iter'], '§2.1 batch count — NumberField, the honest unbounded shape'],
        14 => ['covered', ['core:batch_size'], '§2.1 batch size 1–8/1 · 1'],
        15 => ['ui', [], 'extra-disclosure gate is section chrome (W3.5)'],
        16 => ['covered', ['disclosure:subseed'], '§2.3 variation seed'],
        17 => ['covered', ['disclosure:subseed_strength'], '§2.3 0–1/.01 · 0'],
        18 => ['covered', ['disclosure:seed_resize_from_w', 'disclosure:seed_resize_from_h'], '§2.3 0–2048/8 · 0'],
        19 => ['covered', ['disclosure:enable_hr'], '§2.4 Hires. fix toggle'],
        20 => ['covered', ['disclosure:hr_upscaler'], '§2.8 six latent modes ∪ live /upscalers'],
        21 => ['covered', ['disclosure:hr_scale'], '§2.4 1–4/.05 · 2'],
        22 => ['covered', ['disclosure:hr_resize_x', 'disclosure:hr_resize_y'], '§2.4 HiresMath precedence'],
        23 => ['covered', ['disclosure:hr_second_pass_steps'], '§2.4 0–150 · 0'],
        24 => ['covered', ['disclosure:denoising_strength'], 'one-field-two-contexts ruling: 0.7 hires ctx here'],
        25 => ['covered', ['disclosure:hr_checkpoint_name', 'disclosure:hr_sampler_name', 'disclosure:hr_scheduler'], '§2.4 reveal-rows'],
        26 => ['covered', ['disclosure:hr_prompt', 'disclosure:hr_negative_prompt'], '§2.4 reveal-rows'],
        27 => ['section', [], 'hires-final-res preview is a computed Note row (W3.5)'],
        28 => ['covered', ['disclosure:refiner_checkpoint', 'disclosure:refiner_switch_at'], '§2.1 refiner pair; switch-at lattice .01–1/.01 · 0.8'],
        29 => ['override', ['override_settings', 'override_settings_restore_afterwards'], 'W3.8 typed editor owns both keys'],
        30 => ['generic', ['script_name', 'script_args'], 'W3.6 /scripts renderer'],
        31 => ['generic', ['alwayson_scripts'], 'W3.6 /script-info scrape'],
        32 => ['section', ['init_images'], 'W4.1 ImageSection attach door folds b64 into the DTO'],
        33 => ['covered', ['wave4:resize_mode'], '§2.6 radio 4 · default Just resize'],
        34 => ['ui', [], 'scale_by computes into width/height, never a wire row (§2.6)'],
        35 => ['ui', [], 'detect-size lands dims on the existing W/H rows (W4.1)'],
        36 => ['section', ['mask'], 'W4.3 mask attach door (white=mask law)'],
        37 => ['covered', ['wave4:mask_blur'], 'single-field ruling: mask_blur_x/y stay DTO-only override targets, no registry row'],
        38 => ['descoped', [], 'mask transparency is editor-only upstream (never serialized); x/y blur absence is pinned via ABSENT_FROM_REGISTRY'],
        39 => ['covered', ['wave4:inpainting_mask_invert'], '§2.7 0/1'],
        40 => ['covered', ['wave4:inpainting_fill'], 'C-1: exactly 4 values · UI default original (index 1)'],
        41 => ['covered', ['wave4:inpaint_full_res'], '§2.7 whole/only-masked bool radio'],
        42 => ['covered', ['wave4:inpaint_full_res_padding'], '§2.7 0–256/4 · UI 32 (API 0 is server-side)'],
        43 => ['covered', ['wave4:image_cfg_scale'], '§2.6 0–3/.05 · 1.5, capability-visibility is the section door'],
        44 => ['override', ['initial_noise_multiplier'], '§2.5 DTO-ready, override-only row'],
        45 => ['client', [], 'interrogate: W1.3 method ready, quick-action backlog'],
        46 => ['override', ['restore_faces'], 'moved to Settings upstream — override-reachable'],
        47 => ['override', ['tiling'], 'moved to Settings upstream — override-reachable'],
        48 => ['override', [], 'CLIP_stop_at_last_layers is an opts key (W3.8)'],
        49 => ['client', [], 'progress/skip/interrupt — W1.5 loop + W2.4 preview'],
        50 => ['section', [], 'save pattern — OutputSection row over sd.savePattern (W1.9/W3.9)'],
        51 => ['client', ['infotext'], 'PNG-info import / paste-params — W4.4 ImportPng'],
        52 => ['descoped', [], 'Upscale-✨ approximated by send-to + enable_hr (backlog)'],
        53 => ['generic', ['script_args'], 'X/Y/Z rides the W3.6 generic axis rows'],
        54 => ['generic', ['script_args'], 'prompt-matrix/loops/outpaint ride W3.6'],
        55 => ['descoped', [], 'extra-networks card UI; <lora:> prompt syntax passes through day-1'],
        56 => ['override', [], 'sd_vae and quicksettings are opts keys (W3.8)'],
        57 => ['override', ['eta', 's_churn', 's_tmax', 's_tmin', 's_noise', 's_min_uncond'], '§2.10 sampler-param family — no bespoke widgets'],
        58 => ['client', ['token_merging_ratio', 'token_merging_ratio_hr'], 'DTO-ready, no form row (W7.1 re-rate: stays D)'],
        59 => ['covered', ['wave4:denoising_strength'], 'img2img ctx of the one wire field · default 0.75'],
        60 => ['descoped', [], 'CFG end/restart/window is the Forge-era proven-negative family (e)'],
        61 => ['descoped', [], 'TCD/regional-seeds/per-axis-weights/vary-region/diffuse-noise/Prompt-S-R — D PERMANENT (e); extension-shaped home is W3.6/W3.8 only'],
        62 => ['descoped', [], 'Flux guidance native row awaits live-dialect proof (W0.1)'],
        63 => ['descoped', [], 'repeatable LoRA sub-form is a new widget (backlog); <lora:> syntax works'],
        64 => ['video', ['num_frames'], 'FrameLadder SSOT, 25–257 window, snap-UP law'],
        65 => ['video', ['fps'], '16/24/25–30 recorded typicals'],
        66 => ['video', [], '480p–1080p resolution classes'],
        67 => ['video', ['motion'], '0–1 mapping honesty docblock'],
        68 => ['video', ['first_frame_image'], 'i2v first-frame slot'],
        69 => ['descoped', [], 'first+last/reference/v2v roles: dialect proof pending (W0.1)'],
        70 => ['video', ['cfg', 'cfg2'], 'dual CFG (Wan two-expert) behind the capability flag'],
        71 => ['seam', [], 'flow shift / sample shift (§6.2 1.7–12 family) has no VideoSection row at this tip — reported as seam, asserted absent'],
        72 => ['descoped', [], 'STG scale/blocks — capability-gated later (§6.2)'],
        73 => ['descoped', [], 'Lightning/teacache/TBCT knobs — advanced namespace per (e) doctrine'],
        74 => ['generic', [], 'RIFE/upscale-video scripts ride W3.6 if the server exposes them'],
        75 => ['descoped', [], 'Extras tab form — client-ready, follow-on feature (backlog)'],
        76 => ['descoped', [], 'interrogate model picker travels with #45'],
        77 => ['override', [], 'RNG source is an opts key; infotext carries it (W1.6)'],
        78 => ['descoped', [], 'batch-dir PNG inheritance — out of console scope (§2.13 note)'],
        79 => ['descoped', [], 'gallery arrows/modal keys — crush gallery pane is phase-2 (Shape C)'],
        80 => ['descoped', [], 'generate-forever / context menus — no mouse-context infra in TUI v1'],
        81 => ['descoped', [], 'notification sound / title progress — n/a console'],
        82 => ['section', [], 'ui-config display-override analog — DisplayOverrides (W3.9)'],
        83 => ['section', [], 'preset save/load/apply — MediaPresets (W3.9)'],
        84 => ['client', [], 'render mode switch — ui.imageRenderMode + /render-mode (W2.2/W5.7)'],
        85 => ['client', ['infotext'], 'Version/User/backend stamps ride the W1.6 emit'],
        86 => ['descoped', [], 'save_txt sidecar — Appendix C-3 autosave territory'],
        87 => ['client', [], 'grid file prepend law — BatchResults (W4.5), index 0'],
        88 => ['client', ['force_task_id'], 'always set client-side by the job layer (W1.2)'],
        89 => ['client', ['include_init_images'], 'DTO field, cosmetic echo, no row'],
        90 => ['client', ['send_images', 'save_images', 'do_not_save_samples', 'do_not_save_grid', 'disable_extra_networks', 'comments'], 'client-internal flags, no UI rows (record)'],
    ];

    /** Kinds that expect NO registry row (their pins are absence pins or DTO pins). */
    private const NON_REGISTRY_KINDS = ['section', 'ui', 'override', 'generic', 'client', 'descoped', 'video', 'seam'];

    /**
     * `tui.genform.field.*` keys carried in lang/en.php WITHOUT a registry row,
     * each with its ruling. Appendix B #46/#47 moved restore-faces and tiling
     * to the override channel upstream; the W3.10 vocabulary pre-declared the
     * label rows (GenFormKeys law), so the keys are sanctioned-but-unrowed.
     * The staleness arm below reddens if either ever gains a row (the entry
     * must then leave this map) — the licensé cannot rot silently.
     *
     * @var array<string, string>
     */
    private const SANCTIONED_UNROWED_FIELD_LABELS = [
        'tui.genform.field.tiling' => 'Appendix B #47 — Settings-moved control, override-reachable',
        'tui.genform.field.restore_faces' => 'Appendix B #46 — Settings-moved control, override-reachable',
    ];

    /**
     * The §2-table lattice for EVERY slider/number row in the registry, copied
     * from the Appendix B ranges and the plan's Wave-3/4 spec tables
     * [min, max, step, default]. n_iter's max is the honest "no UI max"
     * NumberField shape (§2.1 documents the webui slider as unbounded);
     * seed/subseed cap at PHP_INT_MAX − 1 to keep the −1-lattice walk on
     * NumberField's exact-integer path — both documented in FieldSpec.
     *
     * @var array<string, array{0: int|float, 1: int|float, 2: int|float, 3: int|float}>
     */
    private const LATTICE = [
        'core:width' => [64, 2048, 8, 512],
        'core:height' => [64, 2048, 8, 512],
        'core:n_iter' => [1, \PHP_INT_MAX, 1, 1],
        'core:batch_size' => [1, 8, 1, 1],
        'core:cfg_scale' => [1.0, 30.0, 0.5, 7.0],
        'core:steps' => [1, 150, 1, 20],
        'core:seed' => [-1, \PHP_INT_MAX - 1, 1, -1],
        'disclosure:subseed' => [-1, \PHP_INT_MAX - 1, 1, -1],
        'disclosure:subseed_strength' => [0.0, 1.0, 0.01, 0.0],
        'disclosure:seed_resize_from_w' => [0, 2048, 8, 0],
        'disclosure:seed_resize_from_h' => [0, 2048, 8, 0],
        'disclosure:hr_second_pass_steps' => [0, 150, 1, 0],
        'disclosure:denoising_strength' => [0.0, 1.0, 0.01, 0.7],
        'disclosure:hr_scale' => [1.0, 4.0, 0.05, 2.0],
        'disclosure:hr_resize_x' => [0, 2048, 8, 0],
        'disclosure:hr_resize_y' => [0, 2048, 8, 0],
        // §2.2's prose is loose here; the lattice below is the W3.5 ruling
        // recorded at Appendix B #28 (0.01–1 / 0.01 / default 0.8).
        'disclosure:refiner_switch_at' => [0.01, 1.0, 0.01, 0.8],
        'wave4:denoising_strength' => [0.0, 1.0, 0.01, 0.75],
        'wave4:image_cfg_scale' => [0.0, 3.0, 0.05, 1.5],
        'wave4:mask_blur' => [0, 64, 1, 4],
        'wave4:inpaint_full_res_padding' => [0, 256, 4, 32],
    ];

    /**
     * Wire names the rulings keep OUT of the registry (row-existence is the
     * defect this pins reddens). DTO-owned ones are separately asserted to
     * EXIST on MediaRequest — the ruling is "reachable elsewhere", never
     * "does not exist".
     *
     * @var list<string>
     */
    private const ABSENT_FROM_REGISTRY = [
        'mask_blur_x', 'mask_blur_y',                 // #37/#38 single-field ruling
        'firstphase_width', 'firstphase_height',      // #22/#27 HiresMath computes them
        'initial_noise_multiplier',                   // #44 override-only
        'restore_faces', 'tiling',                    // #46/#47 Settings-moved
        'eta', 's_churn', 's_tmax', 's_tmin', 's_noise', 's_min_uncond', // #57 family
        'token_merging_ratio', 'token_merging_ratio_hr',                 // #58
        'sigma_min', 'sigma_max', 'rho', 'noise_info', 'uni_pc_order',
        'sd_noise_schedule', 's_min_uncond_all', 'skip_early_cond',
        'sgm_noise_multiplier', 'discard_next_to_last_sigma', 'ENSD',    // #57 pure-opts shapes
        'override_settings', 'override_settings_restore_afterwards',     // #29 W3.8 editor
        'script_name', 'script_args', 'alwayson_scripts',                // #30/#31 W3.6
        'init_images', 'mask', 'infotext',                               // #32/#36/#51 doors
        'send_images', 'save_images', 'do_not_save_samples', 'do_not_save_grid',
        'disable_extra_networks', 'comments', 'force_task_id', 'include_init_images', // #88–#90
    ];

    /**
     * The proven-negatives vocabulary (rule (e), Appendix B #60/#61): stock
     * A1111 does not have these and neither may crush, in any executable
     * position. The scan runs comment-stripped, so the FieldSpec/InpaintSection
     * RULING COMMENTS naming them stay legal.
     */
    private const PROVEN_NEGATIVES_PATTERN = '/\b(tcd|tcm_time_indices|regional_seeds?|regional_hint_matrix|per_axis_weights?|vary_regions?|diffuse_noise|prompt_s_r|cfg_end|cfg_restart|cfg_row)\b/i';

    // ------------------------------------------------------------------ ledger

    public function testTheAppendixLedgerIsCompleteAndWellFormed(): void
    {
        $this->assertCount(90, self::LEDGER, 'Appendix B numbers rows 1–90; the ledger must carry exactly one disposition each');
        $this->assertSame(\range(1, 90), \array_keys(self::LEDGER), 'ledger row numbers must be 1..90 in order');

        foreach (self::LEDGER as $row => [$kind, $pins, $note]) {
            $this->assertNotSame('', $note, "appendix row {$row} needs its ruling note");

            if ($kind === 'covered') {
                foreach ($pins as $coordinate) {
                    $this->assertArrayHasKey($coordinate, self::registryCensus(), "appendix row {$row} cites {$coordinate} but the registry has no such row");
                }

                continue;
            }

            $this->assertContains($kind, self::NON_REGISTRY_KINDS, "appendix row {$row} kind {$kind}");
        }

        // Bidirectional census: every registry row is claimed by a covered entry.
        $claimed = [];
        foreach (self::LEDGER as [$kind, $pins]) {
            if ($kind === 'covered') {
                foreach ($pins as $coordinate) {
                    $this->assertArrayNotHasKey($coordinate, $claimed, "registry row {$coordinate} is claimed by two appendix rows");
                    $claimed[$coordinate] = 'claimed';
                }
            }
        }
        $this->assertEqualsCanonicalizing(
            \array_keys(self::registryCensus()),
            \array_keys($claimed),
            'the FieldSpec registry and the Appendix B covered-set must be exact mirrors of each other',
        );
    }

    public function testRuledAbsentControlsStayOutOfTheRegistry(): void
    {
        $keys = \array_map(static fn (FieldSpec $row): string => $row->key, self::parityRegistryRows());

        foreach (self::ABSENT_FROM_REGISTRY as $wire) {
            $this->assertNotContains($wire, $keys, "wire field {$wire} has a ruled disposition WITH NO registry row — a FieldSpec row for it would contradict Appendix B (override/generic/client channel is its sanctioned home)");
        }
    }

    public function testProvenNegativesNeverEnterExecutableCode(): void
    {
        $offenders = [];

        foreach (self::mediaSourcePaths() as $path) {
            $code = $this->codeTextOf($path);

            if (\preg_match_all(self::PROVEN_NEGATIVES_PATTERN, $code, $hits) > 0) {
                foreach ($hits[0] as $hit) {
                    $offenders[] = \basename($path) . ':' . $hit;
                }
            }
        }

        $this->assertSame([], $offenders, 'proven-negatives rule (e): these names may appear ONLY in ruling comments (stripped here); an executable hit ships a Forge/SD.Next concept as a core field');
    }

    // ------------------------------------------------------------- DTO bridge

    public function testEveryRegistryRowBridgesToADtoWireField(): void
    {
        $dto = $this->dtoWireNames();

        foreach (self::parityRegistryRows() as $row) {
            $this->assertArrayHasKey($row->key, $dto, "FieldSpec row {$row->key} names a wire field MediaRequest does not carry — the bridge would silently land in unknownKeys()");
        }
    }

    public function testOverrideAndClientChannelsCarryTheirDtoFields(): void
    {
        $dto = $this->dtoWireNames();
        $dtoOwned = [];

        foreach (self::LEDGER as $number => [$kind, $pins]) {
            if ($kind === 'descoped' && $pins !== []) {
                // #38 pins the x/y absence on the DTO too — it is the one
                // descoped row whose pins name real wire fields (they DO
                // exist upstream); handled as existence-refuted below.
                foreach ($pins as $wire) {
                    $this->assertArrayNotHasKey($wire, $dto, "descoped row {$number} pins {$wire} away from the DTO as well");
                }

                continue;
            }

            if (\in_array($kind, ['override', 'client', 'generic', 'section'], true)) {
                foreach ($pins as $wire) {
                    if (\str_starts_with($wire, 'tui.genform.')) {
                        continue; // a lang-key citation, not a wire name
                    }

                    $dtoOwned[$wire] = $number;
                }
            }
        }

        foreach ($dtoOwned as $wire => $number) {
            $this->assertArrayHasKey($wire, $dto, "appendix row {$number} routes {$wire} through a non-registry channel, but the DTO does not carry the field at all");
        }

        // The v1 refusal roster itself (probe-first list, W1.1 DoD).
        $this->assertSame(['latent_mask'], \array_keys(MediaRequest::NOT_SUPPORTED_V1));
        $this->assertArrayNotHasKey('latent_mask', $dto);
    }

    // --------------------------------------------------------------- lattices

    /**
     * @return array<string, array{0: int|float, 1: int|float, 2: int|float, 3: int|float}>
     */
    public static function latticeCatalog(): array
    {
        $cases = [];

        foreach (self::LATTICE as $coordinate => $lattice) {
            $cases[$coordinate] = [$coordinate, ...$lattice];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('latticeCatalog')]
    public function testNumericRowsSitOnTheCatalogLattice(string $coordinate, int|float $min, int|float $max, int|float $step, int|float $default): void
    {
        $row = self::registryCensus()[$coordinate] ?? null;
        $this->assertNotNull($row, "lattice catalog cites {$coordinate} but the registry has no such row");
        $this->assertContains($row->widget, [FieldSpec::WIDGET_SLIDER, FieldSpec::WIDGET_NUMBER], "{$coordinate} is cited as numeric but its widget is {$row->widget}");
        $this->assertSame($min, $row->min, "{$coordinate} min drifted from the §2 table");
        $this->assertSame($max, $row->max, "{$coordinate} max drifted from the §2 table");
        $this->assertSame($step, $row->step, "{$coordinate} step drifted from the §2 table");
        $this->assertSame($default, $row->default, "{$coordinate} default drifted from the §2 table");
    }

    public function testTheLatticeCatalogCoversEveryNumericRow(): void
    {
        $numeric = [];

        foreach (self::registryCensus() as $coordinate => $row) {
            if (\in_array($row->widget, [FieldSpec::WIDGET_SLIDER, FieldSpec::WIDGET_NUMBER], true)) {
                $numeric[] = $coordinate;
            }
        }

        $this->assertEqualsCanonicalizing($numeric, \array_keys(self::LATTICE), 'a slider/number row joined the registry without a catalog lattice entry (or one vanished)');
    }

    // ------------------------------------------------------------ value bridge

    /**
     * @return array<string, array{0: string}>
     */
    public static function registryCoordinates(): array
    {
        return \array_combine(
            \array_keys(self::registryCensus()),
            \array_map(static fn (string $coordinate): array => [$coordinate], \array_keys(self::registryCensus())),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('registryCoordinates')]
    public function testRowDefaultsRoundTripThroughTheDraft(string $coordinate): void
    {
        $row = self::registryCensus()[$coordinate];
        $seed = $row->widget === FieldSpec::WIDGET_MULTI ? $row->defaultList : $row->default;

        $draft = $row->applyTo(MediaRequest::new(), $seed);

        $this->assertFalse(
            \array_key_exists($row->key, $draft->unknownKeys()),
            "{$coordinate} applyTo fell through the bridge match-arms into unknownKeys — the row has no typed arm",
        );
        $this->assertEquals($seed, $row->valueFrom($draft), "{$coordinate} default does not round-trip through apply/value");
    }

    // ------------------------------------------------------------------ labels

    public function testEveryRowLabelResolvesInEnglish(): void
    {
        $english = require \dirname(__DIR__, 2) . '/lang/en.php';

        foreach (self::parityRegistryRows() as $row) {
            $this->assertArrayHasKey($row->labelKey, $english, "FieldSpec row {$row->key} labels itself with {$row->labelKey} which en.php does not define");
        }
    }

    public function testNoOrphanFieldLabelsInEnglish(): void
    {
        $english = require \dirname(__DIR__, 2) . '/lang/en.php';
        $rowed = \array_map(static fn (FieldSpec $row): string => $row->labelKey, self::parityRegistryRows());

        foreach ($english as $key => $value) {
            if (!\is_string($key) || !\str_starts_with($key, 'tui.genform.field.')) {
                continue;
            }

            if (\in_array($key, $rowed, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $key,
                self::SANCTIONED_UNROWED_FIELD_LABELS,
                "en.php carries {$key} but no FieldSpec row cites it and no ruling licenses it — delete the copy or add the ledger disposition (GenFormKeys law: never ship a stranded field row)",
            );
        }

        // Staleness arm: a licensé whose control later gains a row must leave
        // the sanctioned map, or the map itself is the drift.
        foreach (\array_keys(self::SANCTIONED_UNROWED_FIELD_LABELS) as $licensed) {
            $this->assertNotContains($licensed, $rowed, "{$licensed} is now a registry row — drop its sanctioned-unrowed licensé");
        }
    }

    // ------------------------------------------------------------- vocabularies

    public function testChoiceVocabulariesMatchTheCatalog(): void
    {
        // §2.9: twelve schedules, the dropdown lands on Automatic.
        $this->assertCount(12, Schedulers::names(), 'Appendix B #10 records a 12-entry scheduler roster');
        $this->assertSame('Automatic', Schedulers::defaultName());
        // §2.1/§2.9: the sampler dropdown lands on plain Euler.
        $this->assertSame('Euler', Samplers::defaultName());
        // §2.8: exactly six latent upscale modes, Latent first.
        $upscalers = FieldSpec::latentUpscalers();
        $this->assertCount(6, $upscalers);
        $this->assertSame('Latent', $upscalers[0]);
        // C-1: exactly four masked-content values, "original" at index 1.
        $this->assertCount(4, FieldSpec::fillOptions(), 'conflict ruling C-1: a fifth value would be a Forge/SD.Next concept');
        // §2.6/§2.7 rosters.
        $this->assertCount(4, FieldSpec::resizeModeOptions());
        $this->assertCount(2, FieldSpec::maskInvertOptions());
        $this->assertCount(2, FieldSpec::areaOptions());
    }

    // ------------------------------------------------------------------- video

    public function testVideoTabConsumesTheFrameLadderSsot(): void
    {
        $ladder = FrameLadder::LADDERS['sglang-diffusion'];
        foreach ($ladder as $rung) {
            $this->assertSame(1, $rung % FrameLadder::STRIDE, "rung {$rung} breaks the 8k+1 law");
        }

        // The §6.2 window rows and the SSOT law: VideoSection's ceiling IS the
        // recorded ladder's top rung (never a re-encoded copy), its default
        // sits ON a rung, and the snap-UP law holds at the edges.
        $this->assertSame(25, VideoSection::FRAMES_MIN, 'Appendix B #64 records the 25–257 window');
        $this->assertSame(\max($ladder), VideoSection::FRAMES_MAX, 'the form ceiling must be derived from the ladder top rung');
        $this->assertSame(257, VideoSection::FRAMES_MAX);
        $this->assertContains(VideoSection::DEFAULT_FRAMES, $ladder, 'the default frames value must sit on a recorded rung (shared Wan/LTX 121)');
        $this->assertSame(121, VideoSection::DEFAULT_FRAMES);

        $family = \SugarCraft\Code\Media\Capability\EndpointFamily::SglangDiffusion;
        $this->assertSame(121, FrameLadder::snap(121, $family)->frames);
        $this->assertSame(81, FrameLadder::snap(25, $family)->frames, 'the snap-UP law: 25 coalesces to the first rung at or above it');
        $this->expectException(\InvalidArgumentException::class);
        FrameLadder::snap(258, $family);
    }

    public function testVideoSectionNeverReEncodesUnchosenRungs(): void
    {
        $code = $this->codeTextOf((new \ReflectionClass(VideoSection::class))->getFileName());

        // The rung set is [81, 121, 193, 257]; 121 (default) and 257 (derived
        // ceiling) are legal constants. A hard 81 or 193 in executable text
        // would mean the form grew its own copy of the ladder.
        $this->assertDoesNotMatchRegularExpression('/\b(81|193)\b/', $code, 'VideoSection must consume FrameLadder, not re-encode its rungs (the ladder law, plan W5.6)');
    }

    public function testVideoWireNamesMatchTheLedger(): void
    {
        $this->assertSame('num_frames', VideoSection::ROW_FRAMES);
        $this->assertSame('fps', VideoSection::ROW_FPS);
        $this->assertSame('cfg', VideoSection::ROW_CFG);
        $this->assertSame('cfg2', VideoSection::ROW_CFG2, 'dual-CFG seam for the Wan two-expert row (#70)');
        $this->assertSame('motion', VideoSection::ROW_MOTION);

        $code = $this->codeTextOf((new \ReflectionClass(VideoSection::class))->getFileName());
        $this->assertStringContainsString("'first_frame_image'", $code, 'the i2v first-frame slot must ride the dialect wire name (#68)');

        // #65 fps typicals and #66 resolution classes.
        $this->assertSame(['16', '24', '25', '26', '27', '28', '29', '30'], VideoSection::FPS_OPTIONS);
        $this->assertSame('24', VideoSection::DEFAULT_FPS);
        $this->assertSame(['480p (832x480)', '720p (1280x720)', '1080p (1920x1080)'], \array_keys(VideoSection::RESOLUTION_CLASSES));

        // #71 seam: flow/sample shift is NOT shipped — this test records the
        // CURRENT truth so the seam cannot silently half-land.
        $this->assertStringNotContainsString('flow_shift', $code);
        $this->assertStringNotContainsString('sample_shift', $code);
    }

    // ----------------------------------------------------------------- plumbing

    /**
     * The full registry: the three group builders, in build order.
     *
     * @return list<FieldSpec>
     */
    public static function parityRegistryRows(): array
    {
        return [...FieldSpec::coreRows(), ...FieldSpec::disclosureRows(), ...FieldSpec::wave4Rows()];
    }

    /**
     * coordinate "group:key" => row. The group tags disambiguate the
     * one-field-two-contexts denoising_strength pair (0.7 hires, 0.75 img2img).
     *
     * @return array<string, FieldSpec>
     */
    public static function registryCensus(): array
    {
        $census = [];

        foreach (['core' => FieldSpec::coreRows(), 'disclosure' => FieldSpec::disclosureRows(), 'wave4' => FieldSpec::wave4Rows()] as $group => $rows) {
            foreach ($rows as $row) {
                $census[$group . ':' . $row->key] = $row;
            }
        }

        return $census;
    }

    /**
     * MediaRequest's canonical wire-name set, read from the private
     * FIELD_SPEC const ([wire, prop, kind, guard] rows).
     *
     * @return array<string, list<mixed>>
     */
    private function dtoWireNames(): array
    {
        $spec = (new \ReflectionClass(MediaRequest::class))->getConstant('FIELD_SPEC');
        $this->assertIsArray($spec, 'MediaRequest::FIELD_SPEC must stay a constant array for this parity guard');

        return \array_combine(\array_column($spec, 0), $spec);
    }

    /**
     * Every PHP file in the media subtree plus the two generation tools — the
     * executable surface the proven-negatives law governs.
     *
     * @return list<string>
     */
    private static function mediaSourcePaths(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $paths = [];

        foreach (['/Media', '/Tui/MediaForm'] as $subtree) {
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $subtree, \FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $paths[] = $root . '/Tools/BuiltIn/GenerateImage.php';
        $paths[] = $root . '/Tools/BuiltIn/GenerateVideo.php';

        return $paths;
    }

    /**
     * The file's executable text with every comment token removed — ruling
     * comments naming forbidden vocabulary stay legal, code naming it reddens.
     * (PhpToken spans via $pos, the campaign law.)
     */
    private function codeTextOf(string $path): string
    {
        $source = \file_get_contents($path);
        $this->assertNotFalse($source, "parity guard cannot read {$path}");

        $text = '';

        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            $text .= $token->text;
        }

        return $text;
    }
}
