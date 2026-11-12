# Botul automat pe bani fictivi — plan APROBAT

Scris pe 12 noiembrie 2026, la cererea utilizatorului, și aprobat în aceeași zi,
cu deciziile din §11.

## 1. Ce e și ce nu e

Un motor care rulează singur, din cron, și execută **reguli fixe, deja testate pe
7 ani de date** — pe bani fictivi. Nu e Claude în buclă și nu ghicește nimic: aceeași
situație dă mereu aceeași decizie, iar fiecare decizie se poate verifica după.

De ce așa, și nu „Claude spune când să intri”: direcția nu se poate prezice (toate
testele din 12.11.2026), iar o judecată care nu poate fi rulată pe date istorice
nu poate fi nici verificată. O regulă scrisă, da.

**Ce face:** ține trei bănci fictive pe un coș ZEC + BTC + ETH, le reechilibrează
după reguli, scrie fiecare decizie cu motivul ei.

**Ce nu face:** nu trimite ordine reale, nu are chei API, nu tranzacționează alt
coș, nu se adaptează singur. Trecerea pe bani reali e un proiect separat, cu
confirmare explicită (vezi §10).

## 2. De unde vin regulile

| Rezultat | Unde e documentat |
|---|---|
| 50/50 fix cu reechilibrare: cel mai bun randament/cădere pe 2019–22 **și** 2023–26 | memorie `trei-straturi-cos`, `baza-automatizare-50-50` |
| „Vol mare după cădere”: confirmat preînregistrat pe 20 de perechi nevăzute (+0,54%/tranz., t = 4,5) | memorie `vol-mare-dupa-cadere` |
| …dar în portofoliu e o **primă de risc**, nu avantaj: crește randamentul și căderile împreună; slăbit în 2026 | idem |
| Ținta de volatilitate **strică** — nu intră în bot | `trei-straturi-cos` |

Botul **reproduce exact** regulile din simulare, ca rezultatele de acum înainte să
se poată pune lângă cele istorice. Orice abatere de la simulare e o greșeală, nu o
îmbunătățire.

## 3. Cele trei bănci

Câte **1000 USDC** fictivi fiecare, pornite în aceeași oră.

| Banca | Regula | De ce există |
|---|---|---|
| **A — fix** | 50% coș (1/6 pe monedă), 50% USDC. Reechilibrare când o monedă se abate cu > 5 pp din capital. | Baza: ce a mers cel mai bine istoric |
| **B — fix + excepție** | Ca A, plus: la semnalul de panică pe o monedă, ea urcă la **1/3** din capital timp de **24 h**, apoi revine la 1/6. | Testul înainte al singurului efect confirmat |
| **C — pasiv** | 1/3 în fiecare monedă la pornire, apoi nimic. | Etalonul: fără el, A și B n-au cu ce se compara |

Proporția 50% e o decizie de risc a utilizatorului, nu o predicție (§11).

## 4. Regulile, întregi

**Datele:** lumânări de **1 oră**, închise, de la Binance REST (`/api/v3/klines`),
pentru BTCUSDC, ETHUSDC, ZECUSDC. Publice, fără cheie API.

**Semnalul de panică** (identic cu testul preînregistrat), la închiderea fiecărei ore:
- `vol` = abaterea standard a ultimelor 24 de randamente log orare
- `rang` = locul lui `vol` printre valorile din cele **720 de ore** dinainte (0…1)
- **semnal** dacă `rang ≥ 0,8` **și** prețul de acum < prețul de acum 24 h

**Ținta pe monedă:**
- A: 1/6 din capitalul băncii, mereu
- B: 1/3 dacă moneda are o excepție activă (semnal în ultimele 24 h), altfel 1/6
- Excepția nu se reînnoiește cât e activă; după expirare, un semnal nou o repornește,
  **începând cu ora de după expirare**. Un semnal chiar în ora expirării e ignorat —
  așa a rulat simularea, iar botul o reproduce, nu o „repară”.
