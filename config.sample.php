<?php
// Nebūtina. Pervadinkite į config.php, jei norite pakeisti numatytus nustatymus.

// Laiko juosta
define('WW_TIMEZONE', 'Europe/Vilnius');

// Pilnas programos adresas (naudojamas nuorodoms pranešimuose).
// Paprastai nustatomas automatiškai, kai atidarote programą naršyklėje.
// define('WW_APP_URL', 'https://watch.jusu-domenas.lt/');

// Kiek sekundžių daugiausiai gali trukti vienas cron paleidimas
// define('WW_CRON_MAX_SECONDS', 240);

/*
 * DUOMENŲ BAZĖ.
 * Numatytai naudojama SQLite (failas data/webwatch.sqlite) – nieko konfigūruoti nereikia.
 *
 * Rekomenduojama MySQL / MariaDB (Hostinger hPanel → Databases → MySQL Databases):
 * tada nebūna „database is locked“. Nustatymus galima įrašyti ČIA arba .env faile
 * (programos kataloge arba – saugiau – vienu katalogu aukščiau, ne viešai):
 *   DB_HOST=localhost
 *   DB_DATABASE=uXXXXXXXX_webwatch
 *   DB_USERNAME=uXXXXXXXX_webwatch
 *   DB_PASSWORD=jusu_slaptazodis
 * Arba čia (atkomentuokite ir užpildykite):
 */
// define('WW_DB_HOST', 'localhost');            // Hostinger dažniausiai 'localhost'
// define('WW_DB_NAME', 'uXXXXXXXX_webwatch');
// define('WW_DB_USER', 'uXXXXXXXX_webwatch');
// define('WW_DB_PASS', 'JUSU_SLAPTAZODIS');
// define('WW_DB_PORT', 3306);
//
// Lentelės sukuriamos automatiškai. Pirmą kartą prisijungus prie TUŠČIOS MySQL bazės visi
// esami duomenys (stebėjimai, istorija, kompiuteriai su raktais, nustatymai) perkeliami iš
// SQLite automatiškai; SQLite failas paliekamas kaip atsarginė kopija.
