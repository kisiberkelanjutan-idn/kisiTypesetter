<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/LanguageAuditor.php
 *
 * Bilingual (Indonesian & English) Typo, Non-Standard Word (Kata Tidak Baku),
 * and Academic Orthography Auditor backed by authoritative linguistic sources:
 *  - Indonesian: KBBI VI & EYD V (Badan Pengembangan dan Pembinaan Bahasa),
 *    Hunspell ID (LGPL-3.0), and curated KBBI baku/tidak-baku datasets.
 *  - English: SCOWL US English (en_US), AMA Manual of Style / Merriam-Webster,
 *    MeSH biomedical terminology, and optional LanguageTool / Gemini API checks.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

require_once __DIR__ . '/VancouverFormatter.php';

class LanguageAuditor
{
    /** @var array<string, int>|null */
    private static ?array $idWordMap = null;

    /** @var array<string, int>|null */
    private static ?array $enWordMap = null;

    /** @var array<string, int>|null */
    private static ?array $medicalMap = null;

    /** @var array<string, array>|null */
    private static ?array $idTidakBakuMap = null;

    /** @var array<string, array>|null */
    private static ?array $enNonStandardMap = null;

    /** @var array<int, array<int, string>>|null Length-indexed buckets for fast edit-distance suggestions */
    private static ?array $idBuckets = null;

    /** @var array<int, array<int, string>>|null */
    private static ?array $enBuckets = null;

    /**
     * Determine whether an article category omits Abstract & Keywords and flows directly into body prose.
     */
    public static function isNoAbstractArticleType(string $articleType): bool
    {
        $norm = mb_strtoupper(trim($articleType), 'UTF-8');
        if ($norm === '') {
            return false;
        }
        return (bool) preg_match('/\b(DATA\s*REPORT|LETTER|LETTERS?\s+TO\s+(?:THE\s+)?EDITOR|LAPORAN\s*DATA|SURAT\s+(?:KE\s+)?REDAKSI)\b/iu', $norm);
    }

    /**
     * Articles published in Volume 3 and onward adopt the updated 2026+ front-matter layout
     * (Received/Accepted/Contributor's Roles, SDGs boxes, and Publisher's Note without re-typeset box).
     */
    public static function isVolume3Plus($volume): bool
    {
        $digits = preg_replace('/\D+/', '', trim((string) $volume));
        return $digits !== '' && (int) $digits >= 3;
    }

    /**
     * Translate English or non-standard month names (full or abbreviated) into official Bahasa Indonesia
     * month names (KBBI / EYD V) across all volumes for footer consistency.
     */
    public static function translateMonthsToIndonesian(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $replacements = [
            '/\bJanuary\b/iu' => 'Januari',
            '/\bJan\.?\b/iu' => 'Januari',
            '/\bFebruary\b/iu' => 'Februari',
            '/\bFeb\.?\b/iu' => 'Februari',
            '/\bMarch\b/iu' => 'Maret',
            '/\bMar\.?\b/iu' => 'Maret',
            '/\bApril\b/iu' => 'April',
            '/\bApr\.?\b/iu' => 'April',
            '/\bMay\b/u' => 'Mei',
            '/\bJune\b/iu' => 'Juni',
            '/\bJun\.?\b/iu' => 'Juni',
            '/\bJuly\b/iu' => 'Juli',
            '/\bJul\.?\b/iu' => 'Juli',
            '/\bAugust\b/iu' => 'Agustus',
            '/\bAug\.?\b/iu' => 'Agustus',
            '/\bAgt\.?\b/iu' => 'Agustus',
            '/\bSeptember\b/iu' => 'September',
            '/\bSept?\.?\b/iu' => 'September',
            '/\bOctober\b/iu' => 'Oktober',
            '/\bOct\.?\b/iu' => 'Oktober',
            '/\bOkt\.?\b/iu' => 'Oktober',
            '/\bNopember\b/iu' => 'November',
            '/\bNovember\b/iu' => 'November',
            '/\bNop\.?\b/iu' => 'November',
            '/\bNov\.?\b/iu' => 'November',
            '/\bDecember\b/iu' => 'Desember',
            '/\bDec\.?\b/iu' => 'Desember',
            '/\bDes\.?\b/iu' => 'Desember',
        ];

        $out = (string) preg_replace(array_keys($replacements), array_values($replacements), $text);
        $monthsPattern = 'Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember';
        $out = (string) preg_replace('/\b(' . $monthsPattern . ')\s*[–—-]\s*(' . $monthsPattern . ')\b/u', '$1-$2', $out);
        return self::normalizeParagraphSpacing($out);
    }

    /**
     * Build canonical footer issue line: "Volume X Nomor Y, <Bulan Indonesia> <Tahun>".
     */
    public static function formatIndonesianFooterIssueLine(
        string $volume,
        string $issueNumber,
        string $periodText,
        string $year = ''
    ): string {
        $vol = trim($volume) !== '' ? trim($volume) : '1';
        $no = trim($issueNumber) !== '' ? trim($issueNumber) : '1';
        $yr = trim($year);

        $period = trim($periodText);
        // Strip leading "Volume X Nomor Y," prefix if caller passed a full line
        $period = (string) preg_replace('/^Volume\s+\S+\s+Nomor\s+\S+\s*,?\s*/iu', '', $period);
        // Strip duplicated year at the end of issue title
        if ($yr !== '') {
            $period = (string) preg_replace('/(?:\s*,)?\s*\b' . preg_quote($yr, '/') . '\b\s*$/u', '', $period);
        } elseif (preg_match('/\b(20\d{2})\s*$/u', $period, $ym)) {
            $yr = $ym[1];
            $period = trim((string) preg_replace('/(?:\s*,)?\s*\b20\d{2}\s*$/u', '', $period));
        }

        $period = self::translateMonthsToIndonesian($period);
        if ($period === '') {
            $quarterMap = [
                '1' => 'Januari-Maret',
                '2' => 'April-Juni',
                '3' => 'Juli-September',
                '4' => 'Oktober-Desember',
            ];
            $period = $quarterMap[$no] ?? 'Januari-Maret';
        }

        return sprintf('Volume %s Nomor %s, %s%s', $vol, $no, $period, $yr !== '' ? ' ' . $yr : '');
    }

    /**
     * Generate an editable CRediT initials summary from authors when none was supplied.
     */
    public static function buildDefaultContributorRoles(array $authors): string
    {
        if (empty($authors)) {
            return '';
        }
        $parts = [];
        foreach ($authors as $idx => $author) {
            if (!is_array($author)) {
                continue;
            }
            $rawName = trim((string) ($author['name'] ?? ''));
            if ($rawName === '') {
                continue;
            }
            $nameOnly = trim(explode(',', $rawName)[0]);
            $words = preg_split('/\s+/u', $nameOnly, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($words)) {
                continue;
            }
            $initials = '';
            foreach ($words as $w) {
                $ch = mb_substr($w, 0, 1, 'UTF-8');
                if (preg_match('/[\p{L}\p{N}]/u', $ch)) {
                    $initials .= mb_strtoupper($ch, 'UTF-8');
                }
            }
            if ($initials === '') {
                continue;
            }
            $roles = ($idx === 0)
                ? 'Conceptualization, Data Curation, Investigation, Methodology, Writing – Original Draft Preparation, Writing – Review & Editing'
                : 'Supervision, Validation';
            $parts[] = $initials . ' (' . $roles . ')';
        }
        if (empty($parts)) {
            return '';
        }
        return implode(', ', $parts) . '.';
    }

