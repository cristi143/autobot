# Plan de tranzacționare — deciziile, pe scurt

Fișierul ăsta e **sursa de adevăr** pentru cum trebuie să funcționeze sistemul.
Stabilit pe 2 septembrie 2026, în discuție cu utilizatorul. **Nimic din el nu e încă
implementat** — site-ul are deocamdată doar graficul.

## Ce construim

Un singur ecran care înlocuiește dus-întorsul TradingView ↔ Binance: graficul live
ZECUSDC 1h, pe care utilizatorul desenează linii de trend, iar sistemul deschide
poziții simulate pe închiderea lumânării.

## Unde rulează: tot pe ClausWeb

**Revizuire față de ce scria înainte în DEPLOY.md §3.** Afirmația „motorul nu poate
rula pe shared hosting" e adevărată pentru strategii care reacționează în secunde.
**Nu se aplică aici:** pe 1h e nevoie de o singură verificare pe oră, adică un cron —
exact ce are cPanel. Fără VPS, fără server local.

### Mediul serverului — verificat pe 2 septembrie 2026

Rulat cu o pagină de probă direct pe `autobot.dunitru.ro`. **Totul e în regulă:**

| | |
|---|---|
| PHP | **8.1.34**, SAPI litespeed |
| Extensii | `curl`, `openssl`, `json`, `pdo_mysql`, `hash` — toate active |
| Conexiune la Binance | **funcționează** de pe server (HTTP 200, lumânare validă) |
| Fus orar al serverului | **Europe/Bucharest** |
| IP de ieșire | **86.107.43.56** (≠ IP-ul de intrare al site-ului, 93.114.248.158) |

Rămâne de confirmat vizual doar existența secțiunii **Cron Jobs** (cPanel → Advanced).

### Trei consecințe de ținut minte

1. **Fusul serverului e Europe/Bucharest, dar Binance lucrează în UTC.**
   Tot codul care formatează sau compară timpi trebuie să forțeze explicit UTC
   (`new DateTimeZone('UTC')`, `gmdate()`). Altfel lumânările se vor alinia greșit
   cu 2–3 ore, în funcție de ora de vară. E cea mai probabilă sursă de bug tăcut
   din tot proiectul.

2. **IP-ul de ieșire diferă de cel de intrare** și, pe shared hosting, se poate
   schimba fără preaviz. La etapa 5, dacă punem cheia Binance pe listă albă de IP,
   o schimbare de IP oprește tranzacționarea. De cântărit atunci: listă albă
   (mai sigur, mai fragil) vs. fără (mai comod, mai riscant).

3. **PHP 8.1 nu mai primește actualizări de securitate** (suport încheiat la finalul
   lui 2025). Merge pentru ce facem, dar merită ridicat la 8.3 din cPanel →
   *MultiPHP Manager*, pentru subdomeniul `autobot.dunitru.ro`. Marcel Parcel
   rulează deja pe 8.3.

## Arhitectura

- **Browser** → desenează linii, le salvează prin API; primește prețul live direct
  de la Binance prin WebSocket, **doar pentru afișare — nu declanșează nimic**.
- **Cron orar** (`1 * * * *`) → cere lumânarea închisă de la Binance REST, o compară
  cu liniile, declanșează semnale, mișcă băncile simulate.
- **MySQL** → sursa de adevăr: linii, lumânări, semnale, tranzacții, solduri.

Sistemul funcționează cu browserul închis.

## Regula de semnal — stabilită de utilizator

O linie = două puncte `(t1,p1)`, `(t2,p2)`, prelungite drept spre dreapta.

**Unitatea de lucru nu e linia, ci perechea: un triunghi.** Utilizatorul trage două
linii **convergente**, una deasupra prețului și una dedesubt. Ele se strâng, așa că
prețul e forțat să iasă pe una dintre ele — nu există scenariul „nu se întâmplă
nimic".

| Semnal | Condiție la închiderea lumânării de 1h |
|---|---|
| **LONG** | lumânare **verde** (`close > open`) închide **peste linia de sus** |
| **SHORT** | lumânare **roșie** (`close < open`) închide **sub linia de jos** |

### Triunghiul e de unică folosință

**Un triunghi produce un singur semnal, apoi ambele linii trec în istoric.**
Se consumă perechea, nu doar linia care a tras. Dacă prețul iese pe o parte și revine
înăuntru, triunghiul tot e consumat — nu se mai poate folosi pentru alt semnal.

