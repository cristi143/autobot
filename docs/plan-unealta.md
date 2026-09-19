# Unealta de tranzacționare manuală — sursa de adevăr

Stabilit pe 19 septembrie 2026, în discuție cu utilizatorul.

**Nu are nicio legătură cu botul de triunghiuri.** Alt cod, alte tabele, alt cron,
alți bani fictivi. Singurele lucruri comune sunt serverul, baza de date și fișierul
de configurare. Pentru botul vechi: `plan-tranzactionare.md`.

## Ce e

Utilizatorul spune unde crede că ajunge prețul. Sistemul așteaptă acolo și execută
o regulă pe care el o setează integral. **Nu decide nimic singur și nu caută
semnale.** Fără o setare scrisă de om, unealta nu face nimic.

Deosebirea față de un ordin obișnuit la Binance: nu intrăm când prețul *atinge*
nivelul, ci când **se întoarce** la el. Un ordin limită la 800 se execută pe
drumul în jos; noi vrem dovada că a fost acolo și s-a întors.

## Regula, întreagă (LONG)

Setare: `intrare 800`, `coborâre minimă 20`, `revenire 20`, `ieșire 875`,
`urmărire 45`, opțional `stop 760`.

| Stare | Ce se întâmplă |
|---|---|
| **așteaptă** | Prețul trebuie să atingă 780 (= 800 − 20). Se uită la wick-uri: contează minimul real, nu închiderea. |
| **armat** | Se ține minte minimul atins. Pragul de intrare devine `minim + 20`, plafonat la 800 — **doar coboară**. Minim 700 → prag 720. |
| **intrare** | O lumânare de 15m **se închide peste prag**. Cumperi la prețul acelei închideri. |
| **în poziție** | Ținta rămâne **875, absolută** — nu se mută cu prețul real de intrare. Stopul (760) se verifică de la prima clipă. |
| **țintă atinsă** | Prețul atinge 875 (wick). De aici pragul de ieșire = `maxim − 45`, plafonat în jos la 875 — **doar urcă**. |
| **ieșire** | O lumânare de 15m **se închide sub prag**. Vinzi la prețul acelei închideri. |

**SHORT e oglinda exactă**, pe banca măsurată în ZEC: prețul trebuie să treacă
peste `intrare + urcare minimă`, pragul de intrare devine `maxim − revenire`
(doar urcă, plafonat la nivelul setat), intrarea e o închidere **sub** el și
vinde ZEC. Ținta e dedesubt; după atingerea ei pragul de ieșire e
`minim + urmărire` (doar coboară) și ieșirea e o închidere **peste** el.

## Cele patru decizii care dau caracterul uneltei

### 1. Declanșarea e pe închidere de 15 minute, nu pe atingere

ZEC e violent. Măsurat pe 19.09.2026: ATR(14) pe 1h = **28,3 USDC (1,86%)**,
amplitudinea mediană a unei ore **1,82%**, decila 9 **3,56%**, iar în ultima
săptămână a existat o oră de **11,95%**.

Un prag care se declanșează la atingere e rupt de zgomot, nu de piață. Utilizatorul
a descris exact problema: *„ajunge la 875, urcă la 890, scade repede la 880, iar
repede 910, iar repede 890"* — oscilații de 20–30 USDC, adică o oră obișnuită.

Deci **o atingere nu înseamnă nimic; o închidere de 15m sub prag, da.**

**Costul, și e real:** ieșirea se face la prețul de închidere, nu la prag.
ATR(14) pe 15m e 11,4 USDC, deci în mod obișnuit ieșirea cade cu **5–11 USDC sub
prag**. Așa se și socotește în bază — un sistem care pretinde că iese fix la prag
minte exact în direcția în care s-ar paria bani adevărați.

### 2. Extremele se iau din wick-uri, nu din închideri

**Wick** = liniuța subțire de deasupra și de dedesubtul corpului unei lumânări.
Corpul arată deschiderea și închiderea sfertului de oră; wick-urile arată cât de
sus și cât de jos a ajuns prețul în acele 15 minute, chiar dacă s-a întors
imediat.

```
        ╷ 1520   ← maximul atins (wick-ul de sus)
      ┌─┴─┐ 1515 ← închiderea
      │   │        corpul
      └─┬─┘ 1505 ← deschiderea
        ╵ 1498   ← minimul atins (wick-ul de jos)
```

Pragul de urmărire se calculează din maximul **real** atins, chiar și de trei
secunde. La fel armarea: o înțepătură sub 780 armează setarea.

Măsurat pe ultimele ~1000 de lumânări de 15m, wick-ul superior are mediana de
**2,71 USDC** (p90: 7,92). Alegerea costă, deci, cam 3 USDC de strictețe în plus
față de varianta cu închideri — neglijabil față de o urmărire de 40–50.

