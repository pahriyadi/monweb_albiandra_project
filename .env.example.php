<?php
/**
 * MONWEB By AP - ALBIANDRA PROJECT
 * Konfigurasi Database Opsional (.env.php)
 *
 * Secara default menggunakan SQLite (otomatis berjalan tanpa setup).
 * Jika sudah diupload ke monweb.baznassumbawa.id (Niagahoster) dan ingin memakai MySQL:
 * 1. Ubah nama file ini menjadi `.env.php`
 * 2. Isi DB_NAME, DB_USER, dan DB_PASS sesuai database cPanel/hPanel Anda.
 */

return [
    'DB_DRIVER' => 'sqlite', // Ubah ke 'mysql' jika ingin memakai MySQL Niagahoster
    'DB_HOST'   => '127.0.0.1',
    'DB_PORT'   => '3306',
    'DB_NAME'   => 'u123456_monweb',
    'DB_USER'   => 'u123456_admin',
    'DB_PASS'   => 'password_database_anda',
];

