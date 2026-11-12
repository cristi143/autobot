# Test preinregistrat: vol mare dupa cadere. Vezi PREINREGISTRARE_vol_cadere.md
# pypy3 volcad.py
import os, csv, math, bisect, json, sys
B = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../historical_data'))
HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(HERE, 'hourly'); os.makedirs(CACHE, exist_ok=True)
FEE = 0.00075
SEEN = {'ZECUSDT', 'BTCUSDT', 'ETHUSDT'}
GOLD = {'PAXGUSDT', 'XAUTUSDT'}

def hourly(sym):
    cf = os.path.join(CACHE, sym + '.json')
    if os.path.exists(cf): return json.load(open(cf))
    d = f'{B}/{sym}/candles_1m'; cl = {}
    for fn in sorted(os.listdir(d)):
        r = csv.reader(open(f'{d}/{fn}')); h = next(r)
        it = h.index('open_time'); ic = h.index('close')
        for x in r:
            t = int(x[it]); t = t // 1000 if t > 1e14 else t
            cl[t // 3600000] = float(x[ic])
    ks = sorted(cl); out = []; last = cl[ks[0]]
    for k in range(ks[0], ks[-1] + 1):
        last = cl.get(k, last); out.append(last)
    res = [ks[0], out]; json.dump(res, open(cf, 'w')); return res

def year(hk): return 1970 + int((hk + 0.5) / 8765.82)

def prep(sym):
    h0, p = hourly(sym); n = len(p)
    r = [0.0] + [math.log(p[i] / p[i - 1]) for i in range(1, n)]
    vol = [None] * n
    s = s2 = 0.0
    for i in range(1, n):
        s += r[i]; s2 += r[i] * r[i]
        if i > 24: s -= r[i - 24]; s2 -= r[i - 24] ** 2
        if i >= 24:
            m = s / 24; vol[i] = math.sqrt(max(0.0, s2 / 24 - m * m))
    # rang fata de cele 720 ore dinainte, fereastra sortata glisanta
    rank = [None] * n; win = []
    for i in range(24, n):
        if len(win) >= 720 and vol[i] is not None:
            rank[i] = bisect.bisect_left(win, vol[i]) / len(win)
        if vol[i] is not None:
            bisect.insort(win, vol[i])
            if len(win) > 720:
                del win[bisect.bisect_left(win, vol[i - 720])]
    # media randamentului 24h pe an (pentru exces)
    ym = {}
    for i in range(0, n - 24):
        y = year(h0 + i); a = ym.setdefault(y, [0.0, 0]); a[0] += p[i + 24] / p[i] - 1; a[1] += 1
    ym = {y: a[0] / a[1] for y, a in ym.items()}
    return h0, p, vol, rank, ym

def trades(D, H, thr, fall, kind='cadere'):
    h0, p, vol, rank, ym = D; n = len(p); out = []; i = 24; free = 0
    while i < n - H:
        if i >= free and rank[i] is not None:
            r24 = math.log(p[i] / p[i - 24])
            if kind == 'cadere':
                ok = rank[i] >= thr and r24 < (-(vol[i] * math.sqrt(24)) if fall else 0)
            elif kind == 'crestere':
                ok = rank[i] >= thr and r24 > 0
            else:  # vol mica
                ok = rank[i] < 0.2
            if ok:
                g = p[i + H] / p[i]
                net = g * (1 - FEE) / (1 + FEE) - 1
                y = year(h0 + i)
                base = ym[y] if H == 24 else (ym[y] + 1) ** (H / 24) - 1
                out.append(((h0 + i) // 24, y, net, net - base))
                free = i + H
        i += 1
    return out

def daily_t(tr, idx=3):
    d = {}
    for t in tr: d.setdefault(t[0], []).append(t[idx])
    v = [sum(x) / len(x) for x in d.values()]; n = len(v)
    if n < 3: return 0, 0, n
    m = sum(v) / n; sd = math.sqrt(sum((x - m) ** 2 for x in v) / (n - 1))
    return m, m / (sd / math.sqrt(n)), n

def summary(name, tr):
    if not tr: print(f'  {name}: 0 tranzactii'); return
    m, t, nd = daily_t(tr); net = sum(x[2] for x in tr) / len(tr)
    yrs = {}
    for x in tr: yrs.setdefault(x[1], []).append(x[3])
    ypos = sum(1 for v in yrs.values() if sum(v) / len(v) > 0)
    print(f'  {name:<34} n={len(tr):>5} zile={nd:>4}  net {net*100:+6.2f}%  exces {m*100:+6.2f}% t={t:+5.2f}  ani cu exces>0: {ypos}/{len(yrs)}')
    return m, t, net, ypos, len(yrs), yrs

if __name__ == '__main__':
    syms = sorted(s for s in os.listdir(B) if s.endswith('USDT') and os.path.isdir(f'{B}/{s}/candles_1m'))
    data = {}
    for s in syms:
        data[s] = prep(s); print('incarcat', s, file=sys.stderr)
    groups = {'NEVAZUTE': [s for s in syms if s not in SEEN | GOLD],
              'ZEC/BTC/ETH (sursa ipotezei)': [s for s in syms if s in SEEN],
              'AUR (control)': [s for s in syms if s in GOLD]}
    print('perechi nevazute:', ' '.join(groups['NEVAZUTE']))
    print('\n=== TEST PRINCIPAL: H=24, rang>=0,8, orice cadere ===')
    for g, ss in groups.items():
        print(g)
        tr = [t for s in ss for t in trades(data[s], 24, 0.8, False)]
        res = summary('vol mare dupa CADERE', tr)
        if g == 'NEVAZUTE':
            main = res
            for y, v in sorted(res[5].items()):
                print(f'      {y}: exces {sum(v)/len(v)*100:+6.2f}%  (n={len(v)})')
        summary('vol mare dupa CRESTERE', [t for s in ss for t in trades(data[s], 24, 0.8, False, 'crestere')])
        summary('vol mica', [t for s in ss for t in trades(data[s], 24, 0.8, False, 'mica')])
    m, t, net, ypos, ny, _ = main
    ok = (m > 0 and t > 2, net > 0, ypos >= 5)
    print('\nCRITERII pe nevazute: exces>0 & t>2: %s | net>0: %s | ani>=5: %s  =>  %s' %
          (ok[0], ok[1], ok[2], 'CONFIRMAT' if all(ok) else 'RESPINS'))
    print('\n=== GRILA (nevazute): exces pe 2019-2022 vs 2023-2026 ===')
    cells = []
    for H in (6, 24, 72):
        for thr in (0.8, 0.9):
            for fall in (False, True):
                tr = [t for s in groups['NEVAZUTE'] for t in trades(data[s], H, thr, fall)]
                a = daily_t([x for x in tr if x[1] <= 2022]); b = daily_t([x for x in tr if x[1] >= 2023])
                net = sum(x[2] for x in tr) / len(tr)
                cells.append((a[0], b[0]))
                print(f'  H={H:>2} rang>={thr} cadere {"<-1 abatere" if fall else "orice     "}  n={len(tr):>5}  net {net*100:+6.2f}%  '
                      f'2019-22 exces {a[0]*100:+6.2f}% (t={a[1]:+.1f})   2023-26 exces {b[0]*100:+6.2f}% (t={b[1]:+.1f})')
    def rk(v): s = sorted(range(len(v)), key=lambda i: v[i]); r = [0]*len(v)
    def ranks(v):
        o = sorted(range(len(v)), key=lambda i: v[i]); r = [0] * len(v)
        for k, i in enumerate(o): r[i] = k
        return r
    ra = ranks([c[0] for c in cells]); rb = ranks([c[1] for c in cells]); n = len(cells)
    rho = 1 - 6 * sum((x - y) ** 2 for x, y in zip(ra, rb)) / (n * (n * n - 1))
    best = max(range(n), key=lambda i: cells[i][0])
    print(f'  corelatia de rang 2019-22 -> 2023-26: {rho:+.2f}; celula aleasa pe 2019-22 da pe 2023-26: {cells[best][1]*100:+.2f}%')
