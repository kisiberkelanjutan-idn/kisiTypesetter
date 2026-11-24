<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/HealthTableFormatter.php
 *
 * Formats research tables (especially in HASIL / Results) according to
 * ICMJE, AMA Manual of Style, STROBE, and CONSORT standards for medical
 * and health sciences journals:
 *  - Boxless three-line open table geometry (top rule, header bottom rule, bottom rule, zero vertical borders)
 *  - Above-table bold-prefixed captions ("Tabel N. Title" without trailing period)
 *  - Semantic alignment: left-aligned stub column with subcategory indentation; tabular numeric/statistical columns
 *  - Automatic classification of header, variable-group, subcategory, and total rows
 *  - Statistical symbol normalization (p-value, n, χ², SD, CI, OR) and inline italicization helpers
 *  - Structured table footnotes (<table-wrap-foot>) for abbreviations, statistical tests, and significance thresholds
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

class HealthTableFormatter
{
    /**
     * Format a single table block to Health-Science Journal Standards
     *
     * @param array $tableBlock
     * @param array $opts ['table_num' => int, 'force_reclassify' => bool, 'force_footnote' => bool, 'lang' => 'id'|'en']
     * @return array
     */
    public static function formatTableBlock(array $tableBlock, array $opts = []): array
    {
        $rows = $tableBlock['rows'] ?? [];
        if (empty($rows) || !is_array($rows)) {
            return $tableBlock;
        }

        $lang = ($opts['lang'] ?? 'id') === 'en' ? 'en' : 'id';
        $forceReclassify = !empty($opts['force_reclassify']);
        $tableNum = (int) ($opts['table_num'] ?? 1);

        // 1. Clean cell text & calculate exact logical grid matrix
        $totalCols = 0;
        $cleanedRows = [];
        $vMergeOccupants = []; // [rIdx][colIdx] => true

        foreach ($rows as $rIdx => $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $rowCells = [];
            $gridCol = 0;

            foreach ($rawRow as $cell) {
                while (!empty($vMergeOccupants[$rIdx][$gridCol])) {
                    $gridCol++;
                }

                if (!is_array($cell)) {
                    $cell = ['text' => (string) $cell];
                }

                $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                $rowspan = max(1, (int) ($cell['rowspan'] ?? 1));
                $vmerge = (string) ($cell['vmerge'] ?? '');
                $isHeader = !empty($cell['header']) || ($rIdx === 0);

                $rawTxt = (string) ($cell['text'] ?? '');
                $cleanTxt = self::cleanTableText($rawTxt, $isHeader);

                if ($vmerge !== 'continue' && $rowspan > 1) {
                    for ($rr = 1; $rr < $rowspan; $rr++) {
                        for ($cc = 0; $cc < $colspan; $cc++) {
                            $vMergeOccupants[$rIdx + $rr][$gridCol + $cc] = true;
                        }
                    }
                }

                $rowCells[] = [
                    'text' => $cleanTxt,
                    'colspan' => $colspan,
                    'rowspan' => $rowspan,
                    'vmerge' => $vmerge,
                    'header' => $isHeader,
                    'align' => (string) ($cell['align'] ?? ''),
                    'row_type' => (string) ($cell['row_type'] ?? ''),
                    '_grid_col' => $gridCol,
                ];

                $gridCol += $colspan;
            }

            $totalCols = max($totalCols, $gridCol);
            if (!empty($rowCells)) {
                $cleanedRows[] = $rowCells;
            }
        }

        if ($totalCols < 1) {
            $totalCols = 1;
        }

        // 2. Determine last header row index
        $lastHeaderRowIdx = 0;
        foreach ($cleanedRows as $rIdx => $rowCells) {
            $rowHasHeader = false;
            foreach ($rowCells as $c) {
                if (!empty($c['header'])) {
                    $rowHasHeader = true;
                    break;
                }
            }
            if ($rowHasHeader) {
                $lastHeaderRowIdx = $rIdx;
            } else {
                break;
            }
        }

        // 3. Classify rows (header, group, subitem, total, data)
        $inGroupContext = false;
        foreach ($cleanedRows as $rIdx => &$rowCells) {
            $existingType = (string) ($rowCells[0]['row_type'] ?? '');
            $isHeaderRow = ($rIdx <= $lastHeaderRowIdx);

            if ($isHeaderRow) {
                $rowType = 'header';
                $inGroupContext = false;
            } elseif (!$forceReclassify && in_array($existingType, ['group', 'subitem', 'total', 'data'], true)) {
                $rowType = $existingType;
                if ($rowType === 'group') {
                    $inGroupContext = true;
                } elseif ($rowType === 'total') {
                    $inGroupContext = false;
                }
            } else {
                $firstText = trim((string) ($rowCells[0]['text'] ?? ''));
                $firstColspan = (int) ($rowCells[0]['colspan'] ?? 1);
                $otherCellsNonEmpty = false;
                for ($ci = 1; $ci < count($rowCells); $ci++) {
                    if (trim((string) ($rowCells[$ci]['text'] ?? '')) !== '') {
                        $otherCellsNonEmpty = true;
                        break;
                    }
                }

                if ($firstText !== '' && preg_match('/^(jumlah|total|overall|keseluruhan|n\s*=)\b/iu', $firstText)) {
                    $rowType = 'total';
                    $inGroupContext = false;
                } elseif ($firstText !== '' && (($firstColspan >= $totalCols && count($rowCells) === 1) || (!$otherCellsNonEmpty && count($rowCells) > 1))) {
                    $rowType = 'group';
                    $inGroupContext = true;
                    // Normalize group row into clean spanning stub cell if trailing cells are all blank
                    if (!$otherCellsNonEmpty && count($rowCells) > 1) {
                        $rowCells = [
                            [
                                'text' => $firstText,
                                'colspan' => $totalCols,
                                'rowspan' => 1,
                                'vmerge' => '',
                                'header' => false,
                                'align' => 'left',
                                'row_type' => 'group',
                                '_grid_col' => 0,
                            ],
                        ];
                    }
                } elseif ($inGroupContext) {
                    $rowType = 'subitem';
                } else {
                    $rowType = 'data';
                }
            }

            foreach ($rowCells as &$c) {
                $c['row_type'] = $rowType;
                $c['header'] = ($rowType === 'header');
            }
            unset($c);
        }
        unset($rowCells);

        // 4. Compute semantic column alignments (respect user overrides if provided)
        $colAlignments = $tableBlock['col_alignments'] ?? [];
        if (!is_array($colAlignments) || count($colAlignments) < $totalCols || $forceReclassify) {
            $colAlignments = self::inferColumnAlignments($cleanedRows, $totalCols);
        } else {
            for ($c = 0; $c < $totalCols; $c++) {
                $a = $colAlignments[$c] ?? '';
                if (!in_array($a, ['left', 'center', 'right'], true)) {
                    $inferred = self::inferColumnAlignments($cleanedRows, $totalCols);
                    $colAlignments[$c] = $inferred[$c] ?? ($c === 0 ? 'left' : 'center');
                }
            }
        }

        // Apply alignment onto cells and strip temporary _grid_col
        foreach ($cleanedRows as &$rowCells) {
            foreach ($rowCells as &$c) {
                $gCol = (int) ($c['_grid_col'] ?? 0);
                $cs = (int) ($c['colspan'] ?? 1);
                if (!in_array($c['align'] ?? '', ['left', 'center', 'right'], true) || $forceReclassify) {
                    if (!empty($c['header']) && $cs > 1) {
                        $c['align'] = 'center';
                    } else {
                        $c['align'] = $colAlignments[$gCol] ?? ($gCol === 0 ? 'left' : 'center');
                    }
                }
                unset($c['_grid_col']);
            }
            unset($c);
        }
        unset($rowCells);

        // 5. Format Caption ("Tabel N. Title", no trailing period)
        $caption = self::normalizeCaption((string) ($tableBlock['caption'] ?? ''), $tableNum, $lang);

        // 6. Preserve or suggest AMA/ICMJE Health-Science Footnote
        $footnote = trim((string) ($tableBlock['footnote'] ?? ''));
        if ($footnote === '' && (!empty($opts['suggest_footnote']) || !empty($opts['force_footnote']))) {
            $footnote = self::buildSuggestedFootnote($cleanedRows, $lang);
        }

        $tableBlock['caption'] = $caption;
        $tableBlock['footnote'] = $footnote;
        $tableBlock['col_alignments'] = array_slice($colAlignments, 0, $totalCols);
        $tableBlock['rows'] = $cleanedRows;
        $tableBlock['health_standard'] = true;

        return $tableBlock;
    }

