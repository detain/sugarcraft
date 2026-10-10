<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

/**
 * Canonical tui.genform.* lang-key vocabulary for the generate form.
 *
 * crush-media W3.10: every control label, help sentence, gate note and chrome
 * row that the W3.4-W3.9 form sections will paint is enumerated HERE, as lang
 * keys, so later lanes render from this module instead of adding en-only
 * stragglers to lang/en.php. Values are NOT duplicated in code — lang/en.php
 * owns the display text; these constants own the KEY SPELLINGS (and keep the
 * TuiLangTest bidirectional census honest from day one, before any section
 * actually cites a key inline).
 *
 * Enumerated from plan_crush_media Appendix F §2.1 (txt2img controls), §2.2
 * (semantics sentences), §2.3 (seed tooltips, verbatim), §2.6 (img2img/inpaint
 * controls, resize-mode and masked-content radios verbatim per conflict
 * ledger C-1), §2.7 (mask semantics), plus the W3.5/W3.7 gate note, the W3.6
 * script-note row and the W3.9 preset/output rows.
 *
 * Lanes b/c/d: when a section renders one of these, cite the constant (or the
 * identical inline literal — the census accepts either) through Lang::t(). If a
 * genuinely new string is needed, add it to BOTH here and lang/en.php in the
 * same commit; never ship a lang key with no vocabulary row.
 *
 * Mirrors the shape of candy-forms FieldSpec labels: one key per visible
 * affordance, placeholders carried as {tokens} for Lang::t($key, $params).
 */
final class GenFormKeys
{
    // -- chrome ----------------------------------------------------------------
    public const string TITLE = 'tui.genform.title';
    public const string TOO_SMALL = 'tui.genform.too_small';
    public const string TAB_PARAMS = 'tui.genform.tab.params';
    public const string TAB_IMAGE = 'tui.genform.tab.image';
    public const string NOT_SET = 'tui.genform.not_set';
    public const string KEYS_HELP = 'tui.genform.keys_help';

    // -- key-help rows (lane a) --------------------------------------------------
    public const string KEY_GENERATE = 'tui.genform.key.generate';
    public const string KEY_EDIT = 'tui.genform.key.edit';
    public const string KEY_MOVE = 'tui.genform.key.move';
    public const string KEY_SECTIONS = 'tui.genform.key.sections';
    public const string KEY_CLOSE = 'tui.genform.key.close';
    public const string KEY_COMMIT = 'tui.genform.key.commit';
    public const string KEY_CANCEL = 'tui.genform.key.cancel';

    // -- row labels (lane a core six) ---------------------------------------------
    public const string ROW_PROMPT = 'tui.genform.row.prompt';
    public const string ROW_STEPS = 'tui.genform.row.steps';
    public const string ROW_CFG_SCALE = 'tui.genform.row.cfg_scale';
    public const string ROW_SAMPLER = 'tui.genform.row.sampler';
    public const string ROW_SIZE = 'tui.genform.row.size';
    public const string ROW_SEED = 'tui.genform.row.seed';

    // -- section headers -----------------------------------------------------------
    public const string SECTION_PROMPT = 'tui.genform.section.prompt';
    public const string SECTION_PARAMS = 'tui.genform.section.params';
    public const string SECTION_SEED = 'tui.genform.section.seed';
    public const string SECTION_EXTRA = 'tui.genform.section.extra';
    public const string SECTION_HIRES = 'tui.genform.section.hires';
    public const string SECTION_REFINER = 'tui.genform.section.refiner';
    public const string SECTION_SCRIPTS = 'tui.genform.section.scripts';
    public const string SECTION_IMAGE = 'tui.genform.section.image';
    public const string SECTION_INPAINT = 'tui.genform.section.inpaint';
    public const string SECTION_ADVANCED = 'tui.genform.section.advanced';
    public const string SECTION_PRESETS = 'tui.genform.section.presets';
    public const string SECTION_OUTPUT = 'tui.genform.section.output';

    // -- text placeholders (lane b W3.4 addition, C-2: neutral, no chord myths) --
    public const string PROMPT_PLACEHOLDER = 'tui.genform.prompt.placeholder';
    public const string NEGATIVE_PLACEHOLDER = 'tui.genform.negative_prompt.placeholder';