    /**
     * Audit all article fields for typos, non-standard words, and academic orthography issues.
     *
     * @param array $articleData Studio article state array
     * @param array $options ['body_lang' => 'auto'|'id'|'en', 'use_languagetool' => bool, 'use_gemini' => bool, 'gemini_api_key' => string, 'gemini_model' => string]
     * @return array ['issues' => [...], 'summary' => [...], 'sources' => [...], 'body_lang_detected' => string]
     */
    public static function audit(array $articleData, array $options = []): array
    {
        $lexDir = self::getLexiconDir();
        self::ensureLexiconsLoaded($lexDir);

        $ignoreSet = self::buildDynamicIgnoreSet($articleData);
        $issues = [];
        $seenKeys = [];
        $noAbstract = self::isNoAbstractArticleType((string) ($articleData['article_type'] ?? 'ORIGINAL ARTICLE'));

        $addIssue = static function (array $issue) use (&$issues, &$seenKeys): void {
            $dedupKey = ($issue['field_path'] ?? '') . '|' . mb_strtolower((string) ($issue['found'] ?? ''), 'UTF-8') . '|' . ($issue['category'] ?? '');
            if (isset($seenKeys[$dedupKey])) {
                return;
            }
            $seenKeys[$dedupKey] = true;
            $issue['id'] = substr(md5($dedupKey . '|' . count($issues)), 0, 12);
            $issues[] = $issue;
        };

        // 1. Fixed-language front-matter fields (omit abstracts/keywords for DATA REPORT & LETTER)
        $fixedFields = [
            ['path' => 'title_id', 'label' => 'Judul Artikel (ID)', 'lang' => 'id', 'text' => (string) ($articleData['title_id'] ?? '')],
            ['path' => 'title_en', 'label' => 'Judul Artikel (EN)', 'lang' => 'en', 'text' => (string) ($articleData['title_en'] ?? '')],
        ];
        if (!$noAbstract) {
            $fixedFields = array_merge($fixedFields, [
                ['path' => 'abstract_id.pendahuluan_metode', 'label' => 'Abstrak ID · Pendahuluan & Metode', 'lang' => 'id', 'text' => (string) ($articleData['abstract_id']['pendahuluan_metode'] ?? '')],
                ['path' => 'abstract_id.hasil', 'label' => 'Abstrak ID · Hasil', 'lang' => 'id', 'text' => (string) ($articleData['abstract_id']['hasil'] ?? '')],
                ['path' => 'abstract_id.kesimpulan_saran', 'label' => 'Abstrak ID · Kesimpulan & Saran', 'lang' => 'id', 'text' => (string) ($articleData['abstract_id']['kesimpulan_saran'] ?? '')],
                ['path' => 'keywords_id', 'label' => 'Kata Kunci (ID)', 'lang' => 'id', 'text' => (string) ($articleData['keywords_id'] ?? '')],
                ['path' => 'abstract_en.intro_methods', 'label' => 'Abstract EN · Introduction & Methods', 'lang' => 'en', 'text' => (string) ($articleData['abstract_en']['intro_methods'] ?? '')],
                ['path' => 'abstract_en.results', 'label' => 'Abstract EN · Results', 'lang' => 'en', 'text' => (string) ($articleData['abstract_en']['results'] ?? '')],
                ['path' => 'abstract_en.conclusions_recommendations', 'label' => 'Abstract EN · Conclusions & Recommendations', 'lang' => 'en', 'text' => (string) ($articleData['abstract_en']['conclusions_recommendations'] ?? '')],
                ['path' => 'keywords_en', 'label' => 'Keywords (EN)', 'lang' => 'en', 'text' => (string) ($articleData['keywords_en'] ?? '')],
            ]);
            if (self::isVolume3Plus($articleData['volume'] ?? '1')) {
                $fixedFields[] = ['path' => 'sdgs_id', 'label' => 'Kontribusi TPB/SDGs (ID)', 'lang' => 'id', 'text' => (string) ($articleData['sdgs_id'] ?? '')];
                $fixedFields[] = ['path' => 'sdgs_en', 'label' => 'Contribution to SDGs (EN)', 'lang' => 'en', 'text' => (string) ($articleData['sdgs_en'] ?? '')];
            }
        }

        $enSegmentsForLt = [];
        foreach ($fixedFields as $f) {
            $txt = trim($f['text']);
            if ($txt === '') {
                continue;
            }
            if ($f['lang'] === 'en') {
                $enSegmentsForLt[] = $f;
            }
            foreach (self::auditText($txt, $f['lang'], $f['path'], $f['label'], $ignoreSet) as $iss) {
                $addIssue($iss);
            }
        }

        // 2. Body sections (auto-detect ID vs EN or honor explicit setting)
        $bodyLangPref = $options['body_lang'] ?? 'auto';
        $allBodySample = '';
        $sectionNames = [
            'badan_artikel' => 'BADAN ARTIKEL',
            'pendahuluan' => 'PENDAHULUAN',
            'metode' => 'METODE',
            'hasil' => 'HASIL',
            'pembahasan' => 'PEMBAHASAN',
            'kesimpulan_saran' => 'KESIMPULAN DAN SARAN',
        ];

        $sections = $articleData['sections'] ?? [];
        foreach ($sectionNames as $secKey => $secTitle) {
            foreach ((array) ($sections[$secKey] ?? []) as $blk) {
                if (is_string($blk)) {
                    $allBodySample .= ' ' . $blk;
                } elseif (is_array($blk)) {
                    $allBodySample .= ' ' . ($blk['text'] ?? '') . ' ' . ($blk['caption'] ?? '');
                }
            }
        }

        $detectedBodyLang = ($bodyLangPref === 'id' || $bodyLangPref === 'en')
            ? $bodyLangPref
            : self::detectLanguage($allBodySample);

        foreach ($sectionNames as $secKey => $secTitle) {
            $blocks = (array) ($sections[$secKey] ?? []);
            foreach ($blocks as $bIdx => $blk) {
                if (is_string($blk)) {
                    $blk = ['type' => 'paragraph', 'text' => $blk];
                }
                $bType = $blk['type'] ?? 'paragraph';
                if ($bType === 'table') {
                    $cap = trim((string) ($blk['caption'] ?? ''));
                    if ($cap !== '') {
                        $fPath = "sections.{$secKey}.{$bIdx}.caption";
                        $fLabel = "{$secTitle} · Judul Tabel";
                        if ($detectedBodyLang === 'en') {
                            $enSegmentsForLt[] = ['path' => $fPath, 'label' => $fLabel, 'text' => $cap];
                        }
                        foreach (self::auditText($cap, $detectedBodyLang, $fPath, $fLabel, $ignoreSet) as $iss) {
                            $addIssue($iss);
                        }
                    }

                    $foot = trim((string) ($blk['footnote'] ?? ''));
                    if ($foot !== '') {
                        $fPath = "sections.{$secKey}.{$bIdx}.footnote";
                        $fLabel = "{$secTitle} · Catatan Kaki Tabel";
                        foreach (self::auditText($foot, $detectedBodyLang, $fPath, $fLabel, $ignoreSet) as $iss) {
                            $addIssue($iss);
                        }
                    }

                    foreach ((array) ($blk['rows'] ?? []) as $rIdx => $row) {
                        foreach ((array) $row as $cIdx => $cell) {
                            $cellText = is_array($cell) ? (string) ($cell['text'] ?? '') : (string) $cell;
                            $cellText = trim($cellText);
                            if ($cellText === '' || preg_match('/^[\d.,\s\-%<>=\/±]+$/u', $cellText)) {
                                continue;
                            }
                            $fPath = "sections.{$secKey}.{$bIdx}.rows.{$rIdx}.{$cIdx}.text";
                            $fLabel = "{$secTitle} · Tabel Baris " . ($rIdx + 1);
                            foreach (self::auditText($cellText, $detectedBodyLang, $fPath, $fLabel, $ignoreSet, true) as $iss) {
                                $addIssue($iss);
                            }
                        }
                    }
                } else {
                    $txt = trim((string) ($blk['text'] ?? ''));
                    if ($txt === '') {
                        continue;
                    }
                    $fPath = "sections.{$secKey}.{$bIdx}.text";
                    $kindLabel = ($bType === 'subheading' || $bType === 'subheading_l3') ? 'Sub-Judul' : 'Paragraf';
                    $fLabel = "{$secTitle} · {$kindLabel} #" . ($bIdx + 1);
                    if ($detectedBodyLang === 'en') {
                        $enSegmentsForLt[] = ['path' => $fPath, 'label' => $fLabel, 'text' => $txt];
                    }
                    foreach (self::auditText($txt, $detectedBodyLang, $fPath, $fLabel, $ignoreSet) as $iss) {
                        $addIssue($iss);
                    }
                }
            }
        }

        // 3. References spacing audit + Vancouver citation <-> bibliography sync audit
        foreach ((array) ($articleData['referensi'] ?? []) as $rIdx => $refItem) {
            $refTxt = trim((string) $refItem);
            if ($refTxt === '') {
                continue;
            }
            $fPath = "referensi.{$rIdx}";
            $fLabel = 'SUMBER PUSTAKA · Baris #' . ($rIdx + 1);
            foreach (self::checkSingleSpaceRule($refTxt, 'id', $fPath, $fLabel) as $iss) {
                $addIssue($iss);
            }
        }

        $vanSync = VancouverFormatter::auditSync($articleData);
        foreach ((array) ($vanSync['issues'] ?? []) as $vIss) {
            $addIssue($vIss);
        }

        $sourcesUsed = [
            'KBBI VI & EYD V (Badan Pengembangan dan Pembinaan Bahasa, Kemendikbudristek)',
            'Hunspell ID & Referensi Kata Baku/Tidak Baku (KBBI / Ivan Lanin)',
            'SCOWL US English (en_US) & AMA Manual of Style / Merriam-Webster',
            'ICMJE Recommendations (Sinkronisasi Sitasi & Daftar Pustaka Gaya Vancouver)',
        ];

        // 3. Optional LanguageTool API check for English segments
        if (!empty($options['use_languagetool']) && !empty($enSegmentsForLt)) {
            $ltIssues = self::runLanguageToolEnglish($enSegmentsForLt, $ignoreSet);
            if (!empty($ltIssues['ran'])) {
                $sourcesUsed[] = 'LanguageTool API (en-US)';
                foreach ($ltIssues['issues'] as $iss) {
                    $addIssue($iss);
                }
            }
        }

        // 4. Optional Gemini AI deep linguistic audit (strictly grounded on KBBI/EYD V & AMA/Oxford)
        $apiKey = trim((string) ($options['gemini_api_key'] ?? ''));
        if (!empty($options['use_gemini']) && $apiKey !== '') {
            $model = trim((string) ($options['gemini_model'] ?? 'gemini-2.5-flash')) ?: 'gemini-2.5-flash';
            $gemIssues = self::runGeminiAudit($articleData, $detectedBodyLang, $apiKey, $model);
            if (!empty($gemIssues['ran'])) {
                $sourcesUsed[] = 'Google Gemini AI (Grounded: KBBI VI / EYD V & AMA / Oxford)';
                foreach ($gemIssues['issues'] as $iss) {
                    $addIssue($iss);
                }
            }
        }

        // Summarize counts
        $counts = [
            'total' => count($issues),
            'kata_tidak_baku' => 0,
            'typo' => 0,
            'eyd_v' => 0,
            'english_ama' => 0,
            'vancouver_sync' => 0,
            'auto_fixable' => 0,
        ];
        foreach ($issues as $iss) {
            $cat = $iss['category'] ?? 'typo';
            if ($cat === 'vancouver_reference_style' || $cat === 'vancouver_citation_sync') {
                $counts['vancouver_sync']++;
            } elseif ($cat === 'kata_tidak_baku' || $cat === 'serapan_medis') {
                $counts['kata_tidak_baku']++;
            } elseif ($cat === 'eyd_v' || $cat === 'imbuhan_eyd' || $cat === 'preposisi_eyd' || $cat === 'pleonasme' || $cat === 'spasi_ganda') {
                $counts['eyd_v']++;
            } elseif ($cat === 'english_uk_us_ama' || $cat === 'english_nonstandard' || $cat === 'languagetool') {
                $counts['english_ama']++;
            } else {
                $counts['typo']++;
            }
            if (!empty($iss['auto_fixable'])) {
                $counts['auto_fixable']++;
            }
        }

        return [
            'issues' => $issues,
            'summary' => $counts,
            'body_lang_detected' => $detectedBodyLang,
            'sources' => $sourcesUsed,
        ];
    }