    /**
     * Format all tables across an article's sections sequentially (re-indexing Tabel 1, 2, 3...)
     */
    public static function formatArticleTables(array $articleData, array $opts = []): array
    {
        $sections = $articleData['sections'] ?? [];
        $tableCounter = 1;
        foreach (['pendahuluan', 'metode', 'hasil', 'pembahasan', 'kesimpulan_saran'] as $secKey) {
            if (empty($sections[$secKey]) || !is_array($sections[$secKey])) {
                continue;
            }
            foreach ($sections[$secKey] as $bIdx => $blk) {
                if (is_array($blk) && ($blk['type'] ?? '') === 'table') {
                    $localOpts = array_merge($opts, ['table_num' => $tableCounter]);
                    $sections[$secKey][$bIdx] = self::formatTableBlock($blk, $localOpts);
                    $tableCounter++;
                }
            }
        }
        $articleData['sections'] = $sections;
        return $articleData;
    }

    /**
     * Infer per-column alignment: Col 0 left (stub), numeric/statistical columns center/right
     */
    public static function inferColumnAlignments(array $rows, int $totalCols): array
    {
        $colStats = [];
        for ($c = 0; $c < $totalCols; $c++) {
            $colStats[$c] = ['numeric' => 0, 'total' => 0, 'header_hint' => false];
        }

        foreach ($rows as $rowCells) {
            foreach ($rowCells as $c) {
                $gCol = (int) ($c['_grid_col'] ?? 0);
                $cs = (int) ($c['colspan'] ?? 1);
                $txt = trim((string) ($c['text'] ?? ''));
                if ($txt === '' || $cs > 1) {
                    continue;
                }

                if (!empty($c['header'])) {
                    if (preg_match('/^(n|f|%|p[\-\s]?value|x2|χ2|chi|t|r|or|aor|rr|hr|sd|se|iqr|ci|mean|median|frekuensi|persentase|jumlah)/iu', $txt) || preg_match('/\((n|%|sd|iqr|95%\s*ci)\)/iu', $txt)) {
                        $colStats[$gCol]['header_hint'] = true;
                    }
                    continue;
                }

                if (($c['row_type'] ?? '') === 'group') {
                    continue;
                }

                $colStats[$gCol]['total']++;
                // Numeric, percentage, p-value, Mean±SD, Median(IQR), 95% CI, dash
                if (preg_match('/^\s*([<>≤≥]?\s*\d+([.,]\d+)?(\s*[±\-–%]\s*\d+([.,]\d+)?)?(\s*\([^\)]+\))?|\-|—)\s*$/u', $txt)) {
                    $colStats[$gCol]['numeric']++;
                }
            }
        }

        $alignments = [];
        for ($c = 0; $c < $totalCols; $c++) {
            if ($c === 0) {
                $alignments[$c] = 'left';
                continue;
            }
            $st = $colStats[$c];
            $isNumericCol = $st['header_hint'] || ($st['total'] > 0 && ($st['numeric'] / $st['total']) >= 0.55);
            $alignments[$c] = $isNumericCol ? 'center' : 'left';
        }
        return $alignments;
    }

