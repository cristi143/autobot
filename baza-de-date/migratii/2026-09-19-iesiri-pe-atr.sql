-- Ieșirile nu mai depind de linii — TP și SL devin praguri fixe, așezate la
-- intrare, la distanțe proporționale cu ATR(14).
--
-- SE RULEAZĂ O SINGURĂ DATĂ, din cPanel → phpMyAdmin → baza marcelpa_autobot →
-- tabul SQL. Nu există sistem de migrații: seeder-ele și migrațiile nu rulează
-- la deploy, deci pasul ăsta e manual și trebuie făcut ÎNAINTE ca motorul cu
-- codul nou să prindă un semnal.
--
-- E sigur de rulat cu motorul pornit: nicio poziție nu era deschisă pe
-- 19.09.2026, iar coloanele noi sunt fie NULL, fie cu valoare implicită.

ALTER TABLE pozitii
    CHANGE linie_sl_id linie_intrare_id INT UNSIGNED NOT NULL
        COMMENT 'linia care a dat semnalul — arhivă pentru etapa 4, NU mai e stop loss',
    ADD COLUMN sl_pret     DECIMAL(20,8) NULL AFTER tp_pret,
    ADD COLUMN atr_intrare DECIMAL(20,8) NULL AFTER sl_pret,
    MODIFY motiv_iesire ENUM('tp','sl','timp') NULL,
    ADD COLUMN mfe_proc DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER rezultat_proc,
    ADD COLUMN mae_proc DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER mfe_proc;

-- Verificare, după rulare: trebuie să apară sl_pret, atr_intrare, mfe_proc,
-- mae_proc, iar linie_sl_id să nu mai existe.
-- SHOW COLUMNS FROM pozitii;