Ca să existe o nouă intrare, utilizatorul trage un triunghi nou.

Consecințe:

- **cel mult o poziție deschisă la un moment dat** dintr-un triunghi, deci băncile
  nu pot fi amândouă în poziție simultan (liniile sunt trase în aproximativ același
  loc — ori iese în sus, ori în jos, nu ambele);
- nu există „reintrare", și nu e nevoie de regula cu „doar la schimbarea de stare" —
  un triunghi consumat nu mai declanșează nimic;
- **nu e nevoie de expirare**: liniile convergente forțează o ieșire oricum;
- dacă nu există triunghi activ, sistemul nu face nimic. Nu tranzacționează singur;
- **arhiva e materialul pentru etapa 4** — fiecare triunghi păstrat e o decizie
  luată de utilizator, cu puncte exacte și moment. Exemple etichetate.

**Atenție la implementare:** „arhivat" nu înseamnă „șters". Geometria liniilor
rămâne în bază pentru totdeauna — nu pentru SL (din 19.09.2026 ieșirile nu mai
depind de ea), ci pentru că e materialul etapei 4: fiecare triunghi e o decizie a
utilizatorului, cu puncte exacte și moment.

### Rolul liniei — dedus la desenare

Când se desenează o linie, prețul e fie sub ea, fie peste ea:

- preț **sub** linie → **linie de sus**; spargere în sus cu lumânare verde = **LONG**
- preț **peste** linie → **linie de jos**; spargere în jos cu lumânare roșie = **SHORT**

Rolul se fixează la desenare și nu se mai schimbă (liniile convergente s-ar
intersecta altfel și rolurile s-ar inversa singure). Se afișează pe ecran ca să fie
verificabil, cu posibilitatea de a-l inversa dintr-un clic.

## Ieșirea din poziție — rescrisă pe 19.09.2026

> Regulile vechi (SL = linia de intrare, evaluată pe închidere; TP fix +1%) sunt
> păstrate mai jos, în secțiunea „Ce era înainte", cu motivul pentru care au picat.

| | Unde | Cum se evaluează |
|---|---|---|
| **TP** | `intrare ± tp_atr × ATR(14)`, așezat la intrare și **înghețat acolo** | la **fiecare rulare**, pe maximul/minimul lumânării în formare |
| **SL** | `intrare ∓ sl_atr × ATR(14)`, la fel | la fel |
| **Stop de timp** | după `ore_maxime` ore fără TP și fără SL | ieșire la prețul de atunci |

Valori implicite: `tp_atr = 1,5`, `sl_atr = 1,0`, `ore_maxime = 48`. Distanțele sunt
plafonate între **0,5%** și **4%** din prețul de intrare.

**Liniile nu mai au niciun rol după intrare.** Triunghiul dă semnalul, apoi iese
din poveste. Geometria rămâne în bază ca arhivă pentru etapa 4 — acolo e valoroasă,
nu în managementul poziției.

### De ce a picat SL-ul pe linie

Trei mecanisme, dintre care al treilea e cel grav:

1. **Se judeca pe închidere** — o oră întreagă de mișcare potrivnică era absorbită
   integral. Amplitudinea mediană a unei ore de ZEC e 1,54%; a nouăzecea percentilă,
   3,68%.
2. **Execuția la deschiderea orei următoare** mai adăuga un gol peste atât.
3. **Linia fugea de poziție.** Laturile fiind convergente, linia de sus coboară spre
   vârf oră de oră. După un long, pragul de SL se **îndepărta** de prețul de intrare
   cu fiecare oră petrecută în poziție: cu cât stăteai mai mult, cu atât aveai voie
   să pierzi mai mult, în timp ce câștigul rămânea plafonat la 1%.

Rezultatul, pe primele patru tranzacții reale: TP mediu **+0,85%**, singurul SL
**−1,97%**. Rata de reușită necesară doar ca să ieși pe zero: **69,8%**.

### Ce au spus datele

Măsurat pe cele 6.856 de ore din `public/data/ZECUSDC-1h.json` (nov. 2025 – aug. 2026):

- **ATR(14) median: 1,72%** din preț. Amplitudinea unei ore obișnuite: 1,54%.
  **TP-ul de 1% era 0,58 ATR** — adică sub zgomotul unei singure ore. Se lua profit
  dinăuntrul zgomotului, plătind comision pentru el.
