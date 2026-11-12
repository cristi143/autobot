-- ============================================================================
-- Botul automat pe bani fictivi — tabelele proprii (prefix `b_`).
-- Regulile: docs/plan-bot.md.
--
-- SE RULEAZĂ O SINGURĂ DATĂ, din cPanel → phpMyAdmin → baza marcelpa_autobot →
-- tabul SQL, ÎNAINTE de deploy-ul codului. Altfel motorul botului pică la prima
-- rulare, pe un tabel care nu există.
--
-- Nu atinge tabelele `u_*` ale uneltei manuale (scoasă pe 12.11.2026): ele rămân
-- în bază până la o decizie separată, fiindcă istoricul lor nu se poate reface.
--
-- Convențiile bazei, din aceleași motive ca până acum:
--   · TIMPUL e BIGINT, milisecunde UTC. Serverul e pe Europe/Bucharest, Binance
--     pe UTC, iar ora de vară mută diferența între 2 și 3 ore.
--   · BANII și prețurile sunt DECIMAL, niciodată FLOAT.
--   · Volatilitatea și rangul sunt DOUBLE: nu sunt bani, sunt măsurători, iar
--     motorul le compară cu exact ce a calculat.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

USE marcelpa_autobot;


-- Închiderile orare, păstrate pentru verificare: din ele se poate reface orice
-- semnal, după fapt. `ora` = deschiderea lumânării de 1h, ca la Binance.
CREATE TABLE IF NOT EXISTS b_lumanari_1h (
  simbol      VARCHAR(16)    NOT NULL,
  ora         BIGINT         NOT NULL,
  inchidere   DECIMAL(20,8)  NOT NULL,
  adaugat_la  BIGINT         NOT NULL,
  PRIMARY KEY (simbol, ora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Fiecare oră judecată, fiecare monedă: tot ce a stat la baza semnalului.
-- `pierduta` = 1 pentru orele în care cronul n-a rulat: semnalul e socotit și
-- scris după fapt, dar NU s-a tranzacționat pe el (plan-bot.md §5).
CREATE TABLE IF NOT EXISTS b_evaluari (
  ora         BIGINT         NOT NULL,
  simbol      VARCHAR(16)    NOT NULL,
  inchidere   DECIMAL(20,8)  NOT NULL,
  vol         DOUBLE         NOT NULL,
  rang        DOUBLE         NOT NULL,
  r24         DOUBLE         NOT NULL,
  semnal      TINYINT(1)     NOT NULL,
  pierduta    TINYINT(1)     NOT NULL DEFAULT 0,
  PRIMARY KEY (ora, simbol),
  KEY k_semnal (simbol, semnal, ora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Cele trei bănci: A fix 50/50, B fix + excepția după panică, C pasivă.
CREATE TABLE IF NOT EXISTS b_banci (
  banca            CHAR(1)        NOT NULL,
  usdc             DECIMAL(24,10) NOT NULL,
  capital_initial  DECIMAL(24,10) NOT NULL,
  pornita_la       BIGINT         NOT NULL,
  actualizat_la    BIGINT         NOT NULL,
  PRIMARY KEY (banca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS b_detineri (
  banca      CHAR(1)        NOT NULL,
  simbol     VARCHAR(16)    NOT NULL,
  cantitate  DECIMAL(30,12) NOT NULL,
  PRIMARY KEY (banca, simbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Fiecare cumpărare (+) și vânzare (−), cu motivul în cuvinte.
-- `pret` = cel plătit (ask la cumpărare, bid la vânzare, în clipa rulării).
-- `pret_simulare` = închiderea orei, prețul pe care l-ar fi folosit simularea:
-- diferența dintre ele e costul real al spread-ului și al întârzierii.
CREATE TABLE IF NOT EXISTS b_tranzactii (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  banca          CHAR(1)         NOT NULL,
  ora            BIGINT          NOT NULL,
  executat_la    BIGINT          NOT NULL,
  simbol         VARCHAR(16)     NOT NULL,
  cantitate      DECIMAL(30,12)  NOT NULL,
  pret           DECIMAL(20,8)   NOT NULL,
  pret_simulare  DECIMAL(20,8)   NOT NULL,
  comision       DECIMAL(24,10)  NOT NULL,
  tinta          DOUBLE          NOT NULL,
  usdc_dupa      DECIMAL(24,10)  NOT NULL,
  motiv          VARCHAR(255)    NOT NULL,
  PRIMARY KEY (id),
  KEY k_banca (banca, ora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Excepțiile băncii B. `inchisa_ora` NULL = încă activă.
-- `pret_pornire` / `pret_inchidere` = închiderile orelor respective, ca la 12
-- luni să se poată socoti media brută a excepțiilor (plan-bot.md §9).
CREATE TABLE IF NOT EXISTS b_exceptii (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  simbol          VARCHAR(16)     NOT NULL,
  pornita_ora     BIGINT          NOT NULL,
  expira_ora      BIGINT          NOT NULL,
  inchisa_ora     BIGINT          NULL,
  pret_pornire    DECIMAL(20,8)   NOT NULL,
  pret_inchidere  DECIMAL(20,8)   NULL,
  PRIMARY KEY (id),
  KEY k_simbol (simbol, pornita_ora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Valoarea fiecărei bănci la fiecare oră judecată (la mijlocul dintre bid și ask).
CREATE TABLE IF NOT EXISTS b_evolutie (
  ora      BIGINT         NOT NULL,
  banca    CHAR(1)        NOT NULL,
  valoare  DECIMAL(24,10) NOT NULL,
  usdc     DECIMAL(24,10) NOT NULL,
  PRIMARY KEY (ora, banca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Fiecare rulare a motorului. `rezultat`: ok, nimic_nou, eroare.
CREATE TABLE IF NOT EXISTS b_jurnal (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pornit_la     BIGINT          NOT NULL,
  durata_ms     INT             NOT NULL,
  ora_judecata  BIGINT          NULL,
  ore_pierdute  INT             NOT NULL DEFAULT 0,
  rezultat      VARCHAR(20)     NOT NULL,
  detaliu       TEXT            NULL,
  PRIMARY KEY (id),
  KEY k_pornit (pornit_la)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
