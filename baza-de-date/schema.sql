-- ============================================================================
-- autobot.dunitru.ro — schema bazei de date
--
-- Se rulează o singură dată, din cPanel → phpMyAdmin, pe baza de date creată
-- la MySQL® Databases.
--
-- DOUĂ DECIZII DE STRUCTURĂ, AMBELE DELIBERATE:
--
-- 1. TIMPUL se stochează ca BIGINT — milisecunde de la epocă, exact formatul în
--    care le trimite Binance. NU folosim DATETIME nicăieri pentru momente de
--    piață. Motivul: serverul are fusul Europe/Bucharest, Binance lucrează în
--    UTC, iar ora de vară mută diferența între 2 și 3 ore. Un BIGINT nu are fus
--    și nu poate fi interpretat greșit. Conversia la text se face doar la
--    afișare, forțat pe UTC.
--
-- 2. PREȚURILE și CANTITĂȚILE sunt DECIMAL, niciodată FLOAT sau DOUBLE.
--    Aici se socotesc bani; erorile de virgulă mobilă se acumulează în solduri.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Spune explicit unde se creează tabelele. Fără linia asta, un import pornit
-- din phpMyAdmin fără să fi selectat întâi baza în panoul din stânga eșuează cu
-- „#1046 - No database selected", pentru că rulează la nivel de server.
-- Dacă baza se numește altfel, se schimbă aici.
USE marcelpa_autobot;


