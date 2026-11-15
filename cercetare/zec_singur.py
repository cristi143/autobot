# Testele preînregistrate în PREINREGISTRARE_zec_singur.md. Rulat cu pypy3.
import json, math, random, bisect, sys, calendar, time

H = 3600000
K = 0.00125            # cost pe parte
raw = json.load(open(sys.argv[1]))
fng_raw = json.load(open(sys.argv[2]))['data']

def grid(rows):
    t0, t1 = rows[0][0], rows[-1][0] - H          # ultima lumânare e deschisă
    n = (t1 - t0) // H + 1
    c = [None] * n; v = [0.0] * n
    for r in rows:
        i = (r[0] - t0) // H
        if 0 <= i < n: c[i] = r[4]; v[i] = r[5]
    gaps = 0
    for i in range(n):
        if c[i] is None: c[i] = c[i - 1]; gaps += 1
    return t0, c, v, gaps

T0, C, V, g1 = grid(raw[sys.argv[3]])
T0b, CB, VB, g2 = grid(raw[sys.argv[4]])
assert T0 == T0b and len(C) == len(CB)
N = len(C)
print('ore', N, 'goluri completate', g1, g2)

def idx(y, m, d): return (calendar.timegm((y, m, d, 0, 0, 0)) * 1000 - T0) // H
def year(i): return time.gmtime((T0 + i * H) / 1000).tm_year

def roll_sig(c):
    n = len(c)
    lr = [0.0] + [math.log(c[i] / c[i - 1]) for i in range(1, n)]
    vol = [None] * n
    for i in range(24, n):
        w = lr[i - 23:i + 1]; m = sum(w) / 24
        vol[i] = math.sqrt(sum((x - m) ** 2 for x in w) / 24)
    rang = [None] * n; ratio = [None] * n; sig = [False] * n
    srt = []
    for i in range(24, n):
        if len(srt) == 720:
            rang[i] = bisect.bisect_left(srt, vol[i]) / 720.0
            med = (srt[359] + srt[360]) / 2
            ratio[i] = vol[i] / med if med > 0 else 1.0
            sig[i] = rang[i] >= 0.8 and c[i] < c[i - 24]
        bisect.insort(srt, vol[i])
        if len(srt) > 720: srt.pop(bisect.bisect_left(srt, vol[i - 720]))
    return vol, rang, ratio, sig

VOL, RANG, RATIO, SIGZ = roll_sig(C)
_, _, _, SIGB = roll_sig(CB)

# rangul volumului pe 24h
V24 = [None] * N; s = 0.0
for i in range(N):
    s += V[i]
    if i >= 24: s -= V[i - 24]
    if i >= 23: V24[i] = s
VRANG = [None] * N; srt = []
for i in range(23, N):
    if len(srt) == 720: VRANG[i] = bisect.bisect_left(srt, V24[i]) / 720.0
    bisect.insort(srt, V24[i])
    if len(srt) > 720: srt.pop(bisect.bisect_left(srt, V24[i - 720]))