    /**
     * Audit a single text segment in 'id' or 'en'
     */
    public static function auditText(
        string $text,
        string $lang,
        string $fieldPath,
        string $fieldLabel,
        array $ignoreSet = [],
        bool $isTableCell = false
    ): array {
        self::ensureLexiconsLoaded(self::getLexiconDir());
        $issues = [];

        if ($lang === 'id') {
            // 1. Indonesian multi-word EYD V rules (prepositions, passive di-, pleonasms, punctuation, strict spacing)
            $issues = array_merge($issues, self::checkIndonesianPhrases($text, $fieldPath, $fieldLabel, $isTableCell));
            if ($isTableCell) {
                $issues = array_merge($issues, self::checkSingleSpaceRule($text, 'id', $fieldPath, $fieldLabel));
            }

            // 2. Identify parenthetical spans (...) so English biomedical terms inside Indonesian text are checked against English!
            $parenRanges = [];
            if (preg_match_all('/\(([^()]{3,90})\)/u', $text, $pm, PREG_OFFSET_CAPTURE)) {
                foreach ($pm[1] as $m) {
                    $inner = $m[0];
                    if (self::looksEnglishPhrase($inner)) {
                        $parenRanges[] = [$m[1], $m[1] + strlen($inner)];
                    }
                }
            }

            // 3. Tokenize Indonesian & parenthetical English words
            if (preg_match_all('/[A-Za-zÀ-ÿ]+(?:[\-\'][A-Za-zÀ-ÿ]+)*/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $token = $match[0];
                    $offset = $match[1];
                    $lower = mb_strtolower($token, 'UTF-8');

                    if (self::shouldSkipToken($token, $lower, $text, $offset, $ignoreSet)) {
                        continue;
                    }

                    // Check if token lies inside a parenthetical English technical term
                    $inEnglishParen = false;
                    foreach ($parenRanges as $pr) {
                        if ($offset >= $pr[0] && $offset < $pr[1]) {
                            $inEnglishParen = true;
                            break;
                        }
                    }

                    if ($inEnglishParen) {
                        $enIss = self::checkSingleEnglishToken($token, $lower, $text, $offset, $fieldPath, $fieldLabel);
                        if ($enIss !== null) {
                            $issues[] = $enIss;
                        }
                        continue;
                    }

                    // Skip tokens containing accented non-Indonesian letters (e.g. from citations)
                    if (preg_match('/[^\x20-\x7E]/u', $token)) {
                        continue;
                    }

                    // Layer A: Non-standard Indonesian word (KBBI / EYD V)
                    if (isset(self::$idTidakBakuMap[$lower])) {
                        $rule = self::$idTidakBakuMap[$lower];
                        $baku = (string) ($rule['baku'] ?? '');
                        if ($baku !== '' && $baku !== $lower) {
                            $issues[] = [
                                'field_path' => $fieldPath,
                                'location_label' => $fieldLabel,
                                'language' => 'id',
                                'severity' => 'warning',
                                'category' => $rule['category'] ?? 'kata_tidak_baku',
                                'category_label' => self::categoryLabel($rule['category'] ?? 'kata_tidak_baku'),
                                'found' => $token,
                                'suggestions' => [self::matchCase($token, $baku)],
                                'context' => self::extractContext($text, $offset, strlen($token)),
                                'reason' => "Bentuk tidak baku menurut kaidah KBBI/EYD V; bentuk baku yang dianjurkan adalah \"{$baku}\".",
                                'source' => $rule['source'] ?? 'KBBI VI (Badan Bahasa)',
                                'auto_fixable' => (strpos($baku, '/') === false),
                            ];
                        }
                        continue;
                    }

                    // Layer B: Valid KBBI / Morphology / Medical / Proper-name check
                    if (self::isValidIndonesianToken($lower)) {
                        continue;
                    }

                    // Flag as suspected Indonesian typo with closest KBBI edit-distance suggestions
                    $suggestions = self::suggestSimilarWords($lower, 'id', 3);
                    $matchedSugg = array_map(fn ($s) => self::matchCase($token, $s), $suggestions);
                    $issues[] = [
                        'field_path' => $fieldPath,
                        'location_label' => $fieldLabel,
                        'language' => 'id',
                        'severity' => 'error',
                        'category' => 'typo',
                        'category_label' => 'Dugaan Typo (ID)',
                        'found' => $token,
                        'suggestions' => $matchedSugg,
                        'context' => self::extractContext($text, $offset, strlen($token)),
                        'reason' => !empty($matchedSugg)
                            ? 'Kata tidak ditemukan di korpus KBBI/Hunspell ID; kemungkinan salah ketik (typo).'
                            : 'Kata tidak dikenali di korpus KBBI/Hunspell ID; periksa ejaan atau istilah asing (cetak miring jika perlu).',
                        'source' => 'KBBI & Hunspell ID (id_ID)',
                        'auto_fixable' => (count($matchedSugg) === 1 && levenshtein($lower, mb_strtolower($matchedSugg[0], 'UTF-8')) <= 1),
                    ];
                }
            }
        } else {
            // English audit (title_en, abstract_en, keywords_en, or English body)
            if (!$isTableCell) {
                $issues = array_merge($issues, self::checkCommonPunctuation($text, 'en', $fieldPath, $fieldLabel));
            } else {
                $issues = array_merge($issues, self::checkSingleSpaceRule($text, 'en', $fieldPath, $fieldLabel));
            }
            if (preg_match_all('/[A-Za-z]+(?:[\-\'][A-Za-z]+)*/', $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $token = $match[0];
                    $offset = $match[1];
                    $lower = strtolower($token);

                    if (self::shouldSkipToken($token, $lower, $text, $offset, $ignoreSet)) {
                        continue;
                    }

                    $enIss = self::checkSingleEnglishToken($token, $lower, $text, $offset, $fieldPath, $fieldLabel);
                    if ($enIss !== null) {
                        $issues[] = $enIss;
                    }
                }
            }
        }

        return $issues;
    }

