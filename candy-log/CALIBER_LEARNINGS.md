# CandyLog Caliber Learnings

[syslog-levels] — Level enum uses syslog-aligned int values (-4/0/4/8/12) rather than sequential integers, making threshold comparisons and external integration (syslog, log aggregators) cleaner without value collisions.

[probe-color] — Color is determined by Probe::colorProfile()->allowsColor() in the Logger constructor, which respects NO_COLOR and FORCE_COLOR environment variables — do not hard-code color decisions.
