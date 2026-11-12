import sys, os, math
sys.path.insert(0, '.')
import straturi as S
from straturi import *

def run5(h_start, h_end, base_w=0.5, ov=1/3):
    coins = BASKET; cash = 1.0; q = {c: 0.0 for c in coins}; until = {c: -1 for c in coins}
    eqs = []; expo = 0.0; fees = 0.0; ntr = 0
    for h in range(h_start, h_end):
        px = {c: price(c, h) for c in coins}
        eq = cash + sum(q[c] * px[c] for c in coins)
        ev = set()
        for c in coins:
            if until[c] == h: ev.add(c)
            if until[c] < h and sig(c, h): until[c] = h + 24; ev.add(c)
        for c in coins:
            tgt = max(base_w / 3, ov) if until[c] > h else base_w / 3
            diff = tgt * eq - q[c] * px[c]
            if abs(diff) > BAND * eq or (c in ev and abs(diff) > 1e-9) or h == h_start:
                fees += abs(diff) * FEE; cash -= diff + abs(diff) * FEE; q[c] += diff / px[c]
                if c in ev: ntr += 1
        eq = cash + sum(q[c] * px[c] for c in coins); eqs.append(eq); expo += (eq - cash) / eq
    peak = eqs[0]; dd = 0.0
    for e in eqs: peak = max(peak, e); dd = max(dd, 1 - e / peak)
    yrs = len(eqs) / AN; cagr = eqs[-1] ** (1 / yrs) - 1
    ms = [eqs[i] for i in range(0, len(eqs), 730)] + [eqs[-1]]
    return dict(tot=eqs[-1]-1, cagr=cagr, dd=dd, calmar=cagr/dd, wm=min(ms[i+1]/ms[i]-1 for i in range(len(ms)-1)),
                expo=expo/len(eqs), fees=fees), ntr

start = max(D[c][0] for c in BASKET) + 900
end = min(D[c][0] + len(D[c][1]) for c in BASKET) - 1
split = hour_of(2023)
for pn, (a, b) in {'ANTRENARE 2019-08 → 2022-12': (start, split), 'TEST 2023-01 → 2026-11': (split, end)}.items():
    print(f'\n=== {pn} ===')
    print(line('S0 detinere simpla', run('S0', a, b)))
    print(line('S1 50/50 fix', run('S1', a, b, static=0.5)))
    for ov, nm in ((1/3, 'S5 50/50 + exceptia 1/3'), (1/4, '   varianta exceptie 1/4'), (1/2, '   varianta exceptie 1/2')):
        r, n = run5(a, b, 0.5, ov); print(line(nm, r) + f'  evenimente {n//2}')
        if ov == 1/3:
            print(line(f'S1m fix la {r["expo"]*100:.0f}%', run('S1m', a, b, static=r['expo'])))
