# Kisi Berkelanjutan Automated Typesetter & AmeliCA JATS XML (`kisiTypesetter`)

Generic Plugin untuk **OJS 3.5** (kompatibel juga dengan OJS 3.4) yang dirancang khusus untuk **_Kisi Berkelanjutan: Sains Medis dan Kesehatan_** (E-ISSN: `3062-9063`, PT Karya Inovasi Berkelanjutan).

## Fitur Utama
1. **Penarikan Metadata Otomatis dari OJS 3.5:**
   - Menarik Judul (ID & EN), Penulis, Afiliasi (mendukung struktur ROR / multi-afiliasi OJS 3.5 + deduplikasi otomatis), Email Korespondensi, DOI, Tanggal Terbit, Volume, Nomor Edisi, Nomor Artikel (`eXX`), dan URL Artikel.
   - **Review Editor Otomatis dari `Metadata > Subject`** & parsing file `.docx` langsung dari tahapan OJS (*Submission / Copyediting / Production*) atau unggahan lokal.
2. **Detektor Typo & Kata Tidak Baku (Bahasa Indonesia & Inggris):**
   - Didukung korpus bahasa terpercaya yang tertanam langsung di `resources/lexicon/`:
     - **Bahasa Indonesia:** **KBBI VI & EYD V** (Badan Pengembangan dan Pembinaan Bahasa Kemendikbudristek), **Hunspell ID (`id_ID`)**, serta dataset kurasi kata baku/tidak baku KBBI & Ivan Lanin (termasuk kaidah imbuhan `di-` vs kata depan `di`, bentuk terikat `pasca-`/`pra-`/`non-`/`antar-`, peluluhan K-T-S-P, kata serapan medis seperti *analisis*, *diagnosis*, *risiko*, *praktik*, *kuesioner*, *efektivitas*, serta pleonasme akademik).
     - **Bahasa Inggris:** **SCOWL US English (`en_US`)**, **AMA Manual of Style / Merriam-Webster** (deteksi ejaan UK vs US dan typo ilmiah medis), serta daftar terminologi biomedis/biostatistika.
     - **Opsional Live Engine:** Integrasi opsional ke **LanguageTool API (`en-US`)** dan **Google Gemini AI** (terkalibrasi pada KBBI VI/EYD V & AMA Style) serta tombol **1-Klik Terapkan Perbaikan**.
3. **Standarisasi Tabel Ilmiah Rumpun Kesehatan (ICMJE / AMA / CONSORT / STROBE):**
   - **Tabel Terbuka 3 Garis (*Three-Line Boxless Table*):** Tanpa garis vertikal dan tanpa kisi-kisi horizontal antar-baris data, garis batas atas/bawah `1.5pt`, garis pemisah header `1pt` (plus *straddle rule* untuk header bertingkat).
   - **Hierarki & Perataan Semantik:** Kolom stub kiri rata kiri dengan indentasi otomatis sub-kategori di bawah grup variabel (`Umur`, `Pendidikan`, `Pekerjaan`), baris `Jumlah`/`Total` berbatas garis tipis atas, perataan kolom numerik/statistik (`tabular-nums`), tipografi simbol statistik miring (*p*-value, *n*, *χ*², *SD*, *OR*, *CI*), serta **Catatan Kaki Tabel (`<table-wrap-foot>`)** di bawah tabel pada DOCX, JATS XML, dan Full-Text HTML.
4. **Pengaturan Blok Manual Lintas Level & Urutan:**
   - Pada Tab 2 (*Isi Artikel & Tabel*), editor dapat menyeret blok (`⋮⋮`) atau menekan tombol **`▲ / ▼`** untuk menggeser **Sub-Judul (L2), Sub-Sub-Judul (L3), Paragraf, maupun Tabel** naik-turun di dalam bab maupun **menembus batas antar bab** (*PENDAHULUAN ↔ METODE ↔ HASIL ↔ PEMBAHASAN ↔ KESIMPULAN DAN SARAN*), memindahkan bab via dropdown, serta mengubah level blok (`Paragraf ↔ Sub-Judul L2 ↔ Sub-Sub-Judul L3`) dan mengelola baris/kolom tabel secara manual.
