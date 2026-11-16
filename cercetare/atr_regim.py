# Testul din PREINREGISTRARE_atr_regim.md. Rulat cu pypy3:
#     pypy3 atr_regim.py <historical_data/ZECUSDT> <ultima zi întreagă, AAAA-LL-ZZ>
import sys, os, math, bisect, calendar, time

DIR = sys.argv[1].rstrip('/')
y, mo, d = map(int, sys.argv[2].split('-'))
M = 60000; H = 3600000
COST = 0.0025                              # dus-întors
MS = (1, 2)
LIMITA = 24 * 60                           # minute

def ms(y, m, d): return calendar.timegm((y, m, d, 0, 0, 0)) * 1000
T0 = ms(2019, 7, 1)
TEND = ms(y, mo, d) + 24 * H               # exclusiv
NM = (TEND - T0) // M

hi = [None] * NM; lo = [None] * NM; cl = [None] * NM; op = [None] * NM
for f in sorted(os.listdir(DIR + '/candles_1m')):
    with open(DIR + '/candles_1m/' + f) as fh:
        next(fh)
        for l in fh:
            p = l.split(',')
            k = (int(p[0]) - T0) // M
            if 0 <= k < NM:
                op[k] = float(p[1]); hi[k] = float(p[2]); lo[k] = float(p[3]); cl[k] = float(p[4])
first = next(k for k in range(NM) if cl[k] is not None)
gol = 0
for k in range(first + 1, NM):
    if cl[k] is None:
        op[k] = hi[k] = lo[k] = cl[k] = cl[k - 1]; gol += 1
print('%s: minute %d, completate %d, până la %s' % (os.path.basename(DIR), NM - first, gol, sys.argv[2]))

# ore
H0 = (first + 59) // 60                    # prima oră întreagă
NH = NM // 60
O = [None] * NH; HH = [None] * NH; LL = [None] * NH; C = [None] * NH
for i in range(H0, NH):
    a = i * 60
    O[i] = op[a]; HH[i] = max(hi[a:a + 60]); LL[i] = min(lo[a:a + 60]); C[i] = cl[a + 59]
TR = [None] * NH
for i in range(H0 + 1, NH):
    TR[i] = max(HH[i] - LL[i], abs(HH[i] - C[i - 1]), abs(LL[i] - C[i - 1]))
ATR = [None] * NH
for i in range(H0 + 14, NH): ATR[i] = sum(TR[i - 13:i + 1]) / 14
RANG = [None] * NH; srt = []; vol = [None] * NH
for i in range(H0 + 24, NH):
    w = [math.log(C[j] / C[j - 1]) for j in range(i - 23, i + 1)]; m = sum(w) / 24
    vol[i] = math.sqrt(sum((x - m) ** 2 for x in w) / 24)
    if len(srt) == 720: RANG[i] = bisect.bisect_left(srt, vol[i]) / 720.0
    bisect.insort(srt, vol[i])
    if len(srt) > 720: srt.pop(bisect.bisect_left(srt, vol[i - 720]))

def hidx(y, m, d): return (ms(y, m, d) - T0) // H
PER = [('antrenare 2019-08..2022-12', hidx(2019, 8, 1), hidx(2023, 1, 1)),
       ('test 2023-01..sfârșit', hidx(2023, 1, 1), NH - 26)]
def regim(r): return 'volatil' if r >= 0.8 else ('liniștit' if r < 0.5 else 'mijloc')

# evenimente: (ora, regim, a/E, {m: (rezultat, brut continuare)})   rezultat: 'C','I','A' (ambele), 'T' (timp)
EV = []; aruncate = 0; ore_jud = 0
for i in range(H0 + 745, NH - 26):
    if RANG[i] is None: continue
    ore_jud += 1
    c = C[i]; a = ATR[i]; up = c + a; dn = c - a
    k0 = (i + 1) * 60; ent = None
    for k in range(k0, k0 + 60):
        u = hi[k] >= up; w = lo[k] <= dn
        if u and w: ent = (k, 0); break
        if u: ent = (k, 1); break
        if w: ent = (k, -1); break
    if ent is None: continue
    k, dr = ent
    if dr == 0: aruncate += 1; continue
    E = up if dr > 0 else dn
    rez = {}
    for m in MS:
        lc = E + dr * m * a; li = E - dr * m * a
        out = None
        for j in range(k + 1, min(k + 1 + LIMITA, NM)):
            if dr > 0: hc = hi[j] >= lc; hr = lo[j] <= li
            else: hc = lo[j] <= lc; hr = hi[j] >= li
            if hc and hr: out = ('A', 0.0); break
            if hc: out = ('C', m * a / E); break
            if hr: out = ('I', -m * a / E); break
        if out is None:
            j = min(k + LIMITA, NM - 1)
            out = ('T', dr * (cl[j] - E) / E)
        rez[m] = out
    EV.append((i, regim(RANG[i]), a / E, rez, k))
