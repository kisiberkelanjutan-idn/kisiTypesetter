<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/DocxParser.php
 *
 * Parses an existing Word (.docx) manuscript (current running issues or back-issues)
 * into structured JSON blocks (metadata, optional bilingual abstracts, direct body or IMRaD sections,
 * subheadings, tables with colspan/rowspan, and references).
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

require_once __DIR__ . '/HealthTableFormatter.php';
require_once __DIR__ . '/LanguageAuditor.php';
require_once __DIR__ . '/VancouverFormatter.php';

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

class DocxParser
{
    public const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Parse a .docx file into structured article data
     */
    public static function parse(string $docxPath): array
    {
        $result = [
            'article_type' => 'ORIGINAL ARTICLE',
            'title_id' => '',
            'title_en' => '',
            'authors' => [],
            'corresponding_name' => '',
            'corresponding_email' => '',
            'citation' => '',
            'doi' => '',
            'review_editor' => '',
            'received_date' => '',
            'accepted_date' => '',
            'published_date' => '',
            'copyright_year' => '',
            'volume' => '',
            'issue_number' => '',
            'footer_issue_line' => '',
            'funding' => 'none',
            'competing_interests' => 'The authors have declared that no competing interests exist.',
            'contributor_roles' => '',
            'abstract_id' => [
                'pendahuluan_metode' => '',
                'hasil' => '',
                'kesimpulan_saran' => '',
                'raw' => '',
            ],
            'keywords_id' => '',
            'sdgs_id' => '',
            'abstract_en' => [
                'intro_methods' => '',
                'results' => '',
                'conclusions_recommendations' => '',
                'raw' => '',
            ],
            'keywords_en' => '',
            'sdgs_en' => '',
            'sections' => [
                'badan_artikel' => [],
                'pendahuluan' => [],
                'metode' => [],
                'hasil' => [],
                'pembahasan' => [],
                'kesimpulan_saran' => [],
            ],
            'referensi' => [],
            'raw_text_for_ai' => '',
        ];

        if (!file_exists($docxPath)) {
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            return $result;
        }

        $docEntry = self::resolveZipEntryName($zip, 'word/document.xml');
        $xmlContent = $zip->getFromName($docEntry);

        // Extract footer issue line & volume/issue metadata from footer2.xml / footer1.xml if present
        foreach (['word/footer2.xml', 'word/footer1.xml'] as $fCandidate) {
            $fEntry = self::resolveZipEntryName($zip, $fCandidate);
            $fXml = $zip->getFromName($fEntry);
            if ($fXml) {
                $fDom = new DOMDocument();
                @$fDom->loadXML($fXml, LIBXML_NOENT | LIBXML_NONET);
                $fXp = new DOMXPath($fDom);
                $fXp->registerNamespace('w', self::NS_W);
                foreach ($fXp->query('//w:p') as $fp) {
                    $ft = trim(self::getElementText($fp, $fXp));
                    if (preg_match('/^Volume\s+(\S+)\s+Nomor\s+(\S+)\s*,?\s*(.*)$/iu', $ft, $fm)) {
                        $result['volume'] = $fm[1];
                        $result['issue_number'] = $fm[2];
                        $result['footer_issue_line'] = LanguageAuditor::translateMonthsToIndonesian($ft);
                        if (preg_match('/\b(20\d{2})\b/', $ft, $ym)) {
                            $result['copyright_year'] = $ym[1];
                        }
                        break 2;
                    }
                }
            }
        }

        // Extract embedded pictures (word/media/*) keyed by their relationship id
        $documentMedia = self::extractDocumentMedia($zip);

        $zip->close();

        if (!$xmlContent) {
            return $result;
        }

        $dom = new DOMDocument();
        @$dom->loadXML($xmlContent, LIBXML_NOENT | LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);

        $body = $xpath->query('//w:body')->item(0);
        if (!$body) {
            return $result;
        }

        $children = [];
        foreach ($body->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            }
        }

        $startIndex = 0;
        $hasFrontMatterTable = false;

        // Check if child 0 is a front-matter layout table (like Kisi 2026 / Letter / Data Report / Vol 3+ template)
        if (count($children) > 0 && $children[0]->localName === 'tbl') {
            $firstTblText = self::getElementText($children[0], $xpath);
            if (stripos($firstTblText, 'ABSTRAK') !== false || stripos($firstTblText, 'OPEN ACCESS') !== false || stripos($firstTblText, 'Citation:') !== false) {
                self::parseFrontMatterTable($children[0], $xpath, $result);
                $startIndex = 1;
                $hasFrontMatterTable = true;

                // Handle manual Vol 3+ DOCX files where SDGs ID box or ABSTRACT row were split into immediately following tables
                while ($startIndex < count($children) && $children[$startIndex]->localName === 'tbl') {
                    $nextTblText = trim(self::getElementText($children[$startIndex], $xpath));
                    $cells = $xpath->query('./w:tr/w:tc', $children[$startIndex]);
                    if (
                        $cells->length === 1
                        && preg_match('/^Kontribusi\s+terhadap\s+Target\s+Pembangunan\s+Berkelanjutan/iu', $nextTblText)
                    ) {
                        self::parseStructuredAbstractCell($cells->item(0), $xpath, 'id', $result);
                        $startIndex++;
                        continue;
                    }
                    if (
                        $cells->length === 1
                        && preg_match('/^ABSTRACT\b/i', $nextTblText)
                        && stripos($nextTblText, 'Introduction and Methods') !== false
                    ) {
                        self::parseStructuredAbstractCell($cells->item(0), $xpath, 'en', $result);
                        $startIndex++;
                        continue;
                    }
                    break;
                }
            }
        }