- Simulare cu **intrări la întâmplare**, la fiecare oră, în ambele sensuri, cu TP și
  SL mecanice, orizont 48h, convenție pesimistă la ambiguitate:

  | TP/SL | reușită | prag necesar | așteptare/tranzacție |
  |---|---|---|---|
  | 1,0 / 1,00 | 45,8% | 57,5% | −0,233% |
  | 1,0 / 0,50 | 28,7% | 43,3% | −0,220% |
  | 1,5 / 1,00 | 38,0% | 46,0% | −0,200% |
  | 2,0 / 2,00 | 49,3% | 53,8% | −0,176% |
  | 3,0 / 1,50 | 33,6% | 36,7% | −0,138% |
  | 3,4 / 1,72 | 34,7% | 36,5% | −0,091% |

**Două concluzii, și sunt de natură diferită.**

1. **Toate combinațiile pierd, cu aproximativ exact costul comisioanelor.** Nu e
   statistică, e matematică: pe o piață fără direcție previzibilă, orice schemă de
   TP/SL are așteptare zero înainte de costuri. Ridici ținta → scade rata de reușită
   fix cât trebuie. **Schema de ieșire nu poate crea avantaj.** Doar intrarea poate.
2. **Țintele mici pierd de 2,5 ori mai mult.** Comisionul e o taxă fixă de 0,15%: la
   un TP de 1% înseamnă 15% din mișcare, la 3,4% doar 4,4%. Asta **nu** e o
   consecință a hazardului, ci a aritmeticii, și se aplică oricât de bună ar fi
   intrarea.

De aici alegerea: praguri departe de zgomot, scalate cu volatilitatea, nu procente
fixe. Un procent fix înseamnă altceva într-o săptămână liniștită decât într-una
agitată; ATR-ul se scalează singur.

### Etalonul

Tabelul de mai sus e de acum **linia de zero a proiectului**. Cu ieșiri mecanice,
rata de reușită a unei maimuțe e cunoscută pentru fiecare pereche de praguri;
desenul utilizatorului trebuie s-o bată cu mai mult decât costul comisioanelor.
Cu regulile vechi, întrebarea „desenez bine?" nu se putea separa de „ies bine?",
pentru că ieșirea depindea de desen. Acum se poate.

### Convenția pentru ambiguitate

Dacă TP și SL cad în aceeași fereastră de preț, **se ia SL**. Din lumânări de o oră
nu se poate ști care a fost primul; cronul la un minut o va ști aproape mereu în
realitate, dar convenția trebuie să fie pesimistă. O simulare care presupune ordinea
favorabilă minte exact în direcția în care s-ar paria bani adevărați.

### O optimism cunoscut, de ținut minte

Ieșirea pe SL se socotește **fix la prețul pragului**. Un TP e un ordin limită, deci
acolo cifra e corectă; un stop e, în realitate, un ordin la piață, cu alunecare.
La etapa 5 diferența devine reală și trebuie măsurată, nu presupusă.

### Ce se înregistrează acum, și nu se putea reconstitui

Pe fiecare poziție: **ATR-ul la intrare**, **MFE** (cât de departe a mers în favoare)
și **MAE** (cât de adânc a intrat în minus). Sunt maxime de pe tot drumul — după
închidere nu se mai pot afla. Cu ele se răspunde, peste 20–30 de tranzacții, la
întrebări care acum n-au răspuns: câte SL-uri ar fi fost evitate de un stop mutat la
break-even? cât se lasă pe masă cu ținta așezată aici? ar fi ținut un stop mai strâns?

### Ce era înainte

| | Când | Cum se evalua |
|---|---|---|
| **SL** | lumânarea închidea înapoi de partea cealaltă a **liniei care a dat intrarea** | la **închiderea** lumânării |
| **TP** | prețul atingea **+1%** față de intrare (`intrare × 1,01`) | în timp real, oricând în timpul orei |

Ieșirea pe SL nu avea condiție de culoare (spre deosebire de intrare) — nici acum nu
are, pragurile nu se uită la culoarea lumânării.

### Ambiguitatea TP vs. SL — rezolvată prin convenție, nu prin construcție

