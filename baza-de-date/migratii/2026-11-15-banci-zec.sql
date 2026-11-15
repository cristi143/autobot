-- ============================================================================
-- Băncile pe ZEC singur (D, E, F) — docs/plan-bot-zec.md.
--
-- SE RULEAZĂ O SINGURĂ DATĂ, din cPanel → phpMyAdmin → baza marcelpa_autobot →
-- tabul SQL, ÎNAINTE de deploy-ul codului. Cu codul nou și fără migrație,
-- motorul pică la fiecare oră (coloana `banca` lipsește) și orele se pierd.
-- Invers nu e nicio problemă: codul vechi merge mai departe cu coloana nouă.
--
-- A doua rulare dă „Duplicate column name 'banca'” — inofensiv, înseamnă că e
-- deja făcută.
--
-- Ce schimbă: excepțiile primesc banca de care țin. Până acum doar B avea
-- excepții, deci toate rândurile existente devin ale ei. Fără coloană,
-- excepțiile băncii F pe ZEC s-ar amesteca cu ale băncii B pe ZEC.
--
-- Celelalte tabele au deja banca în cheie; băncile noi apar singure în
-- b_banci la prima rulare a motorului de după deploy.
-- ============================================================================

SET NAMES utf8mb4;

USE marcelpa_autobot;

ALTER TABLE b_exceptii
  ADD COLUMN banca CHAR(1) NOT NULL DEFAULT 'B' AFTER id,
  ADD KEY k_banca (banca, simbol, pornita_ora);
