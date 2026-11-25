<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/VancouverFormatter.php
 *
 * Enforces Vancouver (ICMJE / NLM) reference style & synchronizes in-text numeric
 * citations ((1), [1], (1,2), (1-3)) with numbered SUMBER PUSTAKA across DOCX,
 * AmeliCA JATS XML, Full-Text HTML Digest, LanguageAuditor, and Studio UI.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

require_once __DIR__ . '/LanguageAuditor.php';

class VancouverFormatter
{
    /**
     * Strip leading reference list numbers like "1. ", "1.", "(1) ", "[1] ", or "1) ".
     */
    public static function stripLeadingNumber(string $refText): string
    {
        $clean = trim($refText);
        if ($clean === '') {
            return '';
        }
        $clean = (string) preg_replace('/^\s*(?:\[\s*\d+\s*\]|\(\s*\d+\s*\)|\d+\s*[\.\)])\s*/u', '', $clean);
        // Handle accidentally doubled numbering (e.g. "1. 1. Author...")
        $clean = (string) preg_replace('/^\s*(?:\[\s*\d+\s*\]|\(\s*\d+\s*\)|\d+\s*[\.\)])\s*/u', '', $clean);
        return LanguageAuditor::normalizeParagraphSpacing($clean);
    }

    /**
     * Format a raw reference string (APA, mixed, or Vancouver) into clean Vancouver (ICMJE) body text
     * WITHOUT a leading "1. " prefix.
     */
    public static function formatReferenceBody(string $rawRef): string
    {
        $ref = self::stripLeadingNumber($rawRef);
        if ($ref === '') {
            return '';
        }

        // Convert APA-style DOI URL to Vancouver doi: prefix
        $ref = (string) preg_replace('#https?://(?:dx\.)?doi\.org/(10\.[^\s,;]+)#i', 'doi:$1', $ref);
        $ref = (string) preg_replace('/\bdoi\s*:\s*/i', 'doi:', $ref);

        // Normalize Indonesian retrieval phrases -> English Vancouver "Available from:"
        $ref = (string) preg_replace(
            '/\b(?:Tersedia\s+(?:pada|dari)|Diakses\s+(?:pada|dari)|Diambil\s+dari|Retrieved\s+from)\s*:?\s*(https?:\/\/)/iu',
            'Available from: $1',
            $ref
        );

        // Case 1: Classic APA / Harvard format with parenthesized year after authors:
        //   e.g. "Smith, J. A., & Doe, B. (2023). Title. Journal, 12(3), 44-51. doi:..."
        if (preg_match('/^(?<authors>.+?)\s*\(\s*(?<year>(?:19|20)\d{2}[a-z]?(?:\s+[A-Za-z]{3,9}(?:\s+\d{1,2})?)?)\s*\)\s*\.?\s*(?<rest>.+)$/u', $ref, $m)) {
            $authorsVan = self::convertAuthorsToVancouver($m['authors']);
            $yearVan = self::normalizeVancouverDate($m['year']);
            $rest = trim($m['rest']);
            return self::rebuildVancouverRest($authorsVan, $yearVan, $rest);
        }

        // Case 2: Comma-year format (e.g., "Notoatmodjo, 2012. Metodologi Penelitian Kesehatan. Jakarta: Rineka Cipta.")
        if (preg_match('/^(?<authors>[^.;]+?),\s*(?<year>(?:19|20)\d{2}[a-z]?)\.\s*(?<rest>.+)$/u', $ref, $m)) {
            $authorsVan = self::convertAuthorsToVancouver($m['authors']);
            $yearVan = trim($m['year']);
            $rest = trim($m['rest']);
            return self::rebuildVancouverRest($authorsVan, $yearVan, $rest);
        }

        // Case 3: Already Vancouver — normalize punctuation, DOI trailing period absence, and whitespace
        $ref = (string) preg_replace('/;\s*([0-9]+)\s*\(\s*([^\)]+)\s*\)\s*,\s*/u', ';$1($2):', $ref);
        $ref = (string) preg_replace('/\.\s*\.\s*/', '. ', $ref);
        return LanguageAuditor::normalizeParagraphSpacing($ref);
    }