Până pe 19.09.2026 nu exista: SL-ul se judeca în ultima clipă a orei, TP-ul oricând
în timpul ei, deci TP-ul era primul prin definiție. **Acum amândouă sunt praguri
verificate pe aceeași fereastră**, iar din lumânări de o oră nu se știe care a fost
primul. Convenția: **se ia SL**. Vezi „Convenția pentru ambiguitate" mai sus.

### Cronul rulează la fiecare minut, cu două ritmuri

**Corectare față de prima versiune a planului.** Scrisesem că un cron orar
„reproduce exact" un TP în timp real. Adevărat despre **prețul** de execuție —
un ordin limită se execută fix la pragul lui — dar fals despre **moment**: cu
verificare orară, o poziție atinsă la 10:05 rămâne marcată deschisă până la 11:01.

Deci:

- **TP și SL — la fiecare rulare**, inclusiv pe lumânarea în formare. Dacă maximul
  (minimul) de până acum a atins pragul, ordinul s-a executat deja. Poziția se
  închide în cel mult 60 de secunde de la atingere.

  Atingerea nu se ratează niciodată, oricât de rar ar rula cronul: `high` și `low`
  ale lumânării în formare sunt extremele oricărei tranzacții de la începutul orei,
  actualizate la fiecare tick. Rularea deasă câștigă doar viteza de recunoaștere.
- **Semnalele — o singură dată per lumânare închisă**, pentru că exact așa sunt
  definite: pe închidere.

Rulările dintre ore se scriu în jurnal cu `ora_lumanare` gol, ca o lumânare să nu
poată fi judecată de două ori pentru SL sau semnale.

### Ordinea de evaluare

1. **MFE/MAE** — se actualizează întâi, ca să prindă și fereastra în care poziția
   se închide
2. **TP și SL împreună**, pe aceeași fereastră de preț; amândouă atinse → SL
3. **stopul de timp** — doar dacă n-a ieșit pe praguri
4. **semnalele noi de intrare** — o singură dată per lumânare închisă

## Cele două bănci — atenție la capcană

Pe spot, „short" = să nu deții ZEC. **Dacă ambele bănci reacționează la aceleași
linii, fac exact aceleași tranzacții în același moment.** Nu sunt două strategii;
sunt aceeași strategie măsurată în două monede:

| Banca | Pornește cu | Se măsoară în | Răspunde la |
|---|---|---|---|
| LONG | **1000 USDC** | USDC | bate „cumpăr și țin ZEC"? |
| SHORT | **1 ZEC** | **ZEC** | adună mai mult ZEC decât „stau pe USDC"? |

Cifre rotunde, alese pentru citire: cu 1 ZEC la pornire, un sold de 1,0086 ZEC
înseamnă +0,86% fără niciun calcul. Cele două bănci **nu pornesc de la aceeași
valoare** — 1 ZEC valora ~822 USDC la rebazare — și nici nu trebuie: se măsoară
în monede diferite, deci nu se compară una cu alta.

**Se afișează mereu în moneda de măsură**, nu în cea ținută. Banca de long arată
USDC și când e în poziție ținând ZEC; cea de short arată ZEC și când stă pe USDC.
Altfel cifra ar sări dintr-o monedă în alta la fiecare intrare și n-ar mai exista
niciun reper. Ce ține de fapt apare ca linie secundară.

**Reperul fiecăreia e cealaltă monedă** — acolo e informația. Pentru long, „stau
pe USDC" ar fi mereu 500, deci comparația utilă e cu „cumpăr ZEC la început și
țin". Pentru short, invers.

A doua întrebare e cea ratată de obicei: o strategie poate câștiga în USDC și
totuși să te lase cu mai puțin ZEC decât dacă nu făceai nimic.

Dacă se vor două strategii cu adevărat diferite, fiecare bancă trebuie să aibă
propriile linii (suport pentru long, rezistență pentru short).

## Realismul simulării — obligatoriu de la început

- **comision 0,075% pe parte, 0,15% dus-întors** (tarif cerut de utilizator;
  corespunde reducerii Binance cu BNB — presupune că are BNB pentru comisioane)
- **execuție la deschiderea lumânării următoare**, nu la close-ul care a dat semnalul
- **comparație permanentă cu „nu fac nimic"** (hold USDC / hold ZEC)

Fără astea, simularea minte în favoarea strategiei.

## Etape