5. **Dual Generator & Full-Text XML Digest di OJS 3.5:**
   - **Download `.docx` (Template 2026)** & **Download `.xml` (JATS 1.2 / AmeliCA)** serta tombol **Simpan Otomatis ke Galley OJS 3.5** yang langsung me-render **Full-Text HTML Interaktif** pada `/article/view/{id}`.

---

## Otomatisasi Git, CI/CD & Deployment cPanel

Proyek ini telah dilengkapi konfigurasi otomatisasi lengkap:
- **`.gitignore` & `.gitattributes`**: Memastikan file sistem (`desktop.ini`, `Thumbs.db`) dan arsip sementara diabaikan, serta menjaga baris akhir `LF` untuk skrip Linux/cPanel.
- **`.githooks/pre-commit`**: Memeriksa otomatis sebelum commit agar tidak ada BOM UTF-8, file `desktop.ini`, username/API key sensitif, maupun syntax error PHP.
- **`scripts/build-release.ps1`**: Skrip lokal untuk memvalidasi kode, menyinkronkan folder `dist/kisiTypesetter/`, dan mengemas ulang `kisiTypesetter.tar.gz` secara bersih.
- **`scripts/sync-git.ps1`**: Skrip sinkronisasi otomatis ke GitHub (`build-release`, pembersihan otomatis `desktop.ini` Google Drive di dalam `.git/`, `commit`, dan `push origin main`).
- **`.github/workflows/ci-release.yml`**: Workflow **GitHub Actions** yang otomatis menjalankan `php -l` & `php tests/run-tests.php` (pada PHP 8.2 & 8.3), membangun `kisiTypesetter.tar.gz` sebagai artifact/GitHub Release, serta mendukung *auto-deploy* FTP opsional via GitHub Secrets (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_DIR` + repository variable `ENABLE_FTP_DEPLOY=true`).
- **`.cpanel.yml`**: Konfigurasi deployment **cPanel Git™ Version Control** ke `$HOME/public_html/plugins/generic/kisiTypesetter/` tanpa menulis username cPanel secara eksplisit.

### Menghubungkan & Mendorong Pembaruan ke GitHub
Repository ini terhubung ke `https://github.com/kisiberkelanjutan-idn/kisiTypesetter.git` (`main`). Untuk menyinkronkan pembaruan rutin (termasuk rilis tag otomatis seperti `v1.1.0`):
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\sync-git.ps1 -Message "feat: deskripsi pembaruan"
```

---

## Cara Instalasi & Akses di OJS 3.5
1. **Via cPanel Git™ Version Control:** Clone repository ke server cPanel lalu klik **Update from Remote** → **Deploy HEAD Commit** (`.cpanel.yml` akan menyalin file runtime ke `public_html/plugins/generic/kisiTypesetter/`).
2. **Via Upload Dashboard OJS:** Unggah `kisiTypesetter.tar.gz` melalui **Settings > Website > Plugins > Upload A New Plugin**.
3. **Via Core FTP:** Unggah isi folder `dist/kisiTypesetter/` ke `public_html/plugins/generic/kisiTypesetter/`.
4. Aktifkan plugin **Kisi Berkelanjutan Automated Typesetter & AmeliCA JATS XML** di **Settings > Website > Plugins > Installed Plugins**.
5. Setelah aktif, menu **Kisi Typesetter** langsung muncul pada navigasi editorial OJS 3.5 **tepat di bawah menu Issues / Terbitan** (selain tombol pintasan cepat **⚡ Kisi Re-Layout & JATS XML** pada halaman Dashboard/Workflow), sehingga Editor tidak perlu membuka *Website > Plugins* lagi.