    /**
     * Clean table text & normalize medical/statistical symbols without altering numbers
     */
    public static function cleanTableText(string $text, bool $isHeader = false): string
    {
        // Remove UTF-8 non-breaking spaces (\xC2\xA0) that Word/DOM leaves behind
        $clean = preg_replace('/\x{00A0}+/u', ' ', $text);
        $clean = preg_replace('/[ \t\r\n]+/', ' ', (string) $clean);
        $clean = trim((string) $clean);

        if ($clean === '') {
            return '';
        }

        // Normalize chi-square symbol "X2" -> "χ²" and "p value" -> "p-value" in headers
        if ($isHeader) {
            $clean = preg_replace('/\bX2\b/u', 'χ²', $clean);
            $clean = preg_replace('/\bp[\s\-]+value\b/iu', 'p-value', $clean);
        } else {
            $clean = preg_replace('/\bX2\s+(hitung|tabel)\b/iu', 'χ² $1', $clean);
        }

        return trim((string) $clean);
    }

    /**
     * Normalize caption format ("Tabel N. Title", no trailing period per AMA/ICMJE)
     */
    public static function normalizeCaption(string $rawCaption, int $tableNum = 1, string $lang = 'id'): string
    {
        $prefixWord = ($lang === 'en') ? 'Table' : 'Tabel';
        $raw = trim(preg_replace('/\s+/', ' ', $rawCaption));
        if ($raw === '' || strcasecmp($raw, 'Tabel') === 0 || strcasecmp($raw, 'Table') === 0) {
            return $prefixWord . ' ' . $tableNum . '.';
        }

        if (preg_match('/^(Tabel|Table)\s+(\d+)[\.\:]?\s*(.*)$/iu', $raw, $m)) {
            $num = $m[2];
            $title = rtrim(trim($m[3]), '.');
            return $title !== '' ? ($prefixWord . ' ' . $num . '. ' . $title) : ($prefixWord . ' ' . $num . '.');
        }

        return $prefixWord . ' ' . $tableNum . '. ' . rtrim($raw, '.');
    }

