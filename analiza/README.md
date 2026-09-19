# Desenul orb — cum se folosește

Unealtă de cercetare, **locală**. Nu are legătură cu site-ul, nu se publică, nu
cere deploy. Răspunde la întrebarea a doua din `../docs/proba-triunghiurilor.md`:
*alege utilizatorul mai bine decât media triunghiurilor care se calificau?*

## Ce faci

1. Deschizi **`desen-orb.html`** cu dublu clic (orice browser).
2. Treci prin cele 60 de ferestre. Pentru fiecare: ori tragi un triunghi (patru
   clicuri — două pentru linia de sus, două pentru cea de jos), ori apeși
   **„Nimic aici"**.
3. La final apeși **„Descarcă ce am desenat"** → `desene.json`.

Progresul se salvează singur după fiecare fereastră, în browser. Poți închide și
relua de unde ai rămas.

## Regula de disciplină

**Se desenează toate cele 60 înainte de a vedea vreun rezultat.** Dacă te uiți la
primele zece și apoi continui, restul nu mai sunt curate: ai învățat ceva din
ele, iar proba măsoară tocmai ce știai dinainte.

Din același motiv, **`cheie.json` nu se deschide** până nu e gata. Acolo scrie ce
simbol și ce perioadă era fiecare fereastră.

## De ce e „orb"

- fără numele monedei, fără date calendaristice;
- prețurile rescalate, prima închidere vizibilă = 100;
- ferestrele sunt din **2019–2023**, ca să nu le poți ține minte;
- **nu există nimic la dreapta** — linia punctată e marginea prezentului.

Lățimea ferestrei variază: **100, 200 sau 400 de lumânări**, aproximativ egal.
Asta nu e decor — e testul care desparte „unghi vizual" de „timp până la
eveniment". La triunghiul #7 te-ai plâns că vârful e prea aproape, deși erai
zoomat și vizual părea departe. Aceleași geometrii la zoomuri diferite ne spun
după care dintre cele două te iei de fapt.

## Refuzurile contează la fel de mult

Sistemul de până acum păstra doar triunghiurile desenate, deci n-avea din ce
învăța — numai exemple pozitive. „Nimic aici" e jumătatea care lipsea. Dacă poți,
scrie și de ce: e cel mai ieftin loc unde apar criterii pe care un interviu nu
le scoate. (Notele tale de la desenare au adus deja două.)

## Ce se întâmplă după

Pentru fiecare fereastră se compară alegerea ta cu **toate** triunghiurile care
se calificau acolo după regula din `../docs/regula-desenului.md`, apoi se
simulează cu regulile de ieșire ale sistemului viu.

Ținta e cunoscută: populația dă **+0,062%** brut pe tranzacție, iar comisionul e
**0,150%**. Ca să merite, alegerea ta trebuie să înmulțească de ~2,5 ori
avantajul mediu.

## Fișiere

| | |
|---|---|
| `desen-orb.html` | unealta, cu datele înăuntru (~434 KB) |
| `desen-orb.sablon.html` | șablonul din care se generează |
| `pregateste-ferestre.py` | generatorul (sămânța 20260920 dă exact ferestrele astea) |
| `cheie.json` | ce era fiecare fereastră — **nu se deschide înainte** |
| `desene.json` | ce ai desenat; îl produci tu din pagină |
