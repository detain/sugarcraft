# C-TUI-4.a — fix round 1 (in progress)

m1 DONE: SettingsSearch::matches gains ?OptionsProvider $options=null (defaults
OptionsProvider::new(), mirrors SettingsSources::fromLaunch); haystack() now unions
$options->for($definition) (declared enums else live picker list) instead of raw
enumValues; SettingsEditor::rows() threads $this->sources->options. GENERIC fix, no
theme special-case. Pins: SettingsEditorPolishTest::testTheSearchFindsAKeyByItsLiveOptionsWithoutDeclaredEnums
(dracula/tokyoNight find theme; 'zzqxjh' control does not; supplied-list-replaces-builtin
both directions; editor rows() with custom launch provider).
m2 DONE: Theme::fromFileOrFallback splits stem===null (file_missing, unchanged shape)
from load()===null on a LISTED stem (new chat.theme.file_refused notice naming the
real-path containment refusal; available-list semantics unchanged — the stem is
genuinely on disk). Warn-once key 'refused|<name>'. Pin: ThemeFileTest symlink test now
drains the notice, asserts wording + NOT 'no file under' + once-per-process.
n1 DONE: Theme::baseNames()/isBaseName() DROPPED (own grep: zero callers; the
WorkflowRegistry baseNames() hits are its own private ($dir,$suffix) method, unrelated).
canonicalBaseName()/isBuiltInName()/suggest() are the live surface.
n2 DONE: lang/en.php chat.theme.* re-sorted alphabetically (current, file_malformed,
file_missing, file_notes, file_refused, set); Theme.php imports sorted (Core\Util\Color
moved into place); ChatTest.php Theme/RuntimeNoticeSink merged into the Code block.
m3 NOT touched per instruction (Chat.php byte-untouched; my m2 change does not alter
what that comment should say about byName throwing — it still never throws).

gates: theme trio 24T/117A OK; Chat theme quartet 4T/13A OK; Polish+LangParity+
DocDrift+Schema 84T/6139A OK; theme|Theme sweep 253T/10570A 1F (foreign MediaSettings
provider-schema, same as lane tip); Tui\Settings+ReadPathCensus 109T/3104A 2F (both
foreign: ReadPathCensus fully-classified ServerConfig×2+RemoteSessionPush, SettingsEditor
git-category — same pair the review recorded).

mutations (1-red-each, cp-restore clean via committed blob):
M1 haystack->raw enumValues   -> SettingsEditorPolishTest 1F (new test names theme leg)
M2 refused-notice -> file_missing key -> ThemeFileTest 1F (symlink notice pin)
M4 editor drops 3rd arg        -> SettingsEditorPolishTest 1F (rows() custom-provider leg)
warn-once arm (delete isset guard) reasoned: second byName('linked') drains a row ->
the new assertSame([], drain()) pin reddens; not executed (M2 covers the branch).

guard triage at tip (446T/51387A, 5F):
- ReadmeSuiteFigureDrift x2 = staleness pair, EXPECTED (+1T this fix: new polish test
  method; welder re-pin duty now +14T over master aa5ddf638).
- DTHD duplication (OPEN/CLOSE/STUB_CANDIDATE/HEAVY_CHARS: CopySlashCommandTest vs
  CopyCommandTest etc.) + TreeWideGuardRoster x2 (NonInteractiveSessionPersistenceTest,
  ServeArgsTest walks) = FOREIGN, PROVEN identical at base 8bc69dcfa (3F same list).
No new test FILES (methods only) -> durations.tsv impact zero; ThemeFileTest unchanged
9T (assertions 35->41), so the carry row 9T stays exact.
m3 disclosure: Chat.php comment ~:7937 truth ("theme() throws…") is unaffected by this
fix round — byName still never throws; no rider wording change needed beyond what the
weld rider already says.
