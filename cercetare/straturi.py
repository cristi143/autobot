# Strategia in trei straturi pe ZEC+BTC+ETH. Vezi PREINREGISTRARE_trei_straturi.md
# pypy3 straturi.py
import os, sys, math
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from volcad import prep, SEEN, GOLD
B = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../historical_data'))
FEE = 0.00075; BAND = 0.05; AN = 8760
BASKET = ['BTCUSDT', 'ETHUSDT', 'ZECUSDT']

def hour_of(y, m=1): # ora unix aproximativa pentru 1 ianuarie y (calcul exact)
    import calendar
    return calendar.timegm((y, m, 1, 0, 0, 0)) // 3600

syms = sorted(s for s in os.listdir(B) if s.endswith('USDT') and os.path.isdir(f'{B}/{s}/candles_1m'))
D = {s: prep(s) for s in syms}
EXTRA = [s for s in syms if s not in SEEN | GOLD]

def price(s, h):
    h0, p = D[s][0], D[s][1]; i = h - h0
    return p[i] if 0 <= i < len(p) else None
def sig(s, h):
    h0, p, vol, rank, _ = D[s]; i = h - h0
    if i < 24 or i >= len(p) or rank[i] is None: return False
    return rank[i] >= 0.8 and p[i] < p[i - 24]
def vol168(s, h):
    h0, p = D[s][0], D[s][1]; i = h - h0
    if i < 169: return None
    r = [math.log(p[j] / p[j - 1]) for j in range(i - 167, i + 1)]
    m = sum(r) / 168
    return math.sqrt(sum((x - m) ** 2 for x in r) / 168 * AN)

def run(kind, h_start, h_end, sstar=None, static=None):
    coins = BASKET + (EXTRA if kind == 'S4' else [])
    cash = 1.0; q = {c: 0.0 for c in coins}; base = {c: 0.0 for c in BASKET}
    until = {c: -1 for c in coins}
    eqs = []; expo_sum = 0.0; fees = 0.0; first = True
    for h in range(h_start, h_end):
        px = {c: price(c, h) for c in coins}
        eq = cash + sum(q[c] * px[c] for c in coins if px[c])
        events = set()
        if kind == 'S0':
            if first:
                for c in BASKET:
                    amt = eq / 3; fees += amt * FEE; cash -= amt * (1 + FEE); q[c] = amt / px[c]
            first = False
        else:
            if kind in ('S1', 'S1m'):
                for c in BASKET: base[c] = static / 3
            elif first or h % 24 == 10:
                for c in BASKET:
                    v = vol168(c, h); base[c] = (1 / 3) * min(1.0, sstar / v) if v else 0.0
            first = False
            if kind in ('S3', 'S4'):
                for c in coins:
                    if until[c] == h: events.add(c)          # expira
                    if until[c] < h and px[c] and sig(c, h):
                        until[c] = h + 24; events.add(c)
            tgt = {}
            for c in BASKET:
                tgt[c] = max(base[c], 1 / 3) if until[c] > h else base[c]
            if kind == 'S4':
                ex = [c for c in EXTRA if until[c] > h and px[c]]
                each = min(0.05, 0.30 / len(ex)) if ex else 0
                for c in EXTRA: tgt[c] = each if c in ex else 0.0
            tot = sum(tgt.values())
            if tot > 1.0:
                for c in tgt: tgt[c] /= tot
            for c in coins:
                if not px[c]: continue
                diff = tgt[c] * eq - q[c] * px[c]
                if abs(diff) > BAND * eq or (c in events and abs(diff) > 1e-9) or (tgt[c] == 0 and q[c] > 0):
                    fees += abs(diff) * FEE; cash -= diff + abs(diff) * FEE; q[c] += diff / px[c]
        eq = cash + sum(q[c] * px[c] for c in coins if px[c])
        eqs.append(eq); expo_sum += (eq - cash) / eq
    # metrici
    peak = eqs[0]; dd = 0.0
    for e in eqs:
        peak = max(peak, e); dd = max(dd, 1 - e / peak)
    yrs = len(eqs) / AN; cagr = eqs[-1] ** (1 / yrs) - 1
    months = [eqs[i] for i in range(0, len(eqs), 730)] + [eqs[-1]]
    worst_m = min(months[i + 1] / months[i] - 1 for i in range(len(months) - 1))
    return dict(tot=eqs[-1] - 1, cagr=cagr, dd=dd, calmar=cagr / dd if dd else 0,
                wm=worst_m, expo=expo_sum / len(eqs), fees=fees)

def line(nm, r):
    return (f'  {nm:<26} total {r["tot"]*100:+8.0f}%  pe an {r["cagr"]*100:+6.1f}%  scadere max {r["dd"]*100:4.0f}%  '
            f'Calmar {r["calmar"]:5.2f}  cea mai proasta luna {r["wm"]*100:+5.0f}%  expunere {r["expo"]*100:3.0f}%  comisioane {r["fees"]*100:.1f}%')

if __name__ == '__main__':
    start = max(D[c][0] for c in BASKET) + 900
    end = min(D[c][0] + len(D[c][1]) for c in BASKET) - 1
    split = hour_of(2023)
    periods = {'ANTRENARE 2019-08 → 2022-12': (start, split), 'TEST 2023-01 → 2026-11': (split, end)}
    S = (0.4, 0.6, 0.8, 1.0)
    res = {}
    for pn, (a, b) in periods.items():
        print(f'\n=== {pn} ===')
        r0 = run('S0', a, b); print(line('S0 detinere simpla', r0))
        r1 = run('S1', a, b, static=0.5); print(line('S1 50/50 fix', r1))
        for s in S:
            r2 = run('S2', a, b, sstar=s); r3 = run('S3', a, b, sstar=s)
            res[(pn, s)] = (r2, r3)
            print(line(f'S2 tinta vol {int(s*100)}%', r2))
            print(line(f'S3 + exceptia {int(s*100)}%', r3))
            r1m = run('S1m', a, b, static=r3['expo']); print(line(f'S1m fix la {r3["expo"]*100:.0f}%', r1m))
            res[(pn, s)] = (r2, r3, r1m, r0)
        sys.stdout.flush()
    tr = list(periods)[0]; te = list(periods)[1]
    best = max(S, key=lambda s: res[(tr, s)][1]['calmar'])
    r2, r3, r1m, r0 = res[(te, best)]
    c1 = r3['calmar'] > r0['calmar'] and r3['calmar'] > r1m['calmar']
    c2 = r3['calmar'] > r2['calmar']
    print(f'\nσ* ales pe antrenare: {int(best*100)}%')
    print(f'Criteriul 1 (S3 > S0 si > S1m pe test): {c1}   [S3 {r3["calmar"]:.2f}, S0 {r0["calmar"]:.2f}, S1m {r1m["calmar"]:.2f}]')
    print(f'Criteriul 2 (S3 > S2 pe test): {c2}   [S3 {r3["calmar"]:.2f}, S2 {r2["calmar"]:.2f}]')
    a, b = periods[te]
    r4 = run('S4', a, b, sstar=best); print(line(f'\nS4 varianta (test, {int(best*100)}%)', r4))
    a, b = start, end
    print('\n=== TOT INTERVALUL (doar informativ) ===')
    print(line('S0 detinere simpla', run('S0', a, b)))
    print(line(f'S3 {int(best*100)}%', run('S3', a, b, sstar=best)))
