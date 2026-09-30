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
 * Jei naudojate namų kompiuterius ir daug stebėjimų, rekomenduojama MySQL / MariaDB
 * (Hostinger hPanel → Databases → MySQL Databases): tada nebūna „database is locked“.
 * Sukūrę duombazę ir vartotoją, atkomentuokite ir užpildykite:
 */
// define('WW_DB_HOST', 'localhost');            // Hostinger dažniausiai 'localhost'
// define('WW_DB_NAME', 'uXXXXXXXX_webwatch');
// define('WW_DB_USER', 'uXXXXXXXX_webwatch');
// define('WW_DB_PASS', 'JUSU_SLAPTAZODIS');
// define('WW_DB_PORT', 3306);
//
// Lentelės sukuriamos automatiškai pirmą kartą atidarius programą.
// Pereinant nuo SQLite prie MySQL, seni stebėjimai nepersikelia automatiškai –
// juos galima perkelti per Nustatymai → Atsarginė kopija (eksportas/importas).
