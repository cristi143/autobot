# Proba triunghiurilor — protocol, scris înainte de rezultate

20 septembrie 2026. **Acest fișier a fost scris și fixat ÎNAINTE de a rula orice
simulare.** Regula de decizie e de aici; dacă rezultatul iese ambiguu, se
citește ce scrie mai jos, nu ce ne-ar conveni atunci.

## Întrebarea

Etapa 4 din `plan-tranzactionare.md` întreabă dacă desenul utilizatorului are
vreun avantaj. Întrebarea se rupe în două, iar aici se răspunde la prima:

> **Are clasa de tipar „triunghi convergent", așa cum a descris-o el în
> `regula-desenului.md`, un avantaj peste intrarea la întâmplare — pe aceleași
> date și cu aceleași reguli de ieșire?**

A doua („alege el mai bine decât media triunghiurilor care se calificau?") cere
desenul orb și vine după, și numai dacă prima primește un răspuns pozitiv.

## Datele

Tot ce e în `../../historical_data/`: **27 de simboluri, 56.954 de zile-simbol,
≈1,37 milioane de ore** de lumânări, agregate din CSV-urile de 1 minut. 21 de
simboluri acoperă iulie 2019 – august 2026.

Verificare de sănătate trecută înainte de orice: agregatorul scoate pentru
`ZECUSDC` exact **6.856 de ore**, cifra din tabelul de etalon al planului.

### Împărțirea, fixată acum

| | |
|---|---|
| **Explorare** | tot ce e **înainte de 1 ianuarie 2024** |
| **Verificare** | **1 ianuarie 2024 → august 2026**, plus toate simbolurile |

Grila de parametri se caută **exclusiv** pe explorare. Partea de verificare nu se
atinge până când configurația e înghețată. Un singur rulaj pe ea, la final.

### ⚠ Un simbol ars, consemnat la momentul faptei

La proba de funcționare a enumeratorului am rulat pe `ZECUSDC` și **am văzut
randamentul mediu**. Datele lui încep în noiembrie 2025, deci sunt în întregime
în partea de verificare — adică exact ce nu aveam voie să privesc.

Efectul e mic (un singur număr, o singură celulă din grilă), dar regula nu se
negociază după ce a fost încălcată. **`ZECUSDC` e scos din verdictul final.**
ZEC rămâne acoperit de `ZECUSDT`, care are toți cei șapte ani și n-a fost atins.

Consemnat aici, nu șters, pentru că un protocol din care dispar abaterile nu mai
e protocol.

## Unitatea de observație

**Un triunghi care a produs un semnal = o tranzacție.** Nu se simulează un
portofoliu, nu există o singură poziție globală: fiecare triunghi e un eveniment
independent. Așa statistica e curată, iar rezultatul nu depinde de ordinea
întâmplătoare în care s-ar fi ocupat banca.

## Regulile de ieșire — FIXE, nu se caută pe grilă

Exact cele din sistemul viu, ca rezultatul să fie transferabil:

- intrare la **deschiderea lumânării următoare** celei care a rupt linia;
- **TP** = intrare ± 1,5 × ATR(14) · **SL** = intrare ∓ 1,0 × ATR(14);
- ambele plafonate între **0,5%** și **4%** din prețul de intrare;
- verificate la fiecare oră, pe maxim/minim; **amândouă atinse → SL**;
- **stop de timp la 48 de ore**;
- **comision 0,075% pe parte**.

## Grila — se caută doar pe explorare

Pragurile pe care el nu le-a putut fixa. Se rulează toate, și se cere ca
rezultatul să fie **stabil pe o zonă**, nu într-un punct norocos.

| Parametru | Valori |
|---|---|
| Proeminența vârfului (lumânări de fiecare parte) | 2 · 3 · 5 |
| Perechea de vârfuri (consecutive, sau la distanță) | 1 · 2 |
| Vârful triunghiului, la desenare (lumânări înainte) | [10,50] · [5,100] · [10,30] |
| Prospețime (lumânări de la ultima atingere) | 8 · 15 · 25 |
| Lățime minimă la semnal (ATR) | 0,5 · 1,0 · 1,5 |
| Perioadă refractară după triunghiul precedent (ore) | 0 · 24 |

**Un vârf e confirmat abia după ce trec cele `p` lumânări din dreapta.** Un
triunghi nu poate exista înainte de clipa în care ambele capete sunt confirmate —
altfel am desena cu date din viitor, adică exact greșeala pe care am făcut-o o
dată la măsurarea proeminenței și am prins-o.

## Etalonul

**Se recalculează pe aceleași date.** Tabelul din plan e măsurat pe ZEC, nouă
luni; aici testăm 27 de simboluri și șapte ani, deci nula trebuie măsurată acolo,
nu importată.

Intrări la întâmplare: triplete (simbol, oră, direcție) alese aleatoriu, cu
aceleași reguli de ieșire. Eșantion suficient cât intervalul de încredere să fie
mult mai strâns decât efectul căutat.

## Regula de decizie — fixată acum

Pe partea de **verificare**, cu configurația înghețată pe explorare:

**CONDIȚIA 1 — desenul aduce informație.**
Randamentul mediu net per tranzacție al triunghiurilor minus cel al intrărilor la
întâmplare, cu interval de încredere 95% prin **bootstrap grupat pe simbol**, care
**exclude zero**.

**CONDIȚIA 2 — informația trece de costuri.**
Randamentul mediu net per tranzacție al triunghiurilor, **în valoare absolută,
peste zero**, cu interval de încredere 95% care exclude zero.

**Plus, amândouă obligatorii:**

- **același semn pe majoritatea simbolurilor** — nu un singur simbol care trage tot;
- **rezistă la scoaterea celui mai bun simbol** (leave-one-out);
- **stabil pe grilă** — dacă merge doar într-o celulă și vecinele ei sunt zero sau
  negative, e potriveală, nu tipar.

### Verdictele

| Rezultat | Ce înseamnă | Ce urmează |
|---|---|---|
| 1 și 2 trec | tiparul are avantaj exploatabil | desenul orb, pentru întrebarea a doua |
| 1 trece, 2 nu | desenul conține informație, dar nu cât comisionul | se caută ieșiri mai bune sau costuri mai mici — NU se tranzacționează |
| 1 nu trece | triunghiurile nu se deosebesc de o intrare la întâmplare | **subiectul se închide**, cu dovadă |

Al treilea rezultat e cel mai probabil dintre toate, și e un rezultat bun: închide
o întrebare deschisă din februarie, pe 1,37 milioane de ore în loc de patru
tranzacții.

## Ce NU dovedește proba asta

Că utilizatorul desenează prost. Proba testează **clasa de tipar**, enumerată
mecanic. Dacă iese negativ, rămâne logic posibil ca alegerea lui dintre
triunghiurile care se califică să fie bună — dar atunci avantajul ar trebui să
vină din ceva ce regula scrisă nu prinde, iar asta se măsoară doar cu desenul orb,
comparându-l cu populația, nu cu hazardul.

---

# REZULTATE — 20 septembrie 2026

## Controlul care validează toată țeava

Înainte de orice concluzie despre triunghiuri, uită-te la etalon **înainte de
comision**:

| | randament brut per tranzacție |
|---|---|
| Intrări la întâmplare, 191.346 de tranzacții | **−0,0008%** |

Teoria din `plan-tranzactionare.md` spune că orice schemă de TP/SL are așteptare
**zero** înainte de costuri. Măsurat independent, pe 27 de simboluri și șapte
ani, iese **−0,0008%** — opt zecimi dintr-o miime de procent de zero.

Asta nu e un rezultat despre piață, e un **certificat că simularea nu minte**.
Dacă țeava ar fi avut o scurgere — o privire în viitor, o greșeală de comision,
o aliniere greșită a barelor — cifra aia n-ar fi fost zero.

## Configurația înghețată pe explorare

`p=5` vârfuri de fiecare parte · perechi de vârfuri la distanță ≤2 ·
vârful triunghiului la 10-50 de lumânări · lățime minimă 1,5 ATR · fără refractar.

**Filtrul de prospețime s-a dovedit inert.** 8, 15 și 25 de lumânări dau
rezultate identice, pentru că prin construcție capătul cel mai recent al unei
linii *este* o atingere, iar desenul se face la `p` lumânări după el. Criteriul
e satisfăcut automat; nu poate discrimina nimic. Nu e o greșeală a lui — e o
consecință a felului în care se construiește un triunghi.

## Verdictul, pe partea neatinsă (ian. 2024 → aug. 2026, 26 de simboluri)

| | tranzacții | net per tranzacție |
|---|---|---|
| **Triunghiuri** | 40.832 | **−0,0878%** |
| Intrări la întâmplare | 191.346 | −0,1508% |
| **Exces** | | **+0,0629%** |

**CONDIȚIA 1 — TRECE.** Excesul: +0,0630%, IÎ 95% **[+0,0317, +0,0969]**, exclude
zero. Pozitiv pe **20 din 26** de simboluri. Rezistă la scoaterea celui mai bun
simbol (fără ALGOUSDT: +0,0536%). Pozitiv în **fiecare** dintre cei trei ani:
+0,085% · +0,032% · +0,066%.

**CONDIȚIA 2 — NU TRECE.** Randamentul absolut: −0,0878%, IÎ 95%
**[−0,1161, −0,0559]** — în întregime sub zero.

## Ce înseamnă, în cifre

Înainte de comision:

| | brut per tranzacție |
|---|---|
| Triunghiuri | **+0,0622%** |
| Intrări la întâmplare | −0,0008% |
| **Comision dus-întors** | **0,1499%** |

**Tiparul conține un avantaj real de ~0,062% pe tranzacție. Comisionul e 0,15%.
Avantajul există, dar e cam 41% din cât ar trebui.**

Rata de reușită: **42,7%**, față de pragul de zero de ~40% pentru praguri de
1,5/1,0 ATR. Deci desenul chiar împinge rata peste prag — doar că nu destul cât
să acopere și taxa.

## Verdict, după tabelul fixat dinainte

> **„1 trece, 2 nu → desenul conține informație, dar nu cât comisionul. Se caută
> ieșiri mai bune sau costuri mai mici — NU se tranzacționează."**

Triunghiurile **nu sunt zgomot**. Asta e un rezultat pozitiv și deloc banal:
majoritatea tiparelor din analiza tehnică nu supraviețuiesc unui test cu grilă,
perioadă ținută deoparte și bootstrap grupat. Ăsta a supraviețuit.

Dar în forma actuală **pierde bani**, și pierde din cauza costului, nu a lipsei
de informație.

## Ce urmează, și ce NU urmează

**Întrebarea a doua devine mai valoroasă, și acum are o țintă numerică.**
Populația de triunghiuri care se califică dă +0,062% brut. Ca să merite,
alegerea utilizatorului dintre ele ar trebui să aducă **peste 0,15%** — adică
să înmulțească de aproximativ **2,5 ori** avantajul mediu al populației. Desenul
orb măsoară exact asta, și acum știm față de ce se compară.

**Ce NU se face:** nu se umblă la regulile de ieșire pe datele astea. Ipoteza că
praguri mai depărtate ar dilua comisionul e rezonabilă — e chiar concluzia a
doua din tabelul etalon al planului — dar e o **ipoteză nouă**, și cere propriul
ei protocol, cu propria ei perioadă ținută deoparte. Reglată pe datele de aici,
n-ar mai însemna nimic.

---

# ÎNCHIS — decizia utilizatorului, 20 septembrie 2026

După verdict, Cristi a decis să **renunțe complet la direcția cu triunghiuri**,
inclusiv la întrebarea a doua:

> „chiar dacă desenele mele sunt mai bune tot nu cred că merită să continuăm să
> mergem în direcția asta. dacă erau rezultate foarte bune iar eu cumva
> optimizam era ceva, dar rezultatele sunt slabe."

**Raționamentul e corect și nu se contrazice.** Informația valorează doar dacă
schimbă o decizie. El știa dinainte că nici un rezultat pozitiv la desenul orb
nu l-ar face să tranzacționeze un tipar al cărui avantaj brut e 41% din comision.
Deci experimentul nu mai avea valoare de decizie — doar de curiozitate.

Unealta de desen orb **rămâne în repo, construită și funcțională**
(`analiza/desen-orb.html`, 60 de ferestre). N-a fost folosită. Dacă vreodată
cineva vrea să răspundă la întrebarea a doua, e gata de pornit.

**Pentru o sesiune viitoare:** nu reporni subiectul. Dacă apare o idee nouă cu
linii de trend, triunghiuri, spargeri de canal sau alte tipare geometrice, dă
cifra măsurată (+0,062% brut față de 0,150% comision, pe 40.832 de tranzacții
și 26 de simboluri) și lasă decizia la el. Nu reface proba.
