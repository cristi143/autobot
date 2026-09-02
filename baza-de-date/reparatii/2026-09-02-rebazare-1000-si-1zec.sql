-- ============================================================================
-- Rebazare: 1000 USDC în banca de long, 1 ZEC în cea de short.
--
-- DE CE
-- Cifrele rotunde se citesc direct: cu 1 ZEC la pornire, un sold de 1,0086 ZEC
-- înseamnă +0,86% fără niciun calcul. Cu 0,60787317 nu însemna nimic la prima
-- vedere.
--
-- CE PĂSTRĂM
-- Tranzacția deja făcută rămâne în evidență. Nu ștergem nimic: restatăm doar
-- punctele de pornire și recalculăm soldurile aplicând aceleași randamente.
-- Rândurile vechi din `miscari` rămân pe baza de 500 — așa s-au întâmplat — iar
-- corectura își lasă propria urmă.
--
-- ÎNAINTE DE ASTA, în /home/marcelpa/autobot-config.php:
--     'capital_initial' => 1000.0,
-- Altfel banca de long își compară cei 1000 cu vechea pornire de 500 și arată
-- un randament de +100%.
--
-- Se rulează în phpMyAdmin, cu baza marcelpa_autobot selectată în stânga.
-- ============================================================================

-- ------------------------------------------------------------ 1. ce va ieși

SELECT
    1000.0 AS long_usdc_nou,
    1.0    AS short_zec_pornire_noua,
    COALESCE((SELECT EXP(SUM(LOG(1 + p.rezultat_proc / 100)))
      FROM pozitii p WHERE p.banca = 'short' AND p.stare = 'inchisa'), 1)
           AS factor_randamente_short,
    1.0 * COALESCE((SELECT EXP(SUM(LOG(1 + p.rezultat_proc / 100)))
      FROM pozitii p WHERE p.banca = 'short' AND p.stare = 'inchisa'), 1)
           AS short_zec_nou;


-- --------------------------------------- 2. noul punct de pornire al short-ului
-- `pornire` pentru banca de short se citește din rândul de inițializare.
-- Prețul rămâne neatins: el e reperul pentru „stau pe USDC".

UPDATE miscari
SET cantitate_zec = 1.0,
    sold_zec_dupa = 1.0,
    explicatie    = CONCAT(explicatie, ' · rebazat la 1 ZEC pe 2026-09-02')
WHERE banca = 'short' AND fel = 'initializare';


-- ------------------------------------------------------------- 3. soldurile

UPDATE banci
SET sold_usdc = 1000.0, sold_zec = 0, actualizat_la = UNIX_TIMESTAMP() * 1000
WHERE banca = 'long';

UPDATE banci
SET sold_usdc = 0,
    sold_zec  = 1.0 * COALESCE((SELECT EXP(SUM(LOG(1 + p.rezultat_proc / 100)))
                  FROM pozitii p WHERE p.banca = 'short' AND p.stare = 'inchisa'), 1),
    actualizat_la = UNIX_TIMESTAMP() * 1000
WHERE banca = 'short';


-- --------------------------------------------------------- 4. urma corecturii

INSERT INTO miscari
    (banca, pozitie_id, moment, fel, pret, cantitate_zec, comision,
     sold_usdc_dupa, sold_zec_dupa, explicatie)
SELECT b.banca, NULL, UNIX_TIMESTAMP() * 1000, 'corectie', NULL,
       b.sold_zec, 0, b.sold_usdc, b.sold_zec,
       'rebazare la 1000 USDC / 1 ZEC, pentru citire ușoară; randamentele păstrate'
FROM banci b;


-- ------------------------------------------------------------- 5. verificare

SELECT banca, sold_usdc, sold_zec FROM banci;
