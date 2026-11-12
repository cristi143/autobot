# Cercetarea din spatele botului (12.11.2026)

Codul care a produs rezultatele din `docs/plan-bot.md`, păstrat ca regulile
botului să poată fi verificate oricând față de el. **Fără date**: totul se
generează din `../../historical_data/` (7.5 GB, în afara git-ului).

| Fișier | Ce e |
|---|---|
| `PREINREGISTRARE_vol_cadere.md` | regulile testului „vol mare după cădere”, scrise înainte de rulare |
| `volcad.py` | testul de mai sus pe 20 de perechi nevăzute (confirmat: +0,54%/tranz., t = 4,5) |
| `PREINREGISTRARE_trei_straturi.md` | regulile testului de portofoliu, scrise înainte de rulare |
| `straturi.py` | coșul ZEC+BTC+ETH: deținere, 50/50, ținta de volatilitate, excepția |
| `s5.py` | 50/50 + excepția (banca B a botului) |
| `gen_echiv.py` | datele de referință pentru `motor/probe/bot-echivalenta.php` |

Rulare (pypy3, din acest dosar):

```bash
pypy3 gen_echiv.py          # scrie echiv_inchideri.csv, echiv_semnale.csv, echiv_referinta.json
php ../motor/probe/bot-echivalenta.php .
```

Prima rulare agregă lumânările de 1m pe ore în `hourly/` (câteva minute).
