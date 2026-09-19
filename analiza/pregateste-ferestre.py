#!/usr/bin/env python3
"""Pregătește ferestrele pentru desenul orb.

Scoate DOUĂ fișiere:
  desen-orb.html  — unealta, cu datele înăuntru. Se deschide cu dublu clic.
  cheie.json      — ce simbol și ce moment era fiecare fereastră. NU se deschide
                    până după ce s-au desenat toate.

ANONIMIZARE, ca desenul să fie cu adevărat orb:
  · fără numele simbolului, fără date calendaristice;
  · prețurile rescalate, prima închidere vizibilă = 100;
  · lățimea ferestrei variază (100 / 200 / 400 de lumânări), ca să putem separa
    „unghi vizual" de „timp până la eveniment" — el s-a plâns de un triunghi cu
    vârful la 10 lumânări deși vizual părea departe, și nu știm care dintre cele
    două îl deranja;
  · ferestrele se iau DINAINTE de 2024, din două motive: nu are cum să-și
    amintească o piață de acum trei ani, iar perioada de verificare a probei
    rămâne neatinsă pentru mai târziu.
"""
import os, sys, gzip, pickle, random, json, datetime as dt

AICI = os.path.dirname(os.path.abspath(__file__))
H1   = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "historical_data", "_1h")
TAIERE = 1704067200000          # 1 ianuarie 2024
CATE = 60
LATIMI = (100, 200, 400)
DUPA_MINIM = 400                # lumânări după fereastră, ca să putem simula

def main(sem=20260920):
    rng = random.Random(sem)
    simboluri = sorted(f[:-7] for f in os.listdir(H1) if f.endswith(".pkl.gz"))
    serii = {}
    for s in simboluri:
        with gzip.open(os.path.join(H1, s + ".pkl.gz"), "rb") as f:
            d = pickle.load(f)
        if d["t"][0] < TAIERE - 400*3600000:
            serii[s] = d

    ferestre, cheie = [], []
    incercari = 0
    while len(ferestre) < CATE and incercari < CATE * 200:
        incercari += 1
        s = rng.choice(list(serii))
        d = serii[s]
        n = len(d["t"])
        lat = rng.choice(LATIMI)
        # capătul ferestrei: înainte de tăiere, cu loc de simulat după
        sus = n - DUPA_MINIM
        for i in range(n - 1, -1, -1):
            if d["t"][i] < TAIERE:
                sus = min(sus, i); break
        if sus <= lat + 50:
            continue
        e = rng.randrange(lat + 50, sus)          # indicele ultimei bare vizibile
        a = e - lat + 1
        c0 = d["c"][a]
        if c0 <= 0: continue
        k = 100.0 / c0
        bare = [[round(d["o"][i]*k, 3), round(d["h"][i]*k, 3),
                 round(d["l"][i]*k, 3), round(d["c"][i]*k, 3)] for i in range(a, e+1)]
        # ferestre plate sau cu goluri mari nu spun nimic
        maxim = max(b[1] for b in bare); minim = min(b[2] for b in bare)
        if maxim / minim < 1.02: continue
        ferestre.append({"id": len(ferestre)+1, "bare": bare})
        cheie.append({"id": len(ferestre), "simbol": s, "idx_start": a, "idx_end": e,
                      "scara": k, "t_start": d["t"][a], "t_end": d["t"][e],
                      "data": dt.datetime.fromtimestamp(d["t"][e]/1000, dt.UTC).strftime("%Y-%m-%d")})

    with open(os.path.join(AICI, "cheie.json"), "w") as f:
        json.dump(cheie, f, indent=1)

    sablon = open(os.path.join(AICI, "desen-orb.sablon.html"), encoding="utf-8").read()
    ies = sablon.replace("/*DATE*/", json.dumps(ferestre, separators=(",", ":")))
    with open(os.path.join(AICI, "desen-orb.html"), "w", encoding="utf-8") as f:
        f.write(ies)

    print(f"{len(ferestre)} ferestre · {len({c['simbol'] for c in cheie})} simboluri")
    print(f"lățimi: {sorted({len(x['bare']) for x in ferestre})}")
    print(f"scris: desen-orb.html ({os.path.getsize(os.path.join(AICI,'desen-orb.html'))//1024} KB) · cheie.json")

if __name__ == "__main__":
    main(int(sys.argv[1]) if len(sys.argv) > 1 else 20260920)