    /**
     * Resolve raw style identifier (from OJS CSL plugin or KisiTypesetter settings) into canonical style family:
     * 'vancouver' (default), 'apa', or 'ieee'.
     */
    public static function resolveReferenceStyle(string $style): string
    {
        $s = strtolower(trim($style));
        if ($s === '' || $s === 'auto' || strpos($s, 'vancouver') !== false || strpos($s, 'ama') !== false || strpos($s, 'nlm') !== false) {
            return 'vancouver';
        }
        if (strpos($s, 'ieee') !== false) {
            return 'ieee';
        }
        if (strpos($s, 'apa') !== false || strpos($s, 'harvard') !== false || strpos($s, 'chicago-author') !== false) {
            return 'apa';
        }
        return 'vancouver';
    }

    /**
     * Format a single reference entry into the active journal reference style ('vancouver', 'ieee', or 'apa').
     */
    public static function formatReferenceForStyle(string $rawRef, string $style = 'vancouver', int $number = 1): string
    {
        $canonicalStyle = self::resolveReferenceStyle($style);
        if ($canonicalStyle === 'apa') {
            return self::formatToApaBody($rawRef);
        }
        $body = self::formatReferenceBody($rawRef);
        if ($body === '') {
            return '';
        }
        if ($canonicalStyle === 'ieee') {
            return "[{$number}] {$body}";
        }
        return "{$number}. {$body}";
    }

