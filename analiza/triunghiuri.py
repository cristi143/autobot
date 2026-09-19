"""Enumerarea mecanică a triunghiurilor după regula din docs/regula-desenului.md,
plus simularea lor cu regulile de ieșire ale sistemului viu.

Doar biblioteca standard.

TRUC DE VITEZĂ, ca să nu rulăm grila de 324 de ori: enumerăm o singură dată
pentru fiecare pereche (proeminență, distanța perechii de vârfuri), cu filtrele
cele mai LARGI, și reținem pentru fiecare candidat cifrele după care s-ar filtra
mai târziu. Restul dimensiunilor grilei devin filtre ieftine peste lista gata
calculată.

E exact pentru că lățimea triunghiului scade monoton: dacă semnalul a venit la o
lățime w, atunci orice prag sub w l-ar fi lăsat să treacă, iar orice prag peste w
ar fi expirat triunghiul ÎNAINTE de semnal. Deci filtrarea ulterioară dă exact
același rezultat ca o resimulare.
"""
import os, gzip, pickle
from array import array

H1 = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "historical_data", "_1h")
COMISION = 0.00075
TP_ATR, SL_ATR = 1.5, 1.0
DIST_MIN, DIST_MAX = 0.005, 0.04      # plafoanele, în fracție din preț
ORE_MAXIME = 48
ATR_N = 14

# filtrele cele mai largi din grilă — enumerarea le folosește pe astea
APEX_MIN_LARG, APEX_MAX_LARG = 5, 100
LATIME_MIN_LARG = 0.5
TOL_ATINGERE = 0.10                    # cât de aproape de linie = „atingere", în ATR


def incarca(simbol):
    with gzip.open(os.path.join(H1, simbol + ".pkl.gz"), "rb") as f:
        d = pickle.load(f)
    return d["t"], d["o"], d["h"], d["l"], d["c"]


def atr_wilder(h, l, c, n=ATR_N):
    """ATR(n) după Wilder, aliniat la indicele barei. None până se poate calcula."""
    m = len(c)
    out = [None] * m
    if m < n + 1:
        return out
    tr = [0.0] * m
    for i in range(1, m):
        tr[i] = max(h[i] - l[i], abs(h[i] - c[i - 1]), abs(l[i] - c[i - 1]))
    a = sum(tr[1:n + 1]) / n
    out[n] = a
    for i in range(n + 1, m):
        a = (a * (n - 1) + tr[i]) / n
        out[i] = a
    return out


def pivoti(v, p, semn):
    """Indicii vârfurilor (semn=+1) sau văilor (semn=-1) cu p lumânări mai mici
    de fiecare parte. Strict la stânga, strict la dreapta."""
    out = []
    m = len(v)
    for i in range(p, m - p):
        x = v[i]
        ok = True
        for j in range(i - p, i):
            if semn * (v[j] - x) >= 0:
                ok = False; break
        if not ok:
            continue
        for j in range(i + 1, i + p + 1):
            if semn * (v[j] - x) >= 0:
                ok = False; break
        if ok:
            out.append(i)
    return out


def simuleaza_iesirea(o, h, l, c, atr, j, tip):
    """Regulile sistemului viu. Intrarea la deschiderea barei j+1.
    Întoarce (randament_net_%, motiv) sau None dacă nu sunt destule bare."""
    m = len(c)
    if j + 1 >= m or atr[j] is None:
        return None
    intrare = o[j + 1]
    if intrare <= 0:
        return None
    d_tp = atr[j] * TP_ATR
    d_sl = atr[j] * SL_ATR
    lo_cap, hi_cap = intrare * DIST_MIN, intrare * DIST_MAX
    d_tp = min(max(d_tp, lo_cap), hi_cap)
    d_sl = min(max(d_sl, lo_cap), hi_cap)
    if tip == "long":
        tp, sl = intrare + d_tp, intrare - d_sl
    else:
        tp, sl = intrare - d_tp, intrare + d_sl

    iesire = motiv = None
    ultim = min(j + ORE_MAXIME, m - 1)
    for k in range(j + 1, ultim + 1):
        if tip == "long":
            atins_tp, atins_sl = h[k] >= tp, l[k] <= sl
        else:
            atins_tp, atins_sl = l[k] <= tp, h[k] >= sl
        if atins_tp and atins_sl:
            iesire, motiv = sl, "sl"; break       # convenția pesimistă
        if atins_sl:
            iesire, motiv = sl, "sl"; break
        if atins_tp:
            iesire, motiv = tp, "tp"; break
    if iesire is None:
        if ultim <= j:
            return None
        iesire, motiv = c[ultim], "timp"

    raport = iesire / intrare if tip == "long" else intrare / iesire
    return (raport * (1 - COMISION) ** 2 - 1) * 100, motiv