        $noAbstractType = LanguageAuditor::isNoAbstractArticleType((string) ($result['article_type'] ?? ''));
        $currentSection = '';
        $keywordsLabelSeen = false;
        $pendingTableCaption = '';
        $lastTableRef = null;
        $tableCounter = 1;
        $rawLines = [];

        for ($i = $startIndex; $i < count($children); $i++) {
            $node = $children[$i];

            if ($node->localName === 'p') {
                // Skip paragraph if it contains a Drawing/TextBox (like Publisher's Note re-typeset box)
                if ($xpath->query('.//w:txbxContent', $node)->length > 0) {
                    continue;
                }

                $text = trim(self::getElementText($node, $xpath));
                $paragraphImages = self::extractParagraphImages($node, $xpath, $documentMedia);

                // Paragraph holds one or more embedded pictures: emit image blocks (plus inline caption text, if any)
                if (!empty($paragraphImages)) {
                    if ($currentSection === 'end') {
                        continue;
                    }
                    $bodySectionKeys = ['badan_artikel', 'pendahuluan', 'metode', 'hasil', 'pembahasan', 'kesimpulan_saran'];
                    $imageSec = in_array($currentSection, $bodySectionKeys, true)
                        ? $currentSection
                        : 'badan_artikel';
                    foreach ($paragraphImages as $imgBlock) {
                        $result['sections'][$imageSec][] = $imgBlock;
                    }
                    if ($text !== '') {
                        if (preg_match('/^(Gambar|Figure|Fig\.?)\s+\d+[\.\:]?/iu', $text)) {
                            $lastImgIdx = count($result['sections'][$imageSec]) - 1;
                            $result['sections'][$imageSec][$lastImgIdx]['caption'] = $text;
                        } else {
                            $result['sections'][$imageSec][] = [
                                'type' => 'paragraph',
                                'text' => $text,
                            ];
                            $rawLines[] = $text;
                        }
                    }
                    $pendingTableCaption = '';
                    $lastTableRef = null;
                    continue;
                }

                if ($text === '') {
                    continue;
                }

                $rawLines[] = $text;

                // Stop if we hit Publisher's Note
                if (preg_match('/^Publisher[’\']?s\s+Note$/iu', $text) || stripos($text, 'The publisher of PT Karya Inovasi Berkelanjutan') === 0) {
                    $currentSection = 'end';
                    $lastTableRef = null;
                    continue;
                }
                if ($currentSection === 'end') {
                    continue;
                }

                // Detect Main Section Heading
                $detectedSection = self::detectMainSectionHeading($text);
                if ($detectedSection !== null) {
                    $currentSection = $detectedSection;
                    $pendingTableCaption = '';
                    $lastTableRef = null;
                    continue;
                }

                // If we haven't entered an explicit Heading1 section yet:
                //  - For LETTER / DATA REPORT or template documents that already have the front-matter table,
                //    direct body paragraphs belong straight to 'badan_artikel' (no forced PENDAHULUAN heading).
                //  - Only fall back to pre-intro metadata extraction for legacy raw files without a front-matter table.
                if ($currentSection === '') {
                    if ($hasFrontMatterTable || $noAbstractType) {
                        $currentSection = 'badan_artikel';
                    } else {
                        if (preg_match('/^(DATA\s*REPORT|LETTER)$/iu', $text)) {
                            $result['article_type'] = mb_strtoupper(trim($text), 'UTF-8');
                            $noAbstractType = true;
                            continue;
                        }
                        // Metadata-only front matter (title, authors, keywords) has ended: the remaining
                        // prose is the article body. Abstract-less categories (LETTER / DATA REPORT) often
                        // arrive as plain paragraphs, without the front-matter table and without ABSTRAK/ABSTRACT.
                        if (!self::parsePreIntroParagraph($text, $node, $xpath, $result, $keywordsLabelSeen)) {
                            continue;
                        }
                        $currentSection = 'badan_artikel';
                    }
                }

                // Figure caption paragraph ("Gambar 1. ...") directly below an image block becomes that image's caption
                if (isset($result['sections'][$currentSection]) && preg_match('/^(Gambar|Figure|Fig\.?)\s+\d+[\.\:]?/iu', $text)) {
                    $lastBlockIdx = count($result['sections'][$currentSection]) - 1;
                    if (
                        $lastBlockIdx >= 0
                        && ($result['sections'][$currentSection][$lastBlockIdx]['type'] ?? '') === 'image'
                        && trim((string) ($result['sections'][$currentSection][$lastBlockIdx]['caption'] ?? '')) === ''
                    ) {
                        $result['sections'][$currentSection][$lastBlockIdx]['caption'] = $text;
                        continue;
                    }
                }

                // Inside SUMBER PUSTAKA / DAFTAR PUSTAKA (normalize away list prefix while preserving original entry text)
                if ($currentSection === 'referensi') {
                    $cleanRef = VancouverFormatter::stripLeadingNumber($text);
                    if ($cleanRef !== '') {
                        $result['referensi'][] = $cleanRef;
                    }
                    continue;
                }

                // Detect Table Footnote immediately following a table
                if ($lastTableRef !== null && self::isLikelyTableFootnote($text)) {
                    $secK = $lastTableRef['sec'];
                    $idxK = $lastTableRef['idx'];
                    $prevFoot = trim((string) ($result['sections'][$secK][$idxK]['footnote'] ?? ''));
                    $result['sections'][$secK][$idxK]['footnote'] = trim($prevFoot !== '' ? ($prevFoot . ' ' . $text) : $text);
                    continue;
                }
                $lastTableRef = null;

                // Detect Table Caption (e.g., "Tabel 1. Karakteristik Responden" or "Table 1.")
                if (preg_match('/^(Tabel|Table)\s+\d+[\.\:]?\s*.+/iu', $text)) {
                    $pendingTableCaption = $text;
                    continue;
                }

                // Check if this paragraph is a Subheading (Heading2/3 or bold short line without ending period; exclude figure/table captions)
                $pStyle = (string) ($xpath->query('./w:pPr/w:pStyle/@w:val', $node)->item(0)?->nodeValue ?? '');
                $isBold = self::isParagraphBold($node, $xpath);
                $isMediaCaption = (bool) preg_match('/^(Gambar|Figure|Fig\.?|Tabel|Table|Sumber|Keterangan|Catatan)\b/iu', $text);
                $wordCount = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
                if (!$isMediaCaption && (preg_match('/^Heading[23]$/i', $pStyle) || ($isBold && $wordCount <= 12 && !preg_match('/[\.\?\!]$/u', $text)))) {
                    if ($pendingTableCaption !== '') {
                        $result['sections'][$currentSection][] = [
                            'type' => 'paragraph',
                            'text' => $pendingTableCaption,
                        ];
                        $pendingTableCaption = '';
                    }
                    $subType = (strcasecmp($pStyle, 'Heading3') === 0) ? 'subheading_l3' : 'subheading';
                    $result['sections'][$currentSection][] = [
                        'type' => $subType,
                        'text' => $text,
                    ];
                } else {
                    if ($pendingTableCaption !== '') {
                        $result['sections'][$currentSection][] = [
                            'type' => 'paragraph',
                            'text' => $pendingTableCaption,
                        ];
                        $pendingTableCaption = '';
                    }
                    $result['sections'][$currentSection][] = [
                        'type' => 'paragraph',
                        'text' => $text,
                    ];
                }
            } elseif ($node->localName === 'tbl' && $currentSection !== 'end') {
                $targetSec = ($currentSection !== '' && isset($result['sections'][$currentSection]))
                    ? $currentSection
                    : (($hasFrontMatterTable || $noAbstractType) ? 'badan_artikel' : 'hasil');

                $parsedTable = self::parseDataTable($node, $xpath, $pendingTableCaption, $tableCounter);
                $pendingTableCaption = '';
                if (!empty($parsedTable['rows'])) {
                    $result['sections'][$targetSec][] = $parsedTable;
                    $lastTableRef = [
                        'sec' => $targetSec,
                        'idx' => count($result['sections'][$targetSec]) - 1,
                    ];
                    $tableCounter++;
                } else {
                    $lastTableRef = null;
                }
            }
        }

