# Testul din PREINREGISTRARE_executie_limita.md. Rulat cu pypy3:
#     pypy3 executie_limita.py <historical_data/ZECUSDC>
# Citește candles_1m/ (închideri orare, semnal) și trades/ (ticks).
import sys, os, math, bisect, calendar, time

DIR = sys.argv[1].rstrip('/')
H = 3600000
FEE = 0.00075
BAZA, EXC, BANDA, DURATA = 0.30, 0.55, 0.05, 24
WS = (5, 15, 60)
INTARZIERE = 120000                       # motorul rulează la minutul 2

def ms(y, m, d, h=0): return calendar.timegm((y, m, d, h, 0, 0)) * 1000
def zi(t): return time.strftime('%d.%m.%Y %H:%M', time.gmtime(t / 1000))

START = ms(2026, 1, 1)                    # prima decizie: închiderea de la 00:00
MIJLOC = ms(2026, 6, 1)
SFARSIT = ms(2026, 11, 9, 22)             # ultima decizie; fereastra de 60 min încape în 09.11

# ---------------------------------------------------------------- închideri orare
inch = {}                                 # ora (deschidere, ms) -> close-ul minutului :59
for f in sorted(os.listdir(DIR + '/candles_1m')):
    with open(DIR + '/candles_1m/' + f) as fh:
        next(fh)
        for l in fh:
            p = l.split(',')
            t = int(p[0])
            if t % H == 59 * 60000: inch[t - 59 * 60000] = float(p[4])