    // -- field labels (§2.1/§2.6) ----------------------------------------------------
    public const string FIELD_NEGATIVE_PROMPT = 'tui.genform.field.negative_prompt';
    public const string FIELD_STYLES = 'tui.genform.field.styles';
    public const string FIELD_WIDTH = 'tui.genform.field.width';
    public const string FIELD_HEIGHT = 'tui.genform.field.height';
    public const string FIELD_SAMPLING_STEPS = 'tui.genform.field.sampling_steps';
    public const string FIELD_SAMPLING_METHOD = 'tui.genform.field.sampling_method';
    public const string FIELD_SAMPLING_SCHEDULE = 'tui.genform.field.sampling_schedule';
    public const string FIELD_BATCH_COUNT = 'tui.genform.field.batch_count';
    public const string FIELD_BATCH_SIZE = 'tui.genform.field.batch_size';
    public const string FIELD_SUBSEED = 'tui.genform.field.subseed';
    public const string FIELD_SUBSEED_STRENGTH = 'tui.genform.field.subseed_strength';
    public const string FIELD_SEED_RESIZE_FROM_W = 'tui.genform.field.seed_resize_from_w';
    public const string FIELD_SEED_RESIZE_FROM_H = 'tui.genform.field.seed_resize_from_h';
    public const string FIELD_TILING = 'tui.genform.field.tiling';
    public const string FIELD_RESTORE_FACES = 'tui.genform.field.restore_faces';
    public const string FIELD_DENOISING_STRENGTH = 'tui.genform.field.denoising_strength';
    public const string FIELD_IMAGE_CFG_SCALE = 'tui.genform.field.image_cfg_scale';
    public const string FIELD_RESIZE_MODE = 'tui.genform.field.resize_mode';
    public const string FIELD_MASK_MODE = 'tui.genform.field.mask_mode';
    public const string FIELD_MASKED_CONTENT = 'tui.genform.field.masked_content';
    public const string FIELD_INPAINT_AREA = 'tui.genform.field.inpaint_area';

    // -- seed affordances (§2.3, tooltip sentences verbatim) --------------------------
    public const string SEED_RANDOMIZE = 'tui.genform.seed.randomize';
    public const string SEED_REUSE = 'tui.genform.seed.reuse';
    public const string SEED_ENSD = 'tui.genform.seed.ensd';
    public const string SEED_RANDN_SOURCE = 'tui.genform.seed.randn_source';
    public const string SEED_LAST = 'tui.genform.seed.last';

    // -- seed action row labels (lane b W3.4 addition; the tooltip sentences above
    //    are the SEED_RANDOMIZE / SEED_REUSE help text, these are the short rows) --
    public const string SEED_ACTION_RANDOMIZE = 'tui.genform.seed.action_randomize';
    public const string SEED_ACTION_REUSE = 'tui.genform.seed.action_reuse';

    // -- semantics help sentences (§2.2/§2.3) ------------------------------------------
    public const string HELP_STEPS = 'tui.genform.help.steps';
    public const string HELP_CFG_SCALE = 'tui.genform.help.cfg_scale';
    public const string HELP_WIDTH = 'tui.genform.help.width';
    public const string HELP_HEIGHT = 'tui.genform.help.height';
    public const string HELP_BATCH_COUNT = 'tui.genform.help.batch_count';
    public const string HELP_BATCH_SIZE = 'tui.genform.help.batch_size';
    public const string HELP_SEED = 'tui.genform.help.seed';
    public const string HELP_VARIATION_STRENGTH = 'tui.genform.help.variation_strength';
    public const string HELP_SEED_RESIZE = 'tui.genform.help.seed_resize';
    public const string HELP_HIRES = 'tui.genform.help.hires';
    public const string HELP_DENOISING = 'tui.genform.help.denoising';
    public const string HELP_IMAGE_CFG = 'tui.genform.help.image_cfg';
    public const string HELP_REFINER = 'tui.genform.help.refiner';
    public const string HELP_TILING = 'tui.genform.help.tiling';
    public const string HELP_RESTORE_FACES = 'tui.genform.help.restore_faces';

