"""Căutarea pe grilă (doar pe explorare) și verdictul (doar pe verificare)."""
import os, gzip, pickle, random, statistics as st

AICI = os.path.join(os.path.dirname(os.path.abspath(__file__)), "rezultate")
TAIERE = 1704067200000          # 1 ianuarie 2024, UTC
ARS = {"ZECUSDC"}               # vezi protocolul: l-am privit din greșeală

APEX = {"[10,50]": (10, 50), "[5,100]": (5, 100), "[10,30]": (10, 30)}
PROSP = (8, 15, 25)
LATIME = (0.5, 1.0, 1.5)
REFRACTAR = (0, 24)
ORA = 3600000

def incarca():
    with gzip.open(os.path.join(AICI, "triunghiuri.pkl.gz"), "rb") as f:
        tri = pickle.load(f)
    with gzip.open(os.path.join(AICI, "etalon.pkl.gz"), "rb") as f:
        et = pickle.load(f)
    return tri, et

def filtreaza(lista, apex, prosp_max, lat_min, refractar):
    """Filtrele ieftine peste candidații deja enumerați. Refractarul cere ordine."""
    lo, hi = apex
    out = []
    ultim = None
    for r in sorted(lista, key=lambda x: x[0]):
        t_d, bare_apex, prosp, w_atr, tip, net, motiv, t_sig = r
        if not (lo <= bare_apex <= hi): continue
        if prosp > prosp_max: continue
        if w_atr < lat_min: continue
        if refractar and ultim is not None and t_d - ultim < refractar * ORA: continue
        ultim = t_d
        out.append(r)
    return out

def imparte(lista):
    return ([r for r in lista if r[7] < TAIERE],
            [r for r in lista if r[7] >= TAIERE])

def bootstrap(per_simbol_a, per_simbol_b=None, n=4000, sem=7):
    """Bootstrap grupat pe simbol: se reeșantionează SIMBOLURI întregi, pentru că
    tranzacțiile aceluiași simbol nu sunt independente. Dacă b e dat, întoarce
    distribuția diferenței de medii (a - b).

    Media grupată se reface din sume și numărători precalculate — altfel fiecare
    iterație ar recalcula media peste sute de mii de valori."""
    rng = random.Random(sem)
    simb = [s for s in per_simbol_a if per_simbol_a[s]]
    if per_simbol_b is not None:
        simb = [s for s in simb if per_simbol_b.get(s)]
    if len(simb) < 3: return None
    SA = {s: (sum(per_simbol_a[s]), len(per_simbol_a[s])) for s in simb}
    SB = {s: (sum(per_simbol_b[s]), len(per_simbol_b[s])) for s in simb} if per_simbol_b else None
    out = []
    N = len(simb)
    for _ in range(n):
        ales = [simb[rng.randrange(N)] for _ in range(N)]
        sa = ca = 0.0
        for s in ales:
            x, y = SA[s]; sa += x; ca += y
        if not ca: continue
        m = sa / ca
        if SB is not None:
            sb = cb = 0.0
            for s in ales:
                x, y = SB[s]; sb += x; cb += y
            if not cb: continue
            m -= sb / cb
        out.append(m)
    out.sort()
    return out[int(0.025*len(out))], st.mean(out), out[int(0.975*len(out))]

def medie(d):
    v = [x for s in d for x in d[s]]
    return st.mean(v) if v else None, len(v)
