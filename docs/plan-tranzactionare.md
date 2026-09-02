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

**Atenție la implementare:** „arhivat" nu înseamnă „șters". Geometria liniei de
intrare rămâne necesară după consumarea triunghiului, pentru că SL-ul se evaluează
față de ea, iar linia continuă să se prelungească în timp.

### Rolul liniei — dedus la desenare

Când se desenează o linie, prețul e fie sub ea, fie peste ea:

- preț **sub** linie → **linie de sus**; spargere în sus cu lumânare verde = **LONG**
- preț **peste** linie → **linie de jos**; spargere în jos cu lumânare roșie = **SHORT**

Rolul se fixează la desenare și nu se mai schimbă (liniile convergente s-ar
intersecta altfel și rolurile s-ar inversa singure). Se afișează pe ecran ca să fie
verificabil, cu posibilitatea de a-l inversa dintr-un clic.

## Ieșirea din poziție

| | Când | Cum se evaluează |
|---|---|---|
| **SL** | lumânarea închide înapoi de partea cealaltă a **liniei care a dat intrarea** | la **închiderea** lumânării |
| **TP** | prețul atinge **+1%** față de intrare, **brut pe preț** (ieșire la `intrare × 1,01`; net rămân ~0,85% după comisioane) | **în timp real**, oricând în timpul orei |

Ieșirea pe SL **nu are condiție de culoare** (spre deosebire de intrare).

### De ce nu există ambiguitate TP vs. SL

SL-ul se evaluează în ultima clipă a orei; TP-ul se poate atinge oricând în timpul ei.
**Deci dacă ambele se întâmplă în aceeași oră, TP-ul a fost întotdeauna primul, prin
construcție.** Nu e o presupunere optimistă, e o consecință a definițiilor.

### Cronul rulează la fiecare minut, cu două ritmuri

**Corectare față de prima versiune a planului.** Scrisesem că un cron orar
„reproduce exact" un TP în timp real. Adevărat despre **prețul** de execuție —
un ordin limită se execută fix la pragul lui — dar fals despre **moment**: cu
verificare orară, o poziție atinsă la 10:05 rămâne marcată deschisă până la 11:01.

Deci:

- **TP — la fiecare rulare**, inclusiv pe lumânarea în formare. Dacă maximul de
  până acum a atins pragul, ordinul s-a executat deja. Poziția se închide în cel
  mult 60 de secunde de la atingere.

  Atingerea nu se ratează niciodată, oricât de rar ar rula cronul: `high` al
  lumânării în formare e maximul oricărei tranzacții de la începutul orei,
  actualizat la fiecare tick. Rularea deasă câștigă doar viteza de recunoaștere.
- **SL și semnale — o singură dată per lumânare închisă**, pentru că exact așa
  sunt definite: pe închidere.

Rulările dintre ore se scriu în jurnal cu `ora_lumanare` gol, ca o lumânare să nu
poată fi judecată de două ori pentru SL sau semnale.

### Ordinea de evaluare

1. **întâi TP** — a atins maximul (sau minimul) pragul de 1%?
2. **apoi SL** — doar dacă lumânarea închisă n-a fost încă judecată
3. **apoi semnalele noi de intrare** — tot o singură dată per lumânare

## Cele două bănci — atenție la capcană

Pe spot, „short" = să nu deții ZEC. **Dacă ambele bănci reacționează la aceleași
linii, fac exact aceleași tranzacții în același moment.** Nu sunt două strategii;
sunt aceeași strategie măsurată în două monede:

| Banca | Pornește cu | Se măsoară în | Răspunde la |
|---|---|---|---|
| LONG | 500 USDC | USDC | bate „cumpăr și țin ZEC"? |
| SHORT | 500 USDC în ZEC | **ZEC** | adună mai mult ZEC decât „stau pe USDC"? |

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

## Etapa 4: modelul matematic

Utilizatorul nu poate explica verbal cum trage liniile. Abordare, după 20–30 de
linii salvate: detectare de pivoți (vârfuri/văi), verificat de care se lipesc
capetele liniilor, măsurat fereastra de timp și toleranța, apoi validare oarbă pe
o perioadă nevăzută.

## Decizii confirmate

- **Cron Jobs există** în cPanel.
- Băncile reacționează la **aceleași linii**.
- Intrare cu **toată banca**.
- **TP fix 1%**, în timp real. Poate deveni trailing mai târziu.
- **SL la închidere de lumânare**, înapoi peste linia de intrare.
- Comision **0,075% pe parte**.
- **Linii de unică folosință**, arhivate după ce trag.

## Presupuneri confirmate de utilizator

1. **Spargere cu lumânare de culoarea greșită** — o roșie care închide peste linia
   de sus nu e semnal. Triunghiul **rămâne armat** până apare o lumânare cu ambele
   condiții.
2. **Prețul de execuție** — intrarea și SL-ul la **deschiderea lumânării
   următoare**; TP-ul exact la `intrare × 1,01`, fiind ordin limită.

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

TP-ul de 1% e fix deocamdată. Utilizatorul vrea să-l facă mai târziu configurabil
sau adaptiv, posibil trailing — de prevăzut în structura datelor, nu de implementat
acum.

## Reguli ferme

- **Cheile API Binance nu ajung în git niciodată.** La etapa 5, pe server: doar spot,
  fără retrageri, IP-ul serverului pe listă albă, fișier în afara zonei publice.
- Datele publice de piață nu au nevoie de chei — de aceea graficul live merge din
  browser. Orice endpoint semnat rămâne exclusiv pe server.