# Fear & Greed pe ore
fday = {int(x['timestamp']) // 86400: int(x['value']) for x in fng_raw}
days_sorted = sorted(fday)
d_first, d_last = days_sorted[0], days_sorted[-1]
FSER = []; last = 50
for d in range(d_first, d_last + 1):
    last = fday.get(d, last); FSER.append(last)
def fng_hourly(shift=0):
    L = len(FSER); out = [50] * N
    for i in range(N):
        ts = (T0 + i * H) // 1000
        d = (ts - 3600) // 86400 - d_first        # ziua D e folosită de la D 01:00
        if d < 0: d = 0
        if d >= L: d = L - 1
        out[i] = FSER[(d + shift) % L]
    return out
FNG = fng_hourly()

WARM = 745
PER = {'antrenare 2019-08..2022-12': (max(WARM, idx(2019, 8, 1)), idx(2023, 1, 1)),
       'test 2023-01..2026-11': (idx(2023, 1, 1), N)}

def sim(a, b, tgt, ev, band):
    """tgt: pondere-țintă pe oră (listă sau număr); ev: eveniment (listă sau None);
       band: listă sau număr. Întoarce (final, cagr, dd, calmar, tranz, expunere medie)."""
    usdc = 1000.0; q = 0.0; peak = 0.0; dd = 0.0; nt = 0; ex = 0.0
    tl = isinstance(tgt, list); bl = isinstance(band, list)
    for i in range(a, b):
        p = C[i]; cap = usdc + q * p
        w = tgt[i] if tl else tgt
        bd = band[i] if bl else band
        dif = w * cap - q * p
        if abs(dif) > bd * cap or (ev is not None and ev[i] and abs(dif) > 1e-9):
            if dif > 0:
                dq = min(dif / p, max(0.0, usdc) / (p * (1 + K)))
                usdc -= dq * p * (1 + K); q += dq
            else:
                dq = -dif / p
                usdc += dq * p * (1 - K); q -= dq
            nt += 1
        v = usdc + q * p
        if v > peak: peak = v
        d = 1 - v / peak
        if d > dd: dd = d
        ex += q * p / v
    yrs = (b - a) / 8766.0
    cagr = (v / 1000.0) ** (1 / yrs) - 1
    return v, cagr, dd, (cagr / dd if dd > 0 else 0), nt, ex / (b - a)

def exc_targets(a, b, sig, w, up=0.25):
    tgt = [w] * N; ev = [False] * N; act = False; exp = None; n = 0
    for i in range(a, b):
        if act and exp <= i: act = False; ev[i] = True
        if not act and (exp is None or exp < i) and sig[i]:
            act = True; exp = i + 24; ev[i] = True; n += 1
        if act: tgt[i] = min(1.0, w + up)
    return tgt, ev, n

def placebo_sig(a, b, n, rng):
    L = b - a; free = L - n * 25
    cuts = sorted(rng.randint(0, free) for _ in range(n))
    sig = [False] * N
    for k, cpos in enumerate(cuts): sig[min(b - 1, a + cpos + k * 25)] = True
    return sig

def fmt(r): return '%8.0f  CAGR %+6.1f%%  cădere %4.1f%%  Calmar %5.2f  tranz %4d  exp %.2f' % (r[0], r[1] * 100, r[2] * 100, r[3], r[4], r[5])
def pct(x, lst): return 100.0 * sum(1 for y in lst if y < x) / len(lst)
def med(l): l = sorted(l); return l[len(l) // 2]

def tstat_daily(pairs):
    """pairs: (i, valoare) -> t pe medii zilnice"""
    by = {}
    for i, x in pairs: by.setdefault(i // 24, []).append(x)
    m = [sum(v) / len(v) for v in by.values()]
    if len(m) < 3: return 0.0, 0.0, len(m)
    mu = sum(m) / len(m); sd = math.sqrt(sum((x - mu) ** 2 for x in m) / (len(m) - 1))
    return mu, mu / (sd / math.sqrt(len(m))), len(m)

F24 = [C[i + 24] / C[i] - 1 if i + 24 < N else None for i in range(N)]
ymean = {}
for i in range(WARM, N - 24): ymean.setdefault(year(i), []).append(F24[i])
ymean = {y: sum(v) / len(v) for y, v in ymean.items()}
YR = [year(i) for i in range(N)]

NP = 200
for name, (a, b) in PER.items():
    print('\n' + '=' * 78 + '\n' + name, '(%d ore)' % (b - a))
    hold = sim(a, b, 1.0, None, 0.5)
    print('ZEC ținut simplu        ', fmt(hold))
    for w in (0.5, 0.3):
        print('\n--- w = %.2f' % w)
        base = sim(a, b, w, None, 0.05)
        print('BAZA bandă 5pp          ', fmt(base))
        # ---- T2
        for bd in (0.025, 0.10, 0.15):
            print('T2 bandă %4.1fpp         ' % (bd * 100), fmt(sim(a, b, w, None, bd)))
        vb = [0.05] * N
        for i in range(a, b):
            if RATIO[i] is not None: vb[i] = min(0.15, max(0.025, 0.05 * RATIO[i]))
        print('T2 bandă după volat.    ', fmt(sim(a, b, w, None, vb)))
        # ---- T4, T5b, T6b
        hi = [SIGZ[i] and VRANG[i] is not None and VRANG[i] >= 0.8 for i in range(N)]
        lo = [SIGZ[i] and not hi[i] for i in range(N)]
        for lab, sg in (('T4 excepție ZEC', SIGZ), ('T5 excepție la BTC', SIGB),
                        ('T6 doar volum mare', hi), ('T6 doar volum normal', lo)):
            tgt, ev, n = exc_targets(a, b, sg, w)
            r = sim(a, b, tgt, ev, 0.05)
            same = sim(a, b, r[5], None, 0.05)
            rng = random.Random(12345)
            pl = []
            for k in range(NP):
                t2, e2, n2 = exc_targets(a, b, placebo_sig(a, b, n, rng), w)
                pl.append(sim(a, b, t2, e2, 0.05))
            fin = [x[0] for x in pl]; cal = [x[3] for x in pl]
            print('%-24s' % lab, fmt(r), ' n=%d' % n)
            print('   aceeași expunere fixă:', fmt(same))
            print('   placebo: final median %.0f | strategia peste %.0f%% din placebo (final), %.0f%% (Calmar)' % (med(fin), pct(r[0], fin), pct(r[3], cal)))
        # ---- T7
        for lab, two in (('T7 F1 (frică ≤25)', False), ('T7 F2 (+lăcomie ≥75)', True)):
            def mk(f):
                tgt = [w] * N; ev = [False] * N; prev = None
                for i in range(a, b):
                    x = w
                    if f[i] <= 25: x = min(1.0, w + 0.25)
                    elif two and f[i] >= 75: x = max(0.0, w - 0.25)
                    tgt[i] = x
                    if prev is not None and x != prev: ev[i] = True
                    prev = x
                return tgt, ev
            tgt, ev = mk(FNG); r = sim(a, b, tgt, ev, 0.05)
            same = sim(a, b, r[5], None, 0.05)
            rng = random.Random(777); pl = []
            for k in range(NP):
                t2, e2 = mk(fng_hourly(rng.randint(90, len(FSER) - 90)))
                pl.append(sim(a, b, t2, e2, 0.05))
            fin = [x[0] for x in pl]; cal = [x[3] for x in pl]
            print('%-24s' % lab, fmt(r))
            print('   aceeași expunere fixă:', fmt(same))
            print('   placebo: final median %.0f | strategia peste %.0f%% din placebo (final), %.0f%% (Calmar)' % (med(fin), pct(r[0], fin), pct(r[3], cal)))

    # ---- studii de eveniment
    e = min(b, N - 24)
    print('\n--- studii de eveniment (randament ZEC pe 24h după, exces față de media anului)')
    def ev_study(lab, cond):
        pr = [(i, F24[i] - ymean[YR[i]]) for i in range(a, e) if cond(i)]
        raw_m = sum(F24[i] for i, _ in pr) / max(1, len(pr))
        mu, t, nd = tstat_daily(pr)
        print('  %-34s ore %5d zile %4d  brut %+5.2f%%  exces %+5.2f%%  t %+5.2f' % (lab, len(pr), nd, raw_m * 100, mu * 100, t))
        return pr
    ev_study('T5a semnal BTC', lambda i: SIGB[i])
    ev_study('T5a semnal BTC, fără semnal ZEC', lambda i: SIGB[i] and not SIGZ[i])
    ev_study('    semnal ZEC (referință)', lambda i: SIGZ[i])
    ph = ev_study('T6a panică ZEC, volum mare', lambda i: SIGZ[i] and VRANG[i] is not None and VRANG[i] >= 0.8)
    pn = ev_study('T6a panică ZEC, volum normal', lambda i: SIGZ[i] and VRANG[i] is not None and VRANG[i] < 0.8)
    def dm(pr):
        by = {}
        for i, x in pr: by.setdefault(i // 24, []).append(x)
        return [sum(v) / len(v) for v in by.values()]
    x, y = dm(ph), dm(pn)
    if len(x) > 2 and len(y) > 2:
        mx, my = sum(x) / len(x), sum(y) / len(y)
        vx = sum((u - mx) ** 2 for u in x) / (len(x) - 1); vy = sum((u - my) ** 2 for u in y) / (len(y) - 1)
        print('  T6a diferența mare − normal: %+.2f%%  t %+.2f' % ((mx - my) * 100, (mx - my) / math.sqrt(vx / len(x) + vy / len(y))))
    print('--- T7a: randament ZEC pe 7 zile după, pe grupe de Fear & Greed (o observație pe zi)')
    e7 = min(b, N - 168); allr = []; grp = {}
    for i in range(a, e7):
        if ((T0 + i * H) // 1000) % 86400 != 3600: continue
        r7 = C[i + 168] / C[i] - 1; allr.append(r7); f = FNG[i]
        g = '≤25' if f <= 25 else '26–45' if f <= 45 else '46–54' if f <= 54 else '55–74' if f < 75 else '≥75'
        grp.setdefault(g, []).append(r7)
    am = sum(allr) / len(allr)
    print('  toate zilele: %d, medie %+.2f%%' % (len(allr), am * 100))
    for g in ('≤25', '26–45', '46–54', '55–74', '≥75'):
        v = grp.get(g, [])
        if not v: print('  %-6s —' % g); continue
        mu = sum(v) / len(v); sd = math.sqrt(sum((x - mu) ** 2 for x in v) / max(1, len(v) - 1))
        print('  %-6s zile %4d  medie %+6.2f%%  exces %+6.2f%%  t≈ %+5.2f (corectat pt. suprapunere)' % (g, len(v), mu * 100, (mu - am) * 100, (mu - am) / (sd / math.sqrt(max(1.0, len(v) / 7.0)))))

# ---- adaos descriptiv (nu e criteriu): pe ani, fiecare an pornit de la 1000
print('\n' + '=' * 78 + '\nPe ani (fiecare an de la 1000): randament / cădere')
hi = [SIGZ[i] and VRANG[i] is not None and VRANG[i] >= 0.8 for i in range(N)]
for w in (0.5, 0.3):
    print('w = %.2f   an | ZEC simplu | baza | T4 excepție | T6 volum mare | n exc | brut mediu/exc' % w)
    for y in range(2019, 2027):
        a = max(WARM, idx(y, 1, 1)); b = min(N, idx(y + 1, 1, 1))
        if b - a < 1000: continue
        h = sim(a, b, 1.0, None, 0.5); bs = sim(a, b, w, None, 0.05)
        t, e, n = exc_targets(a, b, SIGZ, w); r4 = sim(a, b, t, e, 0.05)
        t6, e6, n6 = exc_targets(a, b, hi, w); r6 = sim(a, b, t6, e6, 0.05)
        st = [i for i in range(a, min(b, N - 24)) if e[i] and t[i] > w and (i == a or t[i - 1] == w)]
        gm = sum(F24[i] for i in st) / max(1, len(st))
        f = lambda r: '%+7.1f%% /%3.0f%%' % ((r[0] / 10 - 100), r[2] * 100)
        print('        %d | %s | %s | %s | %s | %3d | %+.2f%%' % (y, f(h), f(bs), f(r4), f(r6), n, gm * 100))
# T6a pe total
a, b = WARM, N - 24
def dm(cond):
    by = {}
    for i in range(a, b):
        if cond(i): by.setdefault(i // 24, []).append(F24[i] - ymean[YR[i]])
    return [sum(v) / len(v) for v in by.values()]
x = dm(lambda i: SIGZ[i] and VRANG[i] is not None and VRANG[i] >= 0.8); y = dm(lambda i: SIGZ[i] and VRANG[i] is not None and VRANG[i] < 0.8)
mx, my = sum(x) / len(x), sum(y) / len(y)
vx = sum((u - mx) ** 2 for u in x) / (len(x) - 1); vy = sum((u - my) ** 2 for u in y) / (len(y) - 1)
print('T6a total 2019–2026: mare − normal %+.2f%%  t %+.2f' % ((mx - my) * 100, (mx - my) / math.sqrt(vx / len(x) + vy / len(y))))
