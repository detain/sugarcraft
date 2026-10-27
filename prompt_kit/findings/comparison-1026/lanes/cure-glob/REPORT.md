# cure-glob — DONE (W3 wave-review MAJOR-1 cured)
Fix: candy-code/tests/TreeWideGuardRosterTest.php:615 row gained licence "glob(\$phar.'.tmp-'.\\chr(42))" — the 404-sweep legs (:1617/:1635) read the same test-sandbox path via local $phar = livePharPath(); derivation cannot follow the assignment, so both spellings carry a licence (file already rostered for the $this->livePharPath() spelling; DST-3 comment extended).
Gates: pre-edit reproduced 2F; post-edit TreeWideGuardRoster|DuplicatedTestHelperDrift 31T/3538A OK; five-guard 133T only the allowed staleness pair (25,537 pinned vs 26,043 live = foreign-era, +0T here).
Commit d0a0ce626 on cc/cure-glob, author+committer detain@interserver.net (separate greps), zero Co-Authored, never pushed.
