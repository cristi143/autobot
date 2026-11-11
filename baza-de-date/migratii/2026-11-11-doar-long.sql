-- ============================================================================
-- Unealta rămâne DOAR LONG — scoate short-ul din bază.
--
-- Cerut pe 11.11.2026: „șterge tot ce ține de short și lasă doar longul”.
--
-- SE RULEAZĂ O SINGURĂ DATĂ, din cPanel → phpMyAdmin → baza marcelpa_autobot →
-- tabul SQL. ORDINEA FAȚĂ DE DEPLOY NU CONTEAZĂ: codul nou citește oricum doar
-- rândurile de long (`banca = 'long'`), iar codul vechi merge și fără rândul
-- băncii de short, cât timp nu e nicio setare short activă. Recomandat: după
-- deploy.
--
-- Ce exista de short pe 11.11.2026, verificat din API: banca neatinsă (1 ZEC,
-- cum a pornit), nicio setare activă, o singură setare `expirat`, nicio poziție.
-- Deci nu se pierde nimic în desfășurare.
--
-- Coloanele `banca` rămân, cu singura valoare 'long'. Scoase de tot, ar fi
-- trebuit ca migrația și codul să ajungă pe server în aceeași clipă — și la
-- noi deploy-ul și migrația se fac de mână, separat.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

USE marcelpa_autobot;

-- ---------------------------------------------------------------------------
-- PASUL 1 — rulează DOAR interogarea asta, singură. phpMyAdmin execută tot ce
-- e în casetă dintr-odată, deci verificarea nu poate opri ștergerile dacă stau
-- în aceeași rulare. Trebuie să întoarcă 0. Dacă nu, nu continua și spune-mi:
-- înseamnă că s-a pornit între timp o setare short pe pagina veche.
-- ---------------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM u_setari  WHERE banca = 'short' AND stare IN ('asteapta','armat','in_pozitie'))
  + (SELECT COUNT(*) FROM u_pozitii WHERE banca = 'short' AND stare = 'deschisa')
    AS short_inca_activ;

-- ---------------------------------------------------------------------------
-- PASUL 2 — restul, într-o a doua rulare.
-- Ordinea ștergerilor ține cont de cheia străină u_pozitii → u_setari.
-- ---------------------------------------------------------------------------
DELETE FROM u_miscari WHERE banca = 'short';
DELETE FROM u_pozitii WHERE banca = 'short';
DELETE FROM u_setari  WHERE banca = 'short';
DELETE FROM u_banci   WHERE banca = 'short';

ALTER TABLE u_setari  MODIFY banca ENUM('long') NOT NULL;
ALTER TABLE u_pozitii MODIFY banca ENUM('long') NOT NULL;
ALTER TABLE u_banci   MODIFY banca ENUM('long') NOT NULL;
ALTER TABLE u_miscari MODIFY banca ENUM('long') NOT NULL;

-- Verificare, după rulare:
--   SELECT * FROM u_banci;                               -- un singur rând: long
--   SELECT banca, COUNT(*) FROM u_setari GROUP BY banca; -- doar long
--   SELECT banca, COUNT(*) FROM u_miscari GROUP BY banca;
