# autobot.dunitru.ro — ghid de deploy

Sistem identic cu `dunitru.ro` și `marcel-parcel.ro`: GitHub → cPanel Git Version
Control → Deploy. Deocamdată site static („în construcție").

---

## 1. Unde trăiește

- **Cod sursă:** GitHub `cristi143/autobot`
- **Producție:** ClausWeb, cont cPanel `marcelpa` (autobot.dunitru.ro e **subdomeniu**)
  - document root: `/home/marcelpa/autobot.dunitru.ro/`
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
└── public/         ← TOT ce ajunge pe autobot.dunitru.ro
    ├── index.html
    ├── style.css
    ├── favicon.svg
    ├── robots.txt  (noindex — aplicație privată)
    └── .htaccess
```

Când apare motorul botului, va sta într-un folder frate (ex. `engine/`) care **nu**
se copiază pe server — deploy-ul atinge doar `public/`.
