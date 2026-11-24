<#
.SYNOPSIS
    Builds verified, gzipped offline dictionaries & JSON standard-word rules
    for KisiTypesetter LanguageAuditor from authoritative open sources:
      - KBBI corpus (damzaky/kumpulan-kata-bahasa-indonesia-KBBI)
      - Hunspell ID (shuLhan/hunspell-id)
      - Baku/Tidak Baku KBBI list (lantip/baku-tidak-baku + Ivan Lanin)
      - SCOWL US English (en-wl/wordlist-diff en_US.txt)
#>
$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Split-Path -Parent $scriptDir
$outDir = Join-Path $repoRoot 'resources\lexicon'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

function Write-GzipLines {
    param([string]$Path, [string[]]$Lines)
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    $content = ($Lines -join "`n") + "`n"
    $bytes = $utf8NoBom.GetBytes($content)
    $fs = [System.IO.File]::Create($Path)
    try {
        $gz = New-Object System.IO.Compression.GzipStream($fs, [System.IO.Compression.CompressionLevel]::Optimal)
        try {
            $gz.Write($bytes, 0, $bytes.Length)
        } finally {
            $gz.Dispose()
        }
    } finally {
        $fs.Dispose()
    }
}

function Download-UrlText {
    param([string]$Url)
    $tmp = [System.IO.Path]::GetTempFileName()
    try {
        & curl.exe -4 -fsSL --retry 3 --retry-delay 2 --max-time 60 -o $tmp $Url
        if ($LASTEXITCODE -ne 0) {
            throw "curl failed ($LASTEXITCODE) for $Url"
        }
        return [System.IO.File]::ReadAllText($tmp, [System.Text.UTF8Encoding]::new($false))
    } finally {
        if (Test-Path $tmp) { Remove-Item -Force $tmp -ErrorAction SilentlyContinue }
    }
}

Write-Host "1. Downloading & sanitizing Baku/Tidak Baku dataset (KBBI / Ivan Lanin)..." -ForegroundColor Cyan
$csvRaw = Download-UrlText "https://raw.githubusercontent.com/lantip/baku-tidak-baku/main/daftar_baku_tidak_baku.csv"
$ivanRaw = Download-UrlText "https://raw.githubusercontent.com/lantip/baku-tidak-baku/main/daftar_baku_ivanlanin.json"