    private static function checkSingleEnglishToken(
        string $token,
        string $lower,
        string $text,
        int $offset,
        string $fieldPath,
        string $fieldLabel
    ): ?array {
        if (isset(self::$enNonStandardMap[$lower])) {
            $rule = self::$enNonStandardMap[$lower];
            $baku = (string) ($rule['baku'] ?? '');
            $cat = (string) ($rule['category'] ?? 'english_typo');
            $isAmaUk = ($cat === 'english_uk_us_ama');
            return [
                'field_path' => $fieldPath,
                'location_label' => $fieldLabel,
                'language' => 'en',
                'severity' => $isAmaUk ? 'warning' : 'error',
                'category' => $cat,
                'category_label' => self::categoryLabel($cat),
                'found' => $token,
                'suggestions' => [self::matchCase($token, $baku)],
                'context' => self::extractContext($text, $offset, strlen($token)),
                'reason' => $isAmaUk
                    ? "Ejaan British English terdeteksi; standar penulisan ilmiah medis (AMA Manual of Style) menyarankan US English \"{$baku}\"."
                    : "Bentuk salah ketik / tidak standar dalam bahasa Inggris akademik; gunakan \"{$baku}\".",
                'source' => $rule['source'] ?? 'AMA Manual of Style / Merriam-Webster',
                'auto_fixable' => true,
            ];
        }

        if (self::isValidEnglishToken($lower)) {
            return null;
        }

        $suggestions = self::suggestSimilarWords($lower, 'en', 3);
        $matchedSugg = array_map(fn ($s) => self::matchCase($token, $s), $suggestions);
        return [
            'field_path' => $fieldPath,
            'location_label' => $fieldLabel,
            'language' => 'en',
            'severity' => 'error',
            'category' => 'typo',
            'category_label' => 'Suspected Typo (EN)',
            'found' => $token,
            'suggestions' => $matchedSugg,
            'context' => self::extractContext($text, $offset, strlen($token)),
            'reason' => 'Kata tidak ditemukan pada kamus SCOWL US English maupun terminologi medis; periksa ejaan.',
            'source' => 'SCOWL US English (en_US)',
            'auto_fixable' => (count($matchedSugg) === 1 && levenshtein($lower, strtolower($matchedSugg[0])) <= 1),
        ];
    }

    /**
     * Check Indonesian multi-word EYD V phrase rules
     */
    private static function checkIndonesianPhrases(string $text, string $fieldPath, string $fieldLabel, bool $isTableCell): array
    {
        $issues = [];

        // 1. Separated passive prefix "di <verb>" -> "di<verb>" (EYD V)
        $passiveVerbs = 'lakukan|berikan|peroleh|analisis|temukan|gunakan|ukur|hitung|ketahui|simpulkan|sarankan|sebabkan|pengaruhi|katakan|anggap|buang|rawat|bagi|kumpulkan|uji|olah|catat|sajikan|jelaskan|bandingkan|hubungkan|laporkan|terima|terbitkan|nyatakan|wajibkan|anjurkan|rekomendasikan|harapkan|butuhkan|perlukan|lihat|temui|jumpai|dapat|dapatkan|laksanakan|jalankan|terapkan|integrasikan|kategorikan|sebar|isi|susun|rancang|tetapkan|pilih|ambil|ubah|buat|kaji|teliti|amati|nilai|periksa';
        if (preg_match_all('/\b(di)\s+(' . $passiveVerbs . ')\b/iu', $text, $mList, PREG_OFFSET_CAPTURE)) {
            foreach ($mList[0] as $idx => $fullMatch) {
                $found = $fullMatch[0];
                $offset = $fullMatch[1];
                $verb = mb_strtolower($mList[2][$idx][0], 'UTF-8');
                $fixed = self::matchCase($found, 'di' . $verb);
                $issues[] = [
                    'field_path' => $fieldPath,
                    'location_label' => $fieldLabel,
                    'language' => 'id',
                    'severity' => 'warning',
                    'category' => 'preposisi_eyd',
                    'category_label' => 'EYD V (Imbuhan di-)',
                    'found' => $found,
                    'suggestions' => [$fixed],
                    'context' => self::extractContext($text, $offset, strlen($found)),
                    'reason' => "Awalan kata kerja pasif \"di-\" harus ditulis serangkai dengan kata dasarnya (\"{$fixed}\"), bukan dipisah.",
                    'source' => 'EYD V — Penulisan Kata Berimbuhan',
                    'auto_fixable' => true,
                ];
            }
        }

        // 2. Separated bound morphemes (pasca-, pra-, non-, antar-, sub-, anti-)
        $boundRules = [
            '/\b(pasca)\s+(salin|melahirkan|persalinan|bedah|operasi|partum|panen)\b/iu' => 'EYD V — Bentuk Terikat (pasca-)',
            '/\b(pra)\s+(nikah|sekolah|persalinan|klinis|operasi|kondisi)\b/iu' => 'EYD V — Bentuk Terikat (pra-)',
            '/\b(non)\s+(medis|formal|kesehatan|parametrik|infeksi|farmakologi|klinis)\b/iu' => 'EYD V — Bentuk Terikat (non-)',
            '/\b(antar)\s+(variabel|kelompok|individu|instansi|daerah|lembaga)\b/iu' => 'EYD V — Bentuk Terikat (antar-)',
            '/\b(sub)\s+(bagian|judul|kategori|kelompok|variabel|sampel|populasi)\b/iu' => 'EYD V — Bentuk Terikat (sub-)',
            '/\b(anti)\s+(oksidan|biotik|bodi|inflamasi|septik|mikroba)\b/iu' => 'EYD V — Bentuk Terikat (anti-)',
        ];
        foreach ($boundRules as $pat => $src) {
            if (preg_match_all($pat, $text, $bmList, PREG_OFFSET_CAPTURE)) {
                foreach ($bmList[0] as $idx => $fm) {
                    $found = $fm[0];
                    $offset = $fm[1];
                    $merged = self::matchCase($found, mb_strtolower($bmList[1][$idx][0] . $bmList[2][$idx][0], 'UTF-8'));
                    $issues[] = [
                        'field_path' => $fieldPath,
                        'location_label' => $fieldLabel,
                        'language' => 'id',
                        'severity' => 'warning',
                        'category' => 'imbuhan_eyd',
                        'category_label' => 'EYD V (Bentuk Terikat)',
                        'found' => $found,
                        'suggestions' => [$merged],
                        'context' => self::extractContext($text, $offset, strlen($found)),
                        'reason' => "Bentuk terikat ditulis serangkai dengan kata yang mengikutinya menjadi \"{$merged}\".",
                        'source' => $src,
                        'auto_fixable' => true,
                    ];
                }
            }
        }

        // 3. Separated location preposition merged incorrectly (diwilayah, dirumah, dimasyarakat, etc.)
        if (preg_match_all('/\bdi(wilayah|rumah|tempat|lapangan|lingkungan|masyarakat|puskesmas|posyandu|indonesia|daerah|kota|desa|kecamatan|kabupaten|provinsi|kalangan|tingkat|bawah|atas|dalam|luar|samping|tengah|antara|antaranya|sini|situ|sana|mana)\b/iu', $text, $lmList, PREG_OFFSET_CAPTURE)) {
            foreach ($lmList[0] as $idx => $fm) {
                $found = $fm[0];
                $offset = $fm[1];
                $noun = mb_strtolower($lmList[1][$idx][0], 'UTF-8');
                $fixed = self::matchCase($found, 'di ' . $noun);
                $issues[] = [
                    'field_path' => $fieldPath,
                    'location_label' => $fieldLabel,
                    'language' => 'id',
                    'severity' => 'warning',
                    'category' => 'preposisi_eyd',
                    'category_label' => 'EYD V (Kata Depan di)',
                    'found' => $found,
                    'suggestions' => [$fixed],
                    'context' => self::extractContext($text, $offset, strlen($found)),
                    'reason' => "Kata depan \"di\" yang menunjukkan tempat/lokasi harus ditulis terpisah menjadi \"{$fixed}\".",
                    'source' => 'EYD V — Kata Depan (Preposisi)',
                    'auto_fixable' => ($noun !== 'mana'),
                ];
            }
        }

        // 4. Academic pleonasms & double plural markings
        $pleonasmPatterns = [
            ['/\bagar\s+supaya\b/iu', 'agar', 'Kata "agar" dan "supaya" bermakna sama; gunakan salah satu saja agar kalimat efektif.'],
            ['/\badalah\s+merupakan\b/iu', 'merupakan', 'Kata "adalah" dan "merupakan" bermakna ganda bila digabung; gunakan salah satu.'],
            ['/\bdemi\s+untuk\b/iu', 'untuk', 'Hindari penggunaan ganda "demi untuk"; pilih "demi" atau "untuk".'],
            ['/\b(para|banyak|beberapa|seluruh|semua|berbagai)\s+([a-z]+)\-\2\b/iu', '', 'Kata jamak tidak perlu diikuti kata ulang utuh (pleonasme penjamakan ganda).'],
        ];
        foreach ($pleonasmPatterns as $pp) {
            if (preg_match_all($pp[0], $text, $pm, PREG_OFFSET_CAPTURE)) {
                foreach ($pm[0] as $idx => $fm) {
                    $found = $fm[0];
                    $offset = $fm[1];
                    $sugg = $pp[1] !== ''
                        ? self::matchCase($found, $pp[1])
                        : ($pm[1][$idx][0] . ' ' . $pm[2][$idx][0]);
                    $issues[] = [
                        'field_path' => $fieldPath,
                        'location_label' => $fieldLabel,
                        'language' => 'id',
                        'severity' => 'warning',
                        'category' => 'pleonasme',
                        'category_label' => 'Kalimat Efektif (EYD V)',
                        'found' => $found,
                        'suggestions' => [$sugg],
                        'context' => self::extractContext($text, $offset, strlen($found)),
                        'reason' => $pp[2],
                        'source' => 'Pedoman Kalimat Efektif — Badan Bahasa',
                        'auto_fixable' => ($pp[1] !== ''),
                    ];
                }
            }
        }

        if (!$isTableCell) {
            $issues = array_merge($issues, self::checkCommonPunctuation($text, 'id', $fieldPath, $fieldLabel));
        }

        return $issues;
    }