def enumereaza(simbol, p, k):
    """Candidații pentru o proeminență p și o distanță k între vârfurile unite.
    Fiecare candidat = un dict cu ce trebuie ca să-l putem filtra apoi ieftin."""
    t, o, h, l, c = incarca(simbol)
    m = len(c)
    atr = atr_wilder(h, l, c)

    vh = pivoti(h, p, +1)
    vl = pivoti(l, p, -1)
    if len(vh) < 2 or len(vl) < 2:
        return []

    # liniile de sus: două vârfuri, al doilea nu mai sus decât primul
    sus = []
    for a in range(len(vh)):
        for b in range(a + 1, min(a + 1 + k, len(vh))):
            i1, i2 = vh[a], vh[b]
            if h[i2] > h[i1]:
                continue                       # trebuie descendentă sau orizontală
            panta = (h[i2] - h[i1]) / (i2 - i1)
            sus.append((i1, h[i1], i2, panta, i2 + p))   # ultimul = când e confirmată

    jos = []
    for a in range(len(vl)):
        for b in range(a + 1, min(a + 1 + k, len(vl))):
            i1, i2 = vl[a], vl[b]
            if l[i2] < l[i1]:
                continue
            panta = (l[i2] - l[i1]) / (i2 - i1)
            jos.append((i1, l[i1], i2, panta, i2 + p))

    # indexăm liniile de jos după momentul confirmării, ca să nu comparăm tot cu tot
    jos.sort(key=lambda x: x[4])
    conf_jos = [x[4] for x in jos]
    import bisect

    out = []
    for s in sus:
        si1, sp1, si2, s_panta, s_conf = s
        # perechea trebuie să fie contemporană: confirmările la cel mult 200 de bare
        lo_i = bisect.bisect_left(conf_jos, s_conf - 200)
        hi_i = bisect.bisect_right(conf_jos, s_conf + 200)
        for q in range(lo_i, hi_i):
            ji1, jp1, ji2, j_panta, j_conf = jos[q]
            if s_panta >= j_panta:
                continue                        # nu converg
            d = max(s_conf, j_conf)             # momentul „desenului"
            if d >= m - 2 or atr[d] is None:
                continue

            def up(x): return sp1 + s_panta * (x - si1)
            def dn(x): return jp1 + j_panta * (x - ji1)

            lat_d = up(d) - dn(d)
            if lat_d <= 0:
                continue
            if not (dn(d) < c[d] < up(d)):
                continue                        # prețul trebuie să fie înăuntru

            apex = d + lat_d / (j_panta - s_panta)
            bare_apex = apex - d
            if not (APEX_MIN_LARG <= bare_apex <= APEX_MAX_LARG):
                continue

            # prospețime: de câte bare n-a mai atins prețul niciuna dintre linii
            tol = TOL_ATINGERE * atr[d]
            prosp = None
            lim = max(si1, ji1)
            for x in range(d, lim - 1, -1):
                if h[x] >= up(x) - tol or l[x] <= dn(x) + tol:
                    prosp = d - x; break
            if prosp is None:
                continue

            # simulăm înainte: prima spargere, cu pragul de lățime cel mai larg
            semnal = None
            for x in range(d + 1, m - 1):
                lat = up(x) - dn(x)
                if atr[x] is None:
                    break
                if lat <= LATIME_MIN_LARG * atr[x]:
                    break                        # a rămas fără loc
                if c[x] > o[x] and c[x] > up(x):
                    semnal = (x, "long", lat / atr[x]); break
                if c[x] < o[x] and c[x] < dn(x):
                    semnal = (x, "short", lat / atr[x]); break
            if semnal is None:
                continue

            x, tip, w_atr = semnal
            r = simuleaza_iesirea(o, h, l, c, atr, x, tip)
            if r is None:
                continue
            out.append((t[d], bare_apex, prosp, w_atr, tip, r[0], r[1], t[x]))
    return out