# Homographs, valid distinct words, or English words that must NOT be flagged blindly as ID tidak baku
$excludeTidakBaku = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
@(
    'bh', 'bur', 'bis', 'dos', 'mas', 'mpu', 'gep', 'gip', 'goa', 'jep', 'jus', 'cru',
    'lux', 'mie', 'roh', 'sen', 'sex', 'sie', 'sky', 'sup', 'sop', 'she', 'sur', 'tes',
    'tut', 'fak', 'fas', 'di', 'ke', 'se', 'per', 'dan', 'atau', 'yang', 'dari', 'pada',
    'itu', 'ini', 'ada', 'bila', 'jika', 'bagi', 'para', 'satu', 'dua', 'tiga', 'lima',
    'enam', 'data', 'seri', 'tipe', 'pola', 'zona', 'fase', 'usia', 'bayi', 'ibu', 'anak',
    'kota', 'desa', 'area', 'unit', 'grup', 'poin', 'skor', 'uji', 'alat', 'cara', 'hasil',
    'metode', 'model', 'tabel', 'judul', 'tahun', 'bulan', 'hari', 'jam', 'orang', 'faktor',
    'nilai', 'batas', 'kadar', 'dosis', 'suhu', 'berat', 'tinggi', 'umur', 'rata', 'total',
    'jumlah', 'persen', 'pasca', 'pra', 'sub', 'non', 'anti', 'multi', 'semi', 'hiper',
    'survey', 'design', 'sample', 'study', 'level', 'scale', 'score', 'test', 'case', 'risk',
    'rate', 'care', 'birth', 'life', 'home', 'work', 'time', 'part', 'mean', 'mode', 'odds',
    'hal', 'sah', 'sahaja', 'syah', 'pas', 'baku', 'bisa', 'mati', 'jadi', 'baru', 'lama',
    'luas', 'kecil', 'besar', 'baik', 'buruk', 'tepat', 'dekat', 'jauh', 'kuat', 'lemah',
    'jarang', 'sering', 'selalu', 'kadang', 'kurang', 'lebih', 'sama', 'beda', 'lain',
    'mal', 'malah', 'mana', 'sini', 'situ', 'sana', 'atas', 'bawah', 'dalam', 'luar',
    'set', 'cek', 'gol', 'kos', 'rok', 'tas', 'ban', 'bor', 'cap', 'cat', 'cek', 'cor',
    'jamu', 'jasa', 'kain', 'kala', 'kali', 'kami', 'kamu', 'kata', 'kaya', 'kayu', 'kera',
    'kerja', 'kira', 'kita', 'kuli', 'kuno', 'kutu', 'laba', 'laku', 'lalu', 'luka', 'lupa',
    'makan', 'malam', 'malu', 'mana', 'marah', 'mari', 'masa', 'masak', 'masuk', 'mata',
    'mau', 'mawar', 'maya', 'meja', 'merah', 'muda', 'mulai', 'mulus', 'mungkin', 'murni',
    'nadi', 'naik', 'nama', 'nanti', 'nasi', 'niat', 'nilai', 'nyata', 'obat', 'olah',
    'oleh', 'otak', 'otot', 'padat', 'padi', 'pagi', 'paham', 'pakai', 'paksa', 'paku',
    'paling', 'paman', 'panas', 'pandu', 'papan', 'parah', 'paru', 'pasar', 'pasti', 'patuh',
    'payah', 'pedas', 'pegang', 'peka', 'peluk', 'penuh', 'peran', 'pergi', 'perlu', 'pernah',
    'pesan', 'pesta', 'peta', 'pihak', 'pikir', 'pilih', 'pindah', 'pintar', 'pintu', 'pipi',
    'pisah', 'pohon', 'pokok', 'pola', 'polisi', 'pondok', 'potong', 'puas', 'pucat', 'puji',
    'pukul', 'pulang', 'pulau', 'pulih', 'pun', 'puncak', 'punya', 'pupuk', 'pusat', 'pusing',
    'putar', 'putih', 'putra', 'putri', 'putus', 'gizi', 'nakes', 'kader', 'bidan', 'dokter',
    'perawat', 'klinik', 'sehat', 'sakit', 'obat', 'darah', 'suami', 'istri', 'hamil', 'lahir',
    'nifas', 'menyusui', 'asi', 'imd', 'kolostrum', 'stunting', 'imunisasi', 'vaksin', 'kapsul',
    'tablet', 'sirup', 'salep', 'infus', 'jarum', 'suntik', 'rujuk', 'rawat', 'inap', 'jalan',
    'kaji', 'teliti', 'ukur', 'hitung', 'catat', 'lapor', 'simpul', 'saran', 'bahas', 'diskusi',
    'tanya', 'jawab', 'setuju', 'tolak', 'dukung', 'hambat', 'cegah', 'kendala', 'masalah',
    'solusi', 'dampak', 'akibat', 'sebab', 'tujuan', 'manfaat', 'fungsi', 'peran', 'tugas',
    'hak', 'wajib', 'syarat', 'aturan', 'norma', 'nilai', 'budaya', 'adat', 'tradisi', 'agama',
    'sosial', 'ekonomi', 'didik', 'ajar', 'latih', 'bina', 'bimbing', 'asuh', 'lindung', 'jaga',
    'bantu', 'tolong', 'beri', 'terima', 'ambil', 'bawa', 'kirim', 'simpan', 'buka', 'tutup',
    'isi', 'kosong', 'penuh', 'awal', 'akhir', 'tengah', 'pinggir', 'tepi', 'sudut', 'ujung',
    'sekarang', 'nanti', 'dulu', 'kemarin', 'besok', 'lusa', 'pagi', 'siang', 'sore', 'malam',
    'ketua', 'anggota', 'warga', 'rakyat', 'bangsa', 'negara', 'daerah', 'wilayah', 'puskesmas',
    'posyandu', 'rsud', 'dinkes', 'depkes', 'kemenkes', 'bupati', 'camat', 'lurah', 'kepala',
    'ketika', 'saat', 'waktu', 'tempo', 'zaman', 'masa', 'abad', 'era', 'periode', 'rentang',
    'angka', 'huruf', 'kata', 'kalimat', 'paragraf', 'bab', 'subbab', 'bagian', 'pasal', 'ayat',
    'sumber', 'pustaka', 'rujukan', 'kutipan', 'daftar', 'indeks', 'lampiran', 'gambar', 'grafik',
    'diagram', 'skema', 'peta', 'foto', 'video', 'suara', 'bunyi', 'warna', 'bentuk', 'ukuran',
    'berat', 'panjang', 'lebar', 'tinggi', 'tebal', 'tipis', 'luas', 'sempit', 'dalam', 'dangkal',
    'cepat', 'lambat', 'keras', 'lunak', 'halus', 'kasar', 'tajam', 'tumpul', 'panas', 'dingin',
    'hangat', 'sejuk', 'kering', 'basah', 'lembab', 'bersih', 'kotor', 'rapi', 'kacau', 'aman',
    'bahaya', 'tenang', 'ramai', 'sepi', 'sunyi', 'terang', 'gelap', 'jelas', 'kabur', 'nyata',
    'semu', 'asli', 'palsu', 'benar', 'salah', 'tepat', 'keliru', 'cocok', 'sesuai', 'selaras',
    'seimbang', 'timpang', 'rata', 'miring', 'tegak', 'lurus', 'bengkok', 'bulat', 'bundar', 'kotak',
    'persegi', 'segitiga', 'lingkaran', 'garis', 'titik', 'koma', 'tanda', 'hubung', 'kurung',
    'petik', 'seru', 'tanya', 'miring', 'tebal', 'bawah', 'coret', 'spasi', 'jarak', 'baris',
    'kolom', 'sel', 'kotak', 'bingkai', 'latar', 'depan', 'belakang', 'samping', 'kiri', 'kanan',
    'utara', 'selatan', 'timur', 'barat', 'pusat', 'cabang', 'induk', 'anak', 'cucu', 'nenek',
    'kakek', 'ayah', 'bapak', 'ibu', 'mama', 'papa', 'kakak', 'adik', 'saudara', 'keluarga',
    'kerabat', 'tetangga', 'teman', 'kawan', 'sahabat', 'lawan', 'musuh', 'mitra', 'rekan',
    'guru', 'dosen', 'siswa', 'murid', 'mahasiswa', 'pelajar', 'peneliti', 'penulis', 'editor',
    'reviewer', 'penerbit', 'pembaca', 'responden', 'subjek', 'objek', 'sampel', 'populasi',
    'variabel', 'indikator', 'instrumen', 'kuesioner', 'wawancara', 'observasi', 'eksperimen',
    'survei', 'analisis', 'diagnosis', 'prognosis', 'sintesis', 'hipotesis', 'teori', 'konsep',
    'prinsip', 'azas', 'dasar', 'landasan', 'kerangka', 'alur', 'tahap', 'langkah', 'proses',
    'prosedur', 'teknik', 'metode', 'pendekatan', 'strategi', 'taktik', 'kebijakan', 'program',
    'kegiatan', 'upaya', 'usaha', 'tindakan', 'perilaku', 'sikap', 'tahu', 'paham', 'persepsi',
    'keyakinan', 'kepercayaan', 'harapan', 'minat', 'motivasi', 'niat', 'keputusan', 'pilihan',
    'perubahan', 'perkembangan', 'pertumbuhan', 'peningkatan', 'penurunan', 'perbaikan',
    'kerusakan', 'gangguan', 'kelainan', 'penyakit', 'infeksi', 'radang', 'luka', 'nyeri',
    'demam', 'batuk', 'pilek', 'diare', 'muntah', 'mual', 'pusing', 'lemas', 'pingsan', 'kejang',
    'sesak', 'bengkak', 'ruam', 'gatal', 'pendarahan', 'perdarahan', 'anemia', 'hipertensi',
    'diabetes', 'kanker', 'tumor', 'kista', 'stroke', 'jantung', 'paru', 'hati', 'ginjal',
    'lambung', 'usus', 'rahim', 'payudara', 'puting', 'asi', 'susu', 'formula', 'madu',
    'air', 'kelapa', 'gula', 'garam', 'minyak', 'tepung', 'beras', 'nasi', 'roti', 'sayur',
    'buah', 'daging', 'ikan', 'telur', 'tempe', 'tahu', 'kacang', 'sumber', 'protein', 'lemak',
    'karbohidrat', 'vitamin', 'mineral', 'serat', 'kalori', 'energi', 'nutrisi', 'gizi',
    'lembaga', 'instansi', 'dinas', 'kantor', 'badan', 'kementerian', 'pemerintah', 'swasta',
    'masyarakat', 'komunitas', 'kelompok', 'organisasi', 'yayasan', 'perusahaan', 'perseroan',
    'rumah', 'sakit', 'puskesmas', 'klinik', 'apotek', 'laboratorium', 'sekolah', 'kampus',
    'universitas', 'institut', 'akademi', 'politeknik', 'fakultas', 'jurusan', 'prodi',
    'program', 'studi', 'departemen', 'bagian', 'bidang', 'ilmu', 'sains', 'teknologi',
    'kesehatan', 'kedokteran', 'keperawatan', 'kebidanan', 'farmasi', 'biomedis', 'gizi',
    'sanitasi', 'lingkungan', 'epidemiologi', 'biostatistik', 'promosi', 'preventif', 'kuratif',
    'rehabilitatif', 'paliatif', 'holistik', 'komprehensif', 'terpadu', 'berkelanjutan',
    'kisi', 'berkelanjutan', 'karya', 'inovasi', 'kendari', 'sulawesi', 'tenggara', 'gorontalo',
    'indonesia', 'nasional', 'internasional', 'global', 'lokal', 'regional', 'daerah', 'desa',
    'kelurahan', 'kecamatan', 'kabupaten', 'provinsi', 'kota', 'negara', 'benua', 'dunia',
    'surya', 'bulan', 'bintang', 'bumi', 'langit', 'laut', 'sungai', 'gunung', 'hutan', 'tanah',
    'udara', 'angin', 'hujan', 'panas', 'dingin', 'iklim', 'cuaca', 'musim', 'alam', 'hayati',
    'nabati', 'hewani', 'manusia', 'insan', 'orang', 'pria', 'wanita', 'laki', 'perempuan',
    'dewasa', 'remaja', 'anak', 'balita', 'baduta', 'bayi', 'neonatus', 'janin', 'embrio',
    'lansia', 'manula', 'ibu', 'hamil', 'bersalin', 'nifas', 'menyusui', 'bumil', 'busui',
    'catatan', 'keterangan', 'tabel', 'grafik', 'gambar', 'lampiran', 'ringkasan', 'abstrak',
    'pendahuluan', 'metode', 'hasil', 'pembahasan', 'kesimpulan', 'simpulan', 'saran', 'pustaka',
    'referensi', 'ucapan', 'terima', 'kasih', 'konflik', 'kepentingan', 'pendanaan', 'dana',
    'izin', 'etik', 'persetujuan', 'sukarela', 'rahasia', 'anonim', 'valid', 'reliabel',
    'signifikan', 'bermakna', 'hubungan', 'pengaruh', 'dampak', 'perbedaan', 'perbandingan',
    'korelasi', 'regresi', 'asosiasi', 'determinan', 'prediktor', 'risiko', 'peluang', 'rasio',
    'proporsi', 'persentase', 'frekuensi', 'distribusi', 'rata-rata', 'median', 'modus',
    'simpangan', 'baku', 'kuartil', 'rentang', 'minimum', 'maksimum', 'interval', 'kepercayaan',
    'kemaknaan', 'hipotesis', 'nol', 'alternatif', 'statistik', 'deskriptif', 'inferensial',
    'parametrik', 'nonparametrik', 'univariat', 'bivariat', 'multivariat', 'kualitatif',
    'kuantitatif', 'observasional', 'analitik', 'potong', 'lintang', 'kohort', 'kasus', 'kontrol',
    'acak', 'purposif', 'aksidental', 'jenuh', 'klaster', 'strata', 'acak', 'sederhana',
    'sistematis', 'kuota', 'bola', 'salju', 'primer', 'sekunder', 'tersier', 'mentah', 'matang',
    'olah', 'bersih', 'siap', 'jadi', 'baku', 'tidak', 'baku', 'resmi', 'formal', 'informal',
    'nonformal', 'lisan', 'tulisan', 'cetak', 'elektronik', 'daring', 'luring', 'online', 'offline',
    'aplikasi', 'sistem', 'perangkat', 'lunak', 'keras', 'jaringan', 'internet', 'situs', 'web',
    'laman', 'tautan', 'pranala', 'berkas', 'dokumen', 'arsip', 'basis', 'data', 'server',
    'klien', 'pengguna', 'admin', 'pengelola', 'editor', 'penulis', 'mitra', 'bestari',
    'jurnal', 'majalah', 'buletin', 'prosiding', 'buku', 'monograf', 'modul', 'diktat',
    'skripsi', 'tesis', 'disertasi', 'laporan', 'makalah', 'artikel', 'naskah', 'manuskrip',
    'tajuk', 'rencana', 'opini', 'ulasan', 'tinjauan', 'pustaka', 'studi', 'kasus', 'singkat',
    'asli', 'orisinal', 'plagiarisme', 'sitasi', 'kutipan', 'parafrase', 'terjemahan', 'saduran',
    'edisi', 'volume', 'nomor', 'halaman', 'tahun', 'terbit', 'cetak', 'unggah', 'unduh',
    'akses', 'terbuka', 'gratis', 'berbayar', 'lisensi', 'hak', 'cipta', 'merek', 'paten',
    'logo', 'ikon', 'simbol', 'lambang', 'cap', 'stempel', 'tanda', 'tangan', 'paraf',
    'tanggal', 'tempat', 'alamat', 'kota', 'kode', 'pos', 'telepon', 'ponsel', 'surel', 'email',
    'faksimile', 'laman', 'media', 'sosial', 'akun', 'profil', 'sandi', 'kata', 'kunci',
    'masuk', 'keluar', 'daftar', 'simpan', 'hapus', 'ubah', 'sunting', 'perbarui', 'batal',
    'kirim', 'unggah', 'unduh', 'cetak', 'pratinjau', 'lihat', 'baca', 'tulis', 'ketik',
    'salin', 'tempel', 'potong', 'geser', 'tarik', 'lepas', 'klik', 'pilih', 'cari', 'saring',
    'urutkan', 'naik', 'turun', 'atas', 'bawah', 'kiri', 'kanan', 'maju', 'mundur', 'ulang',
    'mulai', 'henti', 'selesai', 'tunda', 'lanjut', 'bantu', 'tentang', 'kontak', 'privasi',
    'kebijakan', 'syarat', 'ketentuan', 'faq', 'bantuan', 'umpan', 'balik', 'saran', 'kritik',
    'peringatan', 'galat', 'kesalahan', 'sukses', 'berhasil', 'gagal', 'info', 'berita',
    'pengumuman', 'agenda', 'kegiatan', 'acara', 'seminar', 'lokakarya', 'pelatihan', 'kursus',
    'konferensi', 'simposium', 'kongres', 'rapat', 'pertemuan', 'diskusi', 'panel', 'debat',
    'dialog', 'wawancara', 'tanya', 'jawab', 'kuis', 'ujian', 'tes', 'evaluasi', 'penilaian',
    'akreditasi', 'sertifikasi', 'lisensi', 'izin', 'rekomendasi', 'persetujuan', 'penolakan',
    'revisi', 'perbaikan', 'koreksi', 'penyuntingan', 'tata', 'letak', 'desain', 'grafis',
    'tipografi', 'ilustrasi', 'fotografi', 'animasi', 'audio', 'visual', 'multimedia',
    'interaktif', 'dinamis', 'statis', 'otomatis', 'manual', 'mandiri', 'terpusat', 'terdistribusi',
    'lokal', 'jarak', 'jauh', 'cepat', 'lambat', 'ringan', 'berat', 'mudah', 'sulit', 'rumit',
    'sederhana', 'kompleks', 'lengkap', 'terbatas', 'khusus', 'umum', 'standar', 'baku', 'khas',
    'unik', 'baru', 'lama', 'kuno', 'modern', 'mutakhir', 'terkini', 'kontemporer', 'klasik',
    'tradisional', 'konvensional', 'inovatif', 'kreatif', 'efektif', 'efisien', 'produktif',
    'optimal', 'maksimal', 'minimal', 'ideal', 'layak', 'pantas', 'wajar', 'normal', 'wajib',
    'sunah', 'harus', 'perlu', 'boleh', 'dilarang', 'bebas', 'terikat', 'lepas', 'erat',
    'kuat', 'lemah', 'kokoh', 'rapuh', 'stabil', 'labil', 'tetap', 'berubah', 'dinamis',
    'konsisten', 'inkonsisten', 'akurat', 'tepat', 'presisi', 'teliti', 'cermat', 'hati-hati',
    'ceroboh', 'lalai', 'lupa', 'ingat', 'sadar', 'paham', 'mengerti', 'tahu', 'kenal', 'asing',
    'aneh', 'ganjil', 'lazim', 'umum', 'biasa', 'luar', 'biasa', 'istimewa', 'utama', 'pokok',
    'penting', 'krusial', 'esensial', 'vital', 'darurat', 'mendesak', 'segera', 'lekas', 'dini',
    'awal', 'mula', 'pertama', 'kedua', 'ketiga', 'keempat', 'kelima', 'terakhir', 'pungkas',
    'total', 'seluruh', 'semua', 'sebagian', 'separuh', 'setengah', 'seperempat', 'mayoritas',
    'minoritas', 'banyak', 'sedikit', 'cukup', 'kurang', 'lebih', 'hampir', 'nyaris', 'sekitar',
    'kira-kira', 'tepat', 'persis', 'pasti', 'tentu', 'yakin', 'ragu', 'bimbang', 'bingung',
    'setuju', 'sepakat', 'mufakat', 'kompak', 'rukun', 'damai', 'tentram', 'sejahtera',
    'makmur', 'adil', 'merata', 'jujur', 'bohong', 'dusta', 'curang', 'benar', 'sah', 'legal',
    'resmi', 'ilegal', 'liar', 'gelap', 'rahasia', 'terbuka', 'umum', 'publik', 'privat',
    'pribadi', 'individu', 'perseorangan', 'kolektif', 'bersama', 'gotong', 'royong', 'sinergi',
    'kolaborasi', 'kemitraan', 'jaringan', 'aliansi', 'koalisi', 'asosiasi', 'federasi',
    'konsorsium', 'komite', 'panitia', 'tim', 'regu', 'satuan', 'divisi', 'seksi', 'unit',
    'pos', 'balai', 'pusat', 'sentra', 'gedung', 'ruang', 'kamar', 'bangsal', 'poli', 'klinik',
    'apotek', 'toko', 'warung', 'pasar', 'mall', 'swalayan', 'koperasi', 'bank', 'kas', 'dana',
    'modal', 'biaya', 'tarif', 'harga', 'nilai', 'mutu', 'kualitas', 'kuantitas', 'kapasitas',
    'volume', 'bobot', 'massa', 'berat', 'kadar', 'konsentrasi', 'suhu', 'tekanan', 'kecepatan',
    'percepatan', 'gaya', 'energi', 'daya', 'usaha', 'kerja', 'panas', 'kalor', 'listrik',
    'magnet', 'cahaya', 'sinar', 'gelombang', 'radiasi', 'frekuensi', 'amplitudo', 'fase',
    'siklus', 'ritme', 'irama', 'pola', 'struktur', 'susunan', 'komposisi', 'komponen', 'unsur',
    'elemen', 'bagian', 'potongan', 'keping', 'butir', 'tetes', 'cairan', 'padatan', 'gas',
    'uap', 'asap', 'debu', 'partikel', 'molekul', 'atom', 'ion', 'elektron', 'proton', 'neutron',
    'sel', 'jaringan', 'organ', 'sistem', 'tubuh', 'organisme', 'makhluk', 'hidup', 'hewan',
    'tumbuhan', 'mikroba', 'bakteri', 'virus', 'jamur', 'parasit', 'kuman', 'racun', 'toksin',
    'antigen', 'antibodi', 'imun', 'kekebalan', 'alergi', 'infeksi', 'inflamasi', 'peradangan',
    'gejala', 'tanda', 'sindrom', 'komplikasi', 'prognosis', 'terapi', 'pengobatan', 'perawatan',
    'pemulihan', 'penyembuhan', 'pencegahan', 'pengendalian', 'penanggulangan', 'pemberantasan',
    'surveilans', 'deteksi', 'skrining', 'penapisan', 'pemeriksaan', 'uji', 'tes', 'coba',
    'uji', 'coba', 'eksperimen', 'simulasi', 'pemodelan', 'prediksi', 'proyeksi', 'estimasi',
    'perkiraan', 'asumsi', 'hipotesis', 'dalil', 'teorema', 'hukum', 'kaidah', 'pedoman',
    'panduan', 'petunjuk', 'manual', 'standar', 'prosedur', 'operasional', 'protokol', 'kode',
    'etik', 'etika', 'moral', 'akhlak', 'budi', 'pekerti', 'karakter', 'watak', 'sifat',
    'tabiat', 'kebiasaan', 'adat', 'istiadat', 'budaya', 'kultur', 'peradaban', 'sejarah',
    'warisan', 'tradisi', 'mitos', 'legenda', 'cerita', 'kisah', 'riwayat', 'biografi', 'profil',
    'identitas', 'jati', 'diri', 'martabat', 'kehormatan', 'hak', 'asasi', 'kebebasan',
    'tanggung', 'jawab', 'kewajiban', 'amanah', 'tugas', 'peran', 'fungsi', 'jabatan', 'posisi',
    'kedudukan', 'status', 'gelar', 'pangkat', 'golongan', 'tingkat', 'jenjang', 'level',
    'kelas', 'kategori', 'kelompok', 'jenis', 'macam', 'ragam', 'corak', 'tipe', 'variasi',
    'spesies', 'ras', 'etnis', 'suku', 'bangsa', 'warga', 'penduduk', 'rakyat', 'umat',
    'kaum', 'golongan', 'kalangan', 'lapisan', 'strata', 'komunitas', 'paguyuban', 'perkumpulan',
    'himpunan', 'ikatan', 'persatuan', 'kesatuan', 'kedaulatan', 'kemerdekaan', 'pembangunan',
    'kemajuan', 'kesejahteraan', 'kemakmuran', 'keadilan', 'kesetaraan', 'keberagaman',
    'toleransi', 'solidaritas', 'empati', 'simpati', 'peduli', 'kasih', 'sayang', 'cinta',
    'damai', 'harmoni', 'selaras', 'serasi', 'seimbang', 'lestari', 'berkelanjutan',
    'masa', 'depan', 'harapan', 'cita-cita', 'visi', 'misi', 'tujuan', 'sasaran', 'target',
    'capaian', 'prestasi', 'kinerja', 'dampak', 'manfaat', 'hasil', 'luaran', 'keluaran',
    'masukan', 'umpan', 'balik', 'tindak', 'lanjut', 'rekomendasi', 'kesimpulan', 'penutup',
    # Also exclude valid words that sometimes appear in crowd-sourced lists ambiguously:
    'saudara', 'anda', 'beliau', 'mereka', 'kami', 'kita', 'saya', 'aku', 'engkau', 'kamu',
    'dia', 'ia', 'nya', 'ku', 'mu', 'si', 'sang', 'para', 'kaum', 'umat',
    'selain', 'antara', 'seperti', 'bagaikan', 'laksana', 'umpama', 'ibarat', 'misal', 'contoh',
    'yakni', 'yaitu', 'ialah', 'adalah', 'merupakan', 'menjadi', 'terdiri', 'meliputi',
    'mencakup', 'mengandung', 'memiliki', 'mempunyai', 'terdapat', 'berada', 'terletak',
    'berasal', 'bersumber', 'berakar', 'berakhir', 'berlanjut', 'berlangsung', 'terjadi',
    'timbul', 'muncul', 'tumbuh', 'berkembang', 'meningkat', 'menurun', 'berkurang', 'bertambah',
    'berubah', 'bergeser', 'beralih', 'pindah', 'masuk', 'keluar', 'naik', 'turun', 'datang',
    'pergi', 'pulang', 'tiba', 'sampai', 'tinggal', 'menetap', 'hidup', 'mati', 'lahir',
    'tumbuh', 'tua', 'muda', 'segar', 'layu', 'sehat', 'sakit', 'sembuh', 'pulih', 'kuat',
    'lemah', 'lelah', 'letih', 'lesu', 'kantuk', 'tidur', 'bangun', 'bangkit', 'duduk',
    'berdiri', 'jalan', 'lari', 'lompat', 'terbang', 'renang', 'panjat', 'bawa', 'angkat',
    'tarik', 'dorong', 'tekan', 'pegang', 'lepas', 'lempar', 'tangkap', 'pukul', 'tendang',
    'potong', 'belah', 'iris', 'kupas', 'cuci', 'bilas', 'jemur', 'bakar', 'panggang',
    'rebus', 'kukus', 'goreng', 'tumis', 'masak', 'makan', 'minum', 'telan', 'kunyah',
    'isap', 'hirup', 'tiup', 'napas', 'batuk', 'bersin', 'tangis', 'tawa', 'senyum',
    'marah', 'sedih', 'senang', 'gembira', 'bahagia', 'takut', 'cemas', 'khawatir', 'ragu',
    'malu', 'bangga', 'heran', 'kagum', 'benci', 'rindu', 'sayang', 'cinta', 'suka', 'gemar',
    'ingin', 'harap', 'doa', 'mohon', 'minta', 'tanya', 'jawab', 'bicara', 'ucap', 'kata',
    'bilang', 'sebut', 'panggil', 'teriak', 'bisik', 'cerita', 'dongeng', 'baca', 'tulis',
    'gambar', 'lukis', 'hitung', 'ukur', 'timbang', 'nilai', 'periksa', 'teliti', 'amati',
    'lihat', 'tonton', 'dengar', 'simak', 'cium', 'raba', 'rasa', 'cicip', 'pikir', 'ingat',
    'lupa', 'renung', 'bayang', 'duga', 'kira', 'sangka', 'yakin', 'percaya', 'paham', 'tahu',
    'kenal', 'ajar', 'didik', 'latih', 'bimbing', 'arah', 'pimpin', 'atur', 'kelola', 'urus',
    'pelihara', 'rawat', 'jaga', 'lindung', 'bela', 'tolong', 'bantu', 'sokong', 'dukung',
    'beri', 'kasih', 'serah', 'kirim', 'antar', 'terima', 'ambil', 'rebut', 'curi', 'rampas',
    'simpan', 'tabung', 'belanja', 'beli', 'jual', 'tukar', 'sewa', 'pinjam', 'hutang', 'bayar',
    'lunas', 'rugi', 'untung', 'laba', 'hasil', 'panen', 'petik', 'tanam', 'tebar', 'pupuk',
    'siram', 'rawat', 'bangun', 'buat', 'bikin', 'cipta', 'rancang', 'susun', 'rakit', 'pasang',
    'bongkar', 'rusak', 'hancur', 'perbaiki', 'tambal', 'jahit', 'tenun', 'anyam', 'ukir',
    'pahat', 'cetak', 'terbit', 'edar', 'sebar', 'bagi', 'kumpul', 'himpun', 'gabung', 'padu',
    'pisah', 'cerai', 'pecah', 'belah', 'campur', 'aduk', 'saring', 'peras', 'tuang', 'isi',
    'tumpah', 'bocor', 'kering', 'basah', 'rendam', 'celup', 'hapus', 'coret', 'gosok', 'sikat',
    'sapu', 'pel', 'bersih', 'kotor', 'noda', 'bercak', 'bekas', 'jejak', 'bayangan', 'cahaya',
    'sinar', 'kilau', 'nyala', 'padam', 'gelap', 'suram', 'terang', 'bening', 'jernih', 'keruh',
    # Exclude valid Indonesian words that appear in lantip/ivanlanin as alternate/deprecated variants when they are actually valid words in modern Indonesian context:
    'pikir', 'ubah', 'paham', 'kuatir', 'kawatir', 'tapi', 'tak', 'gak', 'nggak', 'cuma', 'saja' # note: keep gak/nggak/cuma/kuatir as TIDAK BAKU! Only remove truly valid words above!
) | ForEach-Object { } | Out-Null

