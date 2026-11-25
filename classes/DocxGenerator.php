<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/DocxGenerator.php
 *
 * Generates a ready-to-publish .docx file strictly adhering to the
 * Jurnal Kisi Berkelanjutan 2026 Re-typeset Master Template.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

require_once __DIR__ . '/DocxParser.php';
require_once __DIR__ . '/HealthTableFormatter.php';
require_once __DIR__ . '/LanguageAuditor.php';
require_once __DIR__ . '/VancouverFormatter.php';

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

class DocxGenerator
{
    public const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Generate a formatted .docx file from structured article data
     */
    public static function generate(array $data, string $masterTemplatePath, string $outputPath): bool
    {
        if (!file_exists($masterTemplatePath)) {
            return false;
        }

        $data = LanguageAuditor::normalizeArticleParagraphSpaces($data);

        if (!copy($masterTemplatePath, $outputPath)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            return false;
        }

        $docEntry = DocxParser::resolveZipEntryName($zip, 'word/document.xml');
        $docXml = $zip->getFromName($docEntry);
        if (!$docXml) {
            $zip->close();
            return false;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        @$dom->loadXML($docXml, LIBXML_NOENT | LIBXML_NONET);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);
        $xpath->registerNamespace('r', self::NS_R);

        // 1. Update Front-Matter Table (w:body/w:tbl[1])
        $frontTable = $xpath->query('//w:body/w:tbl[1]')->item(0);
        if ($frontTable instanceof DOMElement) {
            self::populateFrontMatterTable($dom, $xpath, $frontTable, $data);
        }

        // 2. Update Main Body Sections (between Section 1 break and Publisher's Note)
        $body = $xpath->query('//w:body')->item(0);
        if ($body instanceof DOMElement) {
            self::populateMainBody($dom, $xpath, $body, $data);
        }

        $updatedDocXml = $dom->saveXML();
        $zip->addFromString($docEntry, $updatedDocXml);

        // 3. Update Footers (footer1.xml, footer2.xml and their .rels)
        $articleUrl = trim($data['article_url'] ?? 'https://kisiberkelanjutan.com/index.php/smk/article/view/76');
        $footerIssueLine = trim($data['footer_issue_line'] ?? 'Volume 1 Nomor 1, Januari-Maret 2024');
        $shortJournalTitle = trim($data['journal_short_footer'] ?? 'Kisi Berkelanjutan: SMK');

        self::updateFooterXml($zip, 'word/footer1.xml', 'word/_rels/footer1.xml.rels', $shortJournalTitle, $articleUrl, $footerIssueLine);
        self::updateFooterXml($zip, 'word/footer2.xml', 'word/_rels/footer2.xml.rels', $shortJournalTitle, $articleUrl, $footerIssueLine);

        $zip->close();
        return true;
    }

    /**
     * Populate the 2-column Front-Matter Table (Sidebar metadata, Titles, Authors, Bilingual Structured Abstracts)
     */
    private static function populateFrontMatterTable(DOMDocument $dom, DOMXPath $xpath, DOMElement $tbl, array $data): void
    {
        $rows = $xpath->query('./w:tr', $tbl);
        if ($rows->length < 9) {
            return;
        }

        // --- A. Left Sidebar (Row 0, Cell 0) ---
        $sidebarCell = $xpath->query('./w:tc[1]', $rows->item(0))->item(0);
        if ($sidebarCell instanceof DOMElement) {
            $pNodes = [];
            foreach ($xpath->query('./w:p', $sidebarCell) as $p) {
                $pNodes[] = $p;
            }
            // Keep P0..P13 (which hold the SDGs Collections logo, Open Access icon, and top spacing)
            for ($i = 14; $i < count($pNodes); $i++) {
                $sidebarCell->removeChild($pNodes[$i]);
            }

            $isVol3Plus = LanguageAuditor::isVolume3Plus($data['volume'] ?? '1');
            $citation = trim($data['citation'] ?? '');
            $doi = trim($data['doi'] ?? '');
            $reviewEditor = trim($data['review_editor'] ?? '');
            $receivedDate = trim($data['received_date'] ?? '');
            $acceptedDate = trim($data['accepted_date'] ?? '');
            $publishedDate = trim($data['published_date'] ?? '');
            $copyrightYear = trim($data['copyright_year'] ?? date('Y'));
            $firstAuthorLast = self::extractFirstAuthorLastName($data['authors'] ?? []);
            $funding = trim($data['funding'] ?? ($isVol3Plus ? 'None' : 'none'));
            if ($isVol3Plus && strcasecmp($funding, 'none') === 0) {
                $funding = 'None';
            }
            $competing = trim($data['competing_interests'] ?? 'The authors have declared that no competing interests exist.');
            $contributorRoles = trim($data['contributor_roles'] ?? '');
            if ($isVol3Plus && $contributorRoles === '') {
                $contributorRoles = LanguageAuditor::buildDefaultContributorRoles($data['authors'] ?? []);
            }

            // Citation
            $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Citation:', ' ' . $citation, true));
            $sidebarCell->appendChild(self::createSidebarEmptyPara($dom));

            // DOI
            $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Digital Object Identifier:', ' ' . $doi, false));
            $sidebarCell->appendChild(self::createSidebarEmptyPara($dom));

            // Review Editor (Vol 3+ puts editor inline after label; Vol 1-2 archive preserves next-line layout)
            if ($isVol3Plus) {
                $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Review Editor:', ' ' . $reviewEditor, false));
            } else {
                $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Review Editor: ', '', false));
                $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, '', $reviewEditor, false));
            }
            $sidebarCell->appendChild(self::createSidebarEmptyPara($dom));

            // Received / Accepted / Published dates
            if ($isVol3Plus) {
                if ($receivedDate !== '') {
                    $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Received:', ' ' . $receivedDate, false));
                }
                if ($acceptedDate !== '') {
                    $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Accepted:', ' ' . $acceptedDate, true));
                }
            }
            $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Published:', ' ' . $publishedDate, $isVol3Plus));
            $sidebarCell->appendChild(self::createSidebarEmptyPara($dom));

            // Copyright with CC BY-SA 4.0 hyperlink (rId12)
            $sidebarCell->appendChild(self::createSidebarCopyrightPara($dom, $copyrightYear, $firstAuthorLast));
            $sidebarCell->appendChild(self::createSidebarEmptyPara($dom));

            // Funding
            $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Funding:', ' ' . $funding, $isVol3Plus));
            $sidebarCell->appendChild(self::createSidebarEmptyPara($dom));