    /**
     * Generate an authentic health-science table footnote from observed headers & values
     */
    public static function buildSuggestedFootnote(array $rows, string $lang = 'id'): string
    {
        $allHeaderText = '';
        $hasSignificantP = false;
        $hasPercent = false;
        $hasFreq = false;
        $hasChiSq = false;
        $hasMeanSd = false;
        $hasCiOr = false;

        foreach ($rows as $rowCells) {
            foreach ($rowCells as $c) {
                $t = (string) ($c['text'] ?? '');
                if (!empty($c['header'])) {
                    $allHeaderText .= ' ' . $t;
                }
                if (preg_match('/\b(F|n)\b|Frekuensi/iu', $t)) {
                    $hasFreq = true;
                }
                if (strpos($t, '%') !== false || stripos($t, 'persentase') !== false) {
                    $hasPercent = true;
                }
                if (preg_match('/χ²|X2|chi[\-\s]?square/iu', $t)) {
                    $hasChiSq = true;
                }
                if (preg_match('/mean|±|\bSD\b/iu', $t)) {
                    $hasMeanSd = true;
                }
                if (preg_match('/\b(OR|aOR|RR|HR|95%\s*CI)\b/u', $t)) {
                    $hasCiOr = true;
                }
                if (preg_match('/^(0[.,]0[0-4]\d*|<\s*0[.,]05)$/u', trim($t))) {
                    $hasSignificantP = true;
                }
            }
        }

        $abbr = [];
        if ($lang === 'en') {
            if ($hasFreq) {
                $abbr[] = 'n, frequency';
            }
            if ($hasMeanSd) {
                $abbr[] = 'SD, standard deviation';
            }
            if ($hasCiOr) {
                $abbr[] = 'OR, odds ratio; CI, confidence interval';
            }
            if ($hasChiSq) {
                $abbr[] = 'χ², Chi-square statistic';
            }
            $notes = [];
            if (!empty($abbr)) {
                $notes[] = 'Abbreviations: ' . implode('; ', $abbr) . '.';
            }
            if ($hasChiSq || stripos($allHeaderText, 'p-value') !== false) {
                $notes[] = $hasSignificantP
                    ? 'Statistical significance evaluated at α = 0.05 (p < 0.05 indicates statistically significant association).'
                    : 'Statistical significance threshold set at p < 0.05.';
            }
            return implode(' ', $notes);
        }

        if ($hasFreq) {
            $abbr[] = 'F/n, frekuensi';
        }
        if ($hasPercent) {
            $abbr[] = '%, persentase';
        }
        if ($hasMeanSd) {
            $abbr[] = 'SD, standar deviasi';
        }
        if ($hasCiOr) {
            $abbr[] = 'OR, odds ratio; CI, confidence interval';
        }
        if ($hasChiSq) {
            $abbr[] = 'χ², nilai uji Chi-square';
        }

        $parts = [];
        if (!empty($abbr)) {
            $parts[] = 'Keterangan: ' . implode('; ', $abbr) . '.';
        }
        if ($hasChiSq || stripos($allHeaderText, 'p-value') !== false) {
            $parts[] = $hasSignificantP
                ? 'Uji kemaknaan statistik pada α = 0,05 (p < 0,05 menunjukkan hubungan bermakna secara statistik).'
                : 'Taraf signifikansi statistik ditetapkan pada p < 0,05.';
        }

        return implode(' ', $parts);
    }

    /**
     * Split a cell string into inline styled runs so statistical symbols (p, n, χ², SD, CI, OR)
     * render with proper medical typography (italic variables, superscript 2).
     *
     * @return array<int, array{text: string, italic: bool, sup: bool}>
     */
    public static function splitStatisticalInlineRuns(string $text): array
    {
        if ($text === '') {
            return [];
        }

        // Tokenize p-value, (n), χ², X2, OR, aOR, CI, SD, IQR, p < 0.05
        $pattern = '/(\bp(?=\-value\b|\s*[<=<>]\s*0[.,]|\s*\))|\b(?<=\()n(?=\))|\b(?:aOR|OR|RR|HR|SD|SE|IQR|CI)\b|χ²|X2)/u';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return [['text' => $text, 'italic' => false, 'sup' => false]];
        }

        $runs = [];
        foreach ($parts as $part) {
            if ($part === 'p' || $part === 'n' || in_array($part, ['OR', 'aOR', 'RR', 'HR', 'SD', 'SE', 'IQR', 'CI'], true)) {
                $runs[] = ['text' => $part, 'italic' => true, 'sup' => false];
            } elseif ($part === 'χ²' || $part === 'X2') {
                $sym = ($part === 'χ²') ? 'χ' : 'X';
                $runs[] = ['text' => $sym, 'italic' => true, 'sup' => false];
                $runs[] = ['text' => '2', 'italic' => false, 'sup' => true];
            } else {
                $runs[] = ['text' => $part, 'italic' => false, 'sup' => false];
            }
        }
        return $runs;
    }
}
