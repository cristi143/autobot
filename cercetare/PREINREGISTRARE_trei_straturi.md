# Preînregistrare — strategia în trei straturi (coș ZEC + BTC + ETH)

Scrisă pe 12.11.2026, ÎNAINTE de prima rulare.

## Date
Ore (close) din lumânări 1m, ZECUSDT / BTCUSDT / ETHUSDT, 2019-08 → 2026-11.
Comision 0,075% pe valoarea tranzacționată, la fiecare cumpărare și vânzare. Fără
levier: expunerea totală ≤ 100%. Banii neinvestiți nu câștigă nimic.

## Strategiile comparate
- **S0 — deținere simplă**: 1/3 din capital în fiecare monedă la start, nimic după.
- **S1 — 50/50 fix**: 50% coș (1/6 fiecare), 50% bani; reechilibrare la abatere > 5 pp.
- **S1m — fix la aceeași expunere medie ca S3** pe perioada respectivă.
- **S2 — straturile 1+2**: ținta pe monedă = 1/3 × min(1, σ* / σ_i), unde σ_i =
  volatilitatea anualizată din ultimele 168 de randamente orare. Ținta se recalculează
  zilnic la 10:00 UTC (oră calmă). Reechilibrare la abatere > 5 pp din capital.
- **S3 — straturile 1+2+3**: ca S2, plus: când o monedă din coș dă semnalul confirmat
  (rang vol24 ≥ 0,8 față de 720 ore ȘI cădere pe 24h), ținta ei devine max(ținta S2, 1/3)
  timp de 24 de ore; tranzacția se face imediat la semnal și la expirare.
- **S4 — variantă**: S3 plus excepția pe celelalte 20 de perechi: 5% din capital pe
  pereche timp de 24h la semnal, cel mult 30% în total pe astfel de poziții.
  Dacă expunerea totală > 100%, se micșorează proporțional.

## Parametru ales pe antrenare
σ* ∈ {40%, 60%, 80%, 100%}. Se alege pe **2019-08 → 2022-12** după raportul
randament anual / cea mai mare scădere (Calmar). Se raportează toate, pe ambele perioade.

## Criteriul de succes, pe 2023-01 → 2026-11, cu σ* ales pe antrenare
1. Calmar(S3) > Calmar(S0) **și** > Calmar(S1m)
2. Calmar(S3) > Calmar(S2) — excepția de după panică adaugă ceva

(1) ratat → straturile nu ajută față de a ține sau de a ține mai puțin.
(2) ratat → excepția nu merită complicația în portofoliu.