- Ordinea în care se tranzacționează monedele într-o oră: BTC, ETH, ZEC; capitalul
  pe care se calculează țintele e cel de la începutul orei (înaintea comisioanelor).

**Când se tranzacționează:**
- la începutul și la sfârșitul unei excepții: întotdeauna, până la țintă;
- altfel: doar dacă valoarea monedei se abate de la țintă cu **> 5% din capital**.

**Execuția (fictivă):**
- prețul: cel de pe piață în clipa rulării (ask la cumpărare, bid la vânzare, din
  `/api/v3/ticker/bookTicker`) — deci include spread-ul;
- se scrie alături și close-ul orei, prețul pe care l-a folosit simularea, ca să
  se vadă cât costă în realitate diferența;
- comision **0,075% pe parte**, ca peste tot în proiect;
- **fără levier**: într-o oră se fac întâi vânzările, apoi cumpărările, iar o
  cumpărare nu cheltuie mai mult USDC decât există (e limitată și scrisă ca atare).

**Singura abatere de la simulare, găsită la proba de echivalență:** simularea băncii
B a avut, fără intenție, USDC negativ în 4% din ore (până la −6% din capital) —
când mai multe excepții erau active și monedele urcaseră în bandă. Un levier mic,
imposibil pe o bursă spot. Botul nu-l are. Pe 2023-01 → 2026-08: B face +460,9%
fără levier față de +457,6% cu; A identic; C +518,2% față de +518,6% (comisionul
de pornire iese din capital, nu peste el).

## 5. Ritmul

Cron **o dată pe oră, la minutul 2** (`2 * * * *`) — după ce lumânarea orei s-a
închis la Binance.

**Ore pierdute:** dacă cronul a stat oprit, botul **nu** tranzacționează retroactiv
la prețuri vechi — ar fi o ficțiune pe care banii reali n-o permit. Judecă doar ora
cea mai recentă, iar orele sărite le scrie în jurnal ca *pierdute*, cu semnalele
pe care le-ar fi avut. O excepție a cărei expirare a căzut într-o oră pierdută se
închide la prima rulare.

Asta diferă de motorul uneltei, care recuperează lumânări una câte una — acolo
deciziile sunt pe niveluri fixe, aici sunt pe preț de piață.

## 6. Ce se scrie (bază de date, prefix `b_`)

Aceeași bază `marcelpa_autobot`, tabele separate de `u_*`.

| Tabel | Ce ține |
|---|---|
| `b_lumanari_1h` | lumânările orare (open_time, close) pe monedă — 750+ de ore, cerute pentru rang |
| `b_evaluari` | **fiecare oră, fiecare monedă:** vol, rang, randament 24 h, semnal da/nu |
| `b_banci` | soldul USDC al fiecărei bănci |
| `b_detineri` | cantitatea din fiecare monedă, pe bancă |
| `b_tranzactii` | fiecare cumpărare/vânzare: bancă, monedă, cantitate, preț, close-ul orei, comision, **motivul** |
| `b_exceptii` | excepțiile băncii B: monedă, pornire, expirare, rezultat |
| `b_evolutie` | valoarea fiecărei bănci, la fiecare oră |
| `b_jurnal` | fiecare rulare: durată, ore pierdute, erori |

Motivul e scris în cuvinte, de exemplu: *„B · cumpărat ETH până la 1/3: semnal de
panică (vol în cincimea de sus, −6,1% în 24 h), expiră mâine la 14:00”*.

## 7. Pagina

`autobot.dunitru.ro` — doar cifre, ca unealta:
- cele trei bănci una lângă alta: valoare, randament, cea mai mare scădere;
- ce deține fiecare acum și cât din capital;
- excepțiile active și când expiră;
- ultimele decizii, cu motivul;
- starea semnalului pe fiecare monedă (rangul de acum), ca să se vadă când se apropie;
- motorul: ultima rulare, ore pierdute.