Cele două reguli lucrează împreună, și fiecare face altceva: **wick-urile spun unde
a fost prețul, închiderile spun dacă s-a rupt ceva.**

### 3. Ținta de ieșire e absolută

Pragul de intrare urmărește minimul, deci intrarea se poate face mult sub nivelul
gândit (800 gândit, 720 real). Ținta **nu se mută**: e un nivel de pe grafic, ales
pentru că acolo utilizatorul vede rezistență, nu o socoteală de randament. Intrat
mai jos → câștigul iese mai mare. Asta e intenția.

### 4. Stopul e instantaneu, nu pe închidere

Singura excepție de la regula celor 15 minute, cerută explicit. Un stop e
protecție: dacă prețul a atins 760, ieșim, fără să așteptăm confirmarea. Stopul e
opțional — lăsat gol, poziția așteaptă oricât.

**Optimismul cunoscut, același ca la botul vechi:** ieșirea pe stop se socotește
fix la prag. În realitate un stop e ordin la piață și alunecă. La bani reali
diferența trebuie măsurată, nu presupusă.

## Stopul atins înainte de intrare: setarea expiră

**Găsit rulând regulile peste lumânări reale, nu la proiectare.** Fără regula
asta, mașina producea intrări absurde:

```
LONG, nivel 1450, stop 1380 · prețul căzuse la 1200
INTRARE la 1227,80     ← pragul urmărise minimul până acolo
STOP    la 1380,00 → +12,23%     ← un „stop” care se declanșează pe profit
```

Cauza e combinația aleasă: pragul de intrare urmărește extremul, deci intrarea
poate ajunge mult sub nivelul gândit — inclusiv **sub** un stop care e valoare
absolută. La validare totul părea în regulă (1380 sub 1450), dar în realitate
intrarea căzuse dedesubt.

**Regula:** dacă prețul atinge stopul cât timp setarea așteaptă sau e armată,
setarea primește starea **`expirat`** și nu mai intră niciodată. Motivul nu e
tehnic, ci de înțeles: stopul e nivelul la care ai spus că te-ai înșelat. Dacă
piața ajunge acolo înainte să fi apucat să intri, teza e deja infirmată.

`expirat` e ținut separat de `anulata` exact ca la botul vechi, unde `expirat` e
verdictul pieței și `sters` e decizia omului. Contopite, istoricul n-ar mai
spune nimic.

**Consecința de ținut minte:** *stopul mărginește cât de jos poate coborî pragul
de intrare.* Cu stop, nu prinzi o cădere mai adâncă decât el — ceea ce e tocmai
ideea. Fără stop, pragul urmărește minimul oricât de jos, și atunci unealta
cumpără orice cădere. E alegerea utilizatorului, dar merită știută.

## Stopul trebuie să fie dincolo de pragul de armare

A doua regulă, scoasă tot de probe. Setarea care pare cea mai firească —
`nivel 800, coborâre minimă 20, stop 780` — **e imposibilă din construcție:**
aceeași atingere a lui 780 care armează setarea o și omoară.

Deci, la validare: pentru long, `stop < nivel − coborâre minimă`; pentru short,
`stop > nivel + urcare minimă`. Regula e mai strictă decât „stopul sub nivel” și
o înlocuiește. Cu `nivel 800, coborâre 20`, stopul trebuie să fie sub 780.

## Ordinea de evaluare, în interiorul unei lumânări

Contează, pentru că pe o lumânare de 15m se pot întâmpla mai multe lucruri:

1. **MFE / MAE** — cât de departe a mers, în favoare și împotrivă
2. **stopul** — pe wick-uri, instantaneu
3. **urmărirea** — se actualizează maximul, se activează ținta dacă a fost atinsă,
   se recalculează pragul
4. **ieșirea** — doar dacă închiderea a rupt pragul
5. **expirarea** — dacă nu e poziție și stopul a fost atins, setarea moare aici
6. **intrarea** — doar dacă nu e poziție deschisă pe banca aceea

Pașii 5 și 6 sunt în ordinea asta dinadins: o lumânare care coboară sub stop și
se închide sus n-are voie să apuce să declanșeze o intrare.

**Stopul și ieșirea pe urmărire în aceeași lumânare → se ia stopul.** Aceeași
convenție pesimistă ca la botul vechi: din lumânări de 15 minute nu se poate ști
care a fost primul, iar presupunerea favorabilă minte în favoarea strategiei.

## Două consecințe acceptate deliberat

**Armare și intrare în aceeași lumânare sunt posibile.** O lumânare cu wick adânc
care se închide sus poate arma setarea *și* declanșa intrarea. Exemplu: prag 800,
coborâre minimă 20, revenire 20 — lumânare cu minim 775 și închidere 798. Minimul
armează, pragul efectiv devine 795, închiderea e peste el. E exact revenirea
căutată, doar comprimată într-un sfert de oră.