print('ore judecate %d, evenimente %d (%.1f%%), aruncate (ambele niveluri în același minut) %d'
      % (ore_jud, len(EV), 100.0 * len(EV) / ore_jud, aruncate))

def tzi(perechi):
    """perechi (zi, valoare) -> medie pe eveniment, t pe medii zilnice"""
    if not perechi: return 0.0, 0.0
    zile = {}
    for z, v in perechi: zile.setdefault(z, []).append(v)
    md = [sum(v) / len(v) for v in zile.values()]
    n = len(md)
    med = sum(v for _, v in perechi) / len(perechi)
    if n < 2: return med, 0.0
    m = sum(md) / n
    se = math.sqrt(sum((x - m) ** 2 for x in md) / (n - 1) / n)
    return med, (m / se if se > 0 else 0.0)

REZ = {}
for nume, a, b in PER:
    print('\n=== %s ===' % nume)
    for rg in ('volatil', 'mijloc', 'liniștit'):
        ev = [e for e in EV if a <= e[0] < b and e[1] == rg]
        if not ev: continue
        atr = sum(e[2] for e in ev) / len(ev)
        print('  %s: %d evenimente, ATR mediu %.2f%% din preț' % (rg, len(ev), atr * 100))
        for m in MS:
            nC = sum(1 for e in ev if e[3][m][0] == 'C'); nI = sum(1 for e in ev if e[3][m][0] == 'I')
            nA = sum(1 for e in ev if e[3][m][0] == 'A'); nT = sum(1 for e in ev if e[3][m][0] == 'T')
            p = nC / float(nC + nI) if nC + nI else 0.0
            # t al frecvenței față de 50%, pe medii zilnice
            _, tp = tzi([(e[4] // 1440, (1.0 if e[3][m][0] == 'C' else 0.0) - 0.5) for e in ev if e[3][m][0] in 'CI'])
            brut = []; netC = []; netI = []
            for e in ev:
                z = e[4] // 1440; r, g = e[3][m]
                if r == 'A':                      # împotriva strategiei judecate
                    brut.append((z, 0.0)); netC.append((z, -m * e[2] - COST)); netI.append((z, -m * e[2] - COST))
                else:
                    brut.append((z, g)); netC.append((z, g - COST)); netI.append((z, -g - COST))
            bm, bt = tzi(brut); cm, ct = tzi(netC); im, it = tzi(netI)
            prag = (m * atr + COST) / (2 * m * atr)
            print('    m=%d  continuare prima %.1f%% (t %+5.2f; prag de rentabilitate %.1f%%)  timp %d, ambele %d'
                  % (m, p * 100, tp, prag * 100, nT, nA))
            print('         brut continuare %+.3f%% (t %+5.2f) · net continuare %+.3f%% (t %+5.2f) · net întoarcere %+.3f%% (t %+5.2f)'
                  % (bm * 100, bt, cm * 100, ct, im * 100, it))
            REZ[nume, rg, m] = (cm, ct, im, it, p, tp)

print('\n=== criteriul ===')
an, te = PER[0][0], PER[1][0]
for ip, rg, ix in (('C: continuare în regim volatil', 'volatil', 0), ('I: întoarcere în regim liniștit', 'liniștit', 2)):
    m = max(MS, key=lambda m: REZ[an, rg, m][ix])
    ra = REZ[an, rg, m]; rt = REZ[te, rg, m]
    ok = ra[ix] > 0 and ra[ix + 1] >= 2 and rt[ix] > 0 and rt[ix + 1] >= 2
    print('  %s, m=%d: antrenare %+.3f%% (t %+.2f), test %+.3f%% (t %+.2f) -> %s'
          % (ip, m, ra[ix] * 100, ra[ix + 1], rt[ix] * 100, rt[ix + 1], 'TRECE' if ok else 'PICĂ'))
    brut = all(abs(REZ[pp, rg, m][5]) >= 2 for pp in (an, te)) and (ra[4] - 0.5) * (rt[4] - 0.5) > 0
    print('     abatere brută de la 50%%: antrenare %.1f%% (t %+.2f), test %.1f%% (t %+.2f) -> %s'
          % (ra[4] * 100, ra[5], rt[4] * 100, rt[5], 'există' if brut else 'nu'))