    // -- Hires. fix accordion (§2.1/§2.4) ----------------------------------------------
    public const string HIRES_UPSCALER = 'tui.genform.hires.upscaler';
    public const string HIRES_STEPS = 'tui.genform.hires.steps';
    public const string HIRES_STEPS_HELP = 'tui.genform.hires.steps_help';
    public const string HIRES_UPSCALE_BY = 'tui.genform.hires.upscale_by';
    public const string HIRES_RESIZE_WIDTH = 'tui.genform.hires.resize_width';
    public const string HIRES_RESIZE_HEIGHT = 'tui.genform.hires.resize_height';
    public const string HIRES_CHECKPOINT = 'tui.genform.hires.checkpoint';
    public const string HIRES_SAMPLING_METHOD = 'tui.genform.hires.sampling_method';
    public const string HIRES_SAMPLING_SCHEDULE = 'tui.genform.hires.sampling_schedule';
    public const string HIRES_USE_SAME = 'tui.genform.hires.use_same';
    public const string HIRES_PROMPT = 'tui.genform.hires.prompt';
    public const string HIRES_NEGATIVE_PROMPT = 'tui.genform.hires.negative_prompt';
    public const string HIRES_FINAL_RESOLUTION = 'tui.genform.hires.final_resolution';

    // -- W3.5 reveal checkboxes + computed row (labels verbatim from A1111
    //    ui.py's hires_fix_show_* checkboxes; the output-size label heads the
    //    txtimg_hr_finalres preview whose value is HIRES_FINAL_RESOLUTION) --
    public const string HIRES_SHOW_CHECKPOINT = 'tui.genform.hires.show_checkpoint';
    public const string HIRES_SHOW_SAMPLER = 'tui.genform.hires.show_sampler';
    public const string HIRES_SHOW_PROMPTS = 'tui.genform.hires.show_prompts';
    public const string HIRES_OUTPUT_SIZE = 'tui.genform.hires.output_size';

    // -- shared option/vocabulary atoms (W3.5): the dropdown "None" entry,
    //    the nouns that fill HIRES_USE_SAME's {field} token, and the on/off
    //    cells every Confirm-toggled row shows in the list --
    public const string OPTION_NONE = 'tui.genform.option.none';
    public const string NOUN_CHECKPOINT = 'tui.genform.noun.checkpoint';
    public const string NOUN_SAMPLER = 'tui.genform.noun.sampler';
    public const string NOUN_SCHEDULER = 'tui.genform.noun.scheduler';
    public const string STATE_ON = 'tui.genform.state.on';
    public const string STATE_OFF = 'tui.genform.state.off';

    // -- upscaler option labels (six latent modes, verbatim) -----------------------------
    public const string UPSCALER_LATENT = 'tui.genform.upscaler.latent';
    public const string UPSCALER_LATENT_ANTIALIASED = 'tui.genform.upscaler.latent_antialiased';
    public const string UPSCALER_LATENT_BICUBIC = 'tui.genform.upscaler.latent_bicubic';
    public const string UPSCALER_LATENT_BICUBIC_ANTIALIASED = 'tui.genform.upscaler.latent_bicubic_antialiased';
    public const string UPSCALER_LATENT_NEAREST = 'tui.genform.upscaler.latent_nearest';
    public const string UPSCALER_LATENT_NEAREST_EXACT = 'tui.genform.upscaler.latent_nearest_exact';

    // -- Refiner accordion (§2.1) ----------------------------------------------------------
    public const string REFINER_CHECKPOINT = 'tui.genform.refiner.checkpoint';
    public const string REFINER_SWITCH_AT = 'tui.genform.refiner.switch_at';
    public const string REFINER_SWITCH_AT_HELP = 'tui.genform.refiner.switch_at_help';

    // -- scripts row (§2.1, W3.6 note) -------------------------------------------------------
    public const string SCRIPTS_NONE = 'tui.genform.scripts.none';
    public const string SCRIPTS_NO_BACKEND = 'tui.genform.scripts.no_backend';
    public const string SCRIPTS_NOTE = 'tui.genform.scripts.note';
    public const string SCRIPTS_SELECT = 'tui.genform.scripts.select';
    public const string SCRIPTS_IMG2IMG_NOTE = 'tui.genform.scripts.img2img_note';

