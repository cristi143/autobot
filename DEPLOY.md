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
├── .cpanel.yml     rețeta de deploy
├── CLAUDE.md
├── DEPLOY.md       acest fișier
├── README.md
├── tools/
│   └── agrega_1h.py    agregă 1m -> 1h, scrie public/data/<SIMBOL>-1h.json
└── public/         ← TOT ce ajunge pe autobot.dunitru.ro
    ├── index.html      graficul de lumânări
    ├── grafic.js       randarea (lightweight-charts de la TradingView, prin CDN)
    ├── style.css
    ├── data/
    │   └── ZECUSDC-1h.json   6.856 lumânări, ~620 KB
    ├── favicon.svg
    ├── robots.txt  (noindex — aplicație privată)
    └── .htaccess
```

Când apare motorul botului, va sta într-un folder frate (ex. `engine/`) care **nu**
se copiază pe server — deploy-ul atinge doar `public/`.

---

## 5. Graficul de lumânări

Pagina principală arată lumânări de 1h în stilul TradingView — chiar biblioteca lor
open-source, `lightweight-charts` v4.2.3, încărcată de pe jsDelivr.

### Cum se regenerează datele

Datele **nu** se citesc live de la Binance; se pregătesc local și se comit ca JSON:

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

(Un selector de simboluri în pagină e următorul pas firesc — deocamdată e o
singură pereche, deliberat.)

### De ce JSON commitat și nu API live

Site-ul e static, iar ClausWeb n-are cum să țină un proces care vorbește cu Binance.
Un JSON de ~620 KB, comprimat de Apache la sub 200 KB, e soluția simplă și robustă.
Când va exista motorul pe VPS, el poate scrie datele direct.