**Fără butoane care schimbă ceva.** Botul nu primește ordine de la pagină — altfel
n-ar mai fi testul unei reguli.

## 8. Cum se verifică faptul că botul face ce a făcut simularea

Fără asta, orice rezultat al botului e de nedistins de o greșeală de cod.

1. **Regulile ca funcții pure** în `motor/bot-reguli.php` (semnal, rang, ținte,
   decizia de tranzacționare) — fără bază de date, fără rețea, ca la unealtă.
2. **Probe** în `motor/probe/bot-matematica.php`: cazuri mici, socotite de mână.
3. **Proba de echivalență** (`motor/probe/bot-echivalenta.php`, trecută pe 12.11.2026:
   0 diferențe în 95 232 de semnale, băncile identice la a 9-a zecimală): se rulează funcțiile PHP peste aceleași ore istorice
   2023–2026 pe care a rulat simularea în Python și se compară, oră cu oră,
   semnalele și țintele. Trebuie să iasă **identic**. Abia apoi se pornește cronul.

## 9. Ce se poate afla din test, sincer

Testul înainte e bun pentru două lucruri: **mecanica** (botul face ce trebuie, cât
costă spread-ul și întârzierea față de simulare) și **învățarea** (un jurnal real,
decizie cu decizie).

**Nu e bun pentru a dovedi repede excepția.** Socoteala: ~120 de semnale pe an pe
cele trei monede, abaterea pe o tranzacție de 24 h ≈ 5–8%, deci după un an eroarea
mediei e ≈ ±0,6% — mai mare decât efectul (+0,4%). Un an de bot poate infirma o
prăbușire clară, dar nu poate confirma efectul.

**Preînregistrat acum, pentru citirea rezultatelor:**
- la **3 luni**: verificare de mecanică (echivalență, spread, ore pierdute) — nu de
  randament;
- la **12 luni**: B față de A — randament, cea mai mare scădere, și media brută a
  excepțiilor cu marja ei. Se raportează oricum ar ieși.
- **nu se schimbă regulile pe parcurs.** O regulă schimbată la 4 luni repornește
  numărătoarea de la zero.

## 10. Siguranța

- Fără chei API, fără ordine reale. Regula din `CLAUDE.md` rămâne: cheile nu ajung
  nici în git, nici pe ClausWeb.
- Parola site-ului rămâne amânată (date fictive) — **se rediscută înainte de orice
  pas spre bani reali**, cum a cerut utilizatorul.
- Trecerea pe bani reali, dacă va fi: proiect separat, cu testnet întâi și
  confirmare explicită la fiecare pas.
- Niciun proces Claude care rulează permanent: analizele jurnalului se fac când le
  cere utilizatorul.

## 11. Decizii — luate de utilizator pe 12.11.2026

1. **Botul ia pagina principală; unealta manuală se scoate.** Utilizatorul va avea
   mai târziu o unealtă nouă, separată. Scoaterea înseamnă: pagina, API-ul,
   `motor/unealta*.php`, cronul ei de la un minut; tabelele `u_*` rămân în bază
   până la o decizie separată (date care nu se pot reface).
2. **Proporția crypto: 50%**, cum a fost testat.
3. **Fără notificări** în prima versiune.
4. **Perechi USDC:** BTCUSDC, ETHUSDC, ZECUSDC.

## 12. Ordinea de lucru

1. `bot-reguli.php` + probe + proba de echivalență cu simularea
2. migrația `baza-de-date/migratii/2026-11-…-bot.sql` (rulată manual, din phpMyAdmin)
3. `motor/bot.php` + rulare de mână, o dată, cu ieșirea arătată utilizatorului
4. pagina + API-ul de citire; scoaterea uneltei manuale (§11.1)
5. cron-ul orar, adăugat de utilizator în cPanel (comanda exactă în `DEPLOY.md`);
   tot atunci, utilizatorul șterge cronul de la un minut al uneltei
6. o săptămână de urmărire a mecanicii, apoi lăsat în pace
