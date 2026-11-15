# autobot

**Bot automat pe bani fictivi**, pe un coș BTC + ETH + ZEC (perechi USDC, Binance).
Web: [autobot.dunitru.ro](https://autobot.dunitru.ro)

Rulează singur, din cron, o dată pe oră, și execută reguli fixe, testate pe
date istorice 2019–2026. Trei bănci de câte 1000 USDC fictivi:

- **A** — 50% crypto (1/6 pe monedă), reechilibrare la abatere peste 5%
- **B** — ca A, plus excepția după panică: moneda la 1/3 timp de 24 h
- **C** — pasivă, etalonul

Și trei bănci doar pe ZEC, pornite pe 15.11.2026 ([docs/plan-bot-zec.md](docs/plan-bot-zec.md)):

- **D** — 50% ZEC, reechilibrare la abatere peste 5%
- **E** — 30% ZEC, aceeași bandă
- **F** — ca E, plus excepția după panică: ZEC la 55% timp de 24 h

Nu trimite ordine reale, nu are chei API, nu primește comenzi de la pagină.

- Regulile, întregi, și de unde vin: **[docs/plan-bot.md](docs/plan-bot.md)**
- Hosting și deploy: **[DEPLOY.md](DEPLOY.md)**
- Ce ajunge pe web: [`public/`](public/) · motorul, din cron: [`motor/`](motor/)

## Probe

```bash
php motor/probe/bot-matematica.php                 # 50 de probe, fără bază de date
php motor/probe/bot-echivalenta.php <dosar>        # regulile față de simularea din Python
php motor/probe/zec-matematica.php                 # 33 de probe pentru D, E, F
php motor/probe/zec-echivalenta.php <dosar>        # D, E, F față de cercetare/zec_singur.py
```

Proba de echivalență cere datele de referință generate de simulare (nu sunt în
git — derivă din `../historical_data/`). Vezi capul fișierului.

## Probă locală, cap-coadă

Motorul și API-ul acceptă `AUTOBOT_CONFIG` (calea configurării) și `db.dsn`
în configurare, deci se pot rula pe SQLite, cu date reale de la Binance:

```bash
# schema SQLite = migrația MySQL fără ENGINE/USE/SET și cu INTEGER PRIMARY KEY AUTOINCREMENT
AUTOBOT_CONFIG=/cale/config.php php motor/bot.php
cd public && AUTOBOT_CONFIG=/cale/config.php php -S 127.0.0.1:8765
```