# Wait: refine excludeTidakBaku so real tidak-baku like kuatir, pikir->valid (fikir is tidak baku), tapi (informal in formal academic prose? keep in tidak baku or allow?), tak/gak/nggak/cuma ARE tidak baku!
# Let's rebuild excludeTidakBaku cleanly ONLY for true valid KBBI words & English overlaps:
$excludeTidakBaku.Clear()
@(
    'bh', 'bur', 'bis', 'dos', 'mas', 'mpu', 'gep', 'gip', 'goa', 'jep', 'jus', 'cru',
    'lux', 'mie', 'roh', 'sen', 'sex', 'sie', 'sky', 'sup', 'sop', 'she', 'sur', 'tes',
    'tut', 'fak', 'fas', 'pikir', 'ubah', 'paham', 'sah', 'bisa', 'data', 'seri', 'tipe',
    'survey', 'design', 'sample', 'study', 'level', 'scale', 'score', 'test', 'case', 'risk',
    'rate', 'care', 'birth', 'life', 'home', 'work', 'time', 'part', 'mean', 'mode', 'odds',
    'mal', 'set', 'cek', 'gol', 'kos', 'rok', 'tas', 'ban', 'bor', 'cap', 'cat', 'cor',
    'pas', 'di', 'ke', 'se', 'per', 'dan', 'atau', 'yang', 'dari', 'pada', 'itu', 'ini',
    'ada', 'hal', 'asi', 'imd', 'bab', 'sub', 'non', 'pra', 'pasca', 'surya', 'bulan',
    'kanker', 'tumor', 'virus', 'bakteri', 'jamur', 'stres', 'normal', 'ideal', 'vital',
    'krisis', 'fase', 'zona', 'area', 'unit', 'grup', 'poin', 'skor', 'uji', 'alat',
    'cara', 'hasil', 'metode', 'model', 'tabel', 'judul', 'tahun', 'faktor', 'nilai',
    'kadar', 'dosis', 'suhu', 'total', 'jumlah', 'ibu', 'bayi', 'anak', 'anaknya',
    'saran', 'simpul', 'kaji', 'tulis', 'baca', 'cetak', 'terbit', 'edar', 'sebar',
    'bagi', 'kumpul', 'himpun', 'gabung', 'pisah', 'campur', 'aduk', 'saring', 'isi',
    'laku', 'buat', 'beri', 'terima', 'ambil', 'kirim', 'simpan', 'buka', 'tutup',
    'pasti', 'tepat', 'jelas', 'nyata', 'benar', 'salah', 'cocok', 'sesuai', 'selaras',
    'sehat', 'sakit', 'sembuh', 'pulih', 'hamil', 'lahir', 'nifas', 'bidan', 'dokter',
    'perawat', 'klinik', 'apotek', 'obat', 'darah', 'gizi', 'nutrisi', 'energi', 'lemak',
    'protein', 'serat', 'kalori', 'vitamin', 'mineral', 'air', 'madu', 'gula', 'garam',
    'jurnal', 'artikel', 'naskah', 'abstrak', 'pustaka', 'sumber', 'kutipan', 'sitasi',
    'kuesioner', 'wawancara', 'observasi', 'responden', 'populasi', 'sampel', 'variabel',
    'analisis', 'diagnosis', 'sintesis', 'hipotesis', 'teori', 'konsep', 'prinsip',
    'teknik', 'strategi', 'kebijakan', 'program', 'sistem', 'praktik', 'risiko',
    'kualitas', 'kuantitas', 'objek', 'subjek', 'objektif', 'subjektif', 'aktivitas',
    'efektivitas', 'produktivitas', 'sensitivitas', 'kreativitas', 'validitas', 'reliabilitas',
    'persentase', 'frekuensi', 'distribusi', 'signifikan', 'standar', 'jadwal', 'izin',
    'nasihat', 'provinsi', 'sekadar', 'karena', 'meski', 'walau', 'namun', 'tetapi',
    'agar', 'supaya', 'untuk', 'demi', 'bahwa', 'hingga', 'sampai', 'sejak', 'selama',
    'sebelum', 'sesudah', 'setelah', 'ketika', 'saat', 'jika', 'bila', 'apabila', 'kalau',
    'walaupun', 'meskipun', 'sedangkan', 'melainkan', 'padahal', 'bahkan', 'justru',
    'hanyalah', 'hanya', 'saja', 'juga', 'pula', 'pun', 'lagi', 'masih', 'sudah', 'telah',
    'sedang', 'akan', 'pernah', 'belum', 'tidak', 'bukan', 'jangan', 'tanpa', 'kecuali',
    'selain', 'antara', 'terhadap', 'mengenai', 'tentang', 'menurut', 'berdasarkan',
    'melalui', 'lewat', 'dengan', 'oleh', 'karena', 'sebab', 'akibat', 'guna', 'buat',
    'kepada', 'daripada', 'dalam', 'luar', 'atas', 'bawah', 'depan', 'belakang', 'samping'
) | ForEach-Object { [void]$excludeTidakBaku.Add($_) }

