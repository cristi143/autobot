# autobot.dunitru.ro — ghid de deploy

Sistem identic cu `dunitru.ro` și `marcel-parcel.ro`: GitHub → cPanel Git Version
Control → Deploy. Deocamdată site static („în construcție").

---

## 1. Unde trăiește

- **Cod sursă:** GitHub `cristi143/autobot`
- **Producție:** ClausWeb, cont cPanel `marcelpa` (autobot.dunitru.ro e **subdomeniu**)
  - document root: `/home/marcelpa/autobot.dunitru.ro/`, sau
    `/home/marcelpa/autobot.dunitru.ro/public_html/` — **deploy-ul îl detectează singur**
    (dacă există subfolderul `public_html`, acela e document root-ul)
  - clona Git a serverului: `/home/marcelpa/repositories/autobot`

### ⚠ De ce document root separat

Deploy-ul lui `dunitru.ro` **golește complet** `/home/marcelpa/dunitru.ro/public_html`.
Dacă subdomeniul ar avea document root-ul înăuntru (ex. `dunitru.ro/public_html/autobot`,
ce propune cPanel implicit), s-ar șterge la fiecare deploy de dunitru.ro.
De aceea folosim arborele separat `/home/marcelpa/autobot.dunitru.ro/`.

---

## 2. Flux de lucru

1. Modificare → commit + push pe `main`
2. cPanel → Git™ Version Control → `autobot` → Manage → **Update from Remote** →
   **Deploy HEAD Commit**
3. Verifici https://autobot.dunitru.ro (Ctrl+F5)

Log: `/home/marcelpa/deploy-autobot.log`

### ⚠ „Update from Remote" a fost găsit stricat (18.09.2026)

Butonul **răspunde „The repository is up-to-date" fără să tragă nimic** — clona de
pe server rămâne pe commitul vechi, iar „Deploy HEAD Commit" publică liniștit
versiunea veche. Descoperit pe `marcel-parcel`, **același cont cPanel**, deci
privește la fel toate site-urile de aici.

Nu e greșeală de configurare: s-au verificat una câte una `.git/config`, fișierele
`.lock`, ștergerea și recrearea repo-ului, tokenul GitHub, cheia SSH de deploy și
rețeaua serverului spre github.com (DNS, 443, 22) — toate în regulă. Un `git fetch`
rulat din cron merge perfect. Povestea completă și tabelul verificărilor:
`../../02_Marcel/marcel-parcel/DEPLOY.md`, secțiunea 4.1.

**Verificarea care nu minte**, din File Manager: data de modificare a
`/home/marcelpa/repositories/autobot/.git/FETCH_HEAD`. Git o rescrie doar după un
fetch reușit — dacă e veche, n-a tras nimic, indiferent ce zice cPanel. Iar în
*Basic Information* compari **HEAD Commit** cu ce ai împins tu.

**Ocolirea, făcută pe 19.09.2026** (o are și `marcel-parcel`): un Cron Job în
cPanel → Advanced → Cron Jobs, la 5 minute, care rulează exact ce refuză butonul:

```
cd /home/marcelpa/repositories/autobot; { date; git fetch origin; git merge --ff-only origin/main; } > /home/marcelpa/git-pull-autobot.log 2>&1
```

- `>` (nu `>>`): log-ul ține doar ultima rulare, deci nu crește la nesfârșit.
- `--ff-only`: dacă ar apărea un commit direct în clona serverului, merge-ul se
  oprește în loc să inventeze o îmbinare.
- log separat de cel al lui `marcel-parcel` (`git-pull.log`), ca să nu se calce.

Deploy-ul rămâne manual, ca până acum — cronul aduce doar codul.

Reverificat pe 19.09.2026, cu un commit nou pe GitHub: butonul tot nu trage. Cronul
a adus commitul în mai puțin de 5 minute.

> **⚠ În aceeași listă de Cron Jobs stă și cronul motorului** (`motor.php`, la fiecare
> minut — vezi `motor/README.md`). Sunt două lucruri fără legătură, unul lângă altul.
> La ștergerea unuia s-a dus din greșeală și celălalt, iar motorul a stat 11 ore fără
> ca nimic să se plângă în afară de banda din panou. Când umbli aici, citește comanda
> întreagă înainte de a apăsa Delete.

---

## 3. Arhitectură — unde rulează botul (important)

**Motorul de tranzacționare NU poate rula pe ClausWeb.** Shared hosting-ul nu are
SSH, nu ține procese pornite permanent și oferă cel mult cron la un minut. Un bot
are nevoie de websocket permanent la Binance și reacție în secunde.

Împărțirea planificată:

| Componentă | Unde rulează | De ce |
|---|---|---|
| Motor bot (strategii, ordine, websocket) | VPS sau calculator local | proces permanent, latență mică |
| Chei API Binance | doar pe mașina motorului, în `.env` | secrete — niciodată pe shared hosting |
| Bază de date (trade-uri, poziții, P&L) | de decis: MySQL cPanel sau pe VPS | |
| Interfață web (dashboard, setări) | ClausWeb, acest repo | doar citește/afișează |

Datele istorice (`../historical_data/`, ~7.5 GB: candles 1m + trades pentru ~30 de
simboluri, cu `download_historical.py`) stau **pe disc local, în afara git-ului** —
vezi `.gitignore`.

---

## 4. Structura repo-ului

```
autobot/
├── .cpanel.yml          rețeta de deploy
├── CLAUDE.md
├── DEPLOY.md            acest fișier
├── README.md
├── baza-de-date/
│   ├── schema.sql       cele 8 tabele
│   ├── config.exemplu.php
│   └── README.md        cum se pregătește baza
├── motor/               → /home/marcelpa/autobot-motor/ (ÎN AFARA webului)
│   ├── motor.php        motorul, rulat din cron orar
│   ├── probe/matematica.php
│   └── README.md
├── tools/
│   └── agrega_1h.py     agregă 1m -> 1h în public/data/
└── public/              → document root
    ├── index.html       graficul + panoul lateral
    ├── grafic.js        lumânări (lightweight-charts, prin CDN)
    ├── rsi.js           panoul RSI de dedesubt
    ├── desen.js         desenarea triunghiurilor
    ├── panou.js         poziție, bănci, istoric
    ├── api/             _comun.php · stare.php · triunghiuri.php
    ├── data/            ZECUSDC-1h.json — istoricul adânc
    ├── style.css, favicon.svg, robots.txt, .htaccess
```

**Motorul nu ajunge în document root.** Deploy-ul îl copiază separat, în
`/home/marcelpa/autobot-motor/`, unde nu e accesibil prin web. Cronul îl cheamă
de acolo.

**Configurarea** (parola bazei, cheia de API) stă în `/home/marcelpa/autobot-config.php`,
cu un nivel deasupra a tot. Nu e în git și nu e accesibilă prin web.

---

## 5. Graficul de lumânări

Pagina principală arată lumânări de 1h în stilul TradingView — chiar biblioteca lor
open-source, `lightweight-charts` v4.2.3, încărcată de pe jsDelivr.

### Cele trei straturi de date

Graficul e continuu de la primul minut de istoric până la lumânarea în formare:

| Strat | Sursă | Acoperă |
|---|---|---|
| **Istoric** | `public/data/<SIMBOL>-1h.json`, commitat | de la începutul datelor tale |
| **Punte** | Binance REST `/api/v3/klines` | golul dintre JSON și prezent |
| **Live** | Binance WebSocket `@kline_1h` | lumânarea curentă, în timp real |

**De ce merge fără server:** Binance permite CORS pe datele publice de piață
(`access-control-allow-origin: *`) și nu cere cheie API pentru ele. Browserul cere
direct. Site-ul rămâne static — nimic de rulat pe ClausWeb.

Puntea se pagineaza singură (1000 de lumânări pe cerere), deci nu contează cât de
vechi e JSON-ul: dacă a rămas în urmă cu o lună sau cu un an, tot se leagă.

**Rezistență la deconectări:** WebSocket-ul se reconectează cu backoff exponențial
(1s, 2s, 4s… max 30s), iar înainte de fiecare reconectare cere prin REST ce s-a
pierdut. La revenirea în tab (`visibilitychange`) se resincronizează, pentru că
browserele suspendă WebSocket-urile în taburile de fundal. Dacă Binance e complet
inaccesibil, graficul rămâne pe istoricul local și indicatorul arată „doar istoric" —
nu se albește pagina.

Indicatorul din antet: **live** (verde, pulsând) · **se conectează / reconectare**
(chihlimbar) · **doar istoric** (gri).

### Panoul RSI de dedesubt

Sub lumânări stă un RSI(14), cu reperele obișnuite: **70** (supracumpărat, roșu),
**30** (supravândut, verde) și 50 punctat la mijloc. Banda dintre 70 și 30 e
umbrită discret — zona în care indicatorul nu spune nimic, ca ieșirile din ea să
sară în ochi. Valoarea exactă se citește în legenda din colț și se colorează când
trece de praguri; cu cursorul pe grafic, arată valoarea lumânării de sub el.

`lightweight-charts` 4.x **nu are panouri** (au apărut în v5), deci panoul e un al
doilea grafic, lipit dedesubt și ținut în pas cu primul. Ce trebuie știut dacă îl
atingi:

- **Alinierea se face pe indici logici, nu pe timp.** Seria de RSI are câte un punct
  pentru fiecare lumânare, inclusiv primele 14, unde RSI-ul încă nu există: acolo se
  trimit puncte goale (doar `time`). Fără ele, indicele 0 al panoului ar cădea peste
  lumânarea 14 și cele două grafice ar fi decalate.
- **Scalele de preț trebuie să aibă aceeași lățime**, altfel zonele de desen diferă
  și graficele se decalează cu câțiva pixeli. `aliniazaScale()` le duce pe amândouă
  la cea mai lată.
- **Axa de timp se desenează o singură dată**, în panoul RSI; graficul de sus o are
  ascunsă (`timeScale.visible: false`).
- **Scala RSI e fixată 0–100** printr-un `autoscaleInfoProvider`. Lăsată să se
  auto-încadreze, liniile de 70 și 30 ar sări de la o fereastră la alta — exact
  reperele care trebuie să stea pe loc.
- Formula e cea a lui Wilder (netezire cu 1/14), ca la TradingView. Se recalculează
  complet doar când apare o lumânare nouă; cât timp se mișcă doar cea în formare,
  se reface un singur punct.

### Cum se regenerează istoricul

JSON-ul commitat e doar baza de istoric — graficul rămâne la zi singur, prin punte
și WebSocket. Îl regenerezi doar când ai descărcat date noi de 1 minut (pentru
backtesting, de exemplu):

```bash
python3 tools/agrega_1h.py ZECUSDC          # -> public/data/ZECUSDC-1h.json
```

Scriptul citește `../historical_data/<SIMBOL>/candles_1m/*.csv`, grupează pe ore
(open = primul open, high = maximul, low = minimul, close = ultimul close,
volume = suma) și elimină ora curentă dacă e incompletă. Rulează în câteva secunde.

După descărcarea de date noi cu `download_historical.py`, rulezi din nou scriptul,
comiți JSON-ul actualizat și dai deploy.

### Cum adaugi alt simbol

1. `python3 tools/agrega_1h.py BTCUSDT`
2. în `public/grafic.js`, schimbi `var SIMBOL = "ZECUSDC";`

(Un selector de simboluri și de interval în pagină e următorul pas firesc —
deocamdată e o singură pereche, deliberat.)

### De ce istoric commitat și nu totul din REST

Binance REST dă maximum 1000 de lumânări pe cerere. Cele ~6.900 de ore de istoric
ar însemna 7 cereri la fiecare încărcare de pagină, cu latență și risc de limitare
de rată. JSON-ul commitat (~620 KB, sub 200 KB după compresia Apache) se încarcă
dintr-o dată și funcționează chiar dacă Binance e inaccesibil. Puntea aduce doar
diferența.
