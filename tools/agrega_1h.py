"""Agregă lumânările de 1 minut în lumânări de 1 oră, gata de afișat pe site.

Citește CSV-urile zilnice descărcate de download_historical.py:
    historical_data/<SIMBOL>/candles_1m/YYYY-MM-DD_candles_1m.csv
și scrie un singur JSON:
    public/data/<SIMBOL>-1h.json

Regula de agregare, standard pentru lumânări:
    open   = open-ul primei lumânări din oră
    high   = maximul tuturor
    low    = minimul tuturor
    close  = close-ul ultimei lumânări din oră
    volume = suma volumelor

Orele incomplete (minute lipsă) sunt păstrate — datele Binance au goluri reale,
iar ascunderea lor ar minți graficul. Ora curentă, încă neînchisă, e eliminată.

Utilizare:
    python3 tools/agrega_1h.py ZECUSDC
    python3 tools/agrega_1h.py ZECUSDC --date-dir /alta/cale/historical_data
"""

import argparse
import csv
import json
import os
import sys
from collections import OrderedDict

ORA_MS = 3_600_000

AICI = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(AICI)
DATE_IMPLICIT = os.path.join(REPO, "..", "historical_data")


def citeste_minutele(dir_simbol):
    """Întoarce {open_time: (open, high, low, close, volume)}, deduplicat."""
    dir_1m = os.path.join(dir_simbol, "candles_1m")
    if not os.path.isdir(dir_1m):
        sys.exit(f"Nu găsesc {dir_1m}")

    minute = {}
    fisiere = sorted(f for f in os.listdir(dir_1m) if f.endswith(".csv"))
    if not fisiere:
        sys.exit(f"Niciun CSV în {dir_1m}")

    for nume in fisiere:
        with open(os.path.join(dir_1m, nume), newline="") as f:
            for rand in csv.DictReader(f):
                try:
                    t = int(rand["open_time"])
                    minute[t] = (
                        float(rand["open"]), float(rand["high"]),
                        float(rand["low"]), float(rand["close"]),
                        float(rand["volume"]),
                    )
                except (KeyError, ValueError):
                    continue  # rând stricat — îl sărim, nu oprim tot

    return minute, len(fisiere)


def agrega(minute):
    """Grupează pe ore. Se bazează pe parcurgerea în ordine cronologică."""
    ore = OrderedDict()
    for t in sorted(minute):
        o, h, l, c, v = minute[t]
        cheie = (t // ORA_MS) * ORA_MS
        if cheie not in ore:
            ore[cheie] = {"time": cheie // 1000, "open": o, "high": h,
                          "low": l, "close": c, "volume": v, "minute": 1}
        else:
            ora = ore[cheie]
            ora["high"] = max(ora["high"], h)
            ora["low"] = min(ora["low"], l)
            ora["close"] = c          # ultima citită = cea mai recentă
            ora["volume"] += v
            ora["minute"] += 1
    return ore


def main():
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("simbol", help="ex. ZECUSDC")
    p.add_argument("--data-dir", default=DATE_IMPLICIT,
                   help="folderul historical_data (implicit: ../historical_data)")
    args = p.parse_args()

    simbol = args.simbol.upper()
    dir_simbol = os.path.join(args.data_dir, simbol)
    minute, n_fisiere = citeste_minutele(dir_simbol)
    ore = agrega(minute)

    if not ore:
        sys.exit("Nicio oră de scris.")

    # Ultima oră e aproape sigur incompletă (încă se tranzacționează în ea).
    ultima = next(reversed(ore))
    if ore[ultima]["minute"] < 60:
        ore.pop(ultima)

    lumanari = [{k: round(o[k], 8) if isinstance(o[k], float) else o[k]
                 for k in ("time", "open", "high", "low", "close", "volume")}
                for o in ore.values()]

    dir_iesire = os.path.join(REPO, "public", "data")
    os.makedirs(dir_iesire, exist_ok=True)
    cale = os.path.join(dir_iesire, f"{simbol}-1h.json")
    with open(cale, "w") as f:
        json.dump({"symbol": simbol, "interval": "1h", "candles": lumanari},
                  f, separators=(",", ":"))

    incomplete = sum(1 for o in ore.values() if o["minute"] < 60)
    print(f"{simbol}: {n_fisiere} fișiere 1m -> {len(minute):,} minute -> "
          f"{len(lumanari):,} ore")
    print(f"  perioadă : {lumanari[0]['time']} .. {lumanari[-1]['time']} (unix)")
    print(f"  ore cu minute lipsă: {incomplete}")
    print(f"  scris    : {cale} ({os.path.getsize(cale) / 1024:.0f} KB)")


if __name__ == "__main__":
    main()
