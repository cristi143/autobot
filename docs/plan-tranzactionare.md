# Plan de tranzacționare — deciziile, pe scurt

Planul complet, cu explicații și diagramă:
**https://claude.ai/code/artifact/83a911c9-a341-441a-a73d-7fd98213a385**

Fișierul ăsta reține doar deciziile de care are nevoie implementarea, ca să nu se
redescopere. Stabilit pe 2 septembrie 2026. **Nimic din el nu e încă implementat** —
site-ul are deocamdată doar graficul.

## Ce construim

Un singur ecran care înlocuiește dus-întorsul TradingView ↔ Binance: graficul live
ZECUSDC 1h, pe care utilizatorul desenează linii de trend, iar sistemul deschide
poziții simulate pe închiderea lumânării.

## Unde rulează: tot pe ClausWeb

**Revizuire față de ce scria înainte în DEPLOY.md §3.** Afirmația „motorul nu poate
rula pe shared hosting" e adevărată pentru strategii care reacționează în secunde.
**Nu se aplică aici:** pe 1h e nevoie de o singură verificare pe oră, adică un cron —
exact ce are cPanel. Fără VPS, fără server local.

Depinde de două lucruri de verificat în cPanel înainte de a începe:
- **Cron Jobs** (secțiunea Advanced)
- extensiile PHP **`curl`** și **`openssl`** (Select PHP Version → Extensions)

Dacă lipsesc, planul se mută pe varianta locală.

## Arhitectura

- **Browser** → desenează linii, le salvează prin API; primește prețul live direct
  de la Binance prin WebSocket, **doar pentru afișare — nu declanșează nimic**.
- **Cron orar** (`1 * * * *`) → cere lumânarea închisă de la Binance REST, o compară
  cu liniile, declanșează semnale, mișcă băncile simulate.
- **MySQL** → sursa de adevăr: linii, lumânări, semnale, tranzacții, solduri.

Sistemul funcționează cu browserul închis.

## Regula de semnal

O linie = două puncte `(t1,p1)`, `(t2,p2)`, prelungite drept spre dreapta.
La închiderea fiecărei lumânări de 1h:

- **LONG**: lumânare verde (`close > open`) **și** `close` peste prețul liniei
- **SHORT**: lumânare roșie (`close < open`) **și** `close` sub prețul liniei

**Semnalul se declanșează doar la schimbarea de stare**, nu la fiecare lumânare care
îndeplinește condiția — altfel apar zeci de intrări identice și comisioane degeaba.

## Cele două bănci — atenție la capcană

Pe spot, „short" = să nu deții ZEC. **Dacă ambele bănci reacționează la aceleași
linii, fac exact aceleași tranzacții în același moment.** Nu sunt două strategii;
sunt aceeași strategie măsurată în două monede:

| Banca | Pornește cu | Se măsoară în | Răspunde la |
|---|---|---|---|
| LONG | 500 USDC | USDC | bate „stau pe USDC"? |
| SHORT | 500 USDC în ZEC | **ZEC** | adună mai mult ZEC decât „cumpăr și țin"? |

A doua întrebare e cea ratată de obicei: o strategie poate câștiga în USDC și
totuși să te lase cu mai puțin ZEC decât dacă nu făceai nimic.

Dacă se vor două strategii cu adevărat diferite, fiecare bancă trebuie să aibă
propriile linii (suport pentru long, rezistență pentru short).

## Realismul simulării — obligatoriu de la început

- **comision 0,1% pe tranzacție**, ambele sensuri (tarif spot Binance)
- **execuție la deschiderea lumânării următoare**, nu la close-ul care a dat semnalul
- **comparație permanentă cu „nu fac nimic"** (hold USDC / hold ZEC)

Fără astea, simularea minte în favoarea strategiei.

## Etape

0. verificare cPanel (cron + extensii PHP)
1. bază de date + API + desenare linii pe grafic, salvate
2. cron orar + motor de semnale + cele două bănci simulate + jurnal
3. protecție cu parolă a paginii (cPanel, `.htpasswd`)
4. analiza liniilor desenate → model matematic
5. bani reali — doar după luni de simulare

## Etapa 4: modelul matematic

Utilizatorul nu poate explica verbal cum trage liniile. Abordare, după 20–30 de
linii salvate: detectare de pivoți (vârfuri/văi), verificat de care se lipesc
capetele liniilor, măsurat fereastra de timp și toleranța, apoi validare oarbă pe
o perioadă nevăzută.

## Decizii încă neconfirmate de utilizator

1. băncile reacționează la aceleași linii (propus) sau la linii separate?
2. la mai multe linii active: orice linie dă semnal (propus) sau fiecare linie are rol declarat?
3. intrare cu toată banca (propus) sau fracționat?
4. stop loss: nu deocamdată (propus)

## Reguli ferme

- **Cheile API Binance nu ajung în git niciodată.** La etapa 5, pe server: doar spot,
  fără retrageri, IP-ul serverului pe listă albă, fișier în afara zonei publice.
- Datele publice de piață nu au nevoie de chei — de aceea graficul live merge din
  browser. Orice endpoint semnat rămâne exclusiv pe server.
