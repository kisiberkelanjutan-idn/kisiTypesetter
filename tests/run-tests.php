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
require_once $root . '/classes/DocxParser.php';
require_once $root . '/classes/DocxGenerator.php';
require_once $root . '/classes/JatsXmlGenerator.php';
require_once $root . '/classes/JatsHtmlRenderer.php';

use APP\plugins\generic\kisiTypesetter\classes\DocxGenerator;
use APP\plugins\generic\kisiTypesetter\classes\DocxParser;
use APP\plugins\generic\kisiTypesetter\classes\HealthTableFormatter;
use APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer;
use APP\plugins\generic\kisiTypesetter\classes\JatsXmlGenerator;
use APP\plugins\generic\kisiTypesetter\classes\LanguageAuditor;

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

if ($failures > 0) {
    fwrite(STDERR, "\nTotal test failures: {$failures}\n");
    exit(1);
}
echo "\nAll KisiTypesetter automated checks passed!\n";