    /**
     * Build an OJS Publication `citationsRaw` multi-line block formatted strictly in the journal's reference style,
     * ready to be parsed by OJS Repo::publication()->edit() / Repo::citation()->importCitations().
     */
    public static function formatCitationsRawBlock(array $refs, string $style = 'vancouver'): string
    {
        $lines = [];
        $num = 1;
        foreach ($refs as $rawRef) {
            $trim = trim((string) $rawRef);
            if ($trim === '') {
                continue;
            }
            $formatted = self::formatReferenceForStyle($trim, $style, $num);
            if ($formatted !== '') {
                $lines[] = $formatted;
                $num++;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Convert Vancouver/mixed reference into APA format (for journals explicitly configured to APA in OJS).
     */
    public static function formatToApaBody(string $rawRef): string
    {
        $ref = self::stripLeadingNumber($rawRef);
        if ($ref === '') {
            return '';
        }

        // Convert doi:10.xxx to https://doi.org/10.xxx and trim "Available from:" prefix
        $ref = (string) preg_replace('/\bdoi\s*:\s*(10\.[^\s,;]+)/i', 'https://doi.org/$1', $ref);
        $ref = (string) preg_replace('/\bAvailable\s+from\s*:\s*(https?:\/\/)/i', '$1', $ref);

        // Already APA (parenthesized year after author)? Return normalized
        if (preg_match('/^[^(\[\]]{2,160}\(\s*(?:19|20)\d{2}[a-z]?\s*\)\.?/u', $ref)) {
            return LanguageAuditor::normalizeParagraphSpacing($ref);
        }

        // Extract trailing link
        $tailLink = '';
        if (preg_match('/^(.*?)\s+(https?:\/\/\S+)$/iu', $ref, $lm)) {
            $ref = rtrim(trim($lm[1]), ' .');
            $tailLink = trim($lm[2]);
        }

        // Parse Vancouver journal: "<Authors>. <Title>. <Journal>. YYYY[ Month Day];Vol[(Issue)]:Pages"
        if (preg_match('/^(?<authors>[^.]+)\.\s*(?<title>.+?)\.\s*(?<journal>[^.;]+)\.\s*(?<year>(?:19|20)\d{2}[a-z]?)(?:\s+[A-Za-z]{3,9}(?:\s+\d{1,2})?)?\s*;\s*(?<vol>\d+)(?:\(\s*(?<issue>[^)]+)\s*\))?(?:\s*:\s*(?<pages>[\w–-]+))?\.?$/u', $ref, $jm)) {
            $apaAuthors = self::convertVancouverAuthorsToApa($jm['authors']);
            $year = $jm['year'];
            $title = trim($jm['title']) . '.';
            $journal = trim($jm['journal']);
            $bib = $journal . ', ' . $jm['vol'] . (!empty($jm['issue']) ? '(' . trim($jm['issue']) . ')' : '') . (!empty($jm['pages']) ? ', ' . trim($jm['pages']) : '') . '.';
            $out = "{$apaAuthors} ({$year}). {$title} {$bib}";
            if ($tailLink !== '') {
                $out .= ' ' . $tailLink;
            }
            return LanguageAuditor::normalizeParagraphSpacing($out);
        }

        // Parse Vancouver book: "<Authors>. <Title>. <City>: <Publisher>; YYYY."
        if (preg_match('/^(?<authors>[^.]+)\.\s*(?<title>.+?)\.\s*(?<city>[^.:;]+)\s*:\s*(?<pub>[^;]+);\s*(?<year>(?:19|20)\d{2}[a-z]?)\.?$/u', $ref, $bm)) {
            $apaAuthors = self::convertVancouverAuthorsToApa($bm['authors']);
            $out = "{$apaAuthors} ({$bm['year']}). " . trim($bm['title']) . '. ' . trim($bm['pub']) . '.';
            if ($tailLink !== '') {
                $out .= ' ' . $tailLink;
            }
            return LanguageAuditor::normalizeParagraphSpacing($out);
        }

        return LanguageAuditor::normalizeParagraphSpacing($ref . ($tailLink !== '' ? ' ' . $tailLink : ''));
    }

    private static function convertVancouverAuthorsToApa(string $authorsRaw): string
    {
        $clean = trim($authorsRaw);
        $hadEtAl = false;
        if (preg_match('/,?\s*et\s+al\.?$/i', $clean)) {
            $hadEtAl = true;
            $clean = (string) preg_replace('/,?\s*et\s+al\.?$/i', '', $clean);
        }
        $parts = array_filter(array_map('trim', explode(',', $clean)), static fn ($x) => $x !== '');
        if (empty($parts)) {
            return $clean;
        }
        $out = [];
        foreach ($parts as $p) {
            if (preg_match('/^(.+?)\s+([A-Z]{1,4})$/u', $p, $pm)) {
                $inits = implode('. ', str_split($pm[2])) . '.';
                $out[] = trim($pm[1]) . ', ' . $inits;
            } else {
                $out[] = $p;
            }
        }
        if ($hadEtAl) {
            return implode(', ', $out) . ', et al.';
        }
        if (count($out) === 1) {
            return $out[0];
        }
        $last = array_pop($out);
        return implode(', ', $out) . ', & ' . $last;
    }

    /**
     * Format reference array cleanly for DOCX SUMBER PUSTAKA ("1. ...", "2. ...", ...).
     */
    public static function formatDocxReferenceLine(int $number, string $rawRef): string
    {
        $body = self::formatReferenceBody($rawRef);
        if ($body === '') {
            return '';
        }
        return $number . '. ' . $body;
    }

    /**
     * Normalize all references in $articleData into clean unprefixed Vancouver entries.
     * Numbering is derived from array order (index + 1) consistently in DOCX, JATS <ref id="B#">, and HTML <ol>.
     */
    public static function convertAllReferences(array $articleData, int &$convertedCount = 0): array
    {
        $convertedCount = 0;
        $refs = (array) ($articleData['referensi'] ?? []);
        $cleanList = [];
        foreach ($refs as $rawRef) {
            $orig = trim((string) $rawRef);
            if ($orig === '') {
                continue;
            }
            $formatted = self::formatReferenceBody($orig);
            if ($formatted === '') {
                continue;
            }
            if ($formatted !== self::stripLeadingNumber($orig)) {
                $convertedCount++;
            }
            $cleanList[] = $formatted;
        }
        $articleData['referensi'] = $cleanList;
        return $articleData;
    }

    /**
     * Detect JATS citation publication-type ("journal", "book", "web", or "other").
     */
    public static function inferPublicationType(string $vancouverRef): string
    {
        if (preg_match('/;\s*\d+(?:\([^\)]+\))?\s*:\s*[\w–-]+/u', $vancouverRef) || stripos($vancouverRef, 'doi:') !== false) {
            return 'journal';
        }
        if (stripos($vancouverRef, '[Internet]') !== false || stripos($vancouverRef, 'Available from:') !== false) {
            return 'web';
        }
        if (preg_match('/[^:]+:\s*[^;]+;\s*(?:19|20)\d{2}\b/u', $vancouverRef)) {
            return 'book';
        }
        return 'other';
    }

    /**
     * Check whether a reference line looks APA / non-Vancouver rather than Vancouver.
     */
    public static function looksNonVancouverReference(string $rawRef): bool
    {
        $clean = self::stripLeadingNumber($rawRef);
        if ($clean === '') {
            return false;
        }
        // Parenthesized year directly after author segment (classic APA: "Depkes RI. (2013)." or "Smith, J. (2020).")
        if (preg_match('/^[^(\[\]]{2,160}\(\s*(?:19|20)\d{2}[a-z]?\s*\)\.?/u', $clean)) {
            return true;
        }
        // "Author, 2012. Title..."
        if (preg_match('/^[^.;]{2,80},\s*(?:19|20)\d{2}[a-z]?\.\s+\S/u', $clean)) {
            return true;
        }
        // Ampersand author separator with surname initials "Smith, J. A., & Doe, B."
        if (preg_match('/,\s*[A-Z]\.\s*,?\s*&\s+[A-Z]/u', $clean)) {
            return true;
        }
        return false;
    }

    /**
     * Audit citation <-> reference synchronization across all body sections and SUMBER PUSTAKA.
     *
     * @return array ['issues' => [...], 'cited_numbers' => int[], 'uncited_numbers' => int[], 'out_of_range' => int[], 'apa_in_text_count' => int]
     */
    public static function auditSync(array $articleData): array
    {
        $refs = array_values(array_filter(
            array_map(static fn ($r) => trim((string) $r), (array) ($articleData['referensi'] ?? [])),
            static fn ($s) => $s !== ''
        ));
        $refCount = count($refs);
        $issues = [];

        // 1. Check each reference item in SUMBER PUSTAKA for non-Vancouver styling
        foreach ($refs as $idx => $rawRef) {
            if (self::looksNonVancouverReference($rawRef)) {
                $van = self::formatReferenceBody($rawRef);
                $numLabel = $idx + 1;
                $issues[] = [
                    'field_path' => "referensi.{$idx}",
                    'location_label' => "SUMBER PUSTAKA · #{$numLabel}",
                    'language' => 'id',
                    'severity' => 'error',
                    'category' => 'vancouver_reference_style',
                    'category_label' => 'Sinkronisasi Daftar Pustaka (Vancouver)',
                    'found' => self::stripLeadingNumber($rawRef),
                    'suggestions' => ($van !== '' && $van !== self::stripLeadingNumber($rawRef)) ? [$van] : [],
                    'context' => $rawRef,
                    'reason' => 'Format sumber pustaka belum mengikuti gaya Vancouver bernomor (masih memakai pola tahun kurung APA/Harvard); sitasi naskah wajib sinkron dengan daftar pustaka gaya Vancouver.',
                    'source' => 'ICMJE Recommendations (Vancouver Style)',
                    'auto_fixable' => ($van !== '' && $van !== self::stripLeadingNumber($rawRef)),
                ];
            }
        }

        // 2. Scan body sections for Vancouver numeric citations AND stray APA author-year citations
        $citedMap = [];
        $outOfRange = [];
        $apaInTextCount = 0;

        $sectionLabels = [
            'badan_artikel' => 'BADAN ARTIKEL',
            'pendahuluan' => 'PENDAHULUAN',
            'metode' => 'METODE',
            'hasil' => 'HASIL',
            'pembahasan' => 'PEMBAHASAN',
            'kesimpulan_saran' => 'KESIMPULAN DAN SARAN',
        ];

        foreach ($sectionLabels as $secKey => $secTitle) {
            foreach ((array) ($articleData['sections'][$secKey] ?? []) as $bIdx => $blk) {
                $txt = is_string($blk) ? $blk : (string) ($blk['text'] ?? '');
                if (trim($txt) === '') {
                    continue;
                }
                $fPath = "sections.{$secKey}.{$bIdx}.text";
                $fLabel = "{$secTitle} · Paragraf #" . ($bIdx + 1);

                // Detect stray APA author-year citations inside prose: (Notoatmodjo, 2012) or Notoatmodjo (2012)
                if (preg_match_all('/\(([A-Z][\p{L}\'\-]+(?:\s+(?:&|and|dan)\s+[A-Z][\p{L}\'\-]+|\s+(?:et\s+al\.?|dkk\.?))?\s*,\s*(?:19|20)\d{2}[a-z]?)\)/u', $txt, $am)) {
                    foreach ($am[0] as $foundApa) {
                        $apaInTextCount++;
                        $issues[] = [
                            'field_path' => $fPath,
                            'location_label' => $fLabel,
                            'language' => 'id',
                            'severity' => 'error',
                            'category' => 'vancouver_citation_sync',
                            'category_label' => 'Sinkronisasi Sitasi Naskah (Vancouver)',
                            'found' => $foundApa,
                            'suggestions' => [],
                            'context' => $foundApa,
                            'reason' => 'Sitasi dalam teks terdeteksi memakai gaya Author-Year (APA). Ubah menjadi nomor urut Vancouver (misalnya (1) atau [1]) sesuai urutan pada SUMBER PUSTAKA.',
                            'source' => 'ICMJE Recommendations (Vancouver Style)',
                            'auto_fixable' => false,
                        ];
                    }
                }
                if (preg_match_all('/\b([A-Z][\p{L}\'\-]+(?:\s+(?:et\s+al\.?|dkk\.?))?)\s*\(\s*((?:19|20)\d{2}[a-z]?)\s*\)/u', $txt, $nm)) {
                    foreach ($nm[0] as $k => $foundNarr) {
                        $leadWord = strtolower(trim($nm[1][$k]));
                        if (in_array($leadWord, ['tahun', 'year', 'sejak', 'periode', 'riset', 'data', 'riskesdas', 'who', 'kemenkes', 'tabel', 'table'], true)) {
                            continue;
                        }
                        $apaInTextCount++;
                        $issues[] = [
                            'field_path' => $fPath,
                            'location_label' => $fLabel,
                            'language' => 'id',
                            'severity' => 'warning',
                            'category' => 'vancouver_citation_sync',
                            'category_label' => 'Sinkronisasi Sitasi Naskah (Vancouver)',
                            'found' => $foundNarr,
                            'suggestions' => [],
                            'context' => $foundNarr,
                            'reason' => 'Sitasi naratif Author (Tahun) terdeteksi. Pastikan disertai atau disesuaikan ke penomoran Vancouver, misalnya ' . $nm[1][$k] . ' (1).',
                            'source' => 'ICMJE Recommendations (Vancouver Style)',
                            'auto_fixable' => false,
                        ];
                    }
                }

                // Detect Vancouver numeric citations: standar(1), [1], (1,2), (1-3), [1–3]
                foreach (self::extractInTextCitationMatches($txt) as $matchInfo) {
                    foreach ($matchInfo['numbers'] as $num) {
                        $citedMap[$num] = true;
                        if ($refCount > 0 && $num > $refCount) {
                            $outOfRange[$num] = true;
                            $issues[] = [
                                'field_path' => $fPath,
                                'location_label' => $fLabel,
                                'language' => 'id',
                                'severity' => 'error',
                                'category' => 'vancouver_citation_sync',
                                'category_label' => 'Sinkronisasi Sitasi ↔ Pustaka',
                                'found' => $matchInfo['raw'],
                                'suggestions' => [],
                                'context' => $matchInfo['raw'],
                                'reason' => "Nomor sitasi Vancouver ({$num}) melebihi total entri di SUMBER PUSTAKA ({$refCount}). Tambahkan referensi #{$num} atau perbaiki nomor sitasi.",
                                'source' => 'Sinkronisasi Sitasi & Daftar Pustaka',
                                'auto_fixable' => false,
                            ];
                        }
                    }
                }
            }
        }

        $citedNumbers = array_keys($citedMap);
        sort($citedNumbers);
        $uncitedNumbers = [];
        if ($refCount > 0 && !empty($citedNumbers)) {
            for ($i = 1; $i <= $refCount; $i++) {
                if (!isset($citedMap[$i])) {
                    $uncitedNumbers[] = $i;
                }
            }
        }

        return [
            'issues' => $issues,
            'cited_numbers' => $citedNumbers,
            'uncited_numbers' => $uncitedNumbers,
            'out_of_range' => array_keys($outOfRange),
            'apa_in_text_count' => $apaInTextCount,
            'ref_count' => $refCount,
        ];
    }

    /**
     * Extract in-text Vancouver citation tokens such as "(1)", "[1]", "(1,2)", "(1-3)", "[1–3]".
     * Carefully excludes years (2024), statistical specs, percentages, and 0-based age ranges like (0-6).
     *
     * @return array<int, array{raw:string, open:string, close:string, numbers:int[], offset:int, length:int}>
     */
    public static function extractInTextCitationMatches(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $results = [];
        // Match bracketed [1] / [1, 2] / [1-3] OR attached parenthetical standar(1) / balita(2,3) / kata (1-3)
        $pattern = '/(?<open>\[|\()(?<spec>[1-9]\d{0,2}(?:\s*(?:,|[-–—])\s*[1-9]\d{0,2})*)(?<close>\]|\))/u';
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as $idx => $full) {
            $raw = $full[0];
            $offset = $full[1];
            $open = $matches['open'][$idx][0];
            $close = $matches['close'][$idx][0];
            $spec = $matches['spec'][$idx][0];

            // Mismatched brackets
            if (($open === '[' && $close !== ']') || ($open === '(' && $close !== ')')) {
                continue;
            }

            $after = substr($text, $offset + strlen($raw), 16);
            $before = substr($text, max(0, $offset - 20), min(20, $offset));

            // Exclude mathematical / scientific units / statistical tokens: e.g. "(5)%" or "usia (1-5) tahun"
            if (preg_match('/^\s*(?:%|kg|cm|mm|mg|ml|tahun|bulan|minggu|hari|jam|menit|orang|responden|bayi|ibu|kali|anak)\b/iu', $after)) {
                continue;
            }
            if (preg_match('/(?:usia|umur|skor|kelompok|table|tabel|gambar|figure|bab|no\.?|nomor|hal\.?|volume|vol\.?|p\s*[=<>≤≥])\s*$/iu', $before)) {
                continue;
            }
            // For parentheses "(1)" when standalone single number with preceding space (not attached like "standar(1)"),
            // only treat as Vancouver citation if multiple numbers "(1,2)" / "(1-3)" OR immediately follows word/char without space!
            if ($open === '(' && strpos($spec, ',') === false && strpos($spec, '-') === false && strpos($spec, '–') === false && strpos($spec, '—') === false) {
                $prevChar = $offset > 0 ? substr($text, $offset - 1, 1) : '';
                if ($prevChar === '' || preg_match('/\s/', $prevChar)) {
                    continue;
                }
            }

            $nums = self::expandCitationNumbers($spec);
            if (empty($nums)) {
                continue;
            }
            // Reject implausibly huge numbers for citations (e.g. > 250)
            if (max($nums) > 250) {
                continue;
            }

            $results[] = [
                'raw' => $raw,
                'open' => $open,
                'close' => $close,
                'numbers' => $nums,
                'offset' => $offset,
                'length' => strlen($raw),
            ];
        }

        return $results;
    }

    /**
     * Expand "1, 3-5" -> [1, 3, 4, 5].
     *
     * @return int[]
     */
    public static function expandCitationNumbers(string $spec): array
    {
        $nums = [];
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*[-–—]\s*(\d+)$/u', $part, $rm)) {
                $start = (int) $rm[1];
                $end = (int) $rm[2];
                if ($start >= 1 && $end >= $start && ($end - $start) <= 30) {
                    for ($n = $start; $n <= $end; $n++) {
                        $nums[] = $n;
                    }
                }
            } elseif (ctype_digit($part)) {
                $val = (int) $part;
                if ($val >= 1) {
                    $nums[] = $val;
                }
            }
        }
        return array_values(array_unique($nums));
    }