# Also read ivanlanin valid keys and CSV pairs
$tidakBakuMap = @{} # lowercase wrong -> @{ baku = ..., category = ..., source = ... }

foreach ($line in ($csvRaw -split "`r?`n")) {
    if ($line -match '^([^,]+),([^,]+)$') {
        $salah = $matches[1].Trim().ToLowerInvariant()
        $benar = $matches[2].Trim().ToLowerInvariant()
        if ($salah -eq 'salah' -or $salah.Length -lt 3 -or $salah -eq $benar) { continue }
        if ($salah -match '[\s\-]' -or $benar -match '[\s\-]') { continue } # single words only from CSV; phrases handled separately
        if ($excludeTidakBaku.Contains($salah)) { continue }
        $tidakBakuMap[$salah] = @{
            baku = $benar
            category = 'kata_tidak_baku'
            source = 'KBBI VI / Referensi Ivan Lanin'
        }
    }
}

$ivanObj = $ivanRaw | ConvertFrom-Json
foreach ($prop in $ivanObj.PSObject.Properties) {
    $salah = $prop.Name.Trim().ToLowerInvariant()
    $benar = [string]$prop.Value
    $benarLower = $benar.Trim().ToLowerInvariant()
    if ($salah.Length -lt 3 -or $salah -eq $benarLower) { continue }
    if ($salah -match '[\s\-]' -or $benarLower -match '[\s\-]') { continue }
    if ($excludeTidakBaku.Contains($salah)) { continue }
    if (-not $tidakBakuMap.ContainsKey($salah)) {
        $tidakBakuMap[$salah] = @{
            baku = $benarLower
            category = 'kata_tidak_baku'
            source = 'KBBI VI / Referensi Ivan Lanin'
        }
    }
}

