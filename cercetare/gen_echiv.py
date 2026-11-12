# Date de referinta pentru proba de echivalenta PHP <-> simularea din 12.11.2026.
import sys, math, json
sys.path.insert(0, '.')
import straturi as S
from straturi import D, BASKET, price, sig, run, hour_of, AN, FEE, BAND
src = open('s5.py', encoding='utf-8').read()
exec(src[src.index('def run5'):src.index('start = max')])   # run5, exact codul rulat

start = max(D[c][0] for c in BASKET) + 900
end = min(D[c][0] + len(D[c][1]) for c in BASKET) - 1
split = hour_of(2023)
h_from = split - 760

with open('echiv_inchideri.csv', 'w') as f:
    f.write('ora,BTCUSDC,ETHUSDC,ZECUSDC\n')
    for h in range(h_from, end):
        f.write('%d,%s\n' % (h, ','.join(repr(price(c, h)) for c in BASKET)))

def vol2(c, h):   # doua treceri, ca in PHP
    h0, p = D[c][0], D[c][1]; i = h - h0
    r = [math.log(p[j] / p[j - 1]) for j in range(i - 23, i + 1)]
    m = sum(r) / 24
    return math.sqrt(sum((x - m) ** 2 for x in r) / 24)
dif = 0; n = 0
with open('echiv_semnale.csv', 'w') as f:
    f.write('ora,' + ','.join(f'{c}_sim,{c}_2t' for c in BASKET) + '\n')
    for h in range(split, end):
        row = [str(h)]
        for c in BASKET:
            s_sim = sig(c, h)
            v = vol2(c, h); prev = [vol2(c, k) for k in range(h - 720, h)] if False else None
            row += ['1' if s_sim else '0', '']
        f.write(','.join(row) + '\n')
# semnalele pe doua treceri: vol pe toate orele o data, apoi rang
v2 = {}
for c in BASKET:
    v2[c] = {h: vol2(c, h) for h in range(split - 721, end)}
lines = open('echiv_semnale.csv').read().splitlines()
out = [lines[0]]
for ln in lines[1:]:
    parts = ln.split(','); h = int(parts[0]); k = 1
    for c in BASKET:
        prev = [v2[c][x] for x in range(h - 720, h)]
        rang = sum(1 for x in prev if x < v2[c][h]) / 720
        s2 = rang >= 0.8 and price(c, h) < price(c, h - 24)
        parts[k + 1] = '1' if s2 else '0'
        if (parts[k] == '1') != s2: dif += 1
        n += 1; k += 2
    out.append(','.join(parts))
open('echiv_semnale.csv', 'w').write('\n'.join(out) + '\n')

def final(kind):
    # starea finala exacta, refacuta cu acelasi cod: rulam si citim echitatea
    pass
rA = run('S1', split, end, static=0.5)
rB, nB = run5(split, end, 0.5, 1/3)
rC = run('S0', split, end)
ref = {'A': rA, 'B': rB, 'C': rC, 'evenimente_B': nB // 2, 'ora_start': split, 'ora_final': end,
       'diferente_semnal_sim_vs_doua_treceri': dif, 'semnale_comparate': n}
json.dump(ref, open('echiv_referinta.json', 'w'), indent=1)
print(json.dumps({k: (v if not isinstance(v, dict) else {x: v[x] for x in ('tot', 'fees')}) for k, v in ref.items()}, indent=1))