0. ~~verificare cPanel~~ — **gata**, totul trece
1. ~~bază de date + API + desenare triunghiuri~~ — **gata**
2. ~~cron orar + motor de semnale + cele două bănci simulate + jurnal~~ — **gata**
3. protecție cu parolă a paginii (cPanel, `.htpasswd`)
4. analiza liniilor desenate → model matematic
5. bani reali — doar după luni de simulare

## Datele strânse pentru etapa 4

Se reconstituie oricând din ce e deja în bază: ce ating capetele liniilor (față de
vârfurile și văile detectate din lumânări), cât de departe în urmă privește
utilizatorul, ce vârfuri a sărit, ce toleranță acceptă.

Se înregistrează pe măsură ce se întâmplă, **pentru că nu se pot completa
retroactiv**:

- **starea `expirat`**, separată de `sters` — piața a infirmat tiparul, față de
  utilizatorul care și-a retras desenul. Amândouă sunt exemple negative, din
  motive diferite. Niciuna nu se poate șterge.
- **fereastra vizibilă la desenare** — cât se vedea pe ecran în acel moment.

- **nota utilizatorului** — ce a văzut acolo, scrisă la desenare. Singurul lucru
  care nu se deduce niciodată din cifre. Motorul nu o atinge.

Rămân neînregistrate, deliberat: momentele în care s-a uitat fără să deseneze —
acelea ar cere un jurnal de vizite, mai intruziv și cu valoare mai mică.

## Etapa 4: modelul matematic

Utilizatorul nu poate explica verbal cum trage liniile. Abordare, după 20–30 de
linii salvate: detectare de pivoți (vârfuri/văi), verificat de care se lipesc
capetele liniilor, măsurat fereastra de timp și toleranța, apoi validare oarbă pe
o perioadă nevăzută.

## Decizii confirmate

- **Cron Jobs există** în cPanel.
- Băncile reacționează la **aceleași linii**.
- Intrare cu **toată banca**.
- ~~**TP fix 1%**, în timp real~~ · ~~**SL la închidere**, peste linia de intrare~~
  — **înlocuite pe 19.09.2026** cu praguri fixe la multipli de ATR. Vezi „Ieșirea
  din poziție".
- Comision **0,075% pe parte**.
- **Linii de unică folosință**, arhivate după ce trag.

## Presupuneri confirmate de utilizator

1. **Spargere cu lumânare de culoarea greșită** — o roșie care închide peste linia
   de sus nu e semnal. Triunghiul **rămâne armat** până apare o lumânare cu ambele
   condiții.
2. **Prețul de execuție** — intrarea la **deschiderea lumânării următoare**.
   Ieșirile se socotesc exact la prag: TP-ul pe drept (e ordin limită), SL-ul cu
   optimismul cunoscut de mai sus (un stop real alunecă).

## Adăugat după prima folosire pe date reale

**Triunghiul expiră la vârf.** Liniile fiind convergente, se intersectează cândva.
Dacă prețul n-a spart până atunci, după intersecție „linia de sus" ajunge sub „cea
de jos" — iar rolurile fiind înghețate la desenare, orice lumânare verde ar
declanșa un long fals. Motorul calculează vârful și marchează triunghiul `sters`,
cu explicație în `nota`.

## Cerut de utilizator, de făcut mai târziu

- **Pagină separată de statistici** — câte ieșiri pe TP față de SL, cel mai lung
  șir de pierderi, timp mediu în piață, distribuția rezultatelor. Ține deliberat
  de altă pagină: panoul lateral rămâne pentru starea curentă, nu pentru analiză.

## Ce urmează

Pragurile sunt acum configurabile (`tp_atr`, `sl_atr`) și adaptive prin ATR.
Rămâne de încercat, **după ce se strâng 20–30 de tranzacții cu MFE/MAE**, nu
înainte: stop mutat la break-even, trailing, și un filtru de intrare care refuză
semnalele cu raport prost. Fără datele acelea, orice alegere e ghicit.

## Reguli ferme

- **Cheile API Binance nu ajung în git niciodată.** La etapa 5, pe server: doar spot,
  fără retrageri, IP-ul serverului pe listă albă, fișier în afara zonei publice.
- Datele publice de piață nu au nevoie de chei — de aceea graficul live merge din
  browser. Orice endpoint semnat rămâne exclusiv pe server.
