<?php
/**
 * @file plugins/generic/kisiTypesetter/tests/run-tests.php
 *
 * Standalone PHPUnit-free automated test suite for CI (GitHub Actions PHP 8.2/8.3)
 * and local verification when PHP CLI is available.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/HealthTableFormatter.php';
require_once $root . '/classes/LanguageAuditor.php';
require_once $root . '/classes/VancouverFormatter.php';
require_once $root . '/classes/GeminiRestructurer.php';
require_once $root . '/classes/DocxParser.php';
require_once $root . '/classes/DocxGenerator.php';
require_once $root . '/classes/JatsXmlGenerator.php';
require_once $root . '/classes/JatsHtmlRenderer.php';

use APP\plugins\generic\kisiTypesetter\classes\DocxGenerator;
use APP\plugins\generic\kisiTypesetter\classes\DocxParser;
use APP\plugins\generic\kisiTypesetter\classes\GeminiRestructurer;
use APP\plugins\generic\kisiTypesetter\classes\HealthTableFormatter;
use APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer;
use APP\plugins\generic\kisiTypesetter\classes\JatsXmlGenerator;
use APP\plugins\generic\kisiTypesetter\classes\LanguageAuditor;
use APP\plugins\generic\kisiTypesetter\classes\VancouverFormatter;

$failures = 0;
function assertTrue(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$msg}\n");
        $failures++;
    } else {
        echo "[PASS] {$msg}\n";
    }
}

// 1. Test HealthTableFormatter
$sampleTable = [
    'type' => 'table',
    'caption' => 'Tabel 1 Karakteristik Responden.',
    'footnote' => '',
    'rows' => [
        [
            ['text' => 'Karakteristik', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => true],
            ['text' => 'Frekuensi (n)', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => true],
            ['text' => 'Persentase (%)', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => true],
            ['text' => 'p-value', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => true],
            ['text' => 'X2', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => true],
        ],
        [
            ['text' => 'Umur (tahun)', 'colspan' => 5, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
        ],
        [
            ['text' => '< 20', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '9', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '30.0', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '0,03', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '8.571', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
        ],
        [
            ['text' => 'Jumlah', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '30', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '100.0', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
            ['text' => '', 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => false],
        ],
    ],
];

$formattedTbl = HealthTableFormatter::formatTableBlock($sampleTable, [
    'table_num' => 1,
    'suggest_footnote' => true,
    'lang' => 'id',
]);

assertTrue($formattedTbl['caption'] === 'Tabel 1. Karakteristik Responden', 'HealthTable caption normalized without trailing period');
assertTrue(($formattedTbl['col_alignments'][0] ?? '') === 'left', 'Stub column aligned left');
assertTrue(($formattedTbl['col_alignments'][1] ?? '') === 'center', 'Numeric column aligned center');
assertTrue(($formattedTbl['rows'][1][0]['row_type'] ?? '') === 'group', 'Variable group row classified');
assertTrue(($formattedTbl['rows'][2][0]['row_type'] ?? '') === 'subitem', 'Subcategory row classified for indent');
assertTrue(($formattedTbl['rows'][3][0]['row_type'] ?? '') === 'total', 'Total/Jumlah row classified');
assertTrue(($formattedTbl['rows'][0][4]['text'] ?? '') === 'χ²', 'Statistical X2 normalized to χ²');
assertTrue(strpos($formattedTbl['footnote'] ?? '', 'Chi-square') !== false, 'HealthTable suggested footnote generated');

// 2. Test LanguageAuditor (Indonesian & English)
$articleAuditInput = [
    'title_id' => 'Analisa Resiko Pemberian ASI pada Ibu Pascasalin',
    'title_en' => 'Behaviour Analysis of Brestfeeding among Postpartum Mothers',
    'authors' => [
        ['name' => 'Fatmah Zakaria', 'affiliation' => 'Universitas Muhammadiyah Gorontalo', 'email' => 'a@b.id', 'is_corresponding' => true],
    ],
    'abstract_id' => [
        'pendahuluan_metode' => 'Penelitian ini di lakukan diwilayah Puskesmas Asparaga menggunakan kuisioner dan mengkonsumsi cairan pranukleal.',
        'hasil' => "Terdapat peneltian   dengan\u{00A0}\u{00A0}skor p-value = 0,03.",
        'kesimpulan_saran' => 'Agar supaya praktik membaik.',
    ],
    'abstract_en' => [
        'intro_methods' => 'This study analysed datas from 30 mothers.',
        'results' => 'There was  a   signficant difference.',
        'conclusions_recommendations' => 'Further studies are needed.',
    ],
    'keywords_id' => 'Pemberian ASI; Sosial budaya',
    'keywords_en' => 'Breastfeeding; Socio-cultural',
    'sections' => [
        'pendahuluan' => [
            ['type' => 'paragraph', 'text' => 'Penelitian di Puskesmas Asparaga Kabupaten Gorontalo dilakukan   oleh Zakaria menggunakan kontak kulit ke kulit (skin-to-skin contact).'],
        ],
        'metode' => [],
        'hasil' => [$formattedTbl],
        'pembahasan' => [],
        'kesimpulan_saran' => [],
    ],
    'referensi' => ['Notoatmodjo, 2012. Metodologi Penelitian Kesehatan. Jakarta:   Rineka Cipta.'],
];

$audit = LanguageAuditor::audit($articleAuditInput, ['body_lang' => 'id']);
$foundTokens = array_map(fn ($i) => strtolower((string) $i['found']), $audit['issues']);
$categories = array_map(fn ($i) => (string) ($i['category'] ?? ''), $audit['issues']);
$multiSpaceIssues = array_values(array_filter($audit['issues'], fn ($i) => ($i['category'] ?? '') === 'spasi_ganda'));

assertTrue(in_array('analisa', $foundTokens, true), 'Detects Indonesian tidak baku: analisa -> analisis');
assertTrue(in_array('resiko', $foundTokens, true), 'Detects Indonesian tidak baku: resiko -> risiko');
assertTrue(in_array('kuisioner', $foundTokens, true), 'Detects Indonesian tidak baku: kuisioner -> kuesioner');
assertTrue(in_array('mengkonsumsi', $foundTokens, true), 'Detects Indonesian EYD V peluluhan: mengkonsumsi -> mengonsumsi');
assertTrue(in_array('di lakukan', $foundTokens, true), 'Detects separated passive prefix: di lakukan -> dilakukan');
assertTrue(in_array('diwilayah', $foundTokens, true), 'Detects merged preposition: diwilayah -> di wilayah');
assertTrue(in_array('agar supaya', $foundTokens, true), 'Detects academic pleonasm: agar supaya');
assertTrue(in_array('peneltian', $foundTokens, true), 'Detects Indonesian typo: peneltian');
assertTrue(in_array('behaviour', $foundTokens, true), 'Detects UK vs US AMA English: behaviour -> behavior');
assertTrue(in_array('brestfeeding', $foundTokens, true), 'Detects English typo: brestfeeding -> breastfeeding');
assertTrue(in_array('datas', $foundTokens, true), 'Detects English non-standard plural: datas -> data');
assertTrue(in_array('signficant', $foundTokens, true), 'Detects English typo: signficant -> significant');
assertTrue(in_array('spasi_ganda', $categories, true) && count($multiSpaceIssues) >= 4, 'Rejects >= 2 consecutive spaces / NBSP across paragraphs, EN abstract, and references');
assertTrue(($multiSpaceIssues[0]['severity'] ?? '') === 'error', 'Marks >= 2 consecutive spaces rule as strict error severity');
assertTrue(LanguageAuditor::normalizeParagraphSpacing("Kata   satu\t\u{00A0}dua    tiga.") === 'Kata satu dua tiga.', 'normalizeParagraphSpacing collapses tabs, NBSP, and multiple spaces into 1 space');
assertTrue(!in_array('gorontalo', $foundTokens, true), 'Does NOT false-positive author affiliation city Gorontalo');
assertTrue(!in_array('zakaria', $foundTokens, true), 'Does NOT false-positive author surname Zakaria');
assertTrue(!in_array('puskesmas', $foundTokens, true), 'Does NOT false-positive medical/health term puskesmas');
assertTrue(!in_array('dilakukan', $foundTokens, true), 'Does NOT false-positive valid affixed Indonesian word dilakukan');

// 3. Test JatsXmlGenerator + JatsHtmlRenderer
$articleFull = array_merge($articleAuditInput, [
    'submission_id' => 76,
    'elocation_id' => 'e76',
    'article_type' => 'ORIGINAL ARTICLE',
    'corresponding_name' => 'Fatmah Zakaria',
    'corresponding_email' => 'fatmahzakaria@umgo.ac.id',
    'doi' => '10.5281/zenodo.21865626',
    'review_editor' => 'Hikmawati (Poltekkes Kendari)',
    'published_date' => 'March 30, 2024',
    'copyright_year' => '2024',
    'volume' => '1',
    'issue_number' => '1',
    'article_url' => 'https://kisiberkelanjutan.com/index.php/smk/article/view/76',
    'sections' => [
        'pendahuluan' => [['type' => 'paragraph', 'text' => "Paragraf   pendahuluan\u{00A0}\u{00A0}dengan   spasi berlebih."]],
        'metode' => [['type' => 'paragraph', 'text' => 'Paragraf metode.']],
        'hasil' => [$formattedTbl],
        'pembahasan' => [
            ['type' => 'subheading', 'text' => 'Sub Judul Level 2'],
            ['type' => 'subheading_l3', 'text' => 'Sub Sub Judul Level 3'],
            ['type' => 'paragraph', 'text' => 'Paragraf pembahasan.'],
        ],
        'kesimpulan_saran' => [['type' => 'paragraph', 'text' => 'Paragraf simpulan.']],
    ],
]);

$xml = JatsXmlGenerator::generate($articleFull);
$dom = new DOMDocument();
assertTrue($dom->loadXML($xml) !== false, 'JatsXmlGenerator produces well-formed XML');
assertTrue(strpos($xml, '<table-wrap-foot>') !== false, 'JATS XML contains <table-wrap-foot>');
assertTrue(strpos($xml, 'frame="hsides" rules="groups"') !== false, 'JATS XML table uses three-line frame/rules');
assertTrue(strpos($xml, 'Paragraf pendahuluan dengan spasi berlebih.') !== false, 'JatsXmlGenerator auto-normalizes multiple consecutive spaces in paragraphs to 1 space');

$html = JatsHtmlRenderer::renderFullTextHtml($xml, ['include_abstract' => true]);
assertTrue(strpos($html, 'kisi-jats-table-foot') !== false, 'JatsHtmlRenderer renders table footnotes');
assertTrue(strpos($html, 'kisi-row-group') !== false, 'JatsHtmlRenderer renders variable group rows');
assertTrue(strpos($html, 'kisi-cell-subitem') !== false, 'JatsHtmlRenderer renders indented subitems');
assertTrue(strpos($html, 'kisi-row-total') !== false, 'JatsHtmlRenderer renders total rows');

// 4. Test DocxGenerator + DocxParser round-trip
$masterDocx = $root . '/resources/master_template.docx';
$tmpDocx = tempnam(sys_get_temp_dir(), 'kisi_test_') . '.docx';
$genOk = DocxGenerator::generate($articleFull, $masterDocx, $tmpDocx);
assertTrue($genOk && file_exists($tmpDocx), 'DocxGenerator generates DOCX from master template');

$parsed = DocxParser::parse($tmpDocx);
assertTrue(!empty($parsed['sections']['hasil']), 'DocxParser parses HASIL section back');
$parsedHasTable = false;
$parsedHasFootnote = false;
foreach ($parsed['sections']['hasil'] as $blk) {
    if (($blk['type'] ?? '') === 'table') {
        $parsedHasTable = true;
        if (strpos((string) ($blk['footnote'] ?? ''), 'Chi-square') !== false) {
            $parsedHasFootnote = true;
        }
    }
}
assertTrue($parsedHasTable, 'DocxParser extracts table from generated DOCX');
assertTrue($parsedHasFootnote, 'DocxParser re-attaches table footnote below table');
$introParaText = (string) ($parsed['sections']['pendahuluan'][0]['text'] ?? '');
assertTrue($introParaText === 'Paragraf pendahuluan dengan spasi berlebih.', 'DOCX round-trip enforces strictly 1 space inside paragraphs');
@unlink($tmpDocx);

// 5. Test Abstract-Exempt Categories (DATA REPORT & LETTER)
assertTrue(LanguageAuditor::isNoAbstractArticleType('LETTER') === true, 'Recognizes LETTER as abstract-exempt category');
assertTrue(LanguageAuditor::isNoAbstractArticleType('DATA REPORT') === true, 'Recognizes DATA REPORT as abstract-exempt category');
assertTrue(LanguageAuditor::isNoAbstractArticleType('ORIGINAL ARTICLE') === false, 'Keeps ORIGINAL ARTICLE with structured abstract');

$letterData = [
    'submission_id' => 32,
    'elocation_id' => 'e32',
    'article_type' => 'LETTER',
    'title_id' => 'Sosialisasi Dampak dan Pencegahan TB dan HIV pada Remaja',
    'title_en' => 'Health Education on the Impacts and Prevention of Tuberculosis and HIV Infections',
    'authors' => [
        ['name' => 'Lena Atoy', 'affiliation' => 'Poltekkes Kemenkes Kendari', 'email' => '', 'is_corresponding' => false],
        ['name' => 'Hikmawati', 'affiliation' => 'Poltekkes Kemenkes Kendari', 'email' => 'hikma2714@gmail.com', 'is_corresponding' => true],
    ],
    'corresponding_name' => 'Hikmawati',
    'corresponding_email' => 'hikma2714@gmail.com',
    'citation' => 'Atoy et al., (2024). Health Education...',
    'doi' => '10.5281/zenodo.23914666',
    'review_editor' => 'Ainul Rafiq',
    'published_date' => 'December 18, 2024',
    'copyright_year' => '2024',
    'volume' => '1',
    'issue_number' => '4',
    'funding' => 'Poltekkes Kemenkes Kendari',
    'competing_interests' => 'The authors have declared that no competing interests exist.',
    'abstract_id' => ['pendahuluan_metode' => 'Seharusnya diabaikan untuk Letter.', 'hasil' => '', 'kesimpulan_saran' => ''],
    'keywords_id' => 'Tuberkulosis; HIV',
    'abstract_en' => ['intro_methods' => 'Should be omitted.', 'results' => '', 'conclusions_recommendations' => ''],
    'keywords_en' => 'Tuberculosis; HIV',
    'sections' => [
        'badan_artikel' => [
            ['type' => 'paragraph', 'text' => 'Gambar 1. Dokumentasi Pelaksanaan Sosialisasi'],
            ['type' => 'paragraph', 'text' => 'Penyakit infeksi seperti tuberkulosis dan HIV masih menjadi analisa masalah kesehatan.'],
        ],
        'pendahuluan' => [],
        'metode' => [],
        'hasil' => [],
        'pembahasan' => [],
        'kesimpulan_saran' => [],
    ],
    'referensi' => ['Tim Riskesdas 2018. Laporan Nasional Riskesdas 2018. Jakarta: LPB; 2019.'],
];

$letterAudit = LanguageAuditor::audit($letterData, ['body_lang' => 'id']);
$letterTokens = array_map(fn ($i) => strtolower((string) $i['found']), $letterAudit['issues']);
assertTrue(in_array('analisa', $letterTokens, true), 'LanguageAuditor audits direct body (badan_artikel) of LETTER articles');

$letterXml = JatsXmlGenerator::generate($letterData);
$letterDom = new DOMDocument();
assertTrue($letterDom->loadXML($letterXml) !== false, 'JatsXmlGenerator generates well-formed XML for LETTER');
assertTrue(strpos($letterXml, 'article-type="letter"') !== false, 'JATS root sets article-type="letter"');
assertTrue(strpos($letterXml, '<abstract') === false && strpos($letterXml, '<trans-abstract') === false && strpos($letterXml, '<kwd-group') === false, 'JATS XML omits abstract and keywords for LETTER');
assertTrue(strpos($letterXml, 'PENDAHULUAN') === false, 'JATS XML emits direct body without forced PENDAHULUAN heading for LETTER');

$letterHtml = JatsHtmlRenderer::renderFullTextHtml($letterXml, ['include_abstract' => true]);
assertTrue(strpos($letterHtml, 'kisi-sec-abstract') === false, 'JatsHtmlRenderer omits abstract block for LETTER');
assertTrue(strpos($letterHtml, 'kisi-sec-body') !== false && strpos($letterHtml, 'Dokumentasi Pelaksanaan Sosialisasi') !== false, 'JatsHtmlRenderer renders direct body paragraphs of LETTER');

$dataReportData = array_merge($letterData, [
    'article_type' => 'DATA REPORT',
    'sections' => [
        'badan_artikel' => [
            ['type' => 'paragraph', 'text' => 'Laporan data penelitian surveilans kesehatan masyarakat di wilayah pesisir.'],
            $formattedTbl,
        ],
        'pendahuluan' => [],
        'metode' => [],
        'hasil' => [],
        'pembahasan' => [],
        'kesimpulan_saran' => [],
    ],
]);
$drXml = JatsXmlGenerator::generate($dataReportData);
assertTrue(strpos($drXml, 'article-type="data-report"') !== false, 'JATS root sets article-type="data-report" for DATA REPORT');
assertTrue(strpos($drXml, '<abstract') === false, 'JATS XML omits abstract for DATA REPORT');

$tmpDrDocx = tempnam(sys_get_temp_dir(), 'kisi_dr_') . '.docx';
$drOk = DocxGenerator::generate($dataReportData, $masterDocx, $tmpDrDocx);
assertTrue($drOk && file_exists($tmpDrDocx), 'DocxGenerator generates DATA REPORT DOCX without abstract rows');

$drZip = new ZipArchive();
$drDocXml = ($drZip->open($tmpDrDocx) === true) ? (string) $drZip->getFromName('word/document.xml') : '';
if ($drZip->numFiles > 0) {
    $drZip->close();
}
assertTrue($drDocXml !== '' && strpos($drDocXml, 'ABSTRAK') === false && strpos($drDocXml, 'ABSTRACT') === false, 'DATA REPORT DOCX removes ABSTRAK/ABSTRACT rows from front-matter table');
assertTrue(strpos($drDocXml, 'DATA REPORT') !== false && strpos($drDocXml, 'PENDAHULUAN') === false, 'DATA REPORT DOCX labels category DATA REPORT and goes straight into body prose');

$drParsed = DocxParser::parse($tmpDrDocx);
assertTrue(mb_strtoupper((string) ($drParsed['article_type'] ?? ''), 'UTF-8') === 'DATA REPORT', 'DocxParser detects DATA REPORT article_type');
assertTrue(!empty($drParsed['sections']['badan_artikel']), 'DocxParser restores direct body content into sections[badan_artikel] without PENDAHULUAN');
@unlink($tmpDrDocx);

// 6. Verify bundled Kisi Berkelanjutan favicon resource exists and is valid PNG
$favFile = $root . '/resources/kisi-favicon.png';
assertTrue(file_exists($favFile) && filesize($favFile) > 1000, 'Bundled Kisi Berkelanjutan favicon PNG exists');

// 7. Verify Indonesian month enforcement across ALL volumes (Vol 1, 2, 3+)
assertTrue(!LanguageAuditor::isVolume3Plus('1') && !LanguageAuditor::isVolume3Plus('2'), 'Volumes 1 and 2 use archive template mode');
assertTrue(LanguageAuditor::isVolume3Plus('3') && LanguageAuditor::isVolume3Plus('Vol. 4'), 'Volumes 3+ activate the new template mode');
assertTrue(
    LanguageAuditor::formatIndonesianFooterIssueLine('1', '1', 'January-March 2024', '2024') === 'Volume 1 Nomor 1, Januari-Maret 2024',
    'Vol 1 footer deduplicates year and uses Indonesian months'
);
assertTrue(
    LanguageAuditor::formatIndonesianFooterIssueLine('2', '4', 'October - December', '2025') === 'Volume 2 Nomor 4, Oktober-Desember 2025',
    'Vol 2 footer translates October-December to Oktober-Desember'
);
assertTrue(
    LanguageAuditor::formatIndonesianFooterIssueLine('3', '3', 'July - September 2026', '2026') === 'Volume 3 Nomor 3, Juli-September 2026',
    'Vol 3 footer translates July - September to Juli-September'
);
assertTrue(
    LanguageAuditor::translateMonthsToIndonesian('May-August 2026 & Dec 2025') === 'Mei-Agustus 2026 & Desember 2025',
    'Translates May, August, and Dec to Mei, Agustus, and Desember'
);

// Check Vol 1-2 DOCX preserves re-typeset box AND translates English footer month if accidentally provided
$vol1DocxTest = array_merge($articleFull, [
    'volume' => '1',
    'issue_number' => '1',
    'footer_issue_line' => 'Volume 1 Nomor 1, January-March 2024',
]);
$tmpV1Docx = tempnam(sys_get_temp_dir(), 'kisi_v1_') . '.docx';
assertTrue(DocxGenerator::generate($vol1DocxTest, $masterDocx, $tmpV1Docx), 'DocxGenerator builds Vol 1-2 archive DOCX');
$v1Zip = new ZipArchive();
$v1DocXml = '';
$v1FooterXml = '';
if ($v1Zip->open($tmpV1Docx) === true) {
    $v1DocXml = (string) $v1Zip->getFromName('word/document.xml');
    $v1FooterXml = (string) $v1Zip->getFromName('word/footer1.xml');
    $v1Zip->close();
}
assertTrue(strpos($v1DocXml, 're-typeset in 2026') !== false && strpos($v1DocXml, 'remains neutral') !== false, 'Vol 1-2 DOCX preserves 2026 re-typeset Publisher Note box');
assertTrue(strpos($v1DocXml, 'Received:') === false && strpos($v1DocXml, 'Accepted:') === false, 'Vol 1-2 DOCX omits Received/Accepted sidebar fields');
assertTrue(strpos($v1FooterXml, 'Januari-Maret 2024') !== false && strpos($v1FooterXml, 'January') === false, 'Vol 1-2 DOCX footer converts English month to Indonesian');
@unlink($tmpV1Docx);

// 8. Verify Volume 3+ Template (Received, Accepted, Contributor's Roles, TPB/SDGs, No re-typeset 2026 box, Indonesian Footer)
$vol3Data = [
    'submission_id' => 84,
    'elocation_id' => 'e84',
    'article_type' => 'CASE REPORT',
    'title_id' => 'Laporan Kasus: Asuhan Kebidanan pada Bayi Usia 7 Bulan',
    'title_en' => 'Case Report: Midwifery Care for a 7-Month-Old Infant',
    'authors' => [
        ['name' => 'Ain Rositahur Amalia Putri', 'affiliation' => 'Poltekkes Kemenkes Surabaya, Indonesia', 'email' => 'ain@example.com', 'is_corresponding' => true],
        ['name' => 'Suryaningsih', 'affiliation' => 'Poltekkes Kemenkes Surabaya, Indonesia', 'email' => '', 'is_corresponding' => false],
    ],
    'corresponding_name' => 'Ain Rositahur Amalia Putri',
    'corresponding_email' => 'ain@example.com',
    'citation' => 'Putri et al., (2026). Case Report: Midwifery Care... 3(3), e84.',
    'doi' => '10.5281/zenodo.23490319',
    'review_editor' => 'Inda Corniawati, SST., M.Keb (Poltekkes Kemenkes Kaltim, Indonesia)',
    'received_date' => 'August 11, 2026',
    'accepted_date' => 'September 25, 2026',
    'published_date' => 'September 30, 2026',
    'copyright_year' => '2026',
    'volume' => '3',
    'issue_number' => '3',
    'footer_issue_line' => 'Volume 3 Nomor 3, July-September 2026',
    'article_url' => 'https://kisiberkelanjutan.com/index.php/smk/article/view/84',
    'funding' => 'None',
    'competing_interests' => 'The authors have declared that no competing interests exist.',
    'contributor_roles' => 'ARAP (Conceptualization, Data Curation, Writing – Original Draft Preparation), S (Supervision, Validation).',
    'retypeset_note' => "This article was re-typeset in 2026 to standardize the journal's visual layout.",
    'abstract_id' => [
        'pendahuluan_metode' => 'Periode usia bayi memerlukan pemantauan gizi.',
        'hasil' => 'Berat badan bayi meningkat menjadi 6,1 kg.',
        'kesimpulan_saran' => 'Stimulasi pijat bayi membantu kenaikan berat badan.',
    ],
    'keywords_id' => 'Asuhan kebidanan; Bayi; Pijat bayi',
    'sdgs_id' => 'Laporan kasus ini berkontribusi pada pencapaian Tujuan Pembangunan Berkelanjutan (TPB/SDGs) Tujuan 2 dan Tujuan 3.',
    'abstract_en' => [
        'intro_methods' => 'Infancy requires close nutritional monitoring.',
        'results' => 'Infant body weight increased to 6.1 kg.',
        'conclusions_recommendations' => 'Infant massage therapy helps improve weight gain.',
    ],
    'keywords_en' => 'Infant; Infant massage; Midwifery care',
    'sdgs_en' => 'This case report contributes to achieving the Sustainable Development Goals (SDGs) Goal 2 and Goal 3.',
    'sections' => [
        'pendahuluan' => [['type' => 'paragraph', 'text' => 'Berat badan kurang pada bayi masih menjadi tantangan kesehatan masyarakat.']],
        'metode' => [['type' => 'paragraph', 'text' => 'Studi kasus dilaksanakan melalui tiga kali kunjungan rumah.']],
        'hasil' => [$formattedTbl],
        'pembahasan' => [['type' => 'paragraph', 'text' => 'Intervensi edukasi gizi dan pijat bayi menunjukkan hasil positif.']],
        'kesimpulan_saran' => [['type' => 'paragraph', 'text' => 'Pemantauan rutin perlu dilakukan setiap bulan.']],
    ],
    'referensi' => ['Kulkarni A, et al. Massage and touch therapy in neonates. Indian Pediatr. 2010;47(9):771-6.'],
];

$tmpV3Docx = tempnam(sys_get_temp_dir(), 'kisi_v3_') . '.docx';
assertTrue(DocxGenerator::generate($vol3Data, $masterDocx, $tmpV3Docx), 'DocxGenerator builds Volume 3+ DOCX');
$v3Zip = new ZipArchive();
$v3DocXml = '';
$v3Footer1 = '';
$v3Footer2 = '';
if ($v3Zip->open($tmpV3Docx) === true) {
    $v3DocXml = (string) $v3Zip->getFromName('word/document.xml');
    $v3Footer1 = (string) $v3Zip->getFromName('word/footer1.xml');
    $v3Footer2 = (string) $v3Zip->getFromName('word/footer2.xml');
    $v3Zip->close();
}
assertTrue(strpos($v3DocXml, 'Received:') !== false && strpos($v3DocXml, 'August 11, 2026') !== false, 'Vol 3+ DOCX includes Received date in sidebar');
assertTrue(strpos($v3DocXml, 'Accepted:') !== false && strpos($v3DocXml, 'September 25, 2026') !== false, 'Vol 3+ DOCX includes Accepted date in sidebar');
assertTrue(strpos($v3DocXml, 'Roles:') !== false && strpos($v3DocXml, 'ARAP (Conceptualization') !== false, 'Vol 3+ DOCX includes Contributor Roles in sidebar');
assertTrue(strpos($v3DocXml, 'TPB/SDGs') !== false && strpos($v3DocXml, 'w:fill="E8E8E8"') !== false, 'Vol 3+ DOCX renders shaded TPB/SDGs Indonesian box');
assertTrue(strpos($v3DocXml, 'Contribution to Sustainable Development Goals (SDGs)') !== false, 'Vol 3+ DOCX renders Contribution to SDGs in English abstract');
assertTrue(strpos($v3DocXml, 're-typeset in 2026') === false && strpos($v3DocXml, '<w:txbxContent') === false, 'Vol 3+ DOCX omits 2026 re-typeset textbox cleanly');
assertTrue(strpos($v3DocXml, 'remains neutral') !== false && strpos($v3DocXml, 'PT Karya Inovasi Berkelanjutan') !== false, 'Vol 3+ DOCX retains clean Publisher Note neutrality declaration');
assertTrue(strpos($v3Footer1, 'Juli-September 2026') !== false && strpos($v3Footer2, 'Juli-September 2026') !== false && strpos($v3Footer1, 'July') === false, 'Vol 3+ DOCX footers render Indonesian months (Juli-September 2026)');

$v3Parsed = DocxParser::parse($tmpV3Docx);
assertTrue($v3Parsed['received_date'] === 'August 11, 2026', 'DocxParser extracts Vol 3+ Received date');
assertTrue($v3Parsed['accepted_date'] === 'September 25, 2026', 'DocxParser extracts Vol 3+ Accepted date');
assertTrue(strpos($v3Parsed['contributor_roles'], 'ARAP (Conceptualization') !== false, 'DocxParser extracts Vol 3+ Contributor Roles');
assertTrue(strpos($v3Parsed['sdgs_id'], 'Tujuan Pembangunan Berkelanjutan') !== false, 'DocxParser extracts Vol 3+ Indonesian SDGs text');
assertTrue(strpos($v3Parsed['sdgs_en'], 'Sustainable Development Goals') !== false, 'DocxParser extracts Vol 3+ English SDGs text');
assertTrue($v3Parsed['volume'] === '3' && strpos($v3Parsed['footer_issue_line'], 'Juli-September 2026') !== false, 'DocxParser extracts Vol 3 footer issue line');
@unlink($tmpV3Docx);

$v3Xml = JatsXmlGenerator::generate($vol3Data);
$v3Dom = new DOMDocument();
assertTrue($v3Dom->loadXML($v3Xml) !== false, 'JatsXmlGenerator generates well-formed XML for Vol 3+');
assertTrue(strpos($v3Xml, '<history>') !== false && strpos($v3Xml, 'date-type="received"') !== false && strpos($v3Xml, 'date-type="accepted"') !== false, 'Vol 3+ JATS XML emits <history> with received and accepted dates');
assertTrue(strpos($v3Xml, 'fn-type="con"') !== false && strpos($v3Xml, 'ARAP (Conceptualization') !== false, 'Vol 3+ JATS XML emits Contributor Roles in <author-notes>');
assertTrue(strpos($v3Xml, 'content-type="sdgs"') !== false, 'Vol 3+ JATS XML emits SDGs sections inside abstracts');
assertTrue(strpos($v3Xml, 're-typeset in 2026') === false, 'Vol 3+ JATS XML omits 2026 re-typeset note in <back>');

$v3Html = JatsHtmlRenderer::renderFullTextHtml($v3Xml, ['include_abstract' => true]);
assertTrue(strpos($v3Html, 'Received:') !== false && strpos($v3Html, 'Accepted:') !== false && strpos($v3Html, 'ARAP (Conceptualization') !== false, 'JatsHtmlRenderer displays Vol 3+ history dates and contributor roles');
assertTrue(strpos($v3Html, 'class="kisi-jats-retypeset-box"') === false && strpos($v3Html, 'TPB/SDGs') !== false, 'JatsHtmlRenderer omits retypeset box and renders TPB/SDGs for Vol 3+');

// 9. Verify Vancouver Citation <-> Bibliography (SUMBER PUSTAKA) Synchronization
assertTrue(
    VancouverFormatter::stripLeadingNumber('1. 1. Ahmed SOM. Exclusive breastfeeding. Clin Nutr. 2023;51:44.') === 'Ahmed SOM. Exclusive breastfeeding. Clin Nutr. 2023;51:44.',
    'VancouverFormatter strips duplicate leading numbers cleanly'
);

$apaJournal = 'Smith, J. A., & Doe, B. C. (2023). Impact of exclusive breastfeeding on growth. J Nutr Health, 12(3), 44-51. https://doi.org/10.1016/j.jnh.2023.01';
$vanJournal = VancouverFormatter::formatReferenceBody($apaJournal);
assertTrue(
    $vanJournal === 'Smith JA, Doe BC. Impact of exclusive breastfeeding on growth. J Nutr Health. 2023;12(3):44-51. doi:10.1016/j.jnh.2023.01',
    'VancouverFormatter converts APA journal article with DOI URL into ICMJE Vancouver format'
);

$apaBook = 'Depkes RI. (2013). Profil Kesehatan Indonesia. Jakarta, Departemen Kesehatan Republik Indonesia.';
$vanBook = VancouverFormatter::formatReferenceBody($apaBook);
assertTrue(
    $vanBook === 'Depkes RI. Profil Kesehatan Indonesia. Jakarta: Departemen Kesehatan Republik Indonesia; 2013.',
    'VancouverFormatter converts APA institutional book entry into Vancouver format'
);

$existingVan = '1.Ahmed SOM, Ashgar RI, Abdelgader AAM. Exclusive breastfeeding. Clin Nutr Open Sci. 2023 Oct 1;51:44-51. doi:10.1016/j.nutos.2023.08.003';
assertTrue(
    VancouverFormatter::formatReferenceBody($existingVan) === 'Ahmed SOM, Ashgar RI, Abdelgader AAM. Exclusive breastfeeding. Clin Nutr Open Sci. 2023 Oct 1;51:44-51. doi:10.1016/j.nutos.2023.08.003',
    'VancouverFormatter preserves existing valid Vancouver references intact'
);

$syncAuditInput = [
    'article_type' => 'ORIGINAL ARTICLE',
    'title_id' => 'Studi Nutrisi Bayi',
    'title_en' => 'Infant Nutrition Study',
    'sections' => [
        'badan_artikel' => [],
        'pendahuluan' => [
            ['type' => 'paragraph', 'text' => 'Berat badan bayi dipengaruhi oleh asupan ASI(1). Namun laporan terdahulu (Notoatmodjo, 2012) juga mencatat kendala pada balita(9).'],
        ],
        'metode' => [],
        'hasil' => [],
        'pembahasan' => [],
        'kesimpulan_saran' => [],
    ],
    'referensi' => [
        '1. Ahmed SOM, Ashgar RI. Exclusive breastfeeding. Clin Nutr Open Sci. 2023;51:44-51.',
        'Depkes RI. (2013). Profil Kesehatan Indonesia. Jakarta: Departemen Kesehatan Republik Indonesia.',
    ],
];
$syncAudit = LanguageAuditor::audit($syncAuditInput, ['body_lang' => 'id']);
$syncCats = array_map(fn ($i) => (string) ($i['category'] ?? ''), $syncAudit['issues']);
assertTrue(in_array('vancouver_reference_style', $syncCats, true), 'LanguageAuditor flags APA-style reference entry in SUMBER PUSTAKA');
assertTrue(in_array('vancouver_citation_sync', $syncCats, true), 'LanguageAuditor flags stray APA in-text citation and out-of-range Vancouver citation (9)');

$vanDocData = array_merge($vol3Data, [
    'sections' => [
        'badan_artikel' => [],
        'pendahuluan' => [
            ['type' => 'paragraph', 'text' => 'Kekurangan zat gizi berdampak pada pertumbuhan(1) serta imunitas bayi(2). Penelitian lanjutan [1,2] menguatkan temuan tersebut.'],
        ],
        'metode' => [],
        'hasil' => [],
        'pembahasan' => [],
        'kesimpulan_saran' => [],
    ],
    'referensi' => [
        '1. Ahmed SOM, Ashgar RI. Exclusive breastfeeding. Clin Nutr Open Sci. 2023;51:44-51. doi:10.1016/j.nutos.2023.08.003',
        $apaBook,
    ],
]);

$tmpVanDocx = tempnam(sys_get_temp_dir(), 'kisi_van_') . '.docx';
assertTrue(DocxGenerator::generate($vanDocData, $masterDocx, $tmpVanDocx), 'DocxGenerator generates Vancouver-synchronized DOCX');
$vanZip = new ZipArchive();
$vanDocXml = ($vanZip->open($tmpVanDocx) === true) ? (string) $vanZip->getFromName('word/document.xml') : '';
if ($vanZip->numFiles > 0) {
    $vanZip->close();
}
assertTrue(strpos($vanDocXml, '1. Ahmed SOM') !== false && strpos($vanDocXml, '1. 1.') === false, 'DOCX SUMBER PUSTAKA renders numbered Vancouver ref #1 without double prefix');
assertTrue(strpos($vanDocXml, '2. Depkes RI. Profil Kesehatan Indonesia. Jakarta: Departemen Kesehatan Republik Indonesia; 2013.') !== false, 'DOCX SUMBER PUSTAKA auto-converts APA ref #2 to numbered Vancouver');

$vanParsed = DocxParser::parse($tmpVanDocx);
assertTrue(count($vanParsed['referensi']) === 2 && strpos($vanParsed['referensi'][1], '2013.') !== false && strpos($vanParsed['referensi'][0], '1.') !== 0, 'DocxParser round-trip preserves 2 references cleanly without duplicated number prefix');
@unlink($tmpVanDocx);

$vanXml = JatsXmlGenerator::generate($vanDocData);
$vanDom = new DOMDocument();
assertTrue($vanDom->loadXML($vanXml) !== false, 'JatsXmlGenerator generates well-formed XML with Vancouver xrefs');
assertTrue(strpos($vanXml, '<xref ref-type="bibr" rid="B1">1</xref>') !== false && strpos($vanXml, '<xref ref-type="bibr" rid="B2">2</xref>') !== false, 'JATS XML links in-text Vancouver citations (1)/(2) via <xref ref-type="bibr">');
assertTrue(strpos($vanXml, '<label>1.</label>') !== false && strpos($vanXml, '<label>2.</label>') !== false, 'JATS XML emits <label>1.</label> and <label>2.</label> in <ref-list>');
assertTrue(strpos($vanXml, 'Jakarta: Departemen Kesehatan Republik Indonesia; 2013.') !== false, 'JATS XML converts <mixed-citation> to Vancouver style');

$vanHtml = JatsHtmlRenderer::renderFullTextHtml($vanXml, ['include_abstract' => true]);
assertTrue(strpos($vanHtml, 'href="#kisi-ref-B1"') !== false && strpos($vanHtml, 'href="#kisi-ref-B2"') !== false, 'JatsHtmlRenderer links in-text Vancouver citations directly to #kisi-ref-B1/#kisi-ref-B2');
assertTrue(strpos($vanHtml, 'id="kisi-ref-B2"') !== false && strpos($vanHtml, '>1. Ahmed SOM') === false, 'JatsHtmlRenderer renders clean <ol> numbered bibliography without double 1. 1.');

// 10. Verify Style-Aware OJS References Menu Formatting (Vancouver / IEEE / APA)
assertTrue(VancouverFormatter::resolveReferenceStyle('auto') === 'vancouver', 'Default journal reference style resolves to vancouver');
assertTrue(VancouverFormatter::resolveReferenceStyle('vancouver-brackets') === 'vancouver', 'Resolves CSL vancouver variants to vancouver');
assertTrue(VancouverFormatter::resolveReferenceStyle('apa-7th-edition') === 'apa', 'Resolves CSL APA variants to apa');
assertTrue(VancouverFormatter::resolveReferenceStyle('ieee') === 'ieee', 'Resolves CSL IEEE to ieee');

$rawRefsMixed = [
    'Smith, J. A., & Doe, B. C. (2023). Impact of exclusive breastfeeding on growth. J Nutr Health, 12(3), 44-51. https://doi.org/10.1016/j.jnh.2023.01',
    'Depkes RI. (2013). Profil Kesehatan Indonesia. Jakarta, Departemen Kesehatan Republik Indonesia.',
];

$ojsBlockVan = VancouverFormatter::formatCitationsRawBlock($rawRefsMixed, 'vancouver');
$vanLines = explode("\n", $ojsBlockVan);
assertTrue(
    count($vanLines) === 2
        && strpos($vanLines[0], '1. Smith JA, Doe BC.') === 0
        && strpos($vanLines[1], '2. Depkes RI. Profil Kesehatan Indonesia. Jakarta: Departemen Kesehatan Republik Indonesia; 2013.') === 0,
    'formatCitationsRawBlock builds numbered OJS References block in Vancouver style'
);

$ojsBlockIeee = VancouverFormatter::formatCitationsRawBlock($rawRefsMixed, 'ieee');
$ieeeLines = explode("\n", $ojsBlockIeee);
assertTrue(
    count($ieeeLines) === 2 && strpos($ieeeLines[0], '[1] Smith JA, Doe BC.') === 0 && strpos($ieeeLines[1], '[2] Depkes RI.') === 0,
    'formatCitationsRawBlock supports IEEE style formatting for OJS References menu'
);

$vanSample = 'Smith JA, Doe BC. Impact of exclusive breastfeeding. J Nutr Health. 2023;12(3):44-51. doi:10.1016/j.jnh.2023.01';
$ojsBlockApa = VancouverFormatter::formatCitationsRawBlock([$vanSample], 'apa');
assertTrue(
    strpos($ojsBlockApa, 'Smith, J. A., & Doe, B. C. (2023).') === 0
        && strpos($ojsBlockApa, 'https://doi.org/10.1016/j.jnh.2023.01') !== false,
    'formatCitationsRawBlock converts Vancouver reference cleanly to APA when journal style is APA'
);

// 11. Verify JatsHtmlRenderer full-text abstract resilience across external JATS variants
$namespacedXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<article xmlns="https://jats.nlm.nih.gov/publishing/1.2/" xmlns:xlink="http://www.w3.org/1999/xlink" article-type="research-article">
  <front>
    <article-meta>
      <title-group><article-title>Studi Lapangan</article-title></title-group>
      <abstract xml:lang="id">
        <sec><title>Pendahuluan</title><p>Paragraf satu.</p><p>Paragraf dua.</p></sec>
      </abstract>
      <abstract xml:lang="en">
        <p>English standalone paragraph abstract.</p>
      </abstract>
    </article-meta>
  </front>
  <body><sec id="s1"><title>PENDAHULUAN</title><p>Isi naskah.</p></sec></body>
</article>
XML;
$nsHtml = JatsHtmlRenderer::renderFullTextHtml($namespacedXml, ['include_abstract' => true]);
assertTrue(
    strpos($nsHtml, 'Paragraf satu.') !== false
        && strpos($nsHtml, 'Paragraf dua.') !== false
        && strpos($nsHtml, 'English standalone paragraph abstract.') !== false,
    'JatsHtmlRenderer renders namespaced JATS XML abstracts, multi-paragraph <sec>, and second <abstract xml:lang="en">'
);

$englishOnlyXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<article article-type="research-article">
  <front>
    <article-meta>
      <abstract xml:lang="id"></abstract>
      <trans-abstract xml:lang="en"><p>Visible English only abstract.</p></trans-abstract>
    </article-meta>
  </front>
  <body><sec id="s1"><title>INTRODUCTION</title><p>Body text.</p></sec></body>
</article>
XML;
$enOnlyHtml = JatsHtmlRenderer::renderFullTextHtml($englishOnlyXml, ['include_abstract' => true]);
assertTrue(
    strpos($enOnlyHtml, 'id="kisi-abs-pane-en"') !== false
        && strpos($enOnlyHtml, 'style="display:block;">') !== false
        && strpos($enOnlyHtml, 'Visible English only abstract.') !== false,
    'JatsHtmlRenderer ensures English-only or empty-primary JATS abstract stays visibly displayed (never hidden)'
);

// 12. Verify English-Only Form Mode (form_mode = 'en') end-to-end across Auditor, Restructurer, DOCX & JATS XML
$enFormArticle = [
    'submission_id' => 105,
    'elocation_id' => 'e105',
    'article_type' => 'ORIGINAL ARTICLE',
    'form_mode' => 'en',
    'title_id' => '',
    'title_en' => 'Effectiveness of Community Midwifery Telemonitoring in Rural Maternal Care',
    'authors' => [
        ['name' => 'Rina Sari', 'affiliation' => 'Faculty of Health Sciences, Jakarta', 'is_corresponding' => true],
        ['name' => 'John Tan', 'affiliation' => 'Public Health Institute, Bandung', 'is_corresponding' => false],
    ],
    'corresponding_name' => 'Rina Sari',
    'corresponding_email' => 'rina@example.ac.id',
    'citation' => 'Sari, R., & Tan, J. (2026). Effectiveness of Community Midwifery Telemonitoring. Kisi Berkelanjutan, 3(3), e105.',
    'doi' => '10.5281/zenodo.999999',
    'review_editor' => 'Dr. Andi Wijaya (Universitas Indonesia)',
    'received_date' => 'August 11, 2026',
    'accepted_date' => 'September 25, 2026',
    'published_date' => 'September 30, 2026',
    'copyright_year' => '2026',
    'volume' => '3',
    'issue_number' => '3',
    'footer_issue_line' => 'Volume 3 Nomor 3, Juli-September 2026',
    'article_url' => 'https://kisiberkelanjutan.com/index.php/smk/article/view/105',
    'funding' => 'None',
    'competing_interests' => 'The authors have declared that no competing interests exist.',
    'contributor_roles' => 'RS (Conceptualization, Writing), JT (Formal Analysis).',
    'retypeset_note' => '',
    'abstract_id' => ['pendahuluan_metode' => '', 'hasil' => '', 'kesimpulan_saran' => ''],
    'keywords_id' => '',
    'sdgs_id' => '',
    'abstract_en' => [
        'intro_methods' => '',
        'results' => '',
        'conclusions_recommendations' => '',
        'raw' => 'Telemonitoring improves antenatal follow-up adherence in remote areas. We conducted a quasi-experimental study involving 120 pregnant women. Results showed that antenatal visits increased significantly (p < 0.01) by 34%. In conclusion, community telemonitoring supports safer rural maternal outcomes.',
    ],
    'keywords_en' => 'Maternal health; Midwifery; Telemonitoring',
    'sdgs_en' => 'This study contributes to achieving the Sustainable Development Goals (SDGs) Goal 3.',
    'sections' => [
        'pendahuluan' => [['type' => 'paragraph', 'text' => 'Maternal mortality remains elevated in  rural districts due to delayed follow-up [1].']],
        'metode' => [['type' => 'paragraph', 'text' => 'A prospective cohort was observed across six primary health centers.']],
        'hasil' => [['type' => 'paragraph', 'text' => 'Adherence improved from 58% to 92% among enrolled mothers.']],
        'pembahasan' => [['type' => 'paragraph', 'text' => 'Digital midwifery reminders strengthen continuity of care [1].']],
        'kesimpulan_saran' => [['type' => 'paragraph', 'text' => 'Routine deployment of telemonitoring is recommended.']],
    ],
    'referensi' => ['World Health Organization. Recommendations on antenatal care for a positive pregnancy experience. Geneva: WHO; 2022.'],
];

assertTrue(LanguageAuditor::isEnglishOnlyArticle($enFormArticle) === true, 'LanguageAuditor detects explicit English-only form_mode');
assertTrue(LanguageAuditor::isEnglishOnlyArticle(['form_mode' => 'bilingual', 'title_en' => 'Test']) === false, 'LanguageAuditor respects explicit bilingual form_mode');
assertTrue(LanguageAuditor::isEnglishOnlyArticle(['title_en' => 'English Only', 'abstract_en' => ['intro_methods' => 'Text']]) === true, 'LanguageAuditor auto-detects English-only content');

$restructuredEnOnly = GeminiRestructurer::restructureLocally($enFormArticle);
assertTrue(
    trim($restructuredEnOnly['abstract_id']['pendahuluan_metode'] ?? '') === ''
        && trim($restructuredEnOnly['abstract_en']['intro_methods'] ?? '') !== ''
        && trim($restructuredEnOnly['abstract_en']['results'] ?? '') !== ''
        && trim($restructuredEnOnly['abstract_en']['conclusions_recommendations'] ?? '') !== '',
    'GeminiRestructurer splits single-paragraph abstract_en and keeps abstract_id empty in English-only mode'
);

$auditEnOnly = LanguageAuditor::audit($restructuredEnOnly);
$auditFieldLabels = array_map(static fn($it) => (string) ($it['field'] ?? ''), $auditEnOnly['issues'] ?? []);
assertTrue(
    !in_array('Judul (ID)', $auditFieldLabels, true) && count($auditEnOnly['issues'] ?? []) >= 1,
    'LanguageAuditor audits English body spacing issues without checking unused Indonesian title/abstract fields'
);

$tmpEnDocx = tempnam(sys_get_temp_dir(), 'kisi_en_') . '.docx';
assertTrue(DocxGenerator::generate($restructuredEnOnly, $masterDocx, $tmpEnDocx), 'DocxGenerator builds English-only Vol 3+ DOCX');
$enZip = new ZipArchive();
$enDocXml = '';
if ($enZip->open($tmpEnDocx) === true) {
    $enDocXml = (string) $enZip->getFromName('word/document.xml');
    $enZip->close();
}
assertTrue(substr_count($enDocXml, 'Effectiveness of Community Midwifery Telemonitoring in Rural Maternal Care') === 1, 'English-only DOCX renders English title once (not duplicated)');
assertTrue(strpos($enDocXml, '* Corresponding author: Rina Sari') !== false && strpos($enDocXml, 'Penulis koresponden') === false, 'English-only DOCX uses Corresponding author English label');
assertTrue(strpos($enDocXml, '>ABSTRAK<') === false && strpos($enDocXml, '>ABSTRACT<') !== false, 'English-only DOCX removes empty ABSTRAK section while keeping ABSTRACT');
assertTrue(strpos($enDocXml, 'Contribution to Sustainable Development Goals (SDGs)') !== false, 'English-only Vol 3+ DOCX retains Contribution to SDGs section');

$enParsedDocx = DocxParser::parse($tmpEnDocx);
assertTrue(
    strpos($enParsedDocx['title_en'] ?? '', 'Effectiveness of Community Midwifery Telemonitoring') !== false
        && trim((string) ($enParsedDocx['title_id'] ?? '')) === ''
        && ($enParsedDocx['corresponding_name'] ?? '') === 'Rina Sari'
        && ($enParsedDocx['corresponding_email'] ?? '') === 'rina@example.ac.id'
        && trim((string) ($enParsedDocx['abstract_en']['intro_methods'] ?? '')) !== ''
        && strpos($enParsedDocx['sdgs_en'] ?? '', 'Sustainable Development Goals') !== false,
    'DocxParser cleanly round-trips English-only DOCX title, corresponding author, structured English abstract, and SDGs EN'
);
@unlink($tmpEnDocx);

$enJatsXml = JatsXmlGenerator::generate($restructuredEnOnly);
$enJatsDom = new DOMDocument();
assertTrue($enJatsDom->loadXML($enJatsXml) !== false, 'JatsXmlGenerator generates well-formed XML for English-only mode');
assertTrue(preg_match('/<article\b[^>]*\barticle-type="research-article"[^>]*\bxml:lang="en"/i', $enJatsXml) === 1, 'English-only JATS root sets xml:lang="en"');
assertTrue(strpos($enJatsXml, '<trans-title-group') === false && strpos($enJatsXml, '<abstract xml:lang="en">') !== false && strpos($enJatsXml, '<trans-abstract') === false, 'English-only JATS emits primary <abstract xml:lang="en"> without duplicate <trans-title-group> or <trans-abstract>');
assertTrue(strpos($enJatsXml, '* Corresponding author: Rina Sari') !== false, 'English-only JATS uses Corresponding author label');

$enRenderedHtml = JatsHtmlRenderer::renderFullTextHtml($enJatsXml, ['include_abstract' => true]);
assertTrue(
    strpos($enRenderedHtml, 'id="kisi-abs-pane-en"') !== false
        && strpos($enRenderedHtml, 'Telemonitoring improves antenatal follow-up adherence') !== false,
    'JatsHtmlRenderer displays English-only primary JATS abstract prominently on full-text page'
);

// 13. Verify JatsHtmlRenderer handles complex external JATS XML (BOM, entities, prefixed tags, nested sec/list, untagged English, graphical abstract & standalone galley reader)
$complexExternalXml = "\xEF\xBB\xBF" . <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<jats:article xmlns:jats="http://jats.nlm.nih.gov/ns/archiving/1.2/" xmlns:xlink="http://www.w3.org/1999/xlink" article-type="research-article" xml:lang="en">
  <jats:front>
    <jats:article-meta>
      <jats:title-group><jats:article-title>Telemedicine &amp; Maternal Care&nbsp;&ndash; Observational Cohort</jats:article-title></jats:title-group>
      <jats:contrib-group>
        <jats:contrib contrib-type="author"><jats:name><jats:surname>Sari</jats:surname><jats:given-names>Rina</jats:given-names></jats:name></jats:contrib>
      </jats:contrib-group>
      <jats:abstract abstract-type="graphical"><jats:p>Visual diagram summary.</jats:p></jats:abstract>
      <jats:abstract>
        <jats:sec>
          <jats:title>Background</jats:title>
          <jats:sec><jats:title>Clinical Context</jats:title><jats:p>Maternal follow-up in&nbsp;rural settings remains challenging.</jats:p></jats:sec>
          <jats:list list-type="bullet"><jats:list-item><jats:p>Key finding: adherence rose&nbsp;by 34%.</jats:p></jats:list-item></jats:list>
        </jats:sec>
      </jats:abstract>
      <jats:kwd-group><jats:kwd>Telemedicine</jats:kwd><jats:kwd>Maternal Health</jats:kwd></jats:kwd-group>
    </jats:article-meta>
  </jats:front>
  <jats:body>
    <jats:sec id="intro"><jats:title>INTRODUCTION</jats:title><jats:p>Body content &amp; data analysis&rsquo;s summary.</jats:p></jats:sec>
  </jats:body>
  <jats:back>
    <jats:ref-list><jats:title>REFERENCES</jats:title><jats:ref id="B1"><jats:mixed-citation>WHO. Antenatal care. Geneva: WHO; 2022.</jats:mixed-citation></jats:ref></jats:ref-list>
  </jats:back>
</jats:article>
XML;

$complexHtml = JatsHtmlRenderer::renderFullTextHtml($complexExternalXml, [
    'include_abstract' => true,
    'form_language_mode' => 'en',
]);
assertTrue(
    strpos($complexHtml, 'id="kisi-sec-abstract"') !== false
        && strpos($complexHtml, 'id="kisi-abs-pane-en"') !== false
        && strpos($complexHtml, 'id="kisi-abs-pane-id"') === false
        && strpos($complexHtml, 'Maternal follow-up in') !== false
        && strpos($complexHtml, 'adherence rose') !== false
        && strpos($complexHtml, 'Telemedicine; Maternal Health') !== false,
    'JatsHtmlRenderer renders BOM/prefixed/entity-rich/nested-sec JATS XML and classifies untagged English abstract cleanly without ID tab'
);

$extractedMap = JatsHtmlRenderer::extractAbstractTextsByLocale($complexExternalXml);
assertTrue(
    strpos($extractedMap['en']['html'] ?? '', 'Maternal follow-up') !== false
        && trim((string) ($extractedMap['id']['html'] ?? '')) === '',
    'JatsHtmlRenderer::extractAbstractTextsByLocale extracts English HTML cleanly for empty OJS publication abstract sync'
);

$enFilteredHtml = JatsHtmlRenderer::renderFullTextHtml($namespacedXml, [
    'include_abstract' => true,
    'form_language_mode' => 'en',
]);
assertTrue(
    strpos($enFilteredHtml, 'id="kisi-abs-pane-en"') !== false
        && strpos($enFilteredHtml, 'id="kisi-abs-pane-id"') === false
        && strpos($enFilteredHtml, 'English standalone paragraph abstract.') !== false,
    'JatsHtmlRenderer honors form_language_mode=en by showing English abstract directly without bilingual tabs'
);

$readerPage = JatsHtmlRenderer::renderStandaloneReaderPage($complexExternalXml, [
    'form_language_mode' => 'en',
    'article_url' => 'https://kisiberkelanjutan.com/index.php/smk/article/view/105',
]);
assertTrue(
    strpos($readerPage, '<!DOCTYPE html>') !== false
        && strpos($readerPage, 'Rina Sari') !== false
        && strpos($readerPage, 'id="kisi-sec-abstract"') !== false
        && strpos($readerPage, 'WHO. Antenatal care.') !== false,
    'JatsHtmlRenderer::renderStandaloneReaderPage produces complete XML Galley reader page including Abstract and References'
);

if ($failures > 0) {
    fwrite(STDERR, "\nTotal test failures: {$failures}\n");
    exit(1);
}
echo "\nAll KisiTypesetter automated checks passed!\n";
