"""Enumerează triunghiurile pe toate simbolurile, pentru fiecare (proeminență,
distanța perechii), și calculează etalonul — intrări la întâmplare, aceleași
reguli de ieșire, aceleași date."""
import os, gzip, pickle, random, time, sys
import triunghiuri as T

AICI = os.path.join(os.path.dirname(os.path.abspath(__file__)), "rezultate")
SIMBOLURI = sorted(f[:-7] for f in os.listdir(T.H1) if f.endswith(".pkl.gz"))
PROEMINENTE = (2, 3, 5)
PERECHI = (1, 2)
LA_INTAMPLARE_PER_SIMBOL = 20000

def etalon(simbol, sem):
    t, o, h, l, c = T.incarca(simbol)
    atr = T.atr_wilder(h, l, c)
    m = len(c)
    prima = T.ATR_N + 1
    ultima = m - T.ORE_MAXIME - 2
    if ultima <= prima:
        return []
    rng = random.Random(sem)
    out = []
    n = min(LA_INTAMPLARE_PER_SIMBOL, ultima - prima)
    for _ in range(n):
        j = rng.randrange(prima, ultima)
        if atr[j] is None:
            continue
        tip = "long" if rng.random() < 0.5 else "short"
        r = T.simuleaza_iesirea(o, h, l, c, atr, j, tip)
        if r is None:
            continue
        out.append((t[j], tip, r[0], r[1]))
    return out

if __name__ == "__main__":
    rez = {}
    t0 = time.time()
    for s in SIMBOLURI:
        for p in PROEMINENTE:
            for k in PERECHI:
                a = time.time()
                rez[(s, p, k)] = T.enumereaza(s, p, k)
                print(f"{s:12s} p={p} k={k}  {len(rez[(s,p,k)]):6d} triunghiuri"
                      f"  {time.time()-a:5.1f}s", flush=True)
    with gzip.open(os.path.join(AICI, "triunghiuri.pkl.gz"), "wb", 1) as f:
        pickle.dump(rez, f, protocol=5)
    print(f"--- enumerare gata în {time.time()-t0:.0f}s ---", flush=True)

    et = {}
    for i, s in enumerate(SIMBOLURI):
        et[s] = etalon(s, 1000 + i)
        print(f"etalon {s:12s} {len(et[s]):6d}", flush=True)
    with gzip.open(os.path.join(AICI, "etalon.pkl.gz"), "wb", 1) as f:
        pickle.dump(et, f, protocol=5)
    print(f"--- tot gata în {time.time()-t0:.0f}s ---", flush=True)