    /**
     * Check spacing & punctuation issues (EYD V / AMA)
     */
    private static function checkCommonPunctuation(string $text, string $lang, string $fieldPath, string $fieldLabel): array
    {
        $issues = [];
        // Space before punctuation: "word ," or "word ."
        if (preg_match_all('/([A-Za-z0-9\)\]])\s+([,.;?!])(?:\s|$)/u', $text, $pm, PREG_OFFSET_CAPTURE)) {
            foreach ($pm[0] as $idx => $fm) {
                $found = trim($fm[0]);
                $fixed = $pm[1][$idx][0] . $pm[2][$idx][0];
                $issues[] = [
                    'field_path' => $fieldPath,
                    'location_label' => $fieldLabel,
                    'language' => $lang,
                    'severity' => 'warning',
                    'category' => 'eyd_v',
                    'category_label' => $lang === 'id' ? 'Tata Tulis (EYD V)' : 'Punctuation',
                    'found' => $found,
                    'suggestions' => [$fixed],
                    'context' => self::extractContext($text, $fm[1], strlen($fm[0])),
                    'reason' => 'Tidak boleh ada spasi sebelum tanda baca (' . $pm[2][$idx][0] . ').',
                    'source' => $lang === 'id' ? 'EYD V — Penggunaan Tanda Baca' : 'AMA Manual of Style',
                    'auto_fixable' => true,
                ];
            }
        }

        $issues = array_merge($issues, self::checkSingleSpaceRule($text, $lang, $fieldPath, $fieldLabel));
        return $issues;
    }

