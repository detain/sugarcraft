# C-TUI-5.d WELD — batch-6 step 1

PICK 963ddd0f6 -> aa0e46158 (master). One conflict: lang/en.php EOF both-sides (HEAD diffreview/commit blocks vs lane wizard block) — resolved keeping BOTH sides; php -l clean. git cherry: '+' (conflict-resolved) — content-equivalence: wizard block bytes + all 6 test/src files identical to lane; en.php union superset documented here.

WELD-CURE 54730fdcd: FirstRunWizardTest piped-leg positive control `self::assertTrue(FirstRunWizard::shouldOffer(false, false, true), ...)` per REVIEW-1 MINOR (the isatty-fed assert was tautological behind the early return; cures the pin, C-TUI-3.f fold-in precedent).

GATES at merged tree (restamped actuals): new files combined 28T/96A; censuses SettingsWriterCensus|ReadPathCensus 24T/5505A (lane 5436A + growth from tree carry); lang/glob family 95T/7493A (lane 7380A + growth); peers MediaForm|DirectoryPicker|LangLaunchLocale 317T/2683A EXACT; combined 147T/13094A (lane 12911A + cure leg +27A? restamp). All OK; 2 standing deprecations.

DURATIONS rows minted (merged junit j-tui5d.xml): ProviderKeyStoreTest 0.016/7; Tui/FirstRunWizard/FirstRunWizardTest 0.140/21.

§4 cites 963ddd0f6. Carry +28T (27 lane methods + 1 cure leg folded — file-level total 21+7=28 tests across the two new files). suite-figure: batch re-pin carry (lead law: figure refresh at closeout, not per-weld).
