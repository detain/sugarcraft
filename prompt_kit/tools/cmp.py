import sys, xml.etree.ElementTree as ET
from collections import defaultdict

def per_class(path):
    """Sum assertions per test CLASS, reading <testcase class=...> leaves only."""
    d = defaultdict(int)
    n = defaultdict(int)
    for tc in ET.parse(path).getroot().iter('testcase'):
        cls = tc.get('class') or tc.get('classname') or '?'
        d[cls] += int(tc.get('assertions') or 0)
        n[cls] += 1
    return d, n

a, na = per_class(sys.argv[1])   # branch
b, nb = per_class(sys.argv[2])   # master
print(f"branch total {sum(a.values())} in {sum(na.values())} tests")
print(f"master total {sum(b.values())} in {sum(nb.values())} tests")
print()
print(f"{'class':<62} {'master':>8} {'branch':>8} {'delta':>7} {'dTests':>7}")
print('-'*96)
rows = []
for k in set(a) | set(b):
    da = a.get(k,0) - b.get(k,0)
    dt = na.get(k,0) - nb.get(k,0)
    if da or dt:
        rows.append((da, k, b.get(k,0), a.get(k,0), dt))
for da, k, m, br, dt in sorted(rows, key=lambda r: -abs(r[0])):
    print(f"{k:<62} {m:>8} {br:>8} {da:>+7} {dt:>+7}")
print('-'*96)
print(f"{'SUM OF DELTAS':<62} {'':>8} {'':>8} {sum(r[0] for r in rows):>+7} {sum(r[4] for r in rows):>+7}")