    /**
     * Strictly reject >= 2 consecutive spaces, tabs, or non-breaking Unicode spaces in paragraphs.
     */
    public static function checkSingleSpaceRule(string $text, string $lang, string $fieldPath, string $fieldLabel): array
    {
        $issues = [];
        $pattern = '/([^\s\x{00A0}]{1,18})([ \t\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]{2,}|\t+|[\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+)(?=([^\s\x{00A0}]{1,18}))/u';
        if (preg_match_all($pattern, $text, $dm, PREG_OFFSET_CAPTURE)) {
            foreach ($dm[0] as $idx => $fm) {
                $leftAnchor = $dm[1][$idx][0];
                $spaceRun = $dm[2][$idx][0];
                $rightAnchor = $dm[3][$idx][0];
                $found = $leftAnchor . $spaceRun;
                $fixed = $leftAnchor . ' ';
                $normalizedRun = (string) preg_replace('/[\t\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $spaceRun);
                $spaceCount = max(2, mb_strlen($normalizedRun, 'UTF-8'));
                $issues[] = [
                    'field_path' => $fieldPath,
                    'location_label' => $fieldLabel,
                    'language' => $lang,
                    'severity' => 'error',
                    'category' => 'spasi_ganda',
                    'category_label' => 'Aturan Baku Spasi (>1 Spasi)',
                    'found' => $found,
                    'suggestions' => [$fixed],
                    'context' => self::extractContext($text, $fm[1], strlen($found) + strlen($rightAnchor)),
                    'reason' => "Aturan baku paragraf: ditemukan {$spaceCount} spasi/pemisah berurutan antara \"{$leftAnchor}\" dan \"{$rightAnchor}\". Maksimal hanya diperbolehkan 1 spasi tunggal.",
                    'source' => $lang === 'id' ? 'Aturan Baku Paragraf KisiTypesetter & EYD V' : 'Aturan Baku Paragraf KisiTypesetter & AMA Style',
                    'auto_fixable' => true,
                ];
            }
        }
        return $issues;
    }

    /**
     * Strictly normalize paragraph text so no consecutive spaces (>= 2 spaces, tabs, NBSP, or newlines) remain.
     */
    public static function normalizeParagraphSpacing(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $clean = preg_replace('/[\t\r\n\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text);
        $clean = preg_replace('/ {2,}/', ' ', (string) $clean);
        return trim((string) $clean);
    }

    /**
     * Normalize spacing across every article text field, abstract, section block, table caption/footnote, and reference.
     */
    public static function normalizeArticleParagraphSpaces(array $articleData, int &$changesCount = 0): array
    {
        $changesCount = 0;
        $normField = static function (string $val) use (&$changesCount): string {
            $cleaned = self::normalizeParagraphSpacing($val);
            if ($cleaned !== trim($val) || $val !== $cleaned) {
                $changesCount++;
            }
            return $cleaned;
        };

        $topKeys = [
            'article_type', 'title_id', 'title_en', 'keywords_id', 'keywords_en',
            'sdgs_id', 'sdgs_en', 'citation', 'review_editor', 'received_date',
            'accepted_date', 'published_date', 'funding', 'competing_interests',
            'contributor_roles', 'corresponding_name', 'journal_short_footer',
            'footer_issue_line', 'retypeset_note',
        ];
        foreach ($topKeys as $k) {
            if (isset($articleData[$k]) && is_string($articleData[$k])) {
                $articleData[$k] = $normField($articleData[$k]);
            }
        }
        if (isset($articleData['footer_issue_line']) && is_string($articleData['footer_issue_line']) && $articleData['footer_issue_line'] !== '') {
            $idFooter = self::translateMonthsToIndonesian($articleData['footer_issue_line']);
            if ($idFooter !== $articleData['footer_issue_line']) {
                $articleData['footer_issue_line'] = $idFooter;
                $changesCount++;
            }
        }

        if (!empty($articleData['authors']) && is_array($articleData['authors'])) {
            foreach ($articleData['authors'] as $idx => $author) {
                if (!is_array($author)) {
                    continue;
                }
                if (isset($author['name']) && is_string($author['name'])) {
                    $articleData['authors'][$idx]['name'] = $normField($author['name']);
                }
                if (isset($author['affiliation']) && is_string($author['affiliation'])) {
                    $articleData['authors'][$idx]['affiliation'] = $normField($author['affiliation']);
                }
            }
        }

        foreach (['abstract_id', 'abstract_en'] as $abKey) {
            if (!empty($articleData[$abKey]) && is_array($articleData[$abKey])) {
                foreach ($articleData[$abKey] as $subKey => $subVal) {
                    if (is_string($subVal)) {
                        $articleData[$abKey][$subKey] = $normField($subVal);
                    }
                }
            }
        }

        if (!empty($articleData['sections']) && is_array($articleData['sections'])) {
            foreach ($articleData['sections'] as $secKey => $blocks) {
                if (!is_array($blocks)) {
                    continue;
                }
                foreach ($blocks as $bIdx => $blk) {
                    if (is_string($blk)) {
                        $articleData['sections'][$secKey][$bIdx] = $normField($blk);
                    } elseif (is_array($blk)) {
                        foreach (['text', 'caption', 'footnote'] as $bk) {
                            if (isset($blk[$bk]) && is_string($blk[$bk])) {
                                $articleData['sections'][$secKey][$bIdx][$bk] = $normField($blk[$bk]);
                            }
                        }
                        if (!empty($blk['rows']) && is_array($blk['rows'])) {
                            foreach ($blk['rows'] as $rIdx => $row) {
                                if (!is_array($row)) {
                                    continue;
                                }
                                foreach ($row as $cIdx => $cell) {
                                    if (is_array($cell) && isset($cell['text']) && is_string($cell['text'])) {
                                        $articleData['sections'][$secKey][$bIdx]['rows'][$rIdx][$cIdx]['text'] = $normField($cell['text']);
                                    } elseif (is_string($cell)) {
                                        $articleData['sections'][$secKey][$bIdx]['rows'][$rIdx][$cIdx] = $normField($cell);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($articleData['referensi']) && is_array($articleData['referensi'])) {
            foreach ($articleData['referensi'] as $rIdx => $refVal) {
                if (is_string($refVal)) {
                    $articleData['referensi'][$rIdx] = $normField($refVal);
                }
            }
        }

        return $articleData;
    }

    /**
     * Check whether an Indonesian token is valid via KBBI lookup, medical whitelist, or morphological derivation
     */
    public static function isValidIndonesianToken(string $lower): bool
    {
        if (isset(self::$idWordMap[$lower]) || isset(self::$medicalMap[$lower])) {
            return true;
        }

        // Handle hyphenated reduplication or compound (e.g. "nilai-nilai", "gotong-royong", "masing-masing", "non-kesehatan")
        if (strpos($lower, '-') !== false) {
            $parts = explode('-', $lower);
            $allValid = true;
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                if (!self::isValidIndonesianToken($part) && !isset(self::$enWordMap[$part])) {
                    $allValid = false;
                    break;
                }
            }
            if ($allValid) {
                return true;
            }
        }

        return self::checkIndonesianMorphology($lower);
    }

    /**
     * Strip valid KBBI VI / Tata Bahasa Baku Indonesian prefixes, suffixes, and particles
     */
    private static function checkIndonesianMorphology(string $word): bool
    {
        if (strlen($word) < 4) {
            return false;
        }

        // Strip trailing clitics & suffixes iteratively
        $suffixCandidates = [$word];
        $particles = ['lah', 'kah', 'pun', 'nya', 'ku', 'mu'];
        foreach ($particles as $pt) {
            if (str_ends_with($word, $pt) && strlen($word) - strlen($pt) >= 3) {
                $stem = substr($word, 0, -strlen($pt));
                $suffixCandidates[] = $stem;
                if (isset(self::$idWordMap[$stem]) || isset(self::$medicalMap[$stem])) {
                    return true;
                }
            }
        }

        $derivSuffixes = ['kan', 'an', 'i'];
        $expanded = $suffixCandidates;
        foreach ($suffixCandidates as $cand) {
            foreach ($derivSuffixes as $ds) {
                if (str_ends_with($cand, $ds) && strlen($cand) - strlen($ds) >= 3) {
                    $s2 = substr($cand, 0, -strlen($ds));
                    $expanded[] = $s2;
                    if (isset(self::$idWordMap[$s2]) || isset(self::$medicalMap[$s2])) {
                        return true;
                    }
                }
            }
        }

        // Strip prefixes (including K-T-S-P morphophonemic restoration for meN-/peN-)
        foreach (array_unique($expanded) as $cand) {
            $roots = self::generateIndonesianPrefixRoots($cand);
            foreach ($roots as $root) {
                if (isset(self::$idWordMap[$root]) || isset(self::$medicalMap[$root])) {
                    return true;
                }
                // Handle second prefix layer e.g. di-per-oleh, mem-per-oleh, ter-se-but, ke-tidak-seimbang-an
                foreach (self::generateIndonesianPrefixRoots($root) as $r2) {
                    if (isset(self::$idWordMap[$r2]) || isset(self::$medicalMap[$r2])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function generateIndonesianPrefixRoots(string $cand): array
    {
        $roots = [];
        $simplePrefixes = ['di', 'ke', 'se', 'ter', 'te', 'ber', 'be', 'per', 'pe', 'pasca', 'pra', 'non', 'sub', 'antar', 'multi', 'anti', 'semi', 'hiper', 'ekstra', 'ketidak', 'ketidakber', 'ketidakter'];
        foreach ($simplePrefixes as $px) {
            if (str_starts_with($cand, $px) && strlen($cand) - strlen($px) >= 3) {
                $roots[] = substr($cand, strlen($px));
            }
        }

        // meN- / peN- morphophonemics
        $nasalRules = [
            ['meng', ['k', 'g', 'h', 'kh', '']],
            ['peng', ['k', 'g', 'h', 'kh', '']],
            ['meny', ['s', '']],
            ['peny', ['s', '']],
            ['mem', ['p', 'b', 'f', 'v', '']],
            ['pem', ['p', 'b', 'f', 'v', '']],
            ['men', ['t', 'd', 'c', 'j', 'z', '']],
            ['pen', ['t', 'd', 'c', 'j', 'z', '']],
            ['me', ['l', 'm', 'n', 'r', 'w', 'y', '']],
            ['pe', ['l', 'm', 'n', 'r', 'w', 'y', '']],
        ];
        foreach ($nasalRules as $nr) {
            $px = $nr[0];
            if (str_starts_with($cand, $px) && strlen($cand) - strlen($px) >= 2) {
                $tail = substr($cand, strlen($px));
                foreach ($nr[1] as $restore) {
                    $roots[] = $restore . $tail;
                }
            }
        }

        return $roots;
    }

    /**
     * Check whether an English token is valid via SCOWL en_US, medical whitelist, or regular morphology
     */
    public static function isValidEnglishToken(string $lower): bool
    {
        if (isset(self::$enWordMap[$lower]) || isset(self::$medicalMap[$lower])) {
            return true;
        }

        // Possessive 's
        if (str_ends_with($lower, "'s") || str_ends_with($lower, "s'")) {
            $base = preg_replace("/'s$|'$/", '', $lower);
            if ($base !== '' && (isset(self::$enWordMap[$base]) || isset(self::$medicalMap[$base]))) {
                return true;
            }
        }

        // Hyphenated compounds (e.g. socio-cultural, skin-to-skin, p-value, Chi-square)
        if (strpos($lower, '-') !== false) {
            $parts = explode('-', $lower);
            $allValid = true;
            foreach ($parts as $p) {
                if ($p === '') {
                    continue;
                }
                if (!self::isValidEnglishToken($p)) {
                    $allValid = false;
                    break;
                }
            }
            if ($allValid) {
                return true;
            }
        }

        // Regular English inflections/prefixes
        $suffixes = ['s', 'es', 'ed', 'd', 'ing', 'ly', 'er', 'est', 'ness', 'ment', 'ity'];
        foreach ($suffixes as $sx) {
            if (str_ends_with($lower, $sx) && strlen($lower) - strlen($sx) >= 3) {
                $stem = substr($lower, 0, -strlen($sx));
                if (isset(self::$enWordMap[$stem]) || isset(self::$enWordMap[$stem . 'e']) || isset(self::$medicalMap[$stem])) {
                    return true;
                }
            }
        }
        $prefixes = ['un', 're', 'pre', 'post', 'non', 'anti', 'sub', 'inter', 'multi', 'semi', 'hyper', 'hypo', 'micro', 'macro'];
        foreach ($prefixes as $px) {
            if (str_starts_with($lower, $px) && strlen($lower) - strlen($px) >= 3) {
                $stem = substr($lower, strlen($px));
                if (isset(self::$enWordMap[$stem]) || isset(self::$medicalMap[$stem])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Suggest closest valid dictionary words via length-bucketed Damerau-Levenshtein
     */
    private static function suggestSimilarWords(string $lower, string $lang, int $limit = 3): array
    {
        $len = strlen($lower);
        if ($len < 3 || $len > 28) {
            return [];
        }

        $buckets = ($lang === 'id') ? self::$idBuckets : self::$enBuckets;
        if (!$buckets) {
            return [];
        }

        $firstChar = $lower[0];
        $candidates = [];
        $maxDist = ($len <= 5) ? 1 : 2;

        for ($l = max(2, $len - 1); $l <= $len + 1; $l++) {
            if (empty($buckets[$l])) {
                continue;
            }
            foreach ($buckets[$l] as $w) {
                // Quick heuristic filter: same first letter OR same second letter (handles transposed first char)
                if ($w[0] !== $firstChar && (!isset($w[1], $lower[1]) || $w[1] !== $lower[0])) {
                    continue;
                }
                $d = self::damerauLevenshtein($lower, $w);
                if ($d <= $maxDist) {
                    $candidates[$w] = $d;
                }
            }
        }

        asort($candidates);
        return array_slice(array_keys($candidates), 0, $limit);
    }

    private static function damerauLevenshtein(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $lenA = strlen($a);
        $lenB = strlen($b);
        if (abs($lenA - $lenB) > 2) {
            return 99;
        }

        // Fast check for pure adjacent transposition (distance = 1)
        if ($lenA === $lenB) {
            $diffs = [];
            for ($i = 0; $i < $lenA; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $diffs[] = $i;
                    if (count($diffs) > 2) {
                        break;
                    }
                }
            }
            if (count($diffs) === 2 && $diffs[1] === $diffs[0] + 1 && $a[$diffs[0]] === $b[$diffs[1]] && $a[$diffs[1]] === $b[$diffs[0]]) {
                return 1;
            }
        }

        return levenshtein($a, $b);
    }

    /**
     * Optional LanguageTool Public API v2 check for English segments
     */
    private static function runLanguageToolEnglish(array $segments, array $ignoreSet): array
    {
        if (!function_exists('curl_init') || empty($segments)) {
            return ['ran' => false, 'issues' => []];
        }

        $issues = [];
        $ranOnce = false;
        // Batch up to 6 segments into a single request (<= 18,000 chars) to respect public API rate limits
        $combinedText = '';
        $ranges = [];
        foreach ($segments as $seg) {
            $t = trim((string) ($seg['text'] ?? ''));
            if ($t === '') {
                continue;
            }
            if (mb_strlen($combinedText, 'UTF-8') + mb_strlen($t, 'UTF-8') > 16000) {
                break;
            }
            $start = mb_strlen($combinedText, 'UTF-8');
            $combinedText .= $t . "\n\n";
            $end = $start + mb_strlen($t, 'UTF-8');
            $ranges[] = [
                'start' => $start,
                'end' => $end,
                'path' => $seg['path'],
                'label' => $seg['label'],
                'text' => $t,
            ];
        }

        if ($combinedText === '') {
            return ['ran' => false, 'issues' => []];
        }

        $postFields = http_build_query([
            'text' => $combinedText,
            'language' => 'en-US',
            'disabledRules' => 'WHITESPACE_RULE,EN_QUOTES',
        ]);

        $ch = curl_init('https://api.languagetool.org/v2/check');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 12,
        ]);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode >= 400) {
            return ['ran' => false, 'issues' => []];
        }

        $data = json_decode((string) $resp, true);
        if (!is_array($data) || !isset($data['matches'])) {
            return ['ran' => false, 'issues' => []];
        }
        $ranOnce = true;

        foreach ($data['matches'] as $m) {
            $mOff = (int) ($m['offset'] ?? 0);
            $mLen = (int) ($m['length'] ?? 0);
            if ($mLen <= 0) {
                continue;
            }
            $found = mb_substr($combinedText, $mOff, $mLen, 'UTF-8');
            $lowerFound = mb_strtolower($found, 'UTF-8');
            if (isset($ignoreSet[$lowerFound]) || isset(self::$medicalMap[$lowerFound])) {
                continue;
            }

            $targetRange = null;
            foreach ($ranges as $r) {
                if ($mOff >= $r['start'] && $mOff < $r['end']) {
                    $targetRange = $r;
                    break;
                }
            }
            if (!$targetRange) {
                continue;
            }

            $suggs = [];
            foreach (array_slice((array) ($m['replacements'] ?? []), 0, 3) as $rep) {
                if (!empty($rep['value'])) {
                    $suggs[] = (string) $rep['value'];
                }
            }
            $ruleId = (string) ($m['rule']['id'] ?? 'LANGUAGETOOL');
            $issues[] = [
                'field_path' => $targetRange['path'],
                'location_label' => $targetRange['label'],
                'language' => 'en',
                'severity' => 'warning',
                'category' => 'languagetool',
                'category_label' => 'LanguageTool (EN)',
                'found' => $found,
                'suggestions' => $suggs,
                'context' => trim((string) ($m['context']['text'] ?? $found)),
                'reason' => (string) ($m['message'] ?? 'Perbaikan ejaan/tata bahasa Inggris.'),
                'source' => 'LanguageTool API (en-US · ' . $ruleId . ')',
                'auto_fixable' => (count($suggs) === 1),
            ];
        }

        return ['ran' => $ranOnce, 'issues' => $issues];
    }

    /**
     * Optional Google Gemini API deep linguistic audit grounded on KBBI VI / EYD V and AMA / Merriam-Webster
     */
    private static function runGeminiAudit(array $articleData, string $bodyLang, string $apiKey, string $model): array
    {
        if (!function_exists('curl_init')) {
            return ['ran' => false, 'issues' => []];
        }

        $payload = [
            'title_id' => $articleData['title_id'] ?? '',
            'title_en' => $articleData['title_en'] ?? '',
            'abstract_id' => $articleData['abstract_id'] ?? [],
            'abstract_en' => $articleData['abstract_en'] ?? [],
            'body_lang' => $bodyLang,
            'sections_sample' => [],
        ];

        $charBudget = 12000;
        foreach (['pendahuluan', 'metode', 'hasil', 'pembahasan', 'kesimpulan_saran'] as $secKey) {
            foreach ((array) ($articleData['sections'][$secKey] ?? []) as $bIdx => $blk) {
                if (is_array($blk) && ($blk['type'] ?? 'paragraph') !== 'table') {
                    $txt = trim((string) ($blk['text'] ?? ''));
                    if ($txt !== '' && $charBudget > 0) {
                        $payload['sections_sample'][] = [
                            'field_path' => "sections.{$secKey}.{$bIdx}.text",
                            'text' => $txt,
                        ];
                        $charBudget -= strlen($txt);
                    }
                }
            }
        }

        $prompt = <<<PROMPT
Anda adalah proofreader ilmiah ahli jurnal kedokteran/kesehatan berbahasa Indonesia dan Inggris.
Periksa teks JSON di bawah untuk menemukan HANYA kesalahan nyata berupa:
1. Typo (salah ketik),
2. Kata tidak baku Bahasa Indonesia menurut **KBBI VI & EYD V (Badan Bahasa)** (mis. analisa->analisis, diagnosa->diagnosis, resiko->risiko, praktek->praktik, di lakukan->dilakukan, mengkonsumsi->mengonsumsi, dimana->di mana/yang),
3. Kesalahan ejaan/grammar/ketidakbakuan Bahasa Inggris menurut **Merriam-Webster / Oxford / AMA Manual of Style**.

Aturan ketat:
- JANGAN pernah mengubah angka statistik, nama penulis/peneliti, nama tempat (Gorontalo, Asparaga, Kendari), singkatan medis valid (IMD, ASI, WHO, SDGs, SPSS), atau istilah teknis asing yang memang tepat.
- Kembalikan JSON dengan format array objek di key "issues":
  {
    "issues": [
      {
        "field_path": "persis seperti key input (mis. title_id atau abstract_id.pendahuluan_metode atau sections.pendahuluan.0.text)",
        "language": "id" atau "en",
        "category": "kata_tidak_baku" | "typo" | "eyd_v" | "english_ama",
        "found": "kata/frasa persis yang salah di teks",
        "suggestion": "perbaikan baku yang persis",
        "reason": "penjelasan singkat kaidah bahasanya",
        "source": "KBBI VI (Badan Bahasa)" atau "EYD V" atau "AMA Manual of Style / Merriam-Webster"
      }
    ]
  }
Input JSON:
PROMPT;
        $prompt .= "\n" . json_encode($payload, JSON_UNESCAPED_UNICODE);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json'],
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 35,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode >= 400) {
            return ['ran' => false, 'issues' => []];
        }

        $decoded = json_decode((string) $resp, true);
        $txtOut = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $txtOut = preg_replace('/^```(?:json)?\s*|\s*```$/is', '', trim($txtOut));
        $aiJson = json_decode($txtOut, true);
        if (!is_array($aiJson) || !is_array($aiJson['issues'] ?? null)) {
            return ['ran' => false, 'issues' => []];
        }

        $issues = [];
        foreach ($aiJson['issues'] as $item) {
            $fp = trim((string) ($item['field_path'] ?? ''));
            $found = trim((string) ($item['found'] ?? ''));
            $sugg = trim((string) ($item['suggestion'] ?? ''));
            if ($fp === '' || $found === '' || $sugg === '' || strcasecmp($found, $sugg) === 0) {
                continue;
            }
            $cat = (string) ($item['category'] ?? 'kata_tidak_baku');
            $issues[] = [
                'field_path' => $fp,
                'location_label' => 'AI Audit · ' . $fp,
                'language' => ($item['language'] ?? 'id') === 'en' ? 'en' : 'id',
                'severity' => 'warning',
                'category' => $cat,
                'category_label' => self::categoryLabel($cat) . ' (AI)',
                'found' => $found,
                'suggestions' => [$sugg],
                'context' => $found . ' → ' . $sugg,
                'reason' => trim((string) ($item['reason'] ?? 'Saran perbaikan bahasa ilmiah.')),
                'source' => trim((string) ($item['source'] ?? 'KBBI VI / EYD V')),
                'auto_fixable' => false, // AI suggestions require explicit editor confirmation
            ];
        }

        return ['ran' => true, 'issues' => $issues];
    }

    /**
     * Build dynamic proper-noun ignore set from the current article's metadata & references
     */
    public static function buildDynamicIgnoreSet(array $articleData): array
    {
        $words = [];
        $collect = static function (string $str) use (&$words): void {
            if ($str === '') {
                return;
            }
            if (preg_match_all('/[A-Za-zÀ-ÿ]{2,}/u', $str, $m)) {
                foreach ($m[0] as $w) {
                    $words[mb_strtolower($w, 'UTF-8')] = true;
                }
            }
        };

        foreach ((array) ($articleData['authors'] ?? []) as $au) {
            $collect((string) ($au['name'] ?? ''));
            $collect((string) ($au['affiliation'] ?? ''));
        }
        $collect((string) ($articleData['corresponding_name'] ?? ''));
        $collect((string) ($articleData['review_editor'] ?? ''));
        $collect((string) ($articleData['journal_title'] ?? ''));
        $collect((string) ($articleData['publisher_name'] ?? ''));

        foreach ((array) ($articleData['referensi'] ?? []) as $ref) {
            // Collect proper-noun capitalized tokens from references (author names, journals, publishers)
            if (preg_match_all('/\b[A-Z][A-Za-zÀ-ÿ\'\-]{2,}\b/u', (string) $ref, $m)) {
                foreach ($m[0] as $capWord) {
                    $words[mb_strtolower($capWord, 'UTF-8')] = true;
                }
            }
        }

        return $words;
    }

    public static function detectLanguage(string $text): string
    {
        $lower = ' ' . mb_strtolower($text, 'UTF-8') . ' ';
        $idMarkers = [' yang ', ' dan ', ' di ', ' dengan ', ' pada ', ' untuk ', ' dalam ', ' adalah ', ' merupakan ', ' penelitian ', ' responden ', ' hasil ', ' terhadap ', ' ini ', ' tersebut ', ' bahwa '];
        $enMarkers = [' the ', ' and ', ' of ', ' in ', ' to ', ' with ', ' for ', ' is ', ' are ', ' was ', ' were ', ' this ', ' study ', ' between ', ' among ', ' results ', ' methods '];

        $idScore = 0;
        foreach ($idMarkers as $m) {
            $idScore += substr_count($lower, $m);
        }
        $enScore = 0;
        foreach ($enMarkers as $m) {
            $enScore += substr_count($lower, $m);
        }

        return ($enScore > $idScore) ? 'en' : 'id';
    }

    private static function looksEnglishPhrase(string $phrase): bool
    {
        if (preg_match('/\b(the|and|of|in|with|contact|consent|intervention|survey|sampling|partum|care|health|skin|early|initiation)\b/i', $phrase)) {
            return true;
        }
        $tokens = preg_split('/[\s\-\/]+/', strtolower(trim($phrase)), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return false;
        }
        $enHits = 0;
        foreach ($tokens as $tk) {
            if (isset(self::$enWordMap[$tk]) && !isset(self::$idWordMap[$tk])) {
                $enHits++;
            }
        }
        return $enHits > 0 && ($enHits / count($tokens)) >= 0.5;
    }

    private static function shouldSkipToken(string $token, string $lower, string $fullText, int $offset, array $ignoreSet): bool
    {
        $len = mb_strlen($token, 'UTF-8');
        if ($len <= 1) {
            return true;
        }
        // ALL-CAPS acronyms (WHO, SDGs, IMD, ASI, PNS, SPSS, OR, CI)
        if ($len <= 8 && preg_match('/^[A-Z0-9\-]+$/', $token)) {
            return true;
        }
        // Mixed acronym + s like SDGs, NGOs
        if (preg_match('/^[A-Z]{2,}s$/', $token)) {
            return true;
        }
        // Skip URLs or emails around this offset
        $before = substr($fullText, max(0, $offset - 12), 12);
        $after = substr($fullText, $offset + strlen($token), 6);
        if (preg_match('#https?://|www\.|@#i', $before) || str_starts_with($after, '@') || str_starts_with($after, '.com') || str_starts_with($after, '.ac.id') || str_starts_with($after, '.go.id') || str_starts_with($after, '.org')) {
            return true;
        }
        // Academic degrees / initials like S.Kep., M.Kep., Ns., Dr., Prof., et al.
        if (str_starts_with($after, '.') && $len <= 4 && preg_match('/^[A-Z]/', $token)) {
            return true;
        }
        if (isset($ignoreSet[$lower])) {
            return true;
        }
        return false;
    }

    private static function matchCase(string $original, string $replacement): string
    {
        if ($original === mb_strtoupper($original, 'UTF-8') && $original !== mb_strtolower($original, 'UTF-8')) {
            return mb_strtoupper($replacement, 'UTF-8');
        }
        $first = mb_substr($original, 0, 1, 'UTF-8');
        if ($first === mb_strtoupper($first, 'UTF-8') && $first !== mb_strtolower($first, 'UTF-8')) {
            return mb_strtoupper(mb_substr($replacement, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($replacement, 1, null, 'UTF-8');
        }
        return $replacement;
    }

    private static function extractContext(string $text, int $byteOffset, int $byteLen): string
    {
        $before = substr($text, 0, $byteOffset);
        $after = substr($text, $byteOffset + $byteLen);
        $target = substr($text, $byteOffset, $byteLen);

        $bSlice = mb_substr($before, -35, null, 'UTF-8');
        $aSlice = mb_substr($after, 0, 35, 'UTF-8');
        $prefix = (mb_strlen($before, 'UTF-8') > 35) ? '...' : '';
        $suffix = (mb_strlen($after, 'UTF-8') > 35) ? '...' : '';

        return $prefix . $bSlice . $target . $aSlice . $suffix;
    }

    private static function categoryLabel(string $cat): string
    {
        return match ($cat) {
            'kata_tidak_baku' => 'Kata Tidak Baku (KBBI)',
            'serapan_medis' => 'Kata Serapan Tidak Baku (KBBI)',
            'imbuhan_eyd' => 'Bentuk Terikat / Imbuhan (EYD V)',
            'preposisi_eyd' => 'Kata Depan / Awalan (EYD V)',
            'pleonasme' => 'Kalimat Tidak Efektif (Pleonasme)',
            'eyd_v' => 'Tata Tulis (EYD V)',
            'english_uk_us_ama' => 'UK vs US English (AMA Style)',
            'english_nonstandard' => 'Non-Standard English',
            'english_typo' => 'English Typo',
            'vancouver_reference_style' => 'Format Daftar Pustaka (Vancouver)',
            'vancouver_citation_sync' => 'Sinkronisasi Sitasi Naskah (Vancouver)',
            'languagetool' => 'LanguageTool (EN)',
            default => 'Dugaan Typo',
        };
    }

    private static function getLexiconDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'lexicon';
    }

    private static function ensureLexiconsLoaded(string $dir): void
    {
        if (self::$idWordMap !== null) {
            return;
        }

        self::$idTidakBakuMap = self::loadJsonFile($dir . DIRECTORY_SEPARATOR . 'id_baku_tidak_baku.json');
        self::$enNonStandardMap = self::loadJsonFile($dir . DIRECTORY_SEPARATOR . 'en_nonstandard_typos.json');
        self::$medicalMap = self::loadPlainWordSet($dir . DIRECTORY_SEPARATOR . 'medical_terms.txt');

        [$idMap, $idBuckets] = self::loadGzWordSetWithBuckets($dir . DIRECTORY_SEPARATOR . 'id_valid_words.txt.gz');
        self::$idWordMap = $idMap;
        self::$idBuckets = $idBuckets;

        [$enMap, $enBuckets] = self::loadGzWordSetWithBuckets($dir . DIRECTORY_SEPARATOR . 'en_valid_words.txt.gz');
        self::$enWordMap = $enMap;
        self::$enBuckets = $enBuckets;
    }

    private static function loadJsonFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function loadPlainWordSet(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $lines = preg_split('/\r?\n/', (string) file_get_contents($path), -1, PREG_SPLIT_NO_EMPTY);
        $map = [];
        foreach ($lines as $w) {
            $w = trim(strtolower($w));
            if ($w !== '') {
                $map[$w] = 1;
            }
        }
        return $map;
    }

    private static function loadGzWordSetWithBuckets(string $path): array
    {
        if (!file_exists($path)) {
            return [[], []];
        }
        $rawGz = file_get_contents($path);
        if ($rawGz === false) {
            return [[], []];
        }
        $plain = function_exists('gzdecode') ? @gzdecode($rawGz) : false;
        if ($plain === false && function_exists('gzopen')) {
            $gz = @gzopen($path, 'rb');
            if ($gz) {
                $buf = '';
                while (!gzeof($gz)) {
                    $buf .= gzread($gz, 65536);
                }
                gzclose($gz);
                $plain = $buf;
            }
        }
        if (!is_string($plain) || $plain === '') {
            return [[], []];
        }

        $lines = explode("\n", $plain);
        $map = [];
        $buckets = [];
        foreach ($lines as $w) {
            $w = trim($w);
            if ($w === '') {
                continue;
            }
            $map[$w] = 1;
            // Index clean alphabetic words up to 24 chars into length buckets for fast suggestions
            if (strpos($w, "'") === false && strpos($w, '-') === false) {
                $len = strlen($w);
                if ($len >= 2 && $len <= 24) {
                    $buckets[$len][] = $w;
                }
            }
        }

        return [$map, $buckets];
    }
}
