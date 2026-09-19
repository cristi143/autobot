import analiza as A, statistics as st
tri, et = A.incarca()
simb = sorted({s for (s,p,k) in tri} - A.ARS)

CFG = dict(p=5, k=2, apex=(10,50), prosp=15, lat=1.5, refr=0)   # ÎNGHEȚATĂ pe explorare
print("Configurație înghețată pe explorare:", CFG)
print("="*66)

tri_hi, et_hi = {}, {}
for s in simb:
    f = A.filtreaza(tri[(s,CFG['p'],CFG['k'])], CFG['apex'], CFG['prosp'], CFG['lat'], CFG['refr'])
    _, hi = A.imparte(f)
    if hi: tri_hi[s] = [r[5] for r in hi]
    e = [r[2] for r in et[s] if r[0] >= A.TAIERE]
    if e: et_hi[s] = e
comun = sorted(set(tri_hi) & set(et_hi))
tri_hi = {s: tri_hi[s] for s in comun}; et_hi = {s: et_hi[s] for s in comun}

m_t, n_t = A.medie(tri_hi); m_e, n_e = A.medie(et_hi)
print(f"\nVERIFICARE (2024-01-01 -> aug. 2026) · {len(comun)} simboluri\n")
print(f"  triunghiuri            {n_t:>7} tranzactii   {m_t:+.4f}%")
print(f"  intrari la intamplare  {n_e:>7} tranzactii   {m_e:+.4f}%")
print(f"  EXCES                                  {m_t-m_e:+.4f}%\n")

d = A.bootstrap(tri_hi, et_hi)
print("CONDITIA 1 - excesul (bootstrap grupat pe simbol, 4000):")
print(f"  {d[1]:+.4f}%   II 95%: [{d[0]:+.4f}, {d[2]:+.4f}]")
print(f"  -> {'TRECE' if (d[0]>0 or d[2]<0) else 'NU TRECE (cuprinde zero)'}\n")

a = A.bootstrap(tri_hi)
print("CONDITIA 2 - randamentul absolut al triunghiurilor:")
print(f"  {a[1]:+.4f}%   II 95%: [{a[0]:+.4f}, {a[2]:+.4f}]")
print(f"  -> {'TRECE' if a[0]>0 else 'NU TRECE'}\n")

poz = sum(1 for s in comun if st.mean(tri_hi[s]) > st.mean(et_hi[s]))
print(f"Semn consecvent: exces pozitiv pe {poz}/{len(comun)} simboluri")
worst=None
for s in comun:
    rt={x:v for x,v in tri_hi.items() if x!=s}; re={x:v for x,v in et_hi.items() if x!=s}
    ex=A.medie(rt)[0]-A.medie(re)[0]
    if worst is None or ex<worst[1]: worst=(s,ex)
print(f"Leave-one-out, cel mai prost caz: fara {worst[0]} -> exces {worst[1]:+.4f}%")

r=[(st.mean(tri_hi[s])-st.mean(et_hi[s]), s, len(tri_hi[s])) for s in comun]
r.sort(reverse=True)
print("\nPe simbol:")
for x in r[:5]: print(f"  {x[1]:12s} {x[2]:>6} tranz.  exces {x[0]:+.3f}%")
print("   ...")
for x in r[-5:]: print(f"  {x[1]:12s} {x[2]:>6} tranz.  exces {x[0]:+.3f}%")

from collections import Counter
print("\nMotive de iesire (triunghiuri):")
mot=Counter()
for s in comun:
    f=A.filtreaza(tri[(s,CFG['p'],CFG['k'])], CFG['apex'],CFG['prosp'],CFG['lat'],CFG['refr'])
    for x in A.imparte(f)[1]: mot[x[6]]+=1
tot=sum(mot.values())
for k,v in mot.most_common(): print(f"  {k:6s} {v:>6}  {v/tot*100:5.1f}%")