ore = sorted(inch)
T0 = ore[0]
N = (ore[-1] - T0) // H + 1
C = [None] * N
for t in ore: C[(t - T0) // H] = inch[t]
goluri = 0
for i in range(N):
    if C[i] is None: C[i] = C[i - 1]; goluri += 1
print('închideri orare: %d, din %s, goluri completate %d' % (N, zi(T0), goluri))

lr = [0.0] + [math.log(C[i] / C[i - 1]) for i in range(1, N)]
SIG = [False] * N; srt = []; vol = [None] * N
for i in range(24, N):
    w = lr[i - 23:i + 1]; m = sum(w) / 24
    vol[i] = math.sqrt(sum((x - m) ** 2 for x in w) / 24)
    if len(srt) == 720:
        SIG[i] = bisect.bisect_left(srt, vol[i]) / 720.0 >= 0.8 and C[i] < C[i - 24]
    bisect.insort(srt, vol[i])
    if len(srt) > 720: srt.pop(bisect.bisect_left(srt, vol[i - 720]))

# deciziile: ora i se închide la T0 + (i+1)*H
I0 = (START - T0) // H - 1
I1 = (SFARSIT - T0) // H - 1
assert I0 >= 745, 'încălzire insuficientă'
def tdec(i): return T0 + (i + 1) * H
print('decizii: %s -> %s (%d ore), semnale %d' % (zi(tdec(I0)), zi(tdec(I1)), I1 - I0 + 1, sum(SIG[I0:I1 + 1])))

# ---------------------------------------------------------------- ticks
# pe oră: bid/ask reconstruit la t0 = decizie + 2 min; pentru fiecare W: minimul
# și maximul tranzacțiilor din (t0, t0+W] și bid/ask la t0+W
B0 = {}; A0 = {}; MN = {}; MX = {}; BW = {}; AW = {}
evenimente = []                           # (timp, tip, i, W)   tip 0 = start, 1 = capăt de fereastră
for i in range(I0, I1 + 1):
    t0 = tdec(i) + INTARZIERE
    evenimente.append((t0, 0, i, 0))
    for W in WS: evenimente.append((t0 + W * 60000, 1, i, W))
evenimente.sort()
ne = len(evenimente); e = 0
bid = ask = ultim = None
active = []                               # [i, W, min, max]
nticks = 0; inversari = 0

def instantaneu():
    global inversari
    if bid is None or ask is None or ask <= bid:
        inversari += 1
        return ultim, ultim
    return bid, ask

for f in sorted(os.listdir(DIR + '/trades')):
    if f[:10] > '2026-11-09': continue
    with open(DIR + '/trades/' + f) as fh:
        next(fh)
        for l in fh:
            c1 = l.index(','); c2 = l.index(',', c1 + 1)
            ts = int(l[:c1]); p = float(l[c1 + 1:c2])
            while e < ne and evenimente[e][0] < ts:
                _, tip, i, W = evenimente[e]; e += 1
                b, a = instantaneu()
                if tip == 0:
                    B0[i] = b; A0[i] = a
                    for W2 in WS: active.append([i, W2, None, None])
                else:
                    BW[i, W] = b; AW[i, W] = a
                    for k, x in enumerate(active):
                        if x[0] == i and x[1] == W:
                            MN[i, W] = x[2]; MX[i, W] = x[3]; del active[k]; break
            if ultim is not None:
                if p > ultim: ask = p
                elif p < ultim: bid = p
            ultim = p
            for x in active:
                if x[2] is None or p < x[2]: x[2] = p
                if x[3] is None or p > x[3]: x[3] = p
            nticks += 1
print('ticks citite: %d; instantanee fără bid<ask (înlocuite cu ultimul preț): %d' % (nticks, inversari))
assert e == ne, 'ticks-urile se termină înaintea ultimei ferestre'

# ---------------------------------------------------------------- execuții
VARIANTE = [(P, W) for P in ('P1', 'P2') for W in WS]

def piata(i, parte):
    return A0[i] if parte > 0 else B0[i]

def limita(i, parte, P, W):
    """întoarce (preț, executat ca limită)"""
    c = C[i]
    if parte > 0:
        pl = B0[i] if P == 'P1' else min(c, B0[i])
        mn = MN[i, W]
        if mn is not None and mn < pl: return pl, True
        return AW[i, W], False
    pl = A0[i] if P == 'P1' else max(c, A0[i])
    mx = MX[i, W]
    if mx is not None and mx > pl: return pl, True
    return BW[i, W], False

def cost(i, parte, px, fee):
    c = C[i]
    return (px * (1 + fee) - c) / c if parte > 0 else (c - px * (1 - fee)) / c

def ruleaza(exe):
    """banca F din START; exe(i, parte) -> (preț, comision). Întoarce (tranzacții, valori pe oră)."""
    usdc = 1000.0; q = 0.0; act = False; exp = None; tr = []; val = {}
    for i in range(I0, I1 + 1):
        c = C[i]; cap = usdc + q * c; ev = False; fel = 'reechilibrare'
        if act and exp <= i: act = False; ev = True; fel = 'expirare'
        if not act and (exp is None or exp < i) and SIG[i]:
            act = True; exp = i + DURATA; ev = True; fel = 'pornire'
        tinta = EXC if act else BAZA
        dif = tinta * cap - q * c
        if abs(dif) > BANDA * cap or (ev and abs(dif) > 1e-9):
            dq = dif / c; parte = 1 if dq > 0 else -1
            px, fee = exe(i, parte)
            if dq > 0:
                dq = min(dq, max(0.0, usdc) / (px * (1 + fee)))
                usdc -= dq * px * (1 + fee)
            else:
                usdc += -dq * px * (1 - fee)
            q += dq
            tr.append((i, parte, fel, abs(dq) * c))
        val[i] = usdc + q * c
    return tr, val

def stat(x):
    n = len(x)
    if n < 2: return (sum(x) / n if n else 0.0), 0.0, 0.0, n
    m = sum(x) / n
    se = math.sqrt(sum((v - m) ** 2 for v in x) / (n - 1) / n)
    return m, se, (m / se if se > 0 else 0.0), n

def pc(x): return '%+.4f%%' % (x * 100)

TR, VAL_P = ruleaza(lambda i, s: (piata(i, s), FEE))
IMIJ = (MIJLOC - T0) // H - 1             # prima decizie din perioada de judecată
PER = {'alegere 01.01-31.05': (I0, IMIJ - 1), 'judecată 01.06-09.11': (IMIJ, I1), 'tot': (I0, I1)}
def din(tr, per): a, b = PER[per]; return [t for t in tr if a <= t[0] <= b]

print('\n=== tranzacțiile băncii F (execuție la piață) ===')
for per in PER:
    t = din(TR, per)
    print('  %-22s %3d  (pornire %d, expirare %d, reechilibrare %d)' % (per, len(t),
          sum(1 for x in t if x[2] == 'pornire'), sum(1 for x in t if x[2] == 'expirare'),
          sum(1 for x in t if x[2] == 'reechilibrare')))

print('\n=== verificarea modelului: costul la piață, fără comision ===')
cp = [cost(i, s, piata(i, s), 0.0) for i, s, _, _ in TR]
m, se, t, n = stat(cp)
print('  pe tranzacțiile lui F: %s ± %s (n=%d)   live: +0.0480%%' % (pc(m), pc(se), n))
sp = [(A0[i] - B0[i]) / (2 * C[i]) for i in range(I0, I1 + 1)]
sps = sorted(sp)
print('  jumătatea spreadului reconstruit, toate orele: medie %s, mediană %s' % (pc(sum(sp) / len(sp)), pc(sps[len(sps) // 2])))
print('  model %s' % ('NESIGUR (în afara [0,016%; 0,144%])' if not (0.00016 <= m <= 0.00144) else 'în limite'))

def economii(tr, P, W, fee_lim=FEE):
    out = []; ex = 0
    for i, s, _, _ in tr:
        px, ok = limita(i, s, P, W)
        ex += ok
        out.append(cost(i, s, piata(i, s), FEE) - cost(i, s, px, fee_lim if ok else FEE))
    return out, ex

print('\n=== economia pe parte, pe tranzacțiile lui F (piață minus limită) ===')
for per in PER:
    t = din(TR, per)
    print('  ' + per)
    for P, W in VARIANTE:
        ec, ex = economii(t, P, W)
        m, se, ts, n = stat(ec)
        ec0, _ = economii(t, P, W, 0.0)
        print('    %s W=%2d  %s ± %s  t %+5.2f  executate ca limită %3.0f%%   [cu comision 0%%: %s]'
              % (P, W, pc(m), pc(se), ts, 100.0 * ex / max(1, n), pc(stat(ec0)[0])))

t_al = din(TR, 'alegere 01.01-31.05')
ALEASA = max(VARIANTE, key=lambda v: stat(economii(t_al, v[0], v[1])[0])[0])
print('\nvarianta reținută pe perioada de alegere: %s, W=%d min' % ALEASA)
P, W = ALEASA

print('\n=== executate ca limită, pe feluri (varianta reținută, tot anul) ===')
for fel in ('pornire', 'expirare', 'reechilibrare'):
    t = [x for x in TR if x[2] == fel]
    ec, ex = economii(t, P, W)
    m, se, ts, n = stat(ec)
    print('  %-14s n=%3d  executate %3.0f%%  economie %s ± %s' % (fel, n, 100.0 * ex / max(1, n), pc(m), pc(se)))

print('\n=== capitalul băncii F ===')
def creste(val, per): a, b = PER[per]; return val[b] / (val[a - 1] if a > I0 else 1000.0)
rez = {'piață': VAL_P}
for v in VARIANTE:
    rez['%s W=%d' % v] = ruleaza(lambda i, s, v=v: (limita(i, s, v[0], v[1])[0], FEE))[1]
rez['la închidere, 0,125% (simularea)'] = ruleaza(lambda i, s: (C[i], 0.00125))[1]
for nume, val in rez.items():
    print('  %-34s alegere ×%.4f   judecată ×%.4f   tot ×%.4f' % (nume, creste(val, 'alegere 01.01-31.05'),
          creste(val, 'judecată 01.06-09.11'), creste(val, 'tot')))

print('\n=== secundar: toate orele, o cumpărare și o vânzare pe oră (varianta reținută) ===')
for per in PER:
    a, b = PER[per]
    for nume, cond in (('cu semnal', True), ('fără semnal', False)):
        tr = [(i, s, '', 0) for i in range(a, b + 1) if SIG[i] == cond for s in (1, -1)]
        ec, ex = economii(tr, P, W)
        m, se, ts, n = stat(ec)
        print('  %-22s %-12s n=%5d  %s ± %s  t %+6.2f  executate %3.0f%%' % (per, nume, n, pc(m), pc(se), ts, 100.0 * ex / max(1, n)))

print('\n=== criteriul ===')
tj = din(TR, 'judecată 01.06-09.11')
mj, sej, tsj, nj = stat(economii(tj, P, W)[0])
ma = stat(economii(t_al, P, W)[0])[0]
cl = creste(rez['%s W=%d' % ALEASA], 'judecată 01.06-09.11'); cpi = creste(VAL_P, 'judecată 01.06-09.11')
print('  tranzacții pe judecată: %d %s' % (nj, '(sub 40: NECONCLUDENT)' if nj < 40 else ''))
print('  1. economie >= 0,03%% și t >= 2:   %s, t %+.2f  -> %s' % (pc(mj), tsj, 'da' if mj >= 0.0003 and tsj >= 2 else 'NU'))
print('  2. capital F cu limită > piață:    ×%.4f față de ×%.4f -> %s' % (cl, cpi, 'da' if cl > cpi else 'NU'))
print('  3. același semn pe alegere:        %s -> %s' % (pc(ma), 'da' if ma * mj > 0 else 'NU'))