        $result['raw_text_for_ai'] = implode("\n", $rawLines);
        return LanguageAuditor::normalizeArticleParagraphSpaces($result);
    }

    /**
     * Parse the 2-column Front-Matter Table of the Kisi Berkelanjutan 2026 template
     */
    private static function parseFrontMatterTable(DOMElement $tbl, DOMXPath $xpath, array &$result): void
    {
        $rows = $xpath->query('./w:tr', $tbl);
        if ($rows->length === 0) {
            return;
        }

        // Row 0 Cell 0: Left Sidebar Metadata
        $sidebarCell = $xpath->query('./w:tc[1]', $rows->item(0))->item(0);
        if ($sidebarCell) {
            $pNodes = $xpath->query('./w:p', $sidebarCell);
            $expectingReviewEditor = false;
            $collectingContribRoles = false;
            foreach ($pNodes as $p) {
                $txt = trim(self::getElementText($p, $xpath));
                if ($txt === '' || $txt === 'OPEN ACCESS') {
                    if ($txt === '' && $collectingContribRoles) {
                        $collectingContribRoles = false;
                    }
                    continue;
                }
                if ($expectingReviewEditor) {
                    $result['review_editor'] = $txt;
                    $expectingReviewEditor = false;
                    continue;
                }
                if (stripos($txt, 'Citation:') === 0) {
                    $collectingContribRoles = false;
                    $result['citation'] = trim(substr($txt, strlen('Citation:')));
                    if ($result['volume'] === '' && preg_match('/,\s*(\d+)\((\d+)\)\s*,/', $result['citation'], $cm)) {
                        $result['volume'] = $cm[1];
                        $result['issue_number'] = $cm[2];
                    }
                } elseif (stripos($txt, 'Digital Object Identifier:') === 0) {
                    $collectingContribRoles = false;
                    $result['doi'] = trim(substr($txt, strlen('Digital Object Identifier:')));
                } elseif (stripos($txt, 'Review Editor:') === 0) {
                    $collectingContribRoles = false;
                    $after = trim(substr($txt, strlen('Review Editor:')));
                    if ($after !== '') {
                        $result['review_editor'] = $after;
                    } else {
                        $expectingReviewEditor = true;
                    }
                } elseif (stripos($txt, 'Received:') === 0) {
                    $collectingContribRoles = false;
                    $result['received_date'] = trim(substr($txt, strlen('Received:')));
                } elseif (stripos($txt, 'Accepted:') === 0) {
                    $collectingContribRoles = false;
                    $result['accepted_date'] = trim(substr($txt, strlen('Accepted:')));
                } elseif (stripos($txt, 'Published:') === 0) {
                    $collectingContribRoles = false;
                    $result['published_date'] = trim(substr($txt, strlen('Published:')));
                } elseif (stripos($txt, 'Copyright:') === 0) {
                    $collectingContribRoles = false;
                    if ($result['copyright_year'] === '' && preg_match('/©\s*(20\d{2})/', $txt, $ym)) {
                        $result['copyright_year'] = $ym[1];
                    }
                } elseif (stripos($txt, 'Funding:') === 0) {
                    $collectingContribRoles = false;
                    $result['funding'] = trim(substr($txt, strlen('Funding:')));
                } elseif (stripos($txt, 'Competing interests:') === 0) {
                    $collectingContribRoles = false;
                    $result['competing_interests'] = trim(substr($txt, strlen('Competing interests:')));
                } elseif (preg_match('/^Contributor[’\']?s?\s+Roles\s*:\s*(.*)$/iu', $txt, $crm)) {
                    $result['contributor_roles'] = trim($crm[1]);
                    $collectingContribRoles = true;
                } elseif ($collectingContribRoles) {
                    $result['contributor_roles'] = trim($result['contributor_roles'] . ' ' . $txt);
                }
            }
        }

        // Row 0 Cell 1: Article Type
        if ($rows->length > 0) {
            $c1 = $xpath->query('./w:tc[2]', $rows->item(0))->item(0);
            if ($c1) {
                $t = trim(self::getElementText($c1, $xpath));
                if ($t !== '') {
                    $result['article_type'] = $t;
                }
            }
        }

        // Row 1 Cell 1: Titles (ID & EN)
        if ($rows->length > 1) {
            $c1 = $xpath->query('./w:tc[2]', $rows->item(1))->item(0);
            if ($c1) {
                $nonEmpty = [];
                foreach ($xpath->query('./w:p', $c1) as $p) {
                    $t = trim(self::getElementText($p, $xpath));
                    if ($t !== '') {
                        $nonEmpty[] = $t;
                    }
                }
                if (isset($nonEmpty[0])) {
                    $result['title_id'] = $nonEmpty[0];
                }
                if (isset($nonEmpty[1])) {
                    $result['title_en'] = $nonEmpty[1];
                }
            }
        }

        // Row 3 Cell 1: Authors & Affiliations
        if ($rows->length > 3) {
            $c1 = $xpath->query('./w:tc[2]', $rows->item(3))->item(0);
            if ($c1) {
                $nonEmpty = [];
                foreach ($xpath->query('./w:p', $c1) as $p) {
                    $t = trim(self::getElementText($p, $xpath));
                    if ($t !== '') {
                        $nonEmpty[] = $t;
                    }
                }
                if (count($nonEmpty) > 0) {
                    $authorLine = $nonEmpty[0];
                    $affLines = array_slice($nonEmpty, 1);
                    $defaultAff = $affLines[0] ?? '';
                    $rawAuthors = explode(',', $authorLine);
                    foreach ($rawAuthors as $idx => $rawName) {
                        $rawName = trim($rawName);
                        if ($rawName === '') {
                            continue;
                        }
                        $isCorresp = strpos($rawName, '*') !== false;
                        $cleanName = trim(preg_replace('/[\d\*]+$/u', '', $rawName));
                        $affText = $affLines[$idx] ?? $defaultAff;
                        $result['authors'][] = [
                            'name' => $cleanName,
                            'affiliation' => $affText,
                            'email' => '',
                            'is_corresponding' => $isCorresp || ($idx === 0 && count($result['authors']) === 0),
                        ];
                    }
                }
            }
        }

        // Row 4 Cell 1: Corresponding Author
        $hasCorrespondingEn = false;
        if ($rows->length > 4) {
            $c1 = $xpath->query('./w:tc[2]', $rows->item(4))->item(0);
            if ($c1) {
                $t = trim(self::getElementText($c1, $xpath));
                if (stripos($t, 'Corresponding author') !== false) {
                    $hasCorrespondingEn = true;
                }
                if (preg_match('/(?:Penulis\s+koresponden|Corresponding\s+author)\s*:\s*([^\(]+)\(([^\)]+)\)/iu', $t, $m)) {
                    $result['corresponding_name'] = trim($m[1]);
                    $result['corresponding_email'] = trim($m[2]);
                    foreach ($result['authors'] as &$a) {
                        if ($a['is_corresponding']) {
                            $a['email'] = $result['corresponding_email'];
                        }
                    }
                    unset($a);
                }
            }
        }

        // Rows 5+: optional TPB/SDGs + Keywords (LETTER/DATA REPORT), ABSTRAK (ID), and ABSTRACT / SDGs (EN)
        // Scan dynamically by cell content so both no-abstract and English-Only templates parse cleanly.
        $pendingAbstractLang = '';
        for ($rIdx = 5; $rIdx < $rows->length; $rIdx++) {
            $rowCells = $xpath->query('./w:tc', $rows->item($rIdx));
            if ($rowCells->length === 0) {
                continue;
            }
            $cellNode = $rowCells->item($rowCells->length - 1);
            if (!$cellNode instanceof DOMElement) {
                continue;
            }
            $cellTxt = trim(self::getElementText($cellNode, $xpath));
            if ($cellTxt === '') {
                continue;
            }
            if (preg_match('/^ABSTRAK$/iu', $cellTxt)) {
                $pendingAbstractLang = 'id';
                continue;
            }
            if (preg_match('/^ABSTRACT$/iu', $cellTxt)) {
                $pendingAbstractLang = 'en';
                continue;
            }
            if (
                preg_match('/^Kontribusi\s+terhadap\s+Target\s+Pembangunan\s+Berkelanjutan/iu', $cellTxt)
                || preg_match('/(?:^|\b)Pendahuluan\s+dan\s+Metode\b/iu', $cellTxt)
                || preg_match('/\bKata\s+Kunci\s*:/iu', $cellTxt)
            ) {
                self::parseStructuredAbstractCell($cellNode, $xpath, 'id', $result);
                $pendingAbstractLang = 'id';
            } elseif (
                preg_match('/^Contribution\s+to\s+Sustainable\s+Development\s+Goals/iu', $cellTxt)
                || preg_match('/(?:^|\b)Introduction\s+and\s+Methods\b/iu', $cellTxt)
                || preg_match('/^ABSTRACT\b/iu', $cellTxt)
                || preg_match('/\bKeywords\s*:/iu', $cellTxt)
            ) {
                self::parseStructuredAbstractCell($cellNode, $xpath, 'en', $result);
                $pendingAbstractLang = 'en';
            } elseif ($pendingAbstractLang === 'id') {
                self::parseStructuredAbstractCell($cellNode, $xpath, 'id', $result);
            } else {
                self::parseStructuredAbstractCell($cellNode, $xpath, 'en', $result);
            }
        }

        // If front-matter table has only 1 title and English-Only cues (no ID abstract/keywords),
        // map that single title to title_en instead of duplicating or misfiling into title_id.
        if (
            $result['title_en'] === ''
            && $result['title_id'] !== ''
            && (
                $hasCorrespondingEn
                || (
                    trim((string) ($result['abstract_id']['pendahuluan_metode'] ?? '')) === ''
                    && trim((string) ($result['keywords_id'] ?? '')) === ''
                    && (
                        trim((string) ($result['abstract_en']['intro_methods'] ?? '')) !== ''
                        || trim((string) ($result['keywords_en'] ?? '')) !== ''
                    )
                )
            )
        ) {
            $result['title_en'] = $result['title_id'];
            $result['title_id'] = '';
            $result['form_mode'] = 'en';
        }
    }

    /**
     * Parse structured abstract cell (ID or EN)
     */
    private static function parseStructuredAbstractCell(DOMElement $cell, DOMXPath $xpath, string $lang, array &$result): void
    {
        $currentKey = '';
        $rawParts = [];

        foreach ($xpath->query('./w:p', $cell) as $p) {
            $t = trim(self::getElementText($p, $xpath));
            if ($t === '' || strcasecmp($t, 'ABSTRAK') === 0 || strcasecmp($t, 'ABSTRACT') === 0) {
                continue;
            }

            if ($lang === 'id') {
                if (preg_match('/^Pendahuluan\s+dan\s+Metode$/iu', $t)) {
                    $currentKey = 'pendahuluan_metode';
                    continue;
                }
                if (preg_match('/^Hasil$/iu', $t)) {
                    $currentKey = 'hasil';
                    continue;
                }
                if (preg_match('/^Kesimpulan(\s+dan\s+Saran)?$/iu', $t)) {
                    $currentKey = 'kesimpulan_saran';
                    continue;
                }
                if (preg_match('/^Kata\s+Kunci\s*:\s*(.+)$/iu', $t, $m)) {
                    $result['keywords_id'] = rtrim(trim($m[1]), '.');
                    continue;
                }
                if (preg_match('/^Kontribusi\s+terhadap\s+Target\s+Pembangunan\s+Berkelanjutan/iu', $t)) {
                    $currentKey = 'sdgs_id';
                    continue;
                }

                if ($currentKey === 'sdgs_id') {
                    $result['sdgs_id'] = trim(($result['sdgs_id'] ?? '') . ' ' . $t);
                    continue;
                }

                $rawParts[] = $t;
                if ($currentKey !== '') {
                    $result['abstract_id'][$currentKey] = trim($result['abstract_id'][$currentKey] . ' ' . $t);
                }
            } else {
                if (preg_match('/^Introduction\s+and\s+Methods$/iu', $t)) {
                    $currentKey = 'intro_methods';
                    continue;
                }
                if (preg_match('/^Results$/iu', $t)) {
                    $currentKey = 'results';
                    continue;
                }
                if (preg_match('/^Conclusions(\s+and\s+Recommendations?)?$/iu', $t)) {
                    $currentKey = 'conclusions_recommendations';
                    continue;
                }
                if (preg_match('/^Keywords\s*:\s*(.+)$/iu', $t, $m)) {
                    $result['keywords_en'] = rtrim(trim($m[1]), '.');
                    continue;
                }
                if (preg_match('/^Contribution\s+to\s+Sustainable\s+Development\s+Goals/iu', $t)) {
                    $currentKey = 'sdgs_en';
                    continue;
                }

                if ($currentKey === 'sdgs_en') {
                    $result['sdgs_en'] = trim(($result['sdgs_en'] ?? '') . ' ' . $t);
                    continue;
                }

                $rawParts[] = $t;
                if ($currentKey !== '') {
                    $result['abstract_en'][$currentKey] = trim($result['abstract_en'][$currentKey] . ' ' . $t);
                }
            }
        }

        if ($lang === 'id') {
            $result['abstract_id']['raw'] = implode("\n", $rawParts);
            if ($result['abstract_id']['pendahuluan_metode'] === '' && !empty($rawParts)) {
                $result['abstract_id']['pendahuluan_metode'] = $result['abstract_id']['raw'];
            }
        } else {
            $result['abstract_en']['raw'] = implode("\n", $rawParts);
            if ($result['abstract_en']['intro_methods'] === '' && !empty($rawParts)) {
                $result['abstract_en']['intro_methods'] = $result['abstract_en']['raw'];
            }
        }
    }

    /**
     * Handle pre-introduction paragraphs in legacy (non-table) Word documents.
     *
     * @return bool True when the paragraph is the start of the article body, so the caller routes it
     *              (and everything after it) to 'badan_artikel' instead of the abstract fields.
     */
    private static function parsePreIntroParagraph(string $text, DOMElement $p, DOMXPath $xpath, array &$result, bool &$keywordsLabelSeen): bool
    {
        if (preg_match('/^Kata\s+Kunci\s*:\s*(.+)$/iu', $text, $m)) {
            $result['keywords_id'] = rtrim(trim($m[1]), '.');
            $keywordsLabelSeen = true;
            return false;
        }
        if (preg_match('/^Keywords\s*:\s*(.+)$/iu', $text, $m)) {
            $result['keywords_en'] = rtrim(trim($m[1]), '.');
            $keywordsLabelSeen = true;
            return false;
        }
        if (preg_match('/^(?:Kata\s+Kunci|Keywords)\s*:\s*$/iu', $text)) {
            // Label-only line; the keyword list follows on the next paragraph.
            $keywordsLabelSeen = true;
            return false;
        }
        if (strcasecmp($text, 'ABSTRAK') === 0 || strcasecmp($text, 'ABSTRACT') === 0) {
            return false;
        }
        if ($result['title_id'] === '' && strlen($text) > 15 && strlen($text) < 300) {
            $result['title_id'] = $text;
            return false;
        }

        if (mb_strlen($text) <= 250) {
            return false;
        }

        $hasKeywords = $keywordsLabelSeen
            || trim((string) ($result['keywords_id'] ?? '')) !== ''
            || trim((string) ($result['keywords_en'] ?? '')) !== '';
        $absIdFilled = trim((string) ($result['abstract_id']['raw'] ?? '')) !== '';
        $absEnFilled = trim((string) ($result['abstract_en']['raw'] ?? '')) !== '';

        // Front matter (title/authors/keywords) is already consumed, or both abstract slots are full:
        // this prose is the article body, not an abstract.
        if ($hasKeywords || ($absIdFilled && $absEnFilled)) {
            return true;
        }

        if ($result['abstract_id']['raw'] === '') {
            $result['abstract_id']['raw'] = $text;
            $result['abstract_id']['pendahuluan_metode'] = $text;
        } elseif ($result['abstract_en']['raw'] === '') {
            $result['abstract_en']['raw'] = $text;
            $result['abstract_en']['intro_methods'] = $text;
        }

        return false;
    }

    /**
     * Detect main section headings
     */
    private static function detectMainSectionHeading(string $text): ?string
    {
        $clean = trim(preg_replace('/^[IVX0-9]+[\.\)]\s*/u', '', $text));
        $upper = mb_strtoupper($clean, 'UTF-8');

        if (in_array($upper, ['PENDAHULUAN', 'INTRODUCTION', 'LATAR BELAKANG'], true)) {
            return 'pendahuluan';
        }
        if (in_array($upper, ['METODE', 'METODE PENELITIAN', 'BAHAN DAN METODE', 'METHODS', 'MATERIALS AND METHODS'], true)) {
            return 'metode';
        }
        if (in_array($upper, ['HASIL', 'HASIL PENELITIAN', 'RESULTS'], true)) {
            return 'hasil';
        }
        if (in_array($upper, ['PEMBAHASAN', 'DISKUSI', 'DISCUSSION'], true)) {
            return 'pembahasan';
        }
        if (in_array($upper, ['HASIL DAN PEMBAHASAN', 'RESULTS AND DISCUSSION'], true)) {
            return 'hasil';
        }
        if (in_array($upper, ['KESIMPULAN', 'KESIMPULAN DAN SARAN', 'SIMPULAN', 'SIMPULAN DAN SARAN', 'CONCLUSION', 'CONCLUSIONS', 'CONCLUSIONS AND RECOMMENDATIONS'], true)) {
            return 'kesimpulan_saran';
        }
        if (in_array($upper, ['SUMBER PUSTAKA', 'DAFTAR PUSTAKA', 'REFERENSI', 'REFERENCES', 'BIBLIOGRAPHY'], true)) {
            return 'referensi';
        }

        return null;
    }

    /**
     * Parse a Word data table (<w:tbl>) preserving gridSpan (colspan), vMerge (rowspan),
     * and normalizing it to Health-Science Table Standards.
     */
    private static function parseDataTable(DOMElement $tbl, DOMXPath $xpath, string $caption = '', int $tableNum = 1): array
    {
        $rowsData = [];
        $trNodes = $xpath->query('./w:tr', $tbl);

        // Track open vertical merges per grid column index: colIndex => ['row' => r, 'cellIndex' => c]
        $vMergeTracker = [];

        foreach ($trNodes as $rIdx => $tr) {
            $rowCells = [];
            $gridCol = 0;
            $isHeaderRow = ($rIdx === 0) || ($xpath->query('./w:trPr/w:tblHeader', $tr)->length > 0);

            foreach ($xpath->query('./w:tc', $tr) as $tc) {
                $gridSpanNode = $xpath->query('./w:tcPr/w:gridSpan/@w:val', $tc)->item(0);
                $colspan = $gridSpanNode ? max(1, (int) $gridSpanNode->nodeValue) : 1;

                $vMergeNode = $xpath->query('./w:tcPr/w:vMerge', $tc)->item(0);
                $vMergeValNode = $xpath->query('./w:tcPr/w:vMerge/@w:val', $tc)->item(0);
                $vMergeVal = $vMergeValNode ? $vMergeValNode->nodeValue : ($vMergeNode ? 'continue' : '');

                $cellText = trim(self::getElementText($tc, $xpath));

                if ($vMergeVal === 'continue' && isset($vMergeTracker[$gridCol])) {
                    $originRow = $vMergeTracker[$gridCol]['row'];
                    $originCellIdx = $vMergeTracker[$gridCol]['cellIndex'];
                    $rowsData[$originRow][$originCellIdx]['rowspan']++;
                    $rowCells[] = [
                        'text' => '',
                        'colspan' => $colspan,
                        'rowspan' => 1,
                        'vmerge' => 'continue',
                        'header' => $isHeaderRow,
                    ];
                } else {
                    $cellIdx = count($rowCells);
                    $rowCells[] = [
                        'text' => $cellText,
                        'colspan' => $colspan,
                        'rowspan' => 1,
                        'vmerge' => ($vMergeVal === 'restart') ? 'restart' : '',
                        'header' => $isHeaderRow,
                    ];

                    if ($vMergeVal === 'restart') {
                        $vMergeTracker[$gridCol] = [
                            'row' => $rIdx,
                            'cellIndex' => $cellIdx,
                        ];
                    } else {
                        unset($vMergeTracker[$gridCol]);
                    }
                }

                $gridCol += $colspan;
            }

            if (!empty($rowCells)) {
                $rowsData[] = $rowCells;
            }
        }

        $tableBlock = [
            'type' => 'table',
            'caption' => $caption !== '' ? $caption : ('Tabel ' . $tableNum . '.'),
            'footnote' => '',
            'rows' => $rowsData,
        ];

        return HealthTableFormatter::formatTableBlock($tableBlock, [
            'table_num' => $tableNum,
            'suggest_footnote' => false,
        ]);
    }

    private static function isLikelyTableFootnote(string $text): bool
    {
        $wordCount = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        if ($wordCount > 60) {
            return false;
        }
        return (bool) preg_match('/^(Keterangan|Catatan|Singkatan|Sumber|Note|Notes|Abbreviations?|Source|\*\s*p\s*[<≤=]|†)/iu', trim($text));
    }

    /**
     * Check if a paragraph has bold formatting
     */
    private static function isParagraphBold(DOMElement $p, DOMXPath $xpath): bool
    {
        if ($xpath->query('./w:pPr/w:rPr/w:b', $p)->length > 0) {
            return true;
        }
        $runs = $xpath->query('./w:r', $p);
        if ($runs->length === 0) {
            return false;
        }
        foreach ($runs as $r) {
            $t = trim(self::getElementText($r, $xpath));
            if ($t === '') {
                continue;
            }
            if ($xpath->query('./w:rPr/w:b', $r)->length === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build a rId => image payload map from word/_rels/document.xml.rels and word/media/*
     * so drawing references (<a:blip r:embed="rIdN">) can be turned into image blocks.
     */
    private static function extractDocumentMedia(ZipArchive $zip): array
    {
        $media = [];
        $relsEntry = self::resolveZipEntryName($zip, 'word/_rels/document.xml.rels');
        $relsXml = $zip->getFromName($relsEntry);
        if (!$relsXml) {
            return $media;
        }

        $relsDom = new DOMDocument();
        if (!@$relsDom->loadXML($relsXml, LIBXML_NOENT | LIBXML_NONET)) {
            return $media;
        }

        foreach ($relsDom->getElementsByTagName('*') as $rel) {
            if (!$rel instanceof DOMElement || $rel->localName !== 'Relationship') {
                continue;
            }
            if (stripos((string) $rel->getAttribute('Type'), '/image') === false) {
                continue;
            }
            if (strcasecmp((string) $rel->getAttribute('TargetMode'), 'External') === 0) {
                continue;
            }

            $rid = (string) $rel->getAttribute('Id');
            $target = str_replace('\\', '/', (string) $rel->getAttribute('Target'));
            if ($rid === '' || $target === '') {
                continue;
            }

            $entryPath = (strpos($target, '/') === 0)
                ? ltrim($target, '/')
                : self::normalizeZipPath('word/' . $target);
            $entry = self::resolveZipEntryName($zip, $entryPath);
            $binary = $zip->getFromName($entry);
            if ($binary === false || $binary === '') {
                continue;
            }

            $ext = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
            $mime = self::imageMimeFromExt($ext);
            $media[$rid] = [
                'data' => 'data:' . $mime . ';base64,' . base64_encode($binary),
                'filename' => basename($entry),
                'mime' => $mime,
                'ext' => $ext !== '' ? $ext : 'png',
            ];
        }

        return $media;
    }

    /**
     * Collect embedded pictures referenced by a paragraph as ready-to-use image blocks.
     */
    private static function extractParagraphImages(DOMElement $p, DOMXPath $xpath, array $media): array
    {
        $blocks = [];
        if (empty($media)) {
            return $blocks;
        }

        foreach ($xpath->query('.//*[local-name()="blip" or local-name()="imagedata"]', $p) as $imgNode) {
            if (!($imgNode instanceof DOMElement)) {
                continue;
            }

            // Skip VML duplicates inside mc:Fallback
            $inFallback = false;
            for ($anc = $imgNode->parentNode; $anc && $anc !== $p; $anc = $anc->parentNode) {
                if ($anc->localName === 'Fallback') {
                    $inFallback = true;
                    break;
                }
            }
            if ($inFallback) {
                continue;
            }

            $rid = (string) ($imgNode->getAttributeNS(self::NS_R, 'embed') ?: $imgNode->getAttributeNS(self::NS_R, 'id'));
            if ($rid === '' || !isset($media[$rid])) {
                continue;
            }

            [$widthPx, $heightPx] = self::resolveDrawingSizePx($imgNode, $p);
            $blocks[] = [
                'type' => 'image',
                'data' => $media[$rid]['data'],
                'filename' => $media[$rid]['filename'],
                'mime' => $media[$rid]['mime'],
                'ext' => $media[$rid]['ext'],
                'caption' => '',
                'alt' => '',
                'width' => $widthPx,
                'height' => $heightPx,
            ];
        }

        return $blocks;
    }

    /**
     * Read the on-page rendered size of a drawing (EMU -> CSS pixels) when available.
     */
    private static function resolveDrawingSizePx(DOMElement $imgNode, DOMElement $limit): array
    {
        $container = null;
        for ($anc = $imgNode->parentNode; $anc && $anc !== $limit; $anc = $anc->parentNode) {
            if ($anc->localName === 'inline' || $anc->localName === 'anchor') {
                $container = $anc;
                break;
            }
        }
        if ($container instanceof DOMElement) {
            foreach ($container->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'extent') {
                    $cx = (int) $child->getAttribute('cx');
                    $cy = (int) $child->getAttribute('cy');
                    if ($cx > 0 && $cy > 0) {
                        return [max(1, (int) round($cx / 9525)), max(1, (int) round($cy / 9525))];
                    }
                }
            }
        }
        return [0, 0];
    }

    private static function imageMimeFromExt(string $ext): string
    {
        $map = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'webp' => 'image/webp',
            'emf' => 'image/x-emf',
            'wmf' => 'image/x-wmf',
            'svg' => 'image/svg+xml',
        ];
        return $map[$ext] ?? 'image/png';
    }

    private static function normalizeZipPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return implode('/', $parts);
    }

    /**
     * Extract text from an element (ignoring fallback VML duplicates)
     */
    public static function getElementText(DOMElement $el, DOMXPath $xpath): string
    {
        $texts = [];
        foreach ($xpath->query('.//w:t', $el) as $tNode) {
            // Skip if inside mc:Fallback to prevent duplicate text
            $parent = $tNode->parentNode;
            $inFallback = false;
            while ($parent && $parent !== $el) {
                if ($parent->localName === 'Fallback') {
                    $inFallback = true;
                    break;
                }
                $parent = $parent->parentNode;
            }
            if (!$inFallback) {
                $texts[] = $tNode->nodeValue;
            }
        }
        return implode('', $texts);
    }

    /**
     * Resolve a ZIP entry name supporting POSIX '/' and Windows '\' path separators.
     */
    public static function resolveZipEntryName(ZipArchive $zip, string $canonicalPath): string
    {
        $canonicalPath = str_replace('\\', '/', $canonicalPath);
        if ($zip->locateName($canonicalPath) !== false) {
            return $canonicalPath;
        }
        $backslashPath = str_replace('/', '\\', $canonicalPath);
        if ($zip->locateName($backslashPath) !== false) {
            return $backslashPath;
        }
        $targetLower = strtolower($canonicalPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (strtolower(str_replace('\\', '/', $name)) === $targetLower) {
                return $name;
            }
        }
        return $canonicalPath;
    }
}