-- ---------------------------------------------------------------------------
-- Lumânările de 1h, așa cum le-a văzut serverul. Copie proprie, ca să putem
-- reface orice decizie fără să depindem de disponibilitatea Binance.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lumanari_1h (
    ora_deschidere  BIGINT       NOT NULL COMMENT 'ms de la epocă, UTC — cheia',
    deschidere      DECIMAL(20,8) NOT NULL,
    maxim           DECIMAL(20,8) NOT NULL,
    minim           DECIMAL(20,8) NOT NULL,
    inchidere       DECIMAL(20,8) NOT NULL,
    volum           DECIMAL(30,8) NOT NULL,
    adaugat_la      BIGINT       NOT NULL COMMENT 'când a văzut-o serverul',
    PRIMARY KEY (ora_deschidere)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Triunghiurile — unitatea de lucru. Două linii convergente, un singur semnal.
-- „consumat" înseamnă că a tras. Liniile rămân în bază ca arhivă: din
-- 19.09.2026 ieșirile nu mai depind de ele, dar geometria deciziei e tocmai
-- materialul etapei 4.
--
-- STĂRILE ÎNSEAMNĂ LUCRURI DIFERITE, ȘI NU SE AMESTECĂ:
--   activ    — desenat, așteaptă o spargere
--   consumat — a produs un semnal
--   expirat  — laturile s-au intersectat fără spargere (verdictul pieței)
--   sters    — utilizatorul l-a retras (decizia lui)
-- Ultimele două sunt amândouă exemple negative pentru analiza de la etapa 4, dar
-- din motive diferite; contopite, n-ar mai spune nimic.
--
-- FEREASTRA VIZIBILĂ la desenare se păstrează pentru că „vârful evident" depinde
-- de cât se vedea pe ecran în acel moment.
--
-- ȘTERGEREA E MOALE, DIN CONSTRUCȚIE. Cheile străine dinspre `semnale` și
-- `pozitii` spre `linii` nu au ON DELETE, deci baza va REFUZA ștergerea unui
-- triunghi care a produs vreodată un semnal. Asta e intenționat: istoricul
-- tranzacțiilor n-are voie să rămână fără explicație. Starea 'sters' e pentru
-- triunghiurile desenate din greșeală, înainte să tragă.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS triunghiuri (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    stare        ENUM('activ','consumat','expirat','sters') NOT NULL DEFAULT 'activ',
    desenat_la   BIGINT       NOT NULL COMMENT 'ms UTC',
    fereastra_de_la   BIGINT  NULL COMMENT 'ms UTC, marginea stângă a graficului la desenare',
    fereastra_pana_la BIGINT  NULL COMMENT 'ms UTC, marginea dreaptă',
    consumat_la  BIGINT       NULL     COMMENT 'ms UTC, când a tras',
    nota         VARCHAR(255) NULL     COMMENT 'ce a văzut utilizatorul aici',
    PRIMARY KEY (id),
    KEY idx_stare (stare)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Liniile — exact două per triunghi. Fiecare e definită de două puncte și se
-- prelungește la infinit spre dreapta.
--
-- `rol` se stabilește la desenare, din poziția prețului față de linie, și NU se
-- mai schimbă: liniile fiind convergente, s-ar intersecta și rolurile s-ar
-- inversa singure.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS linii (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    triunghi_id  INT UNSIGNED NOT NULL,
    rol          ENUM('sus','jos') NOT NULL
                 COMMENT 'sus: long la spargere în sus · jos: short la spargere în jos',
    t1           BIGINT       NOT NULL COMMENT 'ms UTC, primul punct',
    p1           DECIMAL(20,8) NOT NULL,
    t2           BIGINT       NOT NULL COMMENT 'ms UTC, al doilea punct',
    p2           DECIMAL(20,8) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unic_rol_per_triunghi (triunghi_id, rol),
    CONSTRAINT fk_linie_triunghi FOREIGN KEY (triunghi_id)
        REFERENCES triunghiuri (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Semnalele — ce a decis motorul, și pe ce bază. Se păstrează separat de
-- poziții ca să rămână urma deciziei chiar dacă poziția se închide.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS semnale (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    triunghi_id     INT UNSIGNED NOT NULL,
    linie_id        INT UNSIGNED NOT NULL COMMENT 'linia spartă',
    ora_lumanare    BIGINT       NOT NULL COMMENT 'lumânarea care a declanșat',
    tip             ENUM('long','short') NOT NULL,
    pret_inchidere  DECIMAL(20,8) NOT NULL COMMENT 'close-ul care a rupt linia',
    pret_linie      DECIMAL(20,8) NOT NULL COMMENT 'linia la acea oră',
    creat_la        BIGINT       NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ora (ora_lumanare),
    CONSTRAINT fk_semnal_triunghi FOREIGN KEY (triunghi_id) REFERENCES triunghiuri (id),
    CONSTRAINT fk_semnal_linie    FOREIGN KEY (linie_id)    REFERENCES linii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Pozițiile. TP ȘI SL sunt amândouă prețuri fixe, așezate la intrare, la
-- distanțe proporționale cu ATR(14) — vezi docs/plan-tranzactionare.md.
--
-- Până pe 19.09.2026, SL-ul era linia de intrare, evaluată la fiecare oră. Ea
-- fiind convergentă, se depărta de prețul de intrare cu fiecare oră petrecută în
-- poziție: riscul creștea cu timpul, câștigul rămânea plafonat. De aceea acum se
-- stochează un preț, iar linia rămâne doar ca urmă a deciziei.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pozitii (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    semnal_id      INT UNSIGNED NOT NULL,
    banca          ENUM('long','short') NOT NULL,
    stare          ENUM('deschisa','inchisa') NOT NULL DEFAULT 'deschisa',

    intrare_ora    BIGINT       NOT NULL COMMENT 'deschiderea lumânării următoare',
    intrare_pret   DECIMAL(20,8) NOT NULL,
    cantitate      DECIMAL(30,8) NOT NULL COMMENT 'ZEC cumpărat sau vândut',
    tp_pret        DECIMAL(20,8) NOT NULL COMMENT 'intrare ± tp_atr × ATR, înghețat la intrare',
    sl_pret        DECIMAL(20,8) NULL     COMMENT 'intrare ∓ sl_atr × ATR; NULL doar la pozițiile dinainte de 19.09.2026',
    atr_intrare    DECIMAL(20,8) NULL     COMMENT 'ATR(14) în USDC, în momentul intrării',
    linie_intrare_id INT UNSIGNED NOT NULL COMMENT 'linia care a dat semnalul — arhivă pentru etapa 4, NU mai e stop loss',

    iesire_ora     BIGINT       NULL,
    iesire_pret    DECIMAL(20,8) NULL,
    motiv_iesire   ENUM('tp','sl','timp') NULL,

    comision_total DECIMAL(20,8) NOT NULL DEFAULT 0 COMMENT 'ambele părți, în USDC',
    rezultat_proc  DECIMAL(10,4) NULL COMMENT 'net, după comisioane',

    -- Cât de departe a mers prețul cât timp poziția era deschisă, brut, în %.
    -- Nu se pot reconstitui după închidere: sunt maxime de pe tot drumul.
    -- Cu ele se răspunde la „ar fi ajutat un stop la break-even?" și
    -- „cât las pe masă cu TP-ul aici?" — altfel, opinie contra opinie.
    mfe_proc       DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT 'cel mai mult în favoare',
    mae_proc       DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT 'cel mai adânc în minus',

    PRIMARY KEY (id),
    KEY idx_stare (stare),
    CONSTRAINT fk_pozitie_semnal FOREIGN KEY (semnal_id)        REFERENCES semnale (id),
    CONSTRAINT fk_pozitie_linie  FOREIGN KEY (linie_intrare_id) REFERENCES linii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Starea curentă a celor două bănci. Doar două rânduri, mereu.
-- Ambele solduri sunt prezente: banca „stă" în moneda cu sold nenul.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS banci (
    banca          ENUM('long','short') NOT NULL,
    sold_usdc      DECIMAL(30,8) NOT NULL DEFAULT 0,
    sold_zec       DECIMAL(30,8) NOT NULL DEFAULT 0,
    actualizat_la  BIGINT        NOT NULL,
    PRIMARY KEY (banca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Registrul de mișcări — fiecare schimbare de sold, cu soldul rezultat.
-- Redundant față de `pozitii`, intenționat: dacă un calcul iese greșit, aici se
-- vede exact unde s-a rupt lanțul.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS miscari (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    banca          ENUM('long','short') NOT NULL,
    pozitie_id     INT UNSIGNED NULL,
    moment         BIGINT       NOT NULL,
    fel            ENUM('initializare','intrare','iesire') NOT NULL,
    pret           DECIMAL(20,8) NULL,
    cantitate_zec  DECIMAL(30,8) NULL,
    comision       DECIMAL(20,8) NOT NULL DEFAULT 0,
    sold_usdc_dupa DECIMAL(30,8) NOT NULL,
    sold_zec_dupa  DECIMAL(30,8) NOT NULL,
    explicatie     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_banca_moment (banca, moment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Jurnalul cronului. Fără el, un cron care nu rulează e invizibil — nu poți
-- distinge „n-a fost niciun semnal" de „scriptul a murit acum trei zile".
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jurnal_cron (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pornit_la     BIGINT       NOT NULL,
    durata_ms     INT UNSIGNED NULL,
    ora_lumanare  BIGINT       NULL COMMENT 'lumânarea procesată',
    rezultat      ENUM('ok','fara_date','eroare') NOT NULL,
    detaliu       TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_pornit (pornit_la)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Cele două bănci pornesc cu 500 USDC fiecare.
-- Banca de short primește ZEC-ul la prima rulare a motorului, la prețul de
-- atunci — nu îl putem fixa acum, fără să știm cursul.
-- ---------------------------------------------------------------------------
INSERT INTO banci (banca, sold_usdc, sold_zec, actualizat_la) VALUES
    ('long',  500.00000000, 0, UNIX_TIMESTAMP() * 1000),
    ('short', 500.00000000, 0, UNIX_TIMESTAMP() * 1000)
ON DUPLICATE KEY UPDATE banca = banca;