**Lumânarea în formare nu declanșează nimic** în afară de stop. Nu e o pierdere:
orice ar face ea acum e reevaluat la închidere, iar atunci maximul ei intră
oricum în socoteală. Singurul efect e că recunoașterea are o întârziere de cel
mult 15 minute — și asta e chiar scopul.

## Băncile

| Banca | Pornește cu | Se măsoară în | Ce face la intrare |
|---|---|---|---|
| LONG | **1000 USDC** | USDC | cumpără ZEC cu tot soldul |
| SHORT | **1 ZEC** | ZEC | vinde tot ZEC-ul pe USDC |

Aceeași convenție ca la botul vechi: **se afișează mereu în moneda de măsură**,
nu în cea ținută. Cifre rotunde, ca randamentul să se citească direct din sold.

Băncile sunt **proprii uneltei**, separate de cele ale botului cu triunghiuri.
Altfel n-ar mai ști nimeni care strategie a câștigat, și s-ar putea bloca una pe
alta.

**O setare activă per bancă**, dar cele două bănci merg în paralel: poți fi în
long și în short în același timp, pe setări diferite. O setare nouă se poate face
doar după ce se închide cea curentă.

Comision **0,075% pe parte**, ca peste tot în proiect.

## Ritmul

Cron **la fiecare minut**, ca motorul vechi. La fiecare rulare:

- aduce ultimele lumânări de 15m de la Binance;
- procesează **toate lumânările închise nevăzute încă**, în ordine cronologică —
  dacă cronul a stat oprit două ore, le recuperează pe toate opt, una câte una,
  în ordinea în care s-au întâmplat;
- verifică stopul și pe lumânarea în formare.

Fiecare setare ține minte ultima lumânare procesată, deci o lumânare nu poate fi
judecată de două ori. O setare nouă pornește de la **prima lumânare care se
deschide după crearea ei** — una începută mai devreme conține minime de dinainte
ca utilizatorul să fi decis ceva.

## Interfața

Fără grafice, cerut explicit. Pagina arată cifre: soldul fiecărei bănci, setarea
activă cu starea ei, poziția deschisă cu distanța până la praguri, și istoricul.

Două butoane care la o unealtă manuală se simt lipsind: **anulare** a unei setări
care așteaptă, și **„ieși acum"** pe o poziție deschisă. Praguri, țintă și
urmărire se pot modifica și cât ești în poziție.

**„Ieși acum" nu se execută instantaneu, ci la următoarea rulare a cronului** (cel
mult 60 de secunde), și e deliberat: **singurul cod care atinge banii e motorul.**
Dacă ar închide și API-ul poziții, ar exista două scrieri care se pot ciocni pe
aceeași poziție. Un singur scriitor e mai valoros decât 60 de secunde.

## Ce nu face

Nu desenează, nu caută semnale, nu trimite ordine reale, nu trimite mailuri, nu
lucrează pe alt simbol decât ZECUSDC. Bani fictivi.

## Structura

```
public/unealta/index.html · unealta.js      pagina (fără grafice)
public/api/unealta.php                      citește starea, scrie setările
motor/unealta.php                           motorul, din cron la un minut
motor/probe/unealta-matematica.php          probele, fără bază de date
baza-de-date/migratii/2026-09-19-unealta.sql
```

Șase tabele noi, cu prefix `u_`: `u_setari`, `u_pozitii`, `u_banci`,
`u_miscari`, `u_lumanari_15m`, `u_jurnal`.

Regulile de decizie stau singure, în `motor/unealta-reguli.php`: funcții pure,
fără bază de date și fără rețea. Le folosesc toate trei locurile — motorul care
decide, API-ul care validează, probele care verifică. Scrise de trei ori, s-ar
desincroniza tăcut, iar o regulă inversată între long și short e exact felul de
greșeală care nu se vede până nu costă bani. API-ul le cere pe cale absolută,
din `/home/marcelpa/autobot-motor/`, la fel cum cere configurarea.

**Probele: `php motor/probe/unealta-matematica.php` — 88 de probe**, fără bază de
date. Verifică simetria long/short, monotonia pragurilor („doar urcă”, „doar
coboară”), convenția pesimistă, expirarea și validările. Se rulează după orice
atingere a regulilor.

## Capcana de la parolă

Utilizatorul a ales parolă pe **tot** site-ul (rezolvă și etapa 3 a botului vechi).

⚠ cPanel → Directory Privacy scrie liniile de autentificare în `.htaccess`-ul din
document root — **exact fișierul pe care deploy-ul îl suprascrie** cu
`public/.htaccess` din repo. Configurată doar din cPanel, parola dispare la primul
deploy, **fără ca nimic să anunțe**. Liniile `AuthType` / `AuthName` /
`AuthUserFile` / `Require valid-user` trebuie să stea în `public/.htaccess`, în
git. Fișierul `.htpasswd` rămâne unde l-a pus cPanel, în afara document root-ului,
deci el supraviețuiește.
