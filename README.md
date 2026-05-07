# ⚡ Gates of Olympus Simulator - Educational Algorithm Engine

⚠️ **DISCLAIMER / PERHATIAN PENTING:** *Proyek ini dibuat **SECARA EKSKLUSIF UNTUK TUJUAN EDUKASI DAN PENELITIAN ALGORITMA** (Computer Science & Probability Math). Repositori ini mendemonstrasikan bagaimana sistem RNG (Random Number Generator), Weighted Probability (Probabilitas Berbobot), dan Cascade Logic beroperasi di balik layar. DILARANG KERAS menggunakan kode ini untuk kegiatan perjudian uang asli atau aktivitas ilegal lainnya.*

---

## 📖 Tentang Proyek
Proyek ini adalah simulasi 1:1 dari mesin slot populer (Gates of Olympus), dibangun menggunakan **PHP Native dan Vanilla JavaScript**. Proyek ini mendemonstrasikan cara membangun mesin probabilitas (Volatility Engine) mandiri, sistem gravitasi array 2D (Tumbling/Cascade), dan manajemen *state* asinkron melalui AJAX.

## ✨ Fitur Utama
* **Real 6x5 Grid Gravity:** Sistem matriks array yang mengatur simbol jatuh dan runtuh (*cascade*) layaknya gravitasi.
* **Weighted RNG System:** Sistem gacha di mana setiap simbol memiliki "bobot" (kelangkaan) yang berbeda-beda untuk mengatur RTP (*Return to Player*).
* **Admin CMS (God Mode):** Panel khusus untuk mengatur Volatilitas (Persentase Petir, Hit Rate, dll) dan melakukan *Override* (merekayasa kemenangan spesifik atau memaksa Max Win).
* **Seamless AJAX Engine:** Permainan berjalan tanpa *reload* halaman. Animasi sinkron penuh dengan respons *backend*.
* **Anti-Rungkad Limit:** Fitur bandar untuk membatasi maksimal saldo pemain secara sistematis.

---

## 🛠️ Teknologi yang Digunakan
1.  **Backend:** PHP 8+ (Native / Non-Framework)
2.  **Database:** MySQL (PDO Connection)
3.  **Frontend Logic:** Vanilla JavaScript (ES6) + Fetch API (AJAX)
4.  **Styling & UI:** Tailwind CSS (via CDN) + FontAwesome
5.  **Audio System:** HTML5 Audio API (Asynchronous overlapping sound)

---

## 🚀 Panduan Instalasi & Menjalankan Code

### Persyaratan Sistem
* Web Server lokal seperti **XAMPP**, **Laragon**, atau **MAMP**.
* PHP versi 7.4 atau lebih baru.
* MySQL / MariaDB.

### Langkah-langkah
1. **Clone Repositori**
   Unduh atau *clone* repositori ini, lalu letakkan di dalam folder root web server Anda (contoh: `C:\xampp\htdocs\olympus_sim`).

2. **Setup Database (Import SQL)**
   * Buka **phpMyAdmin** Anda (`http://localhost/phpmyadmin`).
   * Buat database baru dengan nama: `db_olympus_sim`.
   * Pilih database tersebut, lalu klik tab **Import**.
   * Pilih (*Choose File*) file `db_olympus_sim.sql` yang ada di dalam folder repositori ini.
   * Klik tombol **Import / Go** di bagian bawah.

3. **Setup Folder Audio (Opsional tapi disarankan)**
   * Buat folder baru bernama `sounds` di dalam folder proyek Anda.
   * Masukkan file audio MP3 (cari mentahan *sound effect* olympus no copyright) lalu ubah namanya menjadi: 
     `spin.mp3`, `pecah.mp3`, `scatter.mp3`, `multiplier.mp3`, `bigwin.mp3`, `sensational.mp3`.

4. **Jalankan Aplikasi**
   Buka browser dan akses URL lokal Anda, contoh: `http://localhost/olympus_sim/index.php`.
   * Klik **PLAYER** di navigasi atas untuk bermain.
   * Klik **ADMIN** untuk mengatur algoritma dan *God Mode*.

---

## 🧠 Penjelasan Logika Code (Educational Breakdown)

Bagi Anda yang sedang belajar alur pemrograman, berikut adalah penjelasan komponen utama di dalam proyek ini:

### 1. Weighted Probability Algorithm (Gacha Berbobot)
Berbeda dengan fungsi `rand()` biasa, algoritma ini tidak memberikan peluang yang sama untuk setiap item. 
Di dalam kode, setiap simbol diberikan bobot (misal: Biru = 80, Mahkota = 2). Total bobot dijumlahkan, lalu dirandom. Semakin besar bobotnya, semakin besar "ruang" area menangnya, sehingga simbol Mahkota sangat langka untuk keluar.

### 2. Algoritma Cascade (Runtuhan Gravitasi Array)
Saat simbol pecah, kode melakukan simulasi gravitasi:
* Sistem membedah array 1D berukuran 30 elemen menjadi kolom (berbasis 6x5).
* Mengosongkan indeks simbol yang pecah.
* Melakukan *looping* dari bawah ke atas pada setiap kolom untuk "menarik" simbol yang masih ada agar jatuh ke indeks bawahnya.
* Menambahkan elemen baru di indeks teratas, lalu merandomnya kembali menggunakan *Weighted Probability*.

### 3. State Management Tanpa Relog (AJAX JSON)
Agar permainan tidak berkedip (*refresh*) saat tombol ditekan, frontend (Javascript) menggunakan `Fetch API` untuk mengirim request ke backend PHP.
* PHP memproses perhitungan saldo, probabilitas, dan membentuk array hasil runtuhan.
* PHP mengembalikan *response* berupa format **JSON**.
* Javascript membaca JSON tersebut (`sequence_data`) lalu merendernya menjadi animasi antrian (`setTimeout`) secara mulus di DOM HTML.

### 4. Algoritma Rekayasa Menang (Reverse Engineering Target Win)
Pada fitur *God Mode* / Admin Override, logika matematika dijalankan terbalik. 
Jika Admin meminta pemain menang tepat **100.000**, sistem tidak merandom dari awal. Sistem akan:
1. Memilih *Multiplier* / Petir yang wajar (misal total x70).
2. Membagi target dengan petir (`100.000 / 70 = 1.428`).
3. Memecah angka `1.428` menjadi beberapa tahap runtuhan, lalu mencari kombinasi *Paytable* (simbol x jumlah pecahan) yang nilainya sama persis dengan angka pecahan tersebut sebelum di-render ke layar.