    // -- img2img controls (§2.6) ---------------------------------------------------------------
    public const string IMAGE_SOURCE = 'tui.genform.image.source';
    public const string IMAGE_SKETCH = 'tui.genform.image.sketch';
    public const string IMAGE_INPAINT_TAB = 'tui.genform.image.inpaint_tab';
    public const string IMAGE_BATCH_DIR = 'tui.genform.image.batch_dir';
    public const string IMAGE_DETECT_SIZE = 'tui.genform.image.detect_size';
    public const string IMAGE_SCALE = 'tui.genform.image.scale';
    public const string IMAGE_SHOW_CFG = 'tui.genform.image.show_cfg';
    public const string IMAGE_SCALE_HELP = 'tui.genform.image.scale_help';

    // -- resize-mode radios (§2.6, verbatim) ----------------------------------------------------
    public const string RESIZE_JUST = 'tui.genform.resize.just';
    public const string RESIZE_CROP = 'tui.genform.resize.crop';
    public const string RESIZE_FILL = 'tui.genform.resize.fill';
    public const string RESIZE_LATENT = 'tui.genform.resize.latent';

    // -- inpaint controls (§2.6/§2.7) -------------------------------------------------------------
    public const string MASK_BLUR = 'tui.genform.mask.blur';
    public const string MASK_BLUR_HELP = 'tui.genform.mask.blur_help';
    public const string MASK_TRANSPARENCY = 'tui.genform.mask.transparency';
    public const string MASK_TRANSPARENCY_NOTE = 'tui.genform.mask.transparency_note';
    public const string MASK_MODE_MASKED = 'tui.genform.mask.mode_masked';
    public const string MASK_MODE_NOT_MASKED = 'tui.genform.mask.mode_not_masked';

    // -- masked-content radios (§2.6, verbatim per C-1) ----------------------------------------------
    public const string FILL_FILL = 'tui.genform.fill.fill';
    public const string FILL_ORIGINAL = 'tui.genform.fill.original';
    public const string FILL_LATENT_NOISE = 'tui.genform.fill.latent_noise';
    public const string FILL_LATENT_NOTHING = 'tui.genform.fill.latent_nothing';

    // -- inpaint-area radios + padding (§2.6, verbatim) -----------------------------------------------
    public const string AREA_WHOLE = 'tui.genform.area.whole';
    public const string AREA_ONLY_MASKED = 'tui.genform.area.only_masked';
    public const string AREA_PADDING = 'tui.genform.area.padding';
    public const string AREA_PADDING_PERCENT = 'tui.genform.area.padding_percent';
    public const string AREA_HELP = 'tui.genform.area.help';

    // -- W3.5/W3.7 gate note (C-2 honesty: the sections ship, the wire lanes do not) --------------------
    public const string GATE_WAVE4 = 'tui.genform.gate.wave4';

    // -- override-settings editor (§2.1 "Override settings", W3.8) --------------------------------------
    public const string ADVANCED_ADD = 'tui.genform.advanced.add';
    public const string ADVANCED_REMOVE = 'tui.genform.advanced.remove';
    public const string ADVANCED_KEY = 'tui.genform.advanced.key';
    public const string ADVANCED_TYPE = 'tui.genform.advanced.type';
    public const string ADVANCED_VALUE = 'tui.genform.advanced.value';
    public const string ADVANCED_JSON = 'tui.genform.advanced.json';
    public const string ADVANCED_JSON_HINT = 'tui.genform.advanced.json_hint';
    public const string ADVANCED_RESTORE_AFTERWARDS = 'tui.genform.advanced.restore_afterwards';

    // -- preset + output rows (W3.9) -----------------------------------------------------------------------
    public const string PRESET_SAVE = 'tui.genform.preset.save';
    public const string PRESET_LOAD = 'tui.genform.preset.load';
    public const string PRESET_APPLY = 'tui.genform.preset.apply';
    public const string PRESET_DELETE = 'tui.genform.preset.delete';
    public const string PRESET_NAME = 'tui.genform.preset.name';
    public const string OUTPUT_PATTERN = 'tui.genform.output.pattern';
    public const string OUTPUT_TOKENS = 'tui.genform.output.tokens';

    private function __construct()
    {
    }
}