    /**
     * Convert APA author block ("Smith, J. A., & Doe, B. C.") into Vancouver ("Smith JA, Doe BC").
     */
    private static function convertAuthorsToVancouver(string $rawAuthors): string
    {
        $clean = trim($rawAuthors, " \t\n\r.");
        if ($clean === '') {
            return '';
        }

        // Preserve pure institutional / corporate names without comma-initial personal structure
        if (strpos($clean, ',') === false && !preg_match('/\s&\s/u', $clean)) {
            return $clean;
        }

        $clean = (string) preg_replace('/,?\s*(?:&|\band\b|\bdan\b)\s+/iu', ', ', $clean);
        // Handle "et al." placeholder
        $hadEtAl = false;
        if (preg_match('/,?\s*(?:et\s+al\.?|dkk\.?)\s*$/iu', $clean)) {
            $hadEtAl = true;
            $clean = (string) preg_replace('/,?\s*(?:et\s+al\.?|dkk\.?)\s*$/iu', '', $clean);
        }

        // Convert personal "Surname, F. M." tokens
        $authors = [];
        if (preg_match_all('/([^,]+),\s*((?:[A-Z]\.?\s*){1,4})(?=,|$)/u', $clean, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $pair) {
                $surname = trim($pair[1]);
                $inits = preg_replace('/[^A-Z]/u', '', strtoupper($pair[2]));
                $authors[] = trim($surname . ($inits !== '' ? (' ' . $inits) : ''));
            }
        }

        if (empty($authors)) {
            return $clean . ($hadEtAl ? ', et al.' : '');
        }

        // ICMJE Vancouver limit: list up to 6 authors followed by ", et al."
        if (count($authors) > 6) {
            $authors = array_slice($authors, 0, 6);
            $hadEtAl = true;
        }

        return implode(', ', $authors) . ($hadEtAl ? ', et al.' : '');
    }