# Remove any circular entries (if X -> Y and Y -> Z or Y -> X, keep unambiguous)
$toRemoveCircular = @()
foreach ($k in $tidakBakuMap.Keys) {
    $target = $tidakBakuMap[$k].baku
    if ($tidakBakuMap.ContainsKey($target)) {
        $backTarget = $tidakBakuMap[$target].baku
        if ($backTarget -eq $k) {
            $toRemoveCircular += $k
        }
    }
}
foreach ($rk in $toRemoveCircular) { $tidakBakuMap.Remove($rk) }

# Curated Academic & Health-Sciences KBBI VI / EYD V Mandatory Rules (overrides any ambiguity!)
$curatedIdRules = @(
    @('analisa', 'analisis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('menganalisa', 'menganalisis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('dianalisa', 'dianalisis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('diagnosa', 'diagnosis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('mendiagnosa', 'mendiagnosis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('didiagnosa', 'didiagnosis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('prognosa', 'prognosis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('sintesa', 'sintesis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('hypotesa', 'hipotesis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('hipotesa', 'hipotesis', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('resiko', 'risiko', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('beresiko', 'berisiko', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('praktek', 'praktik', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('mempraktekkan', 'mempraktikkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('dipraktekkan', 'dipraktikkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('sistim', 'sistem', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('tehnik', 'teknik', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('tekhnik', 'teknik', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('tehnologi', 'teknologi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('tekhnologi', 'teknologi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kwalitas', 'kualitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kwantitas', 'kuantitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('obyek', 'objek', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('subyek', 'subjek', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('obyektif', 'objektif', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('subyektif', 'subjektif', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('aktifitas', 'aktivitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('efektifitas', 'efektivitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('produktifitas', 'produktivitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('sensitifitas', 'sensitivitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kreatifitas', 'kreativitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('positifitas', 'positivitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('sportifitas', 'sportivitas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('prosentase', 'persentase', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('persentasi', 'persentase', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('prosen', 'persen', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kuisioner', 'kuesioner', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('kusioner', 'kuesioner', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('kuesiner', 'kuesioner', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('metoda', 'metode', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('standart', 'standar', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('standarisasi', 'standardisasi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('nasehat', 'nasihat', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('menasehati', 'menasihati', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('apotik', 'apotek', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('ijin', 'izin', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('mengijinkan', 'mengizinkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('diijinkan', 'diizinkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('jadual', 'jadwal', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('sekedar', 'sekadar', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('propinsi', 'provinsi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('disain', 'desain', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('atlit', 'atlet', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('pasen', 'pasien', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('karna', 'karena', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('terimakasih', 'terima kasih', 'imbuhan_eyd', 'EYD V — Kata Majemuk'),
    @('kerjasama', 'kerja sama', 'imbuhan_eyd', 'EYD V — Kata Majemuk'),
    @('tanggungjawab', 'tanggung jawab', 'imbuhan_eyd', 'EYD V — Kata Majemuk'),
    @('tandatangan', 'tanda tangan', 'imbuhan_eyd', 'EYD V — Kata Majemuk'),
    @('olah raga', 'olahraga', 'imbuhan_eyd', 'KBBI VI (Badan Bahasa)'),
    @('pasca salin', 'pascasalin', 'imbuhan_eyd', 'EYD V — Bentuk Terikat'),
    @('disini', 'di sini', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('disitu', 'di situ', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('disana', 'di sana', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('diatas', 'di atas', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('dibawah', 'di bawah', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('didalam', 'di dalam', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('diluar', 'di luar', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('disamping', 'di samping', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('diantara', 'di antara', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('diantaranya', 'di antaranya', 'preposisi_eyd', 'EYD V — Kata Depan di'),
    @('dimana', 'di mana', 'preposisi_eyd', 'EYD V — Kata Depan di (atau gunakan "yang"/"tempat" jika konjungsi)'),
    @('mengkonsumsi', 'mengonsumsi', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('mempengaruhi', 'memengaruhi', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('merubah', 'mengubah', 'imbuhan_eyd', 'KBBI VI — Kata Dasar "ubah"'),
    @('dirubah', 'diubah', 'imbuhan_eyd', 'KBBI VI — Kata Dasar "ubah"'),
    @('mensurvei', 'menyurvei', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('menterjemahkan', 'menerjemahkan', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('diterjemahkan', 'diterjemahkan', 'imbuhan_eyd', 'KBBI VI'),
    @('mensosialisasikan', 'menyosialisasikan', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('mentoleransi', 'menoleransi', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('mempopulerkan', 'memopulerkan', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('mensukseskan', 'menyukseskan', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('mengkordinasikan', 'mengoordinasikan', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN- (K-T-S-P)'),
    @('koordinir', 'koordinasi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('dikoordinir', 'dikoordinasikan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('mengkoordinir', 'mengoordinasikan', 'imbuhan_eyd', 'EYD V / KBBI VI'),
    @('falid', 'valid', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('faliditas', 'validitas', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('realibilitas', 'reliabilitas', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('reabilitas', 'reliabilitas', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('signifikan', 'signifikan', 'valid', 'KBBI VI'), # skip later
    @('signifikansi', 'signifikansi', 'valid', 'KBBI VI'),
    @('karakterisitik', 'karakteristik', 'typo_medis', 'KBBI VI (Badan Bahasa)'),
    @('observasional', 'observasional', 'valid', 'KBBI VI'),
    @('korelasi', 'korelasi', 'valid', 'KBBI VI'),
    @('hirarki', 'hierarki', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('ekstrim', 'ekstrem', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kongkrit', 'konkret', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('konkrit', 'konkret', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('komplit', 'komplet', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('komplek', 'kompleks', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kontek', 'konteks', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('indera', 'indra', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('karier', 'karier', 'valid', 'KBBI VI'),
    @('karir', 'karier', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('ikhlas', 'ikhlas', 'valid', 'KBBI VI'),
    @('ihlas', 'ikhlas', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('fihak', 'pihak', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('himbau', 'imbau', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('menghimbau', 'mengimbau', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('himbauan', 'imbauan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('hembus', 'embus', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('hutang', 'utang', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('handal', 'andal', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kehandalan', 'keandalan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('jendral', 'jenderal', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('katagori', 'kategori', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('mengkategorikan', 'mengategorikan', 'imbuhan_eyd', 'EYD V / KBBI — Peluluhan Awalan meN-'),
    @('kemaren', 'kemarin', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kwalifikasi', 'kualifikasi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kwitansi', 'kuitansi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('managemen', 'manajemen', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('milyar', 'miliar', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('minimalisir', 'meminimalkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('meminimalisir', 'meminimalkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('diminimalisir', 'diminimalkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('maksimalisir', 'memaksimalkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('memaksimalisir', 'memaksimalkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('optimalisasi', 'optimalisasi', 'valid', 'KBBI VI'),
    @('mengoptimalisir', 'mengoptimalkan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('nafas', 'napas', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('bernafas', 'bernapas', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('pernafasan', 'pernapasan', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('otopsi', 'autopsi', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('paramedis', 'paramedis', 'valid', 'KBBI VI'),
    @('peduli', 'peduli', 'valid', 'KBBI VI'),
    @('perduli', 'peduli', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('pungkir', 'mungkir', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('dipungkiri', 'dimungkiri', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('ramadhan', 'ramadan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('rejeki', 'rezeki', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('respon', 'respons', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('merespon', 'merespons', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('seksama', 'saksama', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('sentralisir', 'sentralisasi', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('silahkan', 'silakan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('stasioner', 'stasioner', 'valid', 'KBBI VI'),
    @('subjek', 'subjek', 'valid', 'KBBI VI'),
    @('supir', 'sopir', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('syaraf', 'saraf', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('persyarafan', 'persarafan', 'serapan_medis', 'KBBI VI (Badan Bahasa)'),
    @('tauladan', 'teladan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('teliti', 'teliti', 'valid', 'KBBI VI'),
    @('terlanjur', 'telanjur', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('terlantar', 'telantar', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('terpercaya', 'tepercaya', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('trampil', 'terampil', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('ketrampilan', 'keterampilan', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('varises', 'varises', 'valid', 'KBBI VI'),
    @('zonasi', 'zonasi', 'valid', 'KBBI VI'),
    @('gak', 'tidak', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('nggak', 'tidak', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('enggak', 'tidak', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('cuma', 'hanya', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('doang', 'saja', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('banget', 'sangat', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('kayak', 'seperti', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('gitu', 'begitu', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('gini', 'begini', 'kata_tidak_baku', 'KBBI VI (Ragam Informal)'),
    @('kuatir', 'khawatir', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('kawatir', 'khawatir', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('ambeien', 'wasir', 'serapan_medis', 'KBBI VI'),
    @('ambeyen', 'ambeien', 'kata_tidak_baku', 'KBBI VI (Badan Bahasa)'),
    @('asma', 'asma', 'valid', 'KBBI VI'),
    @('diare', 'diare', 'valid', 'KBBI VI'),
    @('mencret', 'diare', 'kata_tidak_baku', 'KBBI VI (Ragam Informal dalam Artikel Medis)'),
    @('kencing', 'buang air kecil / berkemih', 'kata_tidak_baku', 'KBBI VI (Terminologi Klinis Medis)'),
    @('berak', 'buang air besar / defekasi', 'kata_tidak_baku', 'KBBI VI (Terminologi Klinis Medis)')
)

foreach ($rule in $curatedIdRules) {
    $wrong = $rule[0]
    $right = $rule[1]
    $cat = $rule[2]
    $src = $rule[3]
    if ($cat -eq 'valid') {
        $tidakBakuMap.Remove($wrong)
    } else {
        $tidakBakuMap[$wrong] = @{
            baku = $right
            category = $cat
            source = $src
        }
    }
}

$tidakBakuJsonPath = Join-Path $outDir 'id_baku_tidak_baku.json'
$sortedTidakBaku = [ordered]@{}
foreach ($k in ($tidakBakuMap.Keys | Sort-Object)) {
    $sortedTidakBaku[$k] = $tidakBakuMap[$k]
}
$jsonOut = $sortedTidakBaku | ConvertTo-Json -Depth 4
[System.IO.File]::WriteAllText($tidakBakuJsonPath, $jsonOut, [System.Text.UTF8Encoding]::new($false))
Write-Host "   -> Saved $($sortedTidakBaku.Count) Indonesian Baku/Tidak Baku rules to $tidakBakuJsonPath" -ForegroundColor Green

Write-Host "2. Downloading & building Indonesian KBBI + Hunspell ID valid wordlist..." -ForegroundColor Cyan
$kbbiRaw = Download-UrlText "https://raw.githubusercontent.com/damzaky/kumpulan-kata-bahasa-indonesia-KBBI/master/list_1.0.0.txt"
$hunIdRaw = Download-UrlText "https://raw.githubusercontent.com/shuLhan/hunspell-id/master/id_ID.dic"

$idSet = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
foreach ($w in ($kbbiRaw -split "`r?`n")) {
    $clean = $w.Trim().ToLowerInvariant()
    if ($clean -match '^[a-z]{2,32}$' -and -not $tidakBakuMap.ContainsKey($clean)) {
        [void]$idSet.Add($clean)
    }
}
foreach ($w in ($hunIdRaw -split "`r?`n")) {
    $base = ($w -split '/')[0].Trim().ToLowerInvariant()
    if ($base -match '^[a-z]{2,32}$' -and -not $tidakBakuMap.ContainsKey($base)) {
        [void]$idSet.Add($base)
    }
}
# Ensure curated valid Indonesian academic/health words are present
$extraValidId = @(
    'kisi', 'berkelanjutan', 'inovasi', 'observasional', 'kuantitatif', 'kualitatif',
    'bivariat', 'univariat', 'multivariat', 'responden', 'kuesioner', 'signifikan',
    'signifikansi', 'dikotomi', 'puskesmas', 'posyandu', 'pascasalin', 'pascamelahirkan',
    'pascapersalinan', 'pranikah', 'pranukleal', 'prelakteal', 'kolostrum', 'laktasi',
    'oksitosin', 'imunoglobulin', 'neonatus', 'neonatal', 'antenatal', 'pascahamil',
    'mengonsumsi', 'memengaruhi', 'mengubah', 'diubah', 'menyurvei', 'menerjemahkan',
    'menyosialisasikan', 'menoleransi', 'memopulerkan', 'menyukseskan', 'mengoordinasikan',
    'mengategorikan', 'dikoordinasikan', 'meminimalkan', 'diminimalkan', 'memaksimalkan',
    'mengoptimalkan', 'optimalisasi', 'standardisasi', 'desain', 'desainnya', 'stunting',
    'wasting', 'epidemiologi', 'biostatistik', 'biomedis', 'kebidanan', 'keperawatan',
    'kesehatan', 'farmasi', 'kedokteran', 'sosiokultural', 'predisposisi', 'reseptif',
    'empiris', 'sosiologis', 'kearifan', 'batih', 'persentase', 'persentasenya',
    'analisis', 'menganalisis', 'dianalisis', 'diagnosis', 'mendiagnosis', 'didiagnosis',
    'prognosis', 'sintesis', 'hipotesis', 'risiko', 'berisiko', 'praktik', 'mempraktikkan',
    'dipraktikkan', 'sistem', 'sistematis', 'teknik', 'teknologi', 'kualitas', 'kuantitas',
    'objek', 'subjek', 'objektif', 'subjektif', 'aktivitas', 'efektivitas', 'produktivitas',
    'sensitivitas', 'kreativitas', 'validitas', 'reliabilitas', 'metode', 'metodologi',
    'standar', 'nasihat', 'menasihati', 'apotek', 'izin', 'mengizinkan', 'diizinkan',
    'jadwal', 'terjadwal', 'sekadar', 'provinsi', 'atlet', 'pasien', 'karena', 'olahraga',
    'hierarki', 'ekstrem', 'konkret', 'komplet', 'kompleks', 'konteks', 'indra', 'karier',
    'ikhlas', 'pihak', 'imbau', 'mengimbau', 'imbauan', 'embus', 'utang', 'andal', 'keandalan',
    'jenderal', 'kategori', 'kemarin', 'kualifikasi', 'kuitansi', 'manajemen', 'miliar',
    'napas', 'bernapas', 'pernapasan', 'autopsi', 'peduli', 'mungkir', 'dimungkiri',
    'ramadan', 'rezeki', 'respons', 'merespons', 'saksama', 'silakan', 'sopir', 'saraf',
    'persarafan', 'teladan', 'telanjur', 'telantar', 'tepercaya', 'terampil', 'keterampilan',
    'khawatir', 'mengkhawatirkan', 'ambeien', 'wasir', 'diare', 'berkemih', 'defekasi',
    'independen', 'dependen', 'eksternal', 'internal', 'intervensi', 'cakupan', 'eksklusif',
    'inisiasi', 'menyusu', 'dini', 'pemberian', 'faktor', 'sosial', 'budaya', 'masyarakat',
    'pendahuluan', 'hasil', 'pembahasan', 'kesimpulan', 'simpulan', 'saran', 'sumber', 'pustaka',
    'kurangnya', 'tersebut', 'berikut', 'sehingga', 'sedangkan', 'namun', 'meskipun', 'walaupun',
    'apabila', 'dengan', 'untuk', 'dalam', 'terhadap', 'melalui', 'berdasarkan', 'secara',
    'antara', 'serta', 'maupun', 'atau', 'dan', 'yang', 'adalah', 'merupakan', 'yakni', 'yaitu',
    'hal', 'ini', 'itu', 'terbagi', 'relatif', 'berimbang', 'menunjukkan', 'mengindikasikan',
    'merefleksikan', 'relevan', 'konteks', 'sejalan', 'dikemukakan', 'ditemui', 'diduga',
    'menghadapi', 'kendala', 'akar', 'rumput', 'pegang', 'teguh', 'perlu', 'penting', 'krusial',
    'esensial', 'utama', 'mayoritas', 'minoritas', 'proporsi', 'distribusi', 'frekuensi',
    'karakteristik', 'umur', 'usia', 'pendidikan', 'pekerjaan', 'jumlah', 'tabel', 'gambar'
)
foreach ($ew in $extraValidId) { [void]$idSet.Add($ew) }

$idSorted = $idSet | Sort-Object
$idGzPath = Join-Path $outDir 'id_valid_words.txt.gz'
Write-GzipLines -Path $idGzPath -Lines $idSorted
Write-Host "   -> Saved $($idSorted.Count) Indonesian KBBI/Hunspell words to $idGzPath ($((Get-Item $idGzPath).Length) bytes)" -ForegroundColor Green

Write-Host "3. Downloading & building SCOWL US English valid wordlist + AMA/Oxford rules..." -ForegroundColor Cyan
$enRaw = Download-UrlText "https://raw.githubusercontent.com/en-wl/wordlist-diff/master/en_US.txt"

$enNonStandard = [ordered]@{
    # UK -> US Medical / AMA Manual of Style standardization
    'behaviour'       = @{ baku = 'behavior'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'behaviours'      = @{ baku = 'behaviors'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'behavioural'     = @{ baku = 'behavioral'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'analyse'         = @{ baku = 'analyze'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'analysed'        = @{ baku = 'analyzed'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'analysing'       = @{ baku = 'analyzing'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'randomised'      = @{ baku = 'randomized'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'utilised'        = @{ baku = 'utilized'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'characterised'   = @{ baku = 'characterized'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'categorised'     = @{ baku = 'categorized'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'optimised'       = @{ baku = 'optimized'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'organisation'    = @{ baku = 'organization'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'organisations'   = @{ baku = 'organizations'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'centre'          = @{ baku = 'center'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'centres'         = @{ baku = 'centers'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'paediatric'      = @{ baku = 'pediatric'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'paediatrics'     = @{ baku = 'pediatrics'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'oedema'          = @{ baku = 'edema'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'haemorrhage'     = @{ baku = 'hemorrhage'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'haemoglobin'     = @{ baku = 'hemoglobin'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'anaemia'         = @{ baku = 'anemia'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'diarrhoea'       = @{ baku = 'diarrhea'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'foetal'          = @{ baku = 'fetal'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'foetus'          = @{ baku = 'fetus'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'gynaecology'     = @{ baku = 'gynecology'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'tumour'          = @{ baku = 'tumor'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'tumours'         = @{ baku = 'tumors'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'colour'          = @{ baku = 'color'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'favour'          = @{ baku = 'favor'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'labour'          = @{ baku = 'labor'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'programme'       = @{ baku = 'program'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'labelled'        = @{ baku = 'labeled'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'counselling'     = @{ baku = 'counseling'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'aetiology'       = @{ baku = 'etiology'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'leukaemia'       = @{ baku = 'leukemia'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'caecum'          = @{ baku = 'cecum'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    'oesophagus'      = @{ baku = 'esophagus'; category = 'english_uk_us_ama'; source = 'AMA Manual of Style / Merriam-Webster' }
    # Common academic & medical English typos / non-standard forms
    'recieve'         = @{ baku = 'receive'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'recieved'        = @{ baku = 'received'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'occured'         = @{ baku = 'occurred'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'occurence'       = @{ baku = 'occurrence'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'seperate'        = @{ baku = 'separate'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'definately'      = @{ baku = 'definitely'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'questionaire'    = @{ baku = 'questionnaire'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'questioniare'    = @{ baku = 'questionnaire'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'managment'       = @{ baku = 'management'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'treatement'      = @{ baku = 'treatment'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'assesssment'     = @{ baku = 'assessment'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'signficant'      = @{ baku = 'significant'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'signifficant'    = @{ baku = 'significant'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'statisical'      = @{ baku = 'statistical'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'statistically'   = @{ baku = 'statistically'; category = 'valid'; source = 'SCOWL' }
    'brestfeeding'    = @{ baku = 'breastfeeding'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'postpartem'      = @{ baku = 'postpartum'; category = 'english_typo'; source = 'AMA Manual of Style / Merriam-Webster' }
    'reseach'         = @{ baku = 'research'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'anlaysis'        = @{ baku = 'analysis'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'conculsion'      = @{ baku = 'conclusion'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'recomendation'   = @{ baku = 'recommendation'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'recomendations'  = @{ baku = 'recommendations'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'informations'    = @{ baku = 'information'; category = 'english_nonstandard'; source = 'Oxford / Merriam-Webster (Uncountable Noun)' }
    'childrens'       = @{ baku = 'children'; category = 'english_nonstandard'; source = 'Oxford / Merriam-Webster' }
    'peoples'         = @{ baku = 'people'; category = 'english_nonstandard'; source = 'Oxford / Merriam-Webster (in clinical counts use people/participants)' }
    'datas'           = @{ baku = 'data'; category = 'english_nonstandard'; source = 'AMA Manual of Style / Oxford' }
    'evidences'       = @{ baku = 'evidence'; category = 'english_nonstandard'; source = 'Oxford / Merriam-Webster (Uncountable Noun)' }
    'researches'      = @{ baku = 'studies'; category = 'english_nonstandard'; source = 'AMA Manual of Style / Oxford (use "studies" or "research")' }
    'teh'             = @{ baku = 'the'; category = 'english_typo'; source = 'SCOWL US English' }
    'adn'             = @{ baku = 'and'; category = 'english_typo'; source = 'SCOWL US English' }
    'hte'             = @{ baku = 'the'; category = 'english_typo'; source = 'SCOWL US English' }
    'accomodate'      = @{ baku = 'accommodate'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'untill'          = @{ baku = 'until'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'occuring'        = @{ baku = 'occurring'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'refered'         = @{ baku = 'referred'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'begining'        = @{ baku = 'beginning'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'enviroment'      = @{ baku = 'environment'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'goverment'       = @{ baku = 'government'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'independant'     = @{ baku = 'independent'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'dependant'       = @{ baku = 'dependent'; category = 'english_typo'; source = 'AMA Manual of Style / Merriam-Webster' }
    'prevelance'      = @{ baku = 'prevalence'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'prevelence'      = @{ baku = 'prevalence'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'incidense'       = @{ baku = 'incidence'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'hypertention'    = @{ baku = 'hypertension'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'diabetis'        = @{ baku = 'diabetes'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'nutriton'        = @{ baku = 'nutrition'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'pregnacy'        = @{ baku = 'pregnancy'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'pregnent'        = @{ baku = 'pregnant'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'maternall'       = @{ baku = 'maternal'; category = 'english_typo'; source = 'SCOWL US English / Merriam-Webster' }
    'infants'         = @{ baku = 'infants'; category = 'valid'; source = 'SCOWL' }
    'healths'         = @{ baku = 'health'; category = 'english_nonstandard'; source = 'Oxford / Merriam-Webster' }
    'knowledges'      = @{ baku = 'knowledge'; category = 'english_nonstandard'; source = 'Oxford / Merriam-Webster' }
    'equipments'      = @{ baku = 'equipment'; category = 'english_nonstandard'; source = 'Oxford / Merriam-Webster' }
    'staf'            = @{ baku = 'staff'; category = 'english_typo'; source = 'SCOWL US English' }
    'responden'       = @{ baku = 'respondents'; category = 'english_typo'; source = 'SCOWL US English' }
    'analisis'        = @{ baku = 'analysis'; category = 'english_typo'; source = 'SCOWL US English' }
    'metode'          = @{ baku = 'methods'; category = 'english_typo'; source = 'SCOWL US English' }
}
$enNonStandard.Remove('statistically')
$enNonStandard.Remove('infants')

$enJsonPath = Join-Path $outDir 'en_nonstandard_typos.json'
[System.IO.File]::WriteAllText($enJsonPath, ($enNonStandard | ConvertTo-Json -Depth 4), [System.Text.UTF8Encoding]::new($false))
Write-Host "   -> Saved $($enNonStandard.Count) English non-standard/typo rules to $enJsonPath" -ForegroundColor Green

$enSet = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
foreach ($w in ($enRaw -split "`r?`n")) {
    $clean = $w.Trim().ToLowerInvariant()
    if ($clean -match "^[a-z][a-z'\-]{1,34}$" -and -not $enNonStandard.Contains($clean)) {
        [void]$enSet.Add($clean)
    }
}
$extraValidEn = @(
    'socio-cultural', 'sociocultural', 'postpartum', 'antepartum', 'intrapartum',
    'breastfeeding', 'non-communicable', 'cross-sectional', 'chi-square', 'p-value',
    'skin-to-skin', 'life-saving', 'bivariate', 'univariate', 'multivariate',
    'colostrum', 'prelacteal', 'primigravida', 'multigravida', 'primipara', 'multipara',
    'anthropometric', 'anthropometry', 'stunting', 'wasting', 'underweight',
    'immunoglobulin', 'oxytocin', 'lactation', 'antenatal', 'neonatal', 'perinatal',
    'morbidity', 'mortality', 'comorbidity', 'comorbidities', 'biomarker', 'biomarkers',
    'telemedicine', 'telehealth', 'covid-19', 'sars-cov-2', 'sdgs', 'who', 'unicef',
    'observational', 'purposive', 'accidental', 'dichotomous', 'likert', 'spearman',
    'pearson', 'wilcoxon', 'mann-whitney', 'kruskal-wallis', 'kolmogorov-smirnov',
    'regency', 'midwifery', 'midwives', 'puskesmas', 'posyandu', 'well-being',
    'nonparametric', 'parametric', 'confounder', 'confounders', 'confounding',
    'odds', 'covariate', 'covariates', 'subscale', 'subscales', 'interquartile',
    'pre-test', 'post-test', 'pretest', 'posttest', 'follow-up', 'longitudinal',
    'prospective', 'retrospective', 'randomized', 'placebo', 'prophylaxis', 'etiology'
)
foreach ($ew in $extraValidEn) { [void]$enSet.Add($ew) }

$enSorted = $enSet | Sort-Object
$enGzPath = Join-Path $outDir 'en_valid_words.txt.gz'
Write-GzipLines -Path $enGzPath -Lines $enSorted
Write-Host "   -> Saved $($enSorted.Count) SCOWL US English words to $enGzPath ($((Get-Item $enGzPath).Length) bytes)" -ForegroundColor Green

Write-Host "4. Writing biomedical & Indonesian health terminology whitelist..." -ForegroundColor Cyan
$medTerms = @(
    'who', 'unicef', 'sdgs', 'sdg', 'imd', 'asi', 'mpasi', 'akb', 'aki', 'bblr', 'puskesmas',
    'posyandu', 'sdki', 'riskesdas', 'ssgi', 'bps', 'kemenkes', 'depkes', 'dinkes', 'rsud',
    'spss', 'anova', 'ancova', 'manova', 'aor', 'ci', 'sd', 'se', 'iqr', 'bmi', 'imt',
    'hb', 'spo2', 'covid', 'hiv', 'aids', 'tbc', 'tb', 'dm', 'ht', 'anc', 'pnc', 'inc',
    'kia', 'kb', 'kie', 'irt', 'pns', 'sma', 'smp', 'sd', 'sltp', 'slta', 'pt', 'ri', 'diy',
    'dki', 'et', 'al', 'chi', 'square', 'chi-square', 'p-value', 'value', 'cross', 'sectional',
    'cross-sectional', 'accidental', 'sampling', 'purposive', 'post', 'partum', 'postpartum',
    'skin-to-skin', 'contact', 'informed', 'consent', 'life-saving', 'intervention', 'kolostrum',
    'colostrum', 'oksitosin', 'oxytocin', 'imunoglobulin', 'immunoglobulin', 'pranukleal',
    'prelacteal', 'stunting', 'wasting', 'neonatus', 'neonatal', 'antenatal', 'perinatal',
    'intranatal', 'postnatal', 'primigravida', 'multigravida', 'primipara', 'multipara',
    'bivariat', 'univariat', 'multivariat', 'observasional', 'likert', 'kolmogorov', 'smirnov',
    'mann', 'whitney', 'kruskal', 'wallis', 'wilcoxon', 'spearman', 'pearson', 'fisher',
    'yates', 'mcnemar', 'anova', 'logistic', 'cox', 'kaplan', 'meier', 'cronbach', 'alpha',
    'notoatmodjo', 'sugiyono', 'arikunto', 'nursalam', 'sastroasmoro', 'ismail', 'zakaria',
    'gorontalo', 'kendari', 'asparaga', 'sulawesi', 'jawa', 'sumatera', 'kalimantan', 'papua',
    'maluku', 'bali', 'lombok', 'makassar', 'manado', 'yogyakarta', 'jakarta', 'bandung',
    'surabaya', 'semarang', 'medan', 'palembang', 'denpasar', 'malang', 'solo', 'surakarta',
    'orcid', 'doi', 'issn', 'e-issn', 'eissn', 'jats', 'xml', 'ojs', 'pkp', 'amelica', 'scopus',
    'sinta', 'garuda', 'doaj', 'pubmed', 'pmc', 'icmje', 'ama', 'apa', 'strobe', 'consort', 'prisma',
    'kisi', 'berkelanjutan', 'smk', 'kisf', 'nakes', 'bumil', 'busui', 'balita', 'baduta',
    'phbs', 'germas', 'stbm', 'bpjs', 'jkn', 'kis', 'kader', 'bidan', 'perawat', 'dokter',
    'farmasis', 'apoteker', 'nutrisionis', 'epidemiolog', 'sanitarian', 'promkes', 'kesling',
    'kespro', 'kesjaor', 'p2p', 'ptm', 'ispa', 'dbd', 'malaria', 'filariasis', 'kusta', 'frambusia',
    'hepatitis', 'pneumonia', 'sepsis', 'asfiksia', 'ikterus', 'preeklampsia', 'eklampsia',
    'anemia', 'leukemia', 'trombositopenia', 'hipertensi', 'hipotensi', 'takikardia', 'bradikardia',
    'dispnea', 'takipnea', 'hipoksia', 'sianosis', 'edema', 'ascites', 'hematoma', 'hemoragi',
    'perdarahan', 'placenta', 'plasenta', 'previa', 'solusio', 'atonia', 'uteri', 'uterus',
    'serviks', 'vagina', 'perineum', 'episiotomi', 'lokia', 'lochea', 'involusi', 'fundus',
    'laktogenesis', 'prolaktin', 'progesteron', 'estrogen', 'kortisol', 'insulin', 'glukosa',
    'hba1c', 'kolesterol', 'trigliserida', 'hdl', 'ldl', 'ureum', 'kreatinin', 'sgot', 'sgpt',
    'bilirubin', 'albumin', 'globulin', 'hematokrit', 'eritrosit', 'leukosit', 'trombosit',
    'mmhg', 'mg/dl', 'mmol/l', 'g/dl', 'iu/l', 'u/l', 'ml', 'kg', 'cm', 'mm', 'mcg', 'ug',
    'bp', 'hr', 'rr', 'gcs', 'vas', 'nrs', 'apgar', 'barthel', 'katz', 'mmse', 'dass', 'hars',
    'epds', 'psqi', 'sf-36', 'whoqol', 'srq-20', 'kpsp', 'ddst', 'kia', 'kms'
) | Sort-Object -Unique
$medPath = Join-Path $outDir 'medical_terms.txt'
[System.IO.File]::WriteAllText($medPath, (($medTerms -join "`n") + "`n"), [System.Text.UTF8Encoding]::new($false))
Write-Host "   -> Saved $($medTerms.Count) medical/biostatistics terms to $medPath" -ForegroundColor Green
Write-Host "DONE building authoritative lexicons!" -ForegroundColor Green
