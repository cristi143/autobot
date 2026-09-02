-- ============================================================================
-- Reparație unică: banca de short a rămas cu zero pe ambele solduri.
--
-- CE S-A ÎNTÂMPLAT
-- Blocul de inițializare din motor verifica soldurile: „are USDC și n-are ZEC".
-- Exact așa arată însă și o bancă aflată în mijlocul unui short — tocmai a vândut
-- ZEC-ul. La rularea de după deschiderea poziției, motorul a „reinițializat"
-- banca, convertind USDC-ul poziției în ZEC. La închiderea pe TP, răscumpărarea
-- s-a calculat din USDC-ul care nu mai era acolo: a ieșit zero, iar banca a
-- rămas goală.
--
-- Cauza e reparată în motor (commit „Banca de short nu se mai reinițializează").
-- Fișierul ăsta repară doar datele rămase greșite.
--
-- CUM RECONSTITUIM
-- Nu ghicim: pornim de la ZEC-ul cu care a fost finanțată banca și aplicăm, în
-- ordine, randamentele nete ale pozițiilor de short deja închise. Acelea au fost
-- calculate din prețuri și comisioane, independent de solduri, deci n-au fost
-- atinse de defect.
--
-- Se rulează în phpMyAdmin, pe baza marcelpa_autobot, cu baza selectată în stânga.
-- ============================================================================

-- ---------------------------------------------------------------- 1. ce ieșim
-- Rulează întâi SELECT-ul ăsta singur și uită-te la cifre înainte de a scrie.

SELECT
    (SELECT m.cantitate_zec FROM miscari m
      WHERE m.banca = 'short' AND m.fel = 'initializare'
      ORDER BY m.moment ASC LIMIT 1)                                   AS zec_la_pornire,
    COALESCE((SELECT EXP(SUM(LOG(1 + p.rezultat_proc / 100)))
      FROM pozitii p WHERE p.banca = 'short' AND p.stare = 'inchisa'), 1) AS factor_randamente,
    (SELECT m.cantitate_zec FROM miscari m
      WHERE m.banca = 'short' AND m.fel = 'initializare'
      ORDER BY m.moment ASC LIMIT 1)
    * COALESCE((SELECT EXP(SUM(LOG(1 + p.rezultat_proc / 100)))
      FROM pozitii p WHERE p.banca = 'short' AND p.stare = 'inchisa'), 1) AS zec_corect;


-- --------------------------------------------- 2. loc pentru urma corecturii
-- `miscari` nu avea un fel de mișcare pentru corecturi. Fără el, reparația ar
-- rămâne fără explicație în registru.

ALTER TABLE miscari
    MODIFY fel ENUM('initializare','intrare','iesire','corectie') NOT NULL;


-- ------------------------------------------------------------- 3. reparația

UPDATE banci
SET sold_usdc = 0,
    sold_zec  = (SELECT m.cantitate_zec FROM miscari m
                  WHERE m.banca = 'short' AND m.fel = 'initializare'
                  ORDER BY m.moment ASC LIMIT 1)
                * COALESCE((SELECT EXP(SUM(LOG(1 + p.rezultat_proc / 100)))
                  FROM pozitii p WHERE p.banca = 'short' AND p.stare = 'inchisa'), 1),
    actualizat_la = UNIX_TIMESTAMP() * 1000
WHERE banca = 'short';


-- ------------------------------------------------------ 4. urma în registru

INSERT INTO miscari
    (banca, pozitie_id, moment, fel, pret, cantitate_zec, comision,
     sold_usdc_dupa, sold_zec_dupa, explicatie)
SELECT 'short', NULL, UNIX_TIMESTAMP() * 1000, 'corectie', NULL, b.sold_zec, 0,
       b.sold_usdc, b.sold_zec,
       'reconstituire după reinițializarea greșită a băncii în timpul unui short'
FROM banci b WHERE b.banca = 'short';


-- -------------------------------------------------------------- 5. verificare

SELECT banca, sold_usdc, sold_zec FROM banci;