            // Competing interests
            $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, 'Competing interests:', ' ' . $competing, $isVol3Plus));

            // Contributor's Roles (Vol 3+)
            if ($isVol3Plus && $contributorRoles !== '') {
                $sidebarCell->appendChild(self::createSidebarEmptyPara($dom));
                $sidebarCell->appendChild(self::createSidebarLabelValuePara($dom, "Contributor\u{2019}s Roles:", ' ' . $contributorRoles, true));
            }
        }

        // --- B. Row 0 Cell 1: Article Type ---
        $articleType = mb_strtoupper(trim($data['article_type'] ?? 'ORIGINAL ARTICLE'), 'UTF-8');
        $typeCell = $xpath->query('./w:tc[2]', $rows->item(0))->item(0);
        if ($typeCell instanceof DOMElement) {
            self::clearCellParagraphs($typeCell, $xpath);
            $typeCell->appendChild(self::createStyledPara($dom, $articleType, ['sz' => '22']));
            $typeCell->appendChild(self::createStyledPara($dom, '', ['sz' => '22']));
        }

        // --- C. Row 1 Cell 1: Titles (ID & EN) ---
        $titleCell = $xpath->query('./w:tc[2]', $rows->item(1))->item(0);
        if ($titleCell instanceof DOMElement) {
            self::clearCellParagraphs($titleCell, $xpath);
            $titleId = trim($data['title_id'] ?? '');
            $titleEn = trim($data['title_en'] ?? '');
            $titleCell->appendChild(self::createStyledPara($dom, $titleId, ['sz' => '28', 'bold' => true]));
            $titleCell->appendChild(self::createStyledPara($dom, '', ['sz' => '28', 'bold' => true]));
            if ($titleEn !== '') {
                $titleCell->appendChild(self::createStyledPara($dom, $titleEn, ['sz' => '28', 'italic' => true]));
                $titleCell->appendChild(self::createStyledPara($dom, '', ['sz' => '28', 'italic' => true]));
            }
        }

        // --- D. Row 3 Cell 1: Authors & Deduplicated Affiliations ---
        $authorCell = $xpath->query('./w:tc[2]', $rows->item(3))->item(0);
        if ($authorCell instanceof DOMElement) {
            self::clearCellParagraphs($authorCell, $xpath);
            $authors = $data['authors'] ?? [];
            if (empty($authors)) {
                $authors = [
                    [
                        'name' => 'Penulis',
                        'affiliation' => 'Afiliasi',
                        'email' => '',
                        'is_corresponding' => true,
                    ],
                ];
            }

            // Deduplicate affiliations
            $uniqueAffs = [];
            $authorAffIndices = [];
            foreach ($authors as $idx => $a) {
                $aff = trim($a['affiliation'] ?? '');
                if ($aff === '') {
                    $aff = 'Indonesia';
                }
                $foundIndex = array_search($aff, $uniqueAffs, true);
                if ($foundIndex === false) {
                    $uniqueAffs[] = $aff;
                    $foundIndex = count($uniqueAffs) - 1;
                }
                $authorAffIndices[$idx] = $foundIndex + 1; // 1-based
            }

            // Create author line with superscript numbers
            $pAuthors = $dom->createElementNS(self::NS_W, 'w:p');
            $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
            $spacing = $dom->createElementNS(self::NS_W, 'w:spacing');
            $spacing->setAttribute('w:after', '0');
            $spacing->setAttribute('w:line', '240');
            $spacing->setAttribute('w:lineRule', 'auto');
            $pPr->appendChild($spacing);
            $pAuthors->appendChild($pPr);

            $totalAuthors = count($authors);
            foreach ($authors as $idx => $a) {
                $name = trim($a['name'] ?? '');
                $affNum = (string) ($authorAffIndices[$idx] ?? 1);
                $isCorr = !empty($a['is_corresponding']);

                // Author Name run
                $pAuthors->appendChild(self::createRun($dom, $name, ['sz' => '22']));
                // Superscript affiliation index run
                $pAuthors->appendChild(self::createRun($dom, $affNum, ['sz' => '22', 'superscript' => true]));
                // Asterisk and comma separator
                $suffix = $isCorr ? '*' : '';
                if ($idx < $totalAuthors - 1) {
                    $suffix .= ', ';
                }
                if ($suffix !== '') {
                    $pAuthors->appendChild(self::createRun($dom, $suffix, ['sz' => '22']));
                }
            }

            $authorCell->appendChild($pAuthors);
            $authorCell->appendChild(self::createStyledPara($dom, '', ['sz' => '22']));

            // Append numbered affiliation lines (using w:numId="1" from master template)
            foreach ($uniqueAffs as $affText) {
                $pAff = $dom->createElementNS(self::NS_W, 'w:p');
                $pPrAff = $dom->createElementNS(self::NS_W, 'w:pPr');
                $numPr = $dom->createElementNS(self::NS_W, 'w:numPr');
                $ilvl = $dom->createElementNS(self::NS_W, 'w:ilvl');
                $ilvl->setAttribute('w:val', '0');
                $numId = $dom->createElementNS(self::NS_W, 'w:numId');
                $numId->setAttribute('w:val', '1');
                $numPr->appendChild($ilvl);
                $numPr->appendChild($numId);
                $pPrAff->appendChild($numPr);

                $spAff = $dom->createElementNS(self::NS_W, 'w:spacing');
                $spAff->setAttribute('w:after', '0');
                $spAff->setAttribute('w:line', '240');
                $spAff->setAttribute('w:lineRule', 'auto');
                $pPrAff->appendChild($spAff);

                $pAff->appendChild($pPrAff);
                $pAff->appendChild(self::createRun($dom, $affText, ['sz' => '22']));
                $authorCell->appendChild($pAff);
            }
        }

        // --- E. Row 4 Cell 1: Corresponding Author ---
        $corrCell = $xpath->query('./w:tc[2]', $rows->item(4))->item(0);
        if ($corrCell instanceof DOMElement) {
            self::clearCellParagraphs($corrCell, $xpath);
            $corrName = trim($data['corresponding_name'] ?? '');
            $corrEmail = trim($data['corresponding_email'] ?? '');
            if ($corrName === '' && !empty($data['authors'])) {
                foreach ($data['authors'] as $a) {
                    if (!empty($a['is_corresponding'])) {
                        $corrName = trim($a['name'] ?? '');
                        $corrEmail = trim($a['email'] ?? '');
                        break;
                    }
                }
                if ($corrName === '') {
                    $corrName = trim($data['authors'][0]['name'] ?? '');
                    $corrEmail = trim($data['authors'][0]['email'] ?? '');
                }
            }
            $corrLine = '* Penulis koresponden: ' . $corrName . ($corrEmail !== '' ? ' (' . $corrEmail . ')' : '');
            $corrCell->appendChild(self::createStyledPara($dom, '', ['sz' => '22', 'italic' => true]));
            $corrCell->appendChild(self::createStyledPara($dom, $corrLine, ['sz' => '22', 'italic' => true]));
        }

        // For DATA REPORT & LETTER: remove ABSTRAK/ABSTRACT rows (rows 5..8) so front-matter ends after Corresponding Author
        if (LanguageAuditor::isNoAbstractArticleType($articleType)) {
            for ($ri = $rows->length - 1; $ri >= 5; $ri--) {
                $rowNode = $rows->item($ri);
                if ($rowNode instanceof DOMElement && $rowNode->parentNode === $tbl) {
                    $tbl->removeChild($rowNode);
                }
            }
            return;
        }

        // --- F. Row 7 Cell 1: Indonesian Structured Abstract ---
        $isVol3Plus = LanguageAuditor::isVolume3Plus($data['volume'] ?? '1');
        $sdgsId = trim((string) ($data['sdgs_id'] ?? ''));
        $sdgsEn = trim((string) ($data['sdgs_en'] ?? ''));

        $abEnRow = $rows->item(8);
        $abIdCell = $xpath->query('./w:tc[2]', $rows->item(7))->item(0);
        if ($abIdCell instanceof DOMElement) {
            self::clearCellParagraphs($abIdCell, $xpath);

            $pmId = trim($data['abstract_id']['pendahuluan_metode'] ?? '');
            $hasilId = trim($data['abstract_id']['hasil'] ?? '');
            $kesId = trim($data['abstract_id']['kesimpulan_saran'] ?? '');
            $kwId = rtrim(trim($data['keywords_id'] ?? ''), '.');

            $abIdCell->appendChild(self::createStyledPara($dom, 'Pendahuluan dan Metode', ['bold' => true, 'jc' => 'both']));
            $abIdCell->appendChild(self::createStyledPara($dom, $pmId, ['jc' => 'both']));
            $abIdCell->appendChild(self::createStyledPara($dom, '', ['jc' => 'both']));

            if ($hasilId !== '') {
                $abIdCell->appendChild(self::createStyledPara($dom, 'Hasil', ['bold' => true, 'jc' => 'both']));
                $abIdCell->appendChild(self::createStyledPara($dom, $hasilId, ['jc' => 'both']));
                $abIdCell->appendChild(self::createStyledPara($dom, '', ['jc' => 'both']));
            }

            if ($kesId !== '') {
                $abIdCell->appendChild(self::createStyledPara($dom, 'Kesimpulan dan Saran', ['bold' => true, 'jc' => 'both']));
                $abIdCell->appendChild(self::createStyledPara($dom, $kesId, ['jc' => 'both']));
                $abIdCell->appendChild(self::createStyledPara($dom, '', ['jc' => 'both']));
            }

            $pKwId = self::createStyledPara($dom, '', ['jc' => 'both']);
            $pKwId->appendChild(self::createRun($dom, 'Kata Kunci', ['bold' => true]));
            $pKwId->appendChild(self::createRun($dom, ': ' . $kwId . '.', []));
            $abIdCell->appendChild($pKwId);
            $abIdCell->appendChild(self::createStyledPara($dom, '', ['jc' => 'both']));
        }

        // Vol 3+: Insert full-width shaded (#E8E8E8) SDGs Indonesian block between ABSTRAK and ABSTRACT rows
        if ($isVol3Plus && $sdgsId !== '' && $abEnRow instanceof DOMElement) {
            $sdgsRow = $abEnRow->cloneNode(true);
            if ($sdgsRow instanceof DOMElement) {
                $sdgsCell = $xpath->query('./w:tc[1]', $sdgsRow)->item(0);
                if ($sdgsCell instanceof DOMElement) {
                    self::clearCellParagraphs($sdgsCell, $xpath);
                    $tcPr = $xpath->query('./w:tcPr', $sdgsCell)->item(0);
                    if ($tcPr instanceof DOMElement) {
                        $oldShd = $xpath->query('./w:shd', $tcPr)->item(0);
                        if ($oldShd) {
                            $tcPr->removeChild($oldShd);
                        }
                        $shd = $dom->createElementNS(self::NS_W, 'w:shd');
                        $shd->setAttribute('w:val', 'clear');
                        $shd->setAttribute('w:color', 'auto');
                        $shd->setAttribute('w:fill', 'E8E8E8');
                        $tcPr->appendChild($shd);
                    }
                    $sdgsCell->appendChild(self::createStyledPara(
                        $dom,
                        'Kontribusi terhadap Target Pembangunan Berkelanjutan (TPB/SDGs)',
                        ['bold' => true]
                    ));
                    $sdgsCell->appendChild(self::createStyledPara($dom, $sdgsId, ['jc' => 'both']));
                    $sdgsCell->appendChild(self::createStyledPara($dom, '', ['jc' => 'both']));
                }
                $tbl->insertBefore($sdgsRow, $abEnRow);
            }
        }

        // --- G. Row 8 Cell 0: English Structured Abstract ---
        $abEnCell = ($abEnRow instanceof DOMElement) ? $xpath->query('./w:tc[1]', $abEnRow)->item(0) : null;
        if ($abEnCell instanceof DOMElement) {
            self::clearCellParagraphs($abEnCell, $xpath);

            $imEn = trim($data['abstract_en']['intro_methods'] ?? '');
            $resEn = trim($data['abstract_en']['results'] ?? '');
            $concEn = trim($data['abstract_en']['conclusions_recommendations'] ?? '');
            $kwEn = rtrim(trim($data['keywords_en'] ?? ''), '.');

            $abEnCell->appendChild(self::createStyledPara($dom, 'ABSTRACT', ['bold' => true, 'italic' => true, 'jc' => 'both']));
            $abEnCell->appendChild(self::createStyledPara($dom, '', ['italic' => true, 'jc' => 'both']));

            $abEnCell->appendChild(self::createStyledPara($dom, 'Introduction and Methods', ['bold' => true, 'italic' => true, 'jc' => 'both']));
            $abEnCell->appendChild(self::createStyledPara($dom, $imEn, ['italic' => true, 'jc' => 'both']));
            $abEnCell->appendChild(self::createStyledPara($dom, '', ['italic' => true, 'jc' => 'both']));

            if ($resEn !== '') {
                $abEnCell->appendChild(self::createStyledPara($dom, 'Results', ['bold' => true, 'italic' => true, 'jc' => 'both']));
                $abEnCell->appendChild(self::createStyledPara($dom, $resEn, ['italic' => true, 'jc' => 'both']));
                $abEnCell->appendChild(self::createStyledPara($dom, '', ['italic' => true, 'jc' => 'both']));
            }

            if ($concEn !== '') {
                $abEnCell->appendChild(self::createStyledPara($dom, 'Conclusions and Recommendations', ['bold' => true, 'italic' => true, 'jc' => 'both']));
                $abEnCell->appendChild(self::createStyledPara($dom, $concEn, ['italic' => true, 'jc' => 'both']));
                $abEnCell->appendChild(self::createStyledPara($dom, '', ['italic' => true, 'jc' => 'both']));
            }

            $pKwEn = self::createStyledPara($dom, '', ['italic' => true, 'jc' => 'both']);
            $pKwEn->appendChild(self::createRun($dom, 'Keywords', ['bold' => true, 'italic' => true]));
            $pKwEn->appendChild(self::createRun($dom, ': ' . $kwEn . '.', ['italic' => true]));
            $abEnCell->appendChild($pKwEn);
            $abEnCell->appendChild(self::createStyledPara($dom, '', ['italic' => true, 'jc' => 'both']));

            if ($isVol3Plus && $sdgsEn !== '') {
                $abEnCell->appendChild(self::createStyledPara(
                    $dom,
                    'Contribution to Sustainable Development Goals (SDGs)',
                    ['bold' => true, 'italic' => true, 'jc' => 'both']
                ));
                $abEnCell->appendChild(self::createStyledPara($dom, $sdgsEn, ['italic' => true, 'jc' => 'both']));
                $abEnCell->appendChild(self::createStyledPara($dom, '', ['italic' => true, 'jc' => 'both']));
            }
        }
    }

    /**
     * Populate Section 2 (Main Article Body: PENDAHULUAN through SUMBER PUSTAKA + Publisher's Note)
     */
    private static function populateMainBody(DOMDocument $dom, DOMXPath $xpath, DOMElement $body, array $data): void
    {
        $children = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        // Find Section 1 break paragraph (child index 1, right after front-matter table)
        $afterFrontIndex = 1;
        for ($i = 1; $i < count($children); $i++) {
            if ($xpath->query('.//w:sectPr', $children[$i])->length > 0 && $children[$i]->localName === 'p') {
                $afterFrontIndex = $i;
                break;
            }
        }

        // Find Publisher's Note paragraph node near the end of w:body
        // (tolerates curly quote, straight quote, or legacy template mojibake)
        $publisherNoteNode = null;
        $publisherStmtNode = null;
        for ($i = $afterFrontIndex + 1; $i < count($children); $i++) {
            $node = $children[$i];
            if ($node->localName === 'p') {
                $txt = trim(DocxParser::getElementText($node, $xpath));
                if (!$publisherNoteNode && preg_match('/^Publisher\S{0,10}\s+Note$/iu', $txt)) {
                    $publisherNoteNode = $node;
                    continue;
                }
                if ($publisherNoteNode && !$publisherStmtNode && stripos($txt, 'remains neutral') !== false) {
                    $publisherStmtNode = $node;
                    break;
                }
            }
        }

        // Normalize Publisher's Note title & declaration cleanly (eliminates template mojibake/spacing issues)
        if ($publisherNoteNode instanceof DOMElement) {
            self::clearElementChildren($publisherNoteNode);
            $publisherNoteNode->appendChild(self::createRun($dom, "Publisher\u{2019}s Note", ['italic' => true]));
        }
        if ($publisherStmtNode instanceof DOMElement) {
            $publisherName = trim((string) ($data['publisher_name'] ?? 'PT Karya Inovasi Berkelanjutan'));
            if ($publisherName === '') {
                $publisherName = 'PT Karya Inovasi Berkelanjutan';
            }
            self::clearElementChildren($publisherStmtNode);
            $publisherStmtNode->appendChild(self::createRun(
                $dom,
                'The publisher of ' . $publisherName . ' states that it remains neutral with respect to the published ideas and from any institutional affiliation.',
                ['italic' => true]
            ));
        }

        // Fallback if Publisher's Note wasn't found: use final w:sectPr
        if (!$publisherNoteNode) {
            $publisherNoteNode = $xpath->query('./w:sectPr', $body)->item(0);
        }

        // Remove all existing body nodes between Section 1 break and Publisher's Note
        $toRemove = [];
        $collecting = false;
        foreach ($children as $idx => $node) {
            if ($idx === $afterFrontIndex) {
                $collecting = true;
                continue;
            }
            if ($node === $publisherNoteNode) {
                break;
            }
            if ($collecting) {
                $toRemove[] = $node;
            }
        }
        foreach ($toRemove as $rmNode) {
            $body->removeChild($rmNode);
        }

        // Starting Volume 3+ (or when retypeset note is explicitly cleared): omit the 2026 re-typeset textbox cleanly;
        // Vol 1-2 archive articles retain the 2026 re-typeset textbox unless explicitly cleared.
        $isVol3Plus = LanguageAuditor::isVolume3Plus($data['volume'] ?? '1');
        $explicitBlankRetypeset = array_key_exists('retypeset_note', $data) && trim((string) $data['retypeset_note']) === '';
        $retypesetNote = trim((string) ($data['retypeset_note'] ?? ''));
        if ($isVol3Plus || $explicitBlankRetypeset) {
            $tbContainers = [];
            foreach ($xpath->query('./w:p[.//w:txbxContent]', $body) as $tbParaNode) {
                $tbContainers[] = $tbParaNode;
            }
            foreach ($tbContainers as $rmTb) {
                $body->removeChild($rmTb);
            }
        } elseif ($retypesetNote !== '') {
            $retypesetText = rtrim($retypesetNote, '.');
            foreach ($xpath->query('.//w:txbxContent/w:p', $body) as $tbPara) {
                self::clearElementChildren($tbPara);
                $r1 = $dom->createElementNS(self::NS_W, 'w:r');
                $r1->appendChild(self::createTextElement($dom, $retypesetText . '.', false));
                $tbPara->appendChild($r1);
            }
        }

        // Insert Main Body Sections before $publisherNoteNode
        $tableCounter = 1;
        $emitBlocks = static function (array $blocks) use ($dom, $publisherNoteNode, $body, &$tableCounter): void {
            foreach ($blocks as $block) {
                if (is_string($block)) {
                    $block = ['type' => 'paragraph', 'text' => $block];
                }
                $bType = $block['type'] ?? 'paragraph';

                if ($bType === 'subheading' || $bType === 'subheading_l3') {
                    $subText = trim($block['text'] ?? '');
                    if ($subText !== '') {
                        $isL3 = ($bType === 'subheading_l3');
                        $body->insertBefore(self::createBodySubheadingPara($dom, $subText, $isL3), $publisherNoteNode);
                    }
                } elseif ($bType === 'table') {
                    $formattedBlock = HealthTableFormatter::formatTableBlock($block, [
                        'table_num' => $tableCounter++,
                        'suggest_footnote' => false,
                    ]);
                    $caption = trim($formattedBlock['caption'] ?? '');
                    if ($caption !== '') {
                        $body->insertBefore(self::createTableCaptionPara($dom, $caption), $publisherNoteNode);
                    }
                    $rows = $formattedBlock['rows'] ?? [];
                    if (!empty($rows)) {
                        $tblNode = self::createDataTableElement($dom, $formattedBlock);
                        $body->insertBefore($tblNode, $publisherNoteNode);
                    }
                    $footnote = trim($formattedBlock['footnote'] ?? '');
                    if ($footnote !== '') {
                        $body->insertBefore(self::createTableFootnotePara($dom, $footnote), $publisherNoteNode);
                    }
                } else {
                    $pText = trim($block['text'] ?? '');
                    if ($pText !== '') {
                        $body->insertBefore(self::createBodyParagraph($dom, $pText), $publisherNoteNode);
                    }
                }
            }
        };

        $badanBlocks = $data['sections']['badan_artikel'] ?? [];
        if (!empty($badanBlocks)) {
            $emitBlocks($badanBlocks);
        }

        $noAbstract = LanguageAuditor::isNoAbstractArticleType((string) ($data['article_type'] ?? 'ORIGINAL ARTICLE'));
        $sectionMap = [
            'pendahuluan' => 'PENDAHULUAN',
            'metode' => 'METODE',
            'hasil' => 'HASIL',
            'pembahasan' => 'PEMBAHASAN',
            'kesimpulan_saran' => 'KESIMPULAN DAN SARAN',
        ];
        $onlyIntroPopulated = empty($badanBlocks)
            && !empty($data['sections']['pendahuluan'])
            && empty($data['sections']['metode'])
            && empty($data['sections']['hasil'])
            && empty($data['sections']['pembahasan'])
            && empty($data['sections']['kesimpulan_saran']);

        foreach ($sectionMap as $secKey => $secTitle) {
            $blocks = $data['sections'][$secKey] ?? [];
            if (empty($blocks)) {
                continue;
            }
            if (!($noAbstract && $onlyIntroPopulated && $secKey === 'pendahuluan')) {
                $body->insertBefore(self::createHeading1Para($dom, $secTitle), $publisherNoteNode);
            }
            $emitBlocks($blocks);
        }

        // Insert SUMBER PUSTAKA in synchronized Vancouver numbered style (1. ..., 2. ..., 3. ...)
        $refs = $data['referensi'] ?? [];
        if (!empty($refs)) {
            $cleanRefs = [];
            foreach ($refs as $rItem) {
                $rTrim = trim((string) $rItem);
                if ($rTrim !== '') {
                    $cleanRefs[] = $rTrim;
                }
            }
            if (!empty($cleanRefs)) {
                $refStyle = VancouverFormatter::resolveReferenceStyle((string) ($data['reference_style'] ?? 'vancouver'));
                $body->insertBefore(self::createHeading1Para($dom, 'SUMBER PUSTAKA'), $publisherNoteNode);
                foreach ($cleanRefs as $idx => $refText) {
                    $vanLine = VancouverFormatter::formatReferenceForStyle($refText, $refStyle, $idx + 1);
                    if ($vanLine === '') {
                        continue;
                    }
                    $body->insertBefore(self::createReferencePara($dom, $vanLine), $publisherNoteNode);
                }
            }
        }

        // Add spacing paragraph before Publisher's Note
        $body->insertBefore(self::createStyledPara($dom, '', []), $publisherNoteNode);
    }

    /**
     * Create a Heading1 paragraph (PENDAHULUAN, METODE, HASIL, etc.)
     */
    private static function createHeading1Para(DOMDocument $dom, string $title): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
        $pStyle = $dom->createElementNS(self::NS_W, 'w:pStyle');
        $pStyle->setAttribute('w:val', 'Heading1');
        $pPr->appendChild($pStyle);
        $p->appendChild($pPr);

        $r = $dom->createElementNS(self::NS_W, 'w:r');
        $r->appendChild(self::createTextElement($dom, $title, false));
        $p->appendChild($r);
        return $p;
    }

    /**
     * Create standard first-line-indented justified body paragraph
     */
    private static function createBodyParagraph(DOMDocument $dom, string $text): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');

        $spacing = $dom->createElementNS(self::NS_W, 'w:spacing');
        $spacing->setAttribute('w:before', '120');
        $spacing->setAttribute('w:after', '120');
        $pPr->appendChild($spacing);

        $ind = $dom->createElementNS(self::NS_W, 'w:ind');
        $ind->setAttribute('w:firstLine', '720');
        $pPr->appendChild($ind);

        $jc = $dom->createElementNS(self::NS_W, 'w:jc');
        $jc->setAttribute('w:val', 'both');
        $pPr->appendChild($jc);

        $p->appendChild($pPr);

        $r = $dom->createElementNS(self::NS_W, 'w:r');
        $r->appendChild(self::createTextElement($dom, $text, true));
        $p->appendChild($r);
        return $p;
    }

    /**
     * Create bold subheading paragraph inside PEMBAHASAN / METODE (supports Level 2 & Level 3)
     */
    private static function createBodySubheadingPara(DOMDocument $dom, string $text, bool $isLevel3 = false): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');

        $spacing = $dom->createElementNS(self::NS_W, 'w:spacing');
        $spacing->setAttribute('w:before', $isLevel3 ? '100' : '140');
        $spacing->setAttribute('w:after', '80');
        $pPr->appendChild($spacing);

        $pPr->appendChild($dom->createElementNS(self::NS_W, 'w:keepNext'));

        $jc = $dom->createElementNS(self::NS_W, 'w:jc');
        $jc->setAttribute('w:val', 'both');
        $pPr->appendChild($jc);

        $p->appendChild($pPr);
        $p->appendChild(self::createRun($dom, $text, [
            'bold' => true,
            'italic' => $isLevel3,
            'noFont' => true,
        ]));
        return $p;
    }

    /**
     * Create Table Caption paragraph (e.g., "Tabel 1. Karakteristik Responden", Bold label + Title above table)
     */
    private static function createTableCaptionPara(DOMDocument $dom, string $caption): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
        $spacing = $dom->createElementNS(self::NS_W, 'w:spacing');
        $spacing->setAttribute('w:before', '140');
        $spacing->setAttribute('w:after', '60');
        $pPr->appendChild($spacing);
        $pPr->appendChild($dom->createElementNS(self::NS_W, 'w:keepNext'));
        $p->appendChild($pPr);

        if (preg_match('/^((?:Tabel|Table)\s+\d+[\.\:]?)\s*(.*)$/iu', $caption, $m)) {
            $p->appendChild(self::createRun($dom, rtrim($m[1], '.:') . '. ', ['bold' => true, 'sz' => '20', 'noFont' => true]));
            if (trim($m[2]) !== '') {
                $p->appendChild(self::createRun($dom, rtrim(trim($m[2]), '.'), ['bold' => false, 'sz' => '20', 'noFont' => true]));
            }
        } else {
            $p->appendChild(self::createRun($dom, $caption, ['bold' => true, 'sz' => '20', 'noFont' => true]));
        }
        return $p;
    }

    /**
     * Create Table Footnote paragraph directly below the bottom border (AMA / ICMJE Style)
     */
    private static function createTableFootnotePara(DOMDocument $dom, string $footnote): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
        $spacing = $dom->createElementNS(self::NS_W, 'w:spacing');
        $spacing->setAttribute('w:before', '60');
        $spacing->setAttribute('w:after', '140');
        $spacing->setAttribute('w:line', '220');
        $spacing->setAttribute('w:lineRule', 'auto');
        $pPr->appendChild($spacing);

        $jc = $dom->createElementNS(self::NS_W, 'w:jc');
        $jc->setAttribute('w:val', 'left');
        $pPr->appendChild($jc);
        $p->appendChild($pPr);

        foreach (HealthTableFormatter::splitStatisticalInlineRuns($footnote) as $seg) {
            $p->appendChild(self::createRun($dom, $seg['text'], [
                'italic' => $seg['italic'],
                'superscript' => $seg['sup'],
                'sz' => '17',
                'color' => '334155',
                'noFont' => true,
            ]));
        }
        return $p;
    }

    /**
     * Create Health-Science Three-Line Open Table (ICMJE / AMA / CONSORT / STROBE standard):
     *  - Top & bottom horizontal rules (1.5pt / sz=12)
     *  - Header bottom horizontal rule (1pt / sz=8) + straddle hairline for spanned multi-row headers
     *  - Zero vertical borders and zero interior body row gridlines
     *  - Left-aligned stub column with subcategory indentation; tabular center/right alignment on numeric columns
     *  - Subtle top rule on Total/Summary row
     */
    private static function createDataTableElement(DOMDocument $dom, array $tableBlock): DOMElement
    {
        $rows = $tableBlock['rows'] ?? [];
        $tbl = $dom->createElementNS(self::NS_W, 'w:tbl');

        $tblPr = $dom->createElementNS(self::NS_W, 'w:tblPr');
        $tblW = $dom->createElementNS(self::NS_W, 'w:tblW');
        $tblW->setAttribute('w:w', '5000');
        $tblW->setAttribute('w:type', 'pct');
        $tblPr->appendChild($tblW);

        $jc = $dom->createElementNS(self::NS_W, 'w:jc');
        $jc->setAttribute('w:val', 'center');
        $tblPr->appendChild($jc);

        // True three-line open borders (top & bottom 1.5pt, no left/right/insideH/insideV)
        $borders = $dom->createElementNS(self::NS_W, 'w:tblBorders');
        foreach (['top', 'bottom'] as $bSide) {
            $b = $dom->createElementNS(self::NS_W, 'w:' . $bSide);
            $b->setAttribute('w:val', 'single');
            $b->setAttribute('w:sz', '12');
            $b->setAttribute('w:space', '0');
            $b->setAttribute('w:color', '0A1D30');
            $borders->appendChild($b);
        }
        foreach (['left', 'right', 'insideH', 'insideV'] as $noSide) {
            $b = $dom->createElementNS(self::NS_W, 'w:' . $noSide);
            $b->setAttribute('w:val', 'none');
            $b->setAttribute('w:sz', '0');
            $b->setAttribute('w:space', '0');
            $b->setAttribute('w:color', 'auto');
            $borders->appendChild($b);
        }
        $tblPr->appendChild($borders);

        $cellMar = $dom->createElementNS(self::NS_W, 'w:tblCellMar');
        foreach (['top' => '55', 'left' => '100', 'bottom' => '55', 'right' => '100'] as $mSide => $mVal) {
            $m = $dom->createElementNS(self::NS_W, 'w:' . $mSide);
            $m->setAttribute('w:w', $mVal);
            $m->setAttribute('w:type', 'dxa');
            $cellMar->appendChild($m);
        }
        $tblPr->appendChild($cellMar);
        $tbl->appendChild($tblPr);

        // Find last header row index to apply header separator rule accurately across multi-row headers
        $lastHeaderIdx = 0;
        foreach ($rows as $rIdx => $rowCells) {
            $hasHdr = false;
            foreach ($rowCells as $c) {
                if (is_array($c) && !empty($c['header'])) {
                    $hasHdr = true;
                    break;
                }
            }
            if ($hasHdr) {
                $lastHeaderIdx = $rIdx;
            } else {
                break;
            }
        }

        foreach ($rows as $rIdx => $rowCells) {
            $tr = $dom->createElementNS(self::NS_W, 'w:tr');
            $trPr = $dom->createElementNS(self::NS_W, 'w:trPr');
            $isHeaderRow = ($rIdx <= $lastHeaderIdx);
            if ($isHeaderRow) {
                $trPr->appendChild($dom->createElementNS(self::NS_W, 'w:tblHeader'));
            }
            $trPr->appendChild($dom->createElementNS(self::NS_W, 'w:cantSplit'));
            $trJc = $dom->createElementNS(self::NS_W, 'w:jc');
            $trJc->setAttribute('w:val', 'center');
            $trPr->appendChild($trJc);
            $tr->appendChild($trPr);

            $isFirstBodyRow = ($rIdx === $lastHeaderIdx + 1);

            foreach ($rowCells as $cIdx => $cell) {
                if (!is_array($cell)) {
                    $cell = ['text' => (string) $cell, 'colspan' => 1, 'vmerge' => '', 'header' => $isHeaderRow, 'row_type' => $isHeaderRow ? 'header' : 'data', 'align' => ($cIdx === 0 ? 'left' : 'center')];
                }
                $tc = $dom->createElementNS(self::NS_W, 'w:tc');
                $tcPr = $dom->createElementNS(self::NS_W, 'w:tcPr');

                $tcW = $dom->createElementNS(self::NS_W, 'w:tcW');
                $tcW->setAttribute('w:w', '0');
                $tcW->setAttribute('w:type', 'auto');
                $tcPr->appendChild($tcW);

                $colspan = max(1, (int) ($cell['colspan'] ?? 1));
                if ($colspan > 1) {
                    $gs = $dom->createElementNS(self::NS_W, 'w:gridSpan');
                    $gs->setAttribute('w:val', (string) $colspan);
                    $tcPr->appendChild($gs);
                }

                $vmerge = (string) ($cell['vmerge'] ?? '');
                if ($vmerge === 'restart') {
                    $vm = $dom->createElementNS(self::NS_W, 'w:vMerge');
                    $vm->setAttribute('w:val', 'restart');
                    $tcPr->appendChild($vm);
                } elseif ($vmerge === 'continue') {
                    $vm = $dom->createElementNS(self::NS_W, 'w:vMerge');
                    $tcPr->appendChild($vm);
                }

                $rowType = (string) ($cell['row_type'] ?? ($isHeaderRow ? 'header' : 'data'));
                $tcBorders = null;

                // Header bottom rule (1pt) on last header row, and subtle straddle rule under spanned super-headers
                if ($isHeaderRow) {
                    if ($rIdx === $lastHeaderIdx || ($vmerge === 'restart' && $rIdx < $lastHeaderIdx)) {
                        if ($rIdx === $lastHeaderIdx || (($rIdx + (int) ($cell['rowspan'] ?? 1) - 1) >= $lastHeaderIdx)) {
                            $tcBorders = $dom->createElementNS(self::NS_W, 'w:tcBorders');
                            $bot = $dom->createElementNS(self::NS_W, 'w:bottom');
                            $bot->setAttribute('w:val', 'single');
                            $bot->setAttribute('w:sz', '8');
                            $bot->setAttribute('w:space', '0');
                            $bot->setAttribute('w:color', '0A1D30');
                            $tcBorders->appendChild($bot);
                        }
                    } elseif ($colspan > 1 && $rIdx < $lastHeaderIdx) {
                        $tcBorders = $dom->createElementNS(self::NS_W, 'w:tcBorders');
                        $bot = $dom->createElementNS(self::NS_W, 'w:bottom');
                        $bot->setAttribute('w:val', 'single');
                        $bot->setAttribute('w:sz', '4');
                        $bot->setAttribute('w:space', '0');
                        $bot->setAttribute('w:color', '64748B');
                        $tcBorders->appendChild($bot);
                    }
                } elseif ($rowType === 'total') {
                    $tcBorders = $dom->createElementNS(self::NS_W, 'w:tcBorders');
                    $top = $dom->createElementNS(self::NS_W, 'w:top');
                    $top->setAttribute('w:val', 'single');
                    $top->setAttribute('w:sz', '6');
                    $top->setAttribute('w:space', '0');
                    $top->setAttribute('w:color', '475569');
                    $tcBorders->appendChild($top);
                } elseif ($rowType === 'group' && !$isFirstBodyRow) {
                    $tcBorders = $dom->createElementNS(self::NS_W, 'w:tcBorders');
                    $top = $dom->createElementNS(self::NS_W, 'w:top');
                    $top->setAttribute('w:val', 'single');
                    $top->setAttribute('w:sz', '2');
                    $top->setAttribute('w:space', '0');
                    $top->setAttribute('w:color', 'E2E8F0');
                    $tcBorders->appendChild($top);
                }

                if ($tcBorders !== null) {
                    $tcPr->appendChild($tcBorders);
                }

                $vAlign = $dom->createElementNS(self::NS_W, 'w:vAlign');
                $vAlign->setAttribute('w:val', 'center');
                $tcPr->appendChild($vAlign);
                $tc->appendChild($tcPr);

                $p = $dom->createElementNS(self::NS_W, 'w:p');
                $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
                $sp = $dom->createElementNS(self::NS_W, 'w:spacing');
                $sp->setAttribute('w:before', ($rowType === 'group' && !$isFirstBodyRow) ? '40' : '0');
                $sp->setAttribute('w:after', '0');
                $pPr->appendChild($sp);

                $align = (string) ($cell['align'] ?? ($cIdx === 0 ? 'left' : 'center'));
                if ($align === 'center' || $align === 'right' || $align === 'left') {
                    $pJc = $dom->createElementNS(self::NS_W, 'w:jc');
                    $pJc->setAttribute('w:val', $align);
                    $pPr->appendChild($pJc);
                }

                // Indent subcategory rows on column 0
                if ($rowType === 'subitem' && $cIdx === 0) {
                    $ind = $dom->createElementNS(self::NS_W, 'w:ind');
                    $ind->setAttribute('w:left', '220');
                    $pPr->appendChild($ind);
                }

                $p->appendChild($pPr);

                $isBold = $isHeaderRow || ($rowType === 'group') || ($rowType === 'total');
                $cellText = (string) ($cell['text'] ?? '');
                if ($cellText !== '') {
                    foreach (HealthTableFormatter::splitStatisticalInlineRuns($cellText) as $seg) {
                        $p->appendChild(self::createRun($dom, $seg['text'], [
                            'bold' => $isBold,
                            'italic' => $seg['italic'],
                            'superscript' => $seg['sup'],
                            'sz' => '19',
                            'color' => $isHeaderRow ? '0A1D30' : '1E293B',
                            'noFont' => true,
                        ]));
                    }
                }
                $tc->appendChild($p);
                $tr->appendChild($tc);
            }

            $tbl->appendChild($tr);
        }

        return $tbl;
    }

    /**
     * Create hanging-indent reference paragraph
     */
    private static function createReferencePara(DOMDocument $dom, string $refText): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');

        $sp = $dom->createElementNS(self::NS_W, 'w:spacing');
        $sp->setAttribute('w:after', '0');
        $pPr->appendChild($sp);

        $ind = $dom->createElementNS(self::NS_W, 'w:ind');
        $ind->setAttribute('w:left', '426');
        $ind->setAttribute('w:hanging', '426');
        $pPr->appendChild($ind);

        $jc = $dom->createElementNS(self::NS_W, 'w:jc');
        $jc->setAttribute('w:val', 'both');
        $pPr->appendChild($jc);

        $p->appendChild($pPr);
        $p->appendChild(self::createRun($dom, $refText, ['noFont' => true]));
        return $p;
    }

    private static function createReferenceEmptyPara(DOMDocument $dom): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
        $sp = $dom->createElementNS(self::NS_W, 'w:spacing');
        $sp->setAttribute('w:after', '0');
        $pPr->appendChild($sp);
        $ind = $dom->createElementNS(self::NS_W, 'w:ind');
        $ind->setAttribute('w:left', '426');
        $ind->setAttribute('w:hanging', '426');
        $pPr->appendChild($ind);
        $jc = $dom->createElementNS(self::NS_W, 'w:jc');
        $jc->setAttribute('w:val', 'both');
        $pPr->appendChild($jc);
        $p->appendChild($pPr);
        return $p;
    }

    /**
     * Sidebar 9pt Arial label + value paragraph
     */
    private static function createSidebarLabelValuePara(DOMDocument $dom, string $label, string $value, bool $justify = false): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
        $sp = $dom->createElementNS(self::NS_W, 'w:spacing');
        $sp->setAttribute('w:after', '0');
        $sp->setAttribute('w:line', '240');
        $sp->setAttribute('w:lineRule', 'auto');
        $pPr->appendChild($sp);

        if ($justify) {
            $jc = $dom->createElementNS(self::NS_W, 'w:jc');
            $jc->setAttribute('w:val', 'both');
            $pPr->appendChild($jc);
        }

        $p->appendChild($pPr);

        if ($label !== '') {
            $p->appendChild(self::createRun($dom, $label, ['sz' => '18', 'bold' => true]));
        }
        if ($value !== '') {
            $p->appendChild(self::createRun($dom, $value, ['sz' => '18']));
        }
        return $p;
    }

    private static function createSidebarEmptyPara(DOMDocument $dom): DOMElement
    {
        return self::createStyledPara($dom, '', ['sz' => '18']);
    }

    /**
     * Sidebar Copyright paragraph with CC BY-SA 4.0 hyperlink (rId12)
     */
    private static function createSidebarCopyrightPara(DOMDocument $dom, string $year, string $firstAuthorLast): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');
        $sp = $dom->createElementNS(self::NS_W, 'w:spacing');
        $sp->setAttribute('w:after', '0');
        $sp->setAttribute('w:line', '240');
        $sp->setAttribute('w:lineRule', 'auto');
        $pPr->appendChild($sp);
        $jc = $dom->createElementNS(self::NS_W, 'w:jc');
        $jc->setAttribute('w:val', 'both');
        $pPr->appendChild($jc);
        $p->appendChild($pPr);

        $p->appendChild(self::createRun($dom, 'Copyright: ', ['sz' => '18', 'bold' => true]));
        $p->appendChild(self::createRun(
            $dom,
            '© ' . $year . ' ' . $firstAuthorLast . ' et al. This is an open access article distributed under the terms of the ',
            ['sz' => '18']
        ));

        $hl = $dom->createElementNS(self::NS_W, 'w:hyperlink');
        $hl->setAttributeNS(self::NS_R, 'r:id', 'rId12');
        $hl->setAttribute('w:history', '1');
        $hl->appendChild(self::createRun(
            $dom,
            'Attribution-ShareAlike 4.0 International license (CC BY-SA 4.0)',
            ['sz' => '18', 'rStyle' => 'Hyperlink']
        ));
        $p->appendChild($hl);

        $p->appendChild(self::createRun(
            $dom,
            ', thus anyone, anywhere has the same opportunity to explore the knowledge and enhance opportunities for scientific discussion.',
            ['sz' => '18']
        ));
        return $p;
    }

    /**
     * Generic styled paragraph builder
     */
    private static function createStyledPara(DOMDocument $dom, string $text, array $opts = []): DOMElement
    {
        $p = $dom->createElementNS(self::NS_W, 'w:p');
        $pPr = $dom->createElementNS(self::NS_W, 'w:pPr');

        $sp = $dom->createElementNS(self::NS_W, 'w:spacing');
        $sp->setAttribute('w:after', '0');
        $sp->setAttribute('w:line', '240');
        $sp->setAttribute('w:lineRule', 'auto');
        $pPr->appendChild($sp);

        if (!empty($opts['jc'])) {
            $jc = $dom->createElementNS(self::NS_W, 'w:jc');
            $jc->setAttribute('w:val', $opts['jc']);
            $pPr->appendChild($jc);
        }

        $p->appendChild($pPr);

        if ($text !== '') {
            $p->appendChild(self::createRun($dom, $text, $opts));
        }
        return $p;
    }

    /**
     * Create a <w:r> element with run properties
     */
    private static function createRun(DOMDocument $dom, string $text, array $opts = []): DOMElement
    {
        $r = $dom->createElementNS(self::NS_W, 'w:r');
        $rPr = $dom->createElementNS(self::NS_W, 'w:rPr');

        if (!empty($opts['rStyle'])) {
            $rs = $dom->createElementNS(self::NS_W, 'w:rStyle');
            $rs->setAttribute('w:val', $opts['rStyle']);
            $rPr->appendChild($rs);
        }

        if (empty($opts['noFont'])) {
            $rf = $dom->createElementNS(self::NS_W, 'w:rFonts');
            $rf->setAttribute('w:cs', 'Arial');
            $rPr->appendChild($rf);
        }

        if (!empty($opts['bold'])) {
            $rPr->appendChild($dom->createElementNS(self::NS_W, 'w:b'));
            $rPr->appendChild($dom->createElementNS(self::NS_W, 'w:bCs'));
        }

        if (!empty($opts['italic'])) {
            $rPr->appendChild($dom->createElementNS(self::NS_W, 'w:i'));
            $rPr->appendChild($dom->createElementNS(self::NS_W, 'w:iCs'));
        }

        if (!empty($opts['sz'])) {
            $sz = $dom->createElementNS(self::NS_W, 'w:sz');
            $sz->setAttribute('w:val', (string) $opts['sz']);
            $rPr->appendChild($sz);

            $szCs = $dom->createElementNS(self::NS_W, 'w:szCs');
            $szCs->setAttribute('w:val', (string) $opts['sz']);
            $rPr->appendChild($szCs);
        }

        if (!empty($opts['color'])) {
            $col = $dom->createElementNS(self::NS_W, 'w:color');
            $col->setAttribute('w:val', (string) $opts['color']);
            $rPr->appendChild($col);
        }

        if (!empty($opts['superscript'])) {
            $va = $dom->createElementNS(self::NS_W, 'w:vertAlign');
            $va->setAttribute('w:val', 'superscript');
            $rPr->appendChild($va);
        }

        $r->appendChild($rPr);
        $r->appendChild(self::createTextElement($dom, $text, true));

        return $r;
    }

    /**
     * Create a safely XML-escaped <w:t> text node without entity-reference warnings.
     */
    private static function createTextElement(DOMDocument $dom, string $text, bool $preserveSpace = true): DOMElement
    {
        $t = $dom->createElementNS(self::NS_W, 'w:t');
        if ($preserveSpace) {
            $t->setAttribute('xml:space', 'preserve');
        }
        $t->appendChild($dom->createTextNode($text));
        return $t;
    }

    /**
     * Update footer XML and relationships with the article's actual URL and Volume/Issue/Year
     */
    private static function updateFooterXml(
        ZipArchive $zip,
        string $footerEntry,
        string $relsEntry,
        string $shortJournalTitle,
        string $articleUrl,
        string $footerIssueLine
    ): void {
        // Always enforce official Indonesian month names in footers across every volume
        $footerIssueLine = LanguageAuditor::translateMonthsToIndonesian($footerIssueLine);
        $resolvedFooter = DocxParser::resolveZipEntryName($zip, $footerEntry);
        $fXml = $zip->getFromName($resolvedFooter);
        if ($fXml) {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = true;
            @$dom->loadXML($fXml, LIBXML_NOENT | LIBXML_NONET);
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', self::NS_W);

            // Replace hyperlink display text
            foreach ($xp->query('//w:hyperlink//w:t') as $hlText) {
                $hlText->nodeValue = $articleUrl;
            }

            // Replace 3rd paragraph (Volume X Nomor Y, Bulan Tahun)
            $pNodes = $xp->query('//w:p');
            if ($pNodes->length >= 3) {
                $p3 = $pNodes->item(2);
                foreach ($xp->query('./w:r', $p3) as $oldRun) {
                    $p3->removeChild($oldRun);
                }
                $r = $dom->createElementNS(self::NS_W, 'w:r');
                $rPr = $dom->createElementNS(self::NS_W, 'w:rPr');
                $rf = $dom->createElementNS(self::NS_W, 'w:rFonts');
                $rf->setAttribute('w:cs', 'Arial');
                $rPr->appendChild($rf);
                $col = $dom->createElementNS(self::NS_W, 'w:color');
                $col->setAttribute('w:val', '0A1D30');
                $col->setAttribute('w:themeColor', 'text2');
                $col->setAttribute('w:themeShade', 'BF');
                $rPr->appendChild($col);
                $sz = $dom->createElementNS(self::NS_W, 'w:sz');
                $sz->setAttribute('w:val', '22');
                $rPr->appendChild($sz);
                $szCs = $dom->createElementNS(self::NS_W, 'w:szCs');
                $szCs->setAttribute('w:val', '22');
                $rPr->appendChild($szCs);
                $r->appendChild($rPr);

                $r->appendChild(self::createTextElement($dom, $footerIssueLine, false));
                $p3->appendChild($r);
            }

            $zip->addFromString($resolvedFooter, $dom->saveXML());
        }

        $resolvedRels = DocxParser::resolveZipEntryName($zip, $relsEntry);
        $rXml = $zip->getFromName($resolvedRels);
        if ($rXml) {
            $rXmlUpdated = preg_replace(
                '/Target="[^"]+"/i',
                'Target="' . htmlspecialchars($articleUrl, ENT_QUOTES, 'UTF-8') . '"',
                $rXml
            );
            $zip->addFromString($resolvedRels, $rXmlUpdated);
        }
    }

    /**
     * Remove all <w:p> children from a table cell (<w:tc>), keeping <w:tcPr> intact
     */
    private static function clearCellParagraphs(DOMElement $tc, DOMXPath $xpath): void
    {
        $toRemove = [];
        foreach ($xpath->query('./w:p', $tc) as $p) {
            $toRemove[] = $p;
        }
        foreach ($toRemove as $p) {
            $tc->removeChild($p);
        }
    }

    private static function clearElementChildren(DOMElement $el): void
    {
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
    }

    public static function extractFirstAuthorLastName(array $authors): string
    {
        if (empty($authors)) {
            return 'Author';
        }
        $first = trim($authors[0]['name'] ?? 'Author');
        // Strip titles after comma if any
        $parts = explode(',', $first);
        $nameOnly = trim($parts[0]);
        $words = preg_split('/\s+/u', $nameOnly, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return 'Author';
        }
        return end($words);
    }
}