    private static function normalizeVancouverDate(string $yearSpec): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($yearSpec));
        if (preg_match('/^((?:19|20)\d{2}[a-z]?)(?:\s+([A-Za-z]+)(?:\s+(\d{1,2}))?)?$/u', $clean, $m)) {
            $out = $m[1];
            if (!empty($m[2])) {
                $out .= ' ' . ucfirst(substr($m[2], 0, 3));
                if (!empty($m[3])) {
                    $out .= ' ' . $m[3];
                }
            }
            return $out;
        }
        return $clean;
    }

    private static function rebuildVancouverRest(string $authorsVan, string $yearVan, string $rest): string
    {
        $authorsPart = rtrim($authorsVan, '.') . '.';

        // Extract trailing URL or doi:... before parsing title/journal/publisher
        $tailLink = '';
        if (preg_match('/^(.*?)\s*((?:doi:\S+|Available\s+from:\s*\S+|https?:\/\/\S+).*)$/iu', $rest, $lm)) {
            $rest = rtrim(trim($lm[1]), ' .');
            $tailLink = trim($lm[2]);
            if (preg_match('#^https?://#i', $tailLink)) {
                $tailLink = 'Available from: ' . $tailLink;
            }
        }

        $rest = rtrim($rest, ' .');

        // Subpattern A: Article in Journal -> "<Title>. <Journal>, <Vol>(<Issue>), <Pages>" or "<Title>. <Journal>, <Vol>, <Pages>"
        if (preg_match('/^(?<title>.+?\.)\s*(?<journal>[^.,]+),\s*(?<vol>\d+)(?:\(\s*(?<issue>[^)]+)\s*\))?(?:\s*,\s*(?<pages>[\w–-]+))?$/u', $rest, $jm)) {
            $title = rtrim(trim($jm['title']), '.') . '.';
            $journal = rtrim(trim($jm['journal']), '. ,') . '.';
            $bib = $yearVan . ';' . $jm['vol'];
            if (!empty($jm['issue'])) {
                $bib .= '(' . trim($jm['issue']) . ')';
            }
            if (!empty($jm['pages'])) {
                $bib .= ':' . trim($jm['pages']);
            }
            $bib .= '.';
            $out = "{$authorsPart} {$title} {$journal} {$bib}";
            if ($tailLink !== '') {
                $out .= ' ' . $tailLink;
            }
            return LanguageAuditor::normalizeParagraphSpacing($out);
        }

        // Subpattern B: Book / Report -> "<Title>. <City>[: or ,] <Publisher>"
        if (preg_match('/^(?<title>.+?\.)\s*(?<city>[^.:,]+)\s*[:\,]\s*(?<publisher>.+)$/u', $rest, $bm)) {
            $title = rtrim(trim($bm['title']), '.') . '.';
            $city = trim($bm['city']);
            $pub = rtrim(trim($bm['publisher']), '. ,');
            $out = "{$authorsPart} {$title} {$city}: {$pub}; {$yearVan}.";
            if ($tailLink !== '') {
                $out .= ' ' . $tailLink;
            }
            return LanguageAuditor::normalizeParagraphSpacing($out);
        }

        // Subpattern C: Generic fallback "<Title>[. Source]"
        $titleOnly = $rest !== '' ? (rtrim($rest, '.') . ($tailLink !== '' ? ' [Internet]. ' : '. ')) : '';
        $out = trim("{$authorsPart} {$titleOnly}{$yearVan}.");
        if ($tailLink !== '') {
            $out .= ' ' . $tailLink;
        }
        return LanguageAuditor::normalizeParagraphSpacing($out);
    }
}
