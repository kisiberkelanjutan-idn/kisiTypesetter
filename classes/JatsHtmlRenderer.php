<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/JatsHtmlRenderer.php
 *
 * Digests a JATS 1.2 / AmeliCA XML file and renders it directly as
 * semantic, responsive Full-Text HTML on the OJS 3.5 public article landing page
 * (/article/view/{id}) as well as in the dedicated AmeliCA-style Galley Reader.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class JatsHtmlRenderer
{
    /**
     * Digest a JATS XML string into semantic Full-Text HTML ready for the OJS article landing page
     *
     * @param string $xmlContent Raw JATS XML string
     * @param array $options Optional rendering flags ('include_abstract' => bool, 'standalone_reader' => bool)
     * @return string Rendered HTML block
     */
    public static function renderFullTextHtml(string $xmlContent, array $options = []): string
    {
        if (trim($xmlContent) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        if (!@$dom->loadXML($xmlContent, LIBXML_NOENT | LIBXML_NONET)) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

        // Extract Front-Matter metadata for sidebar / badges
        $articleType = self::getNodeText($xpath, '//article-meta/article-categories/subj-group/subject') ?: 'ORIGINAL ARTICLE';
        $doi = self::getNodeText($xpath, '//article-meta/article-id[@pub-id-type="doi"]');
        $elocationId = self::getNodeText($xpath, '//article-meta/elocation-id');
        $volume = self::getNodeText($xpath, '//article-meta/volume');
        $issue = self::getNodeText($xpath, '//article-meta/issue');
        $pubYear = self::getNodeText($xpath, '//article-meta/pub-date/year');
        $pubMonth = self::getNodeText($xpath, '//article-meta/pub-date/month');
        $pubDay = self::getNodeText($xpath, '//article-meta/pub-date/day');

        $editorSurname = self::getNodeText($xpath, '//article-meta/contrib-group[@content-type="editor"]/contrib/name/surname');
        $editorGiven = self::getNodeText($xpath, '//article-meta/contrib-group[@content-type="editor"]/contrib/name/given-names');
        $editorAff = self::getNodeText($xpath, '//article-meta/contrib-group[@content-type="editor"]/contrib/aff/institution');
        $editorDisplay = trim($editorGiven . ' ' . $editorSurname);
        if ($editorDisplay !== '' && $editorAff !== '') {
            $editorDisplay .= ' (' . $editorAff . ')';
        }

        $correspText = self::getNodeText($xpath, '//article-meta/author-notes/corresp');
        $conflictText = self::getNodeText($xpath, '//article-meta/author-notes/fn[@fn-type="conflict"]/p');
        $fundingText = self::getNodeText($xpath, '//article-meta/funding-group/funding-statement');
        $licenseText = self::getNodeText($xpath, '//article-meta/permissions/license/license-p');
        $copyrightStmt = self::getNodeText($xpath, '//article-meta/permissions/copyright-statement');

        // Build TOC & Body Sections HTML
        $tocItems = [];
        $bodyHtmlParts = [];

        // Optional Structured Bilingual Abstract Digest (so visitors see both ID and EN structured abstracts cleanly)
        $includeAbstract = $options['include_abstract'] ?? true;
        if ($includeAbstract) {
            $absHtml = self::renderBilingualAbstracts($xpath);
            if ($absHtml !== '') {
                $tocItems[] = ['id' => 'kisi-sec-abstract', 'title' => 'Abstrak / Abstract'];
                $bodyHtmlParts[] = $absHtml;
            }
        }

        // Parse <body> children: direct paragraphs/tables (e.g., DATA REPORT / LETTER) and <sec> elements
        $bodyNode = $xpath->query('//body')->item(0);
        if ($bodyNode instanceof DOMElement) {
            $directBodyBuffer = '';
            $secIndex = 1;

            foreach ($bodyNode->childNodes as $child) {
                if (!($child instanceof DOMElement)) {
                    continue;
                }
                if ($child->localName === 'p') {
                    $pHtml = self::renderInlineContent($child);
                    if (trim($pHtml) !== '') {
                        $directBodyBuffer .= '<p class="kisi-jats-p">' . $pHtml . '</p>';
                    }
                } elseif ($child->localName === 'table-wrap') {
                    $directBodyBuffer .= self::renderTableWrap($child, $xpath);
                } elseif ($child->localName === 'sec') {
                    if ($directBodyBuffer !== '') {
                        $tocItems[] = ['id' => 'kisi-sec-body', 'title' => 'Isi Artikel'];
                        $bodyHtmlParts[] = '<section id="kisi-sec-body" class="kisi-jats-section">' . $directBodyBuffer . '</section>';
                        $directBodyBuffer = '';
                    }
                    $secId = $child->getAttribute('id') ?: ('kisi-sec-' . $secIndex);
                    $secId = 'kisi-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $secId);
                    $titleNode = $xpath->query('./title', $child)->item(0);
                    $secTitle = $titleNode ? trim($titleNode->textContent) : ('Bagian ' . $secIndex);

                    $tocItems[] = ['id' => $secId, 'title' => $secTitle];
                    $bodyHtmlParts[] = self::renderSectionElement($child, $xpath, $secId, 2);
                    $secIndex++;
                }
            }

            if ($directBodyBuffer !== '') {
                $tocItems[] = ['id' => 'kisi-sec-body', 'title' => 'Isi Artikel'];
                $bodyHtmlParts[] = '<section id="kisi-sec-body" class="kisi-jats-section">' . $directBodyBuffer . '</section>';
            }
        }

        // Parse <back>/<ref-list> (SUMBER PUSTAKA)
        $refNodes = $xpath->query('//back/ref-list/ref');
        if ($refNodes && $refNodes->length > 0) {
            $refTitle = self::getNodeText($xpath, '//back/ref-list/title') ?: 'SUMBER PUSTAKA';
            $tocItems[] = ['id' => 'kisi-sec-references', 'title' => $refTitle];
            $bodyHtmlParts[] = self::renderReferencesSection($refNodes, $xpath, $refTitle);
        }

        // Parse <back>/<fn-group> (Publisher's Note & Re-typeset 2026 Box)
        $fnNodes = $xpath->query('//back/fn-group/fn/p');
        if ($fnNodes && $fnNodes->length > 0) {
            $bodyHtmlParts[] = self::renderPublishersNote($fnNodes);
        }

        if (empty($bodyHtmlParts)) {
            return '';
        }

        // Build Navigation Pills / Sticky TOC
        $tocHtml = '<nav class="kisi-jats-toc" aria-label="Navigasi Bagian Artikel">';
        $tocHtml .= '<div class="kisi-jats-toc-label">📖 Navigasi Cepat Full-Text:</div>';
        $tocHtml .= '<div class="kisi-jats-toc-links">';
        foreach ($tocItems as $item) {
            $tocHtml .= '<a href="#' . htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') . '" class="kisi-jats-toc-pill">'
                . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $tocHtml .= '</div></nav>';

        // Editorial Metadata Bar (Review Editor, Funding, Competing Interests, License)
        $metaBarHtml = '<div class="kisi-jats-metabar">';
        $metaBarHtml .= '<div class="kisi-jats-metabar-badges">';
        $metaBarHtml .= '<span class="kisi-badge kisi-badge-oa">🔓 OPEN ACCESS</span>';
        $metaBarHtml .= '<span class="kisi-badge kisi-badge-sdg">🌐 SDGs Collections</span>';
        $metaBarHtml .= '<span class="kisi-badge kisi-badge-type">' . htmlspecialchars($articleType, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($elocationId !== '') {
            $metaBarHtml .= '<span class="kisi-badge kisi-badge-eloc">Artikel ' . htmlspecialchars($elocationId, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $metaBarHtml .= '</div>';

        $metaDetails = [];
        if ($editorDisplay !== '') {
            $metaDetails[] = '<strong>Review Editor:</strong> ' . htmlspecialchars($editorDisplay, ENT_QUOTES, 'UTF-8');
        }
        if ($correspText !== '') {
            $metaDetails[] = '<strong>Korespondensi:</strong> ' . htmlspecialchars(ltrim($correspText, '* '), ENT_QUOTES, 'UTF-8');
        }
        if ($fundingText !== '') {
            $metaDetails[] = '<strong>Funding:</strong> ' . htmlspecialchars($fundingText, ENT_QUOTES, 'UTF-8');
        }
        if ($conflictText !== '') {
            $metaDetails[] = '<strong>Competing Interests:</strong> ' . htmlspecialchars($conflictText, ENT_QUOTES, 'UTF-8');
        }
        if (!empty($metaDetails)) {
            $metaBarHtml .= '<div class="kisi-jats-metabar-details">' . implode(' &nbsp;|&nbsp; ', $metaDetails) . '</div>';
        }
        $metaBarHtml .= '</div>';

        $css = self::getFullTextCss();
        $fullHtml = $css
            . '<div id="kisi-jats-fulltext-container" class="kisi-jats-digest-wrapper">'
            . $metaBarHtml
            . $tocHtml
            . '<div class="kisi-jats-article-body">'
            . implode("\n", $bodyHtmlParts)
            . '</div>'
            . '</div>';

        return $fullHtml;
    }

    /**
     * Render Bilingual Structured Abstracts (<abstract> & <trans-abstract>)
     */
    private static function renderBilingualAbstracts(DOMXPath $xpath): string
    {
        $absIdNode = $xpath->query('//article-meta/abstract')->item(0);
        $absEnNode = $xpath->query('//article-meta/trans-abstract')->item(0);

        if (!$absIdNode && !$absEnNode) {
            return '';
        }

        $kwId = [];
        foreach ($xpath->query('//article-meta/kwd-group[@xml:lang="id"]/kwd') as $k) {
            $kwId[] = trim($k->textContent);
        }
        $kwEn = [];
        foreach ($xpath->query('//article-meta/kwd-group[@xml:lang="en"]/kwd') as $k) {
            $kwEn[] = trim($k->textContent);
        }

        $html = '<section id="kisi-sec-abstract" class="kisi-jats-section kisi-jats-abstract-box">';
        $html .= '<div class="kisi-jats-abstract-tabs">';
        $html .= '<button type="button" class="kisi-abs-tab active" onclick="kisiSwitchAbsLang(\'id\', this)">🇮🇩 ABSTRAK (Indonesia)</button>';
        if ($absEnNode) {
            $html .= '<button type="button" class="kisi-abs-tab" onclick="kisiSwitchAbsLang(\'en\', this)">🇬🇧 ABSTRACT (English)</button>';
        }
        $html .= '<button type="button" class="kisi-abs-tab" onclick="kisiSwitchAbsLang(\'both\', this)">🔀 Tampilkan Keduanya</button>';
        $html .= '</div>';

        // Indonesian Abstract Pane
        if ($absIdNode) {
            $html .= '<div id="kisi-abs-pane-id" class="kisi-abs-pane">';
            $secs = $xpath->query('./sec', $absIdNode);
            if ($secs && $secs->length > 0) {
                foreach ($secs as $s) {
                    $st = self::getNodeText($xpath, './title', $s);
                    $sp = self::getNodeText($xpath, './p', $s);
                    if ($st !== '') {
                        $html .= '<h4 class="kisi-abs-subhead">' . htmlspecialchars($st, ENT_QUOTES, 'UTF-8') . '</h4>';
                    }
                    if ($sp !== '') {
                        $html .= '<p class="kisi-abs-p">' . htmlspecialchars($sp, ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                }
            } else {
                foreach ($xpath->query('./p', $absIdNode) as $p) {
                    $html .= '<p class="kisi-abs-p">' . htmlspecialchars(trim($p->textContent), ENT_QUOTES, 'UTF-8') . '</p>';
                }
            }
            if (!empty($kwId)) {
                $html .= '<p class="kisi-abs-keywords"><strong>Kata Kunci:</strong> ' . htmlspecialchars(implode('; ', $kwId) . '.', ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $html .= '</div>';
        }

        // English Abstract Pane
        if ($absEnNode) {
            $html .= '<div id="kisi-abs-pane-en" class="kisi-abs-pane kisi-abs-en" style="display:none;">';
            $secs = $xpath->query('./sec', $absEnNode);
            if ($secs && $secs->length > 0) {
                foreach ($secs as $s) {
                    $st = self::getNodeText($xpath, './title', $s);
                    $sp = self::getNodeText($xpath, './p', $s);
                    if ($st !== '') {
                        $html .= '<h4 class="kisi-abs-subhead"><em>' . htmlspecialchars($st, ENT_QUOTES, 'UTF-8') . '</em></h4>';
                    }
                    if ($sp !== '') {
                        $html .= '<p class="kisi-abs-p"><em>' . htmlspecialchars($sp, ENT_QUOTES, 'UTF-8') . '</em></p>';
                    }
                }
            } else {
                foreach ($xpath->query('./p', $absEnNode) as $p) {
                    $html .= '<p class="kisi-abs-p"><em>' . htmlspecialchars(trim($p->textContent), ENT_QUOTES, 'UTF-8') . '</em></p>';
                }
            }
            if (!empty($kwEn)) {
                $html .= '<p class="kisi-abs-keywords"><em><strong>Keywords:</strong> ' . htmlspecialchars(implode('; ', $kwEn) . '.', ENT_QUOTES, 'UTF-8') . '</em></p>';
            }
            $html .= '</div>';
        }

        $html .= <<<JS
<script>
function kisiSwitchAbsLang(mode, btn) {
    var container = btn.closest('.kisi-jats-abstract-box');
    container.querySelectorAll('.kisi-abs-tab').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    var pId = document.getElementById('kisi-abs-pane-id');
    var pEn = document.getElementById('kisi-abs-pane-en');
    if (mode === 'id') {
        if (pId) pId.style.display = 'block';
        if (pEn) pEn.style.display = 'none';
    } else if (mode === 'en') {
        if (pId) pId.style.display = 'none';
        if (pEn) pEn.style.display = 'block';
    } else {
        if (pId) pId.style.display = 'block';
        if (pEn) { pEn.style.display = 'block'; pEn.style.marginTop = '14px'; pEn.style.paddingTop = '14px'; pEn.style.borderTop = '1px dashed #cbd5e1'; }
    }
}
</script>
JS;
        $html .= '</section>';
        return $html;
    }

    /**
     * Recursively render a JATS <sec> element (handling paragraphs, nested sub-sections, and <table-wrap>)
     */
    private static function renderSectionElement(DOMElement $secNode, DOMXPath $xpath, string $secId, int $headingLevel = 2): string
    {
        $hTag = 'h' . min(4, max(2, $headingLevel));
        $cls = ($headingLevel === 2) ? 'kisi-jats-section' : 'kisi-jats-subsection';
        $html = '<section id="' . htmlspecialchars($secId, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '">';

        foreach ($secNode->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if ($child->localName === 'title') {
                $t = trim($child->textContent);
                if ($t !== '') {
                    $hClass = ($headingLevel === 2) ? 'kisi-jats-h2' : 'kisi-jats-h3';
                    $html .= '<' . $hTag . ' class="' . $hClass . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</' . $hTag . '>';
                }
            } elseif ($child->localName === 'p') {
                $pHtml = self::renderInlineContent($child);
                if (trim($pHtml) !== '') {
                    $html .= '<p class="kisi-jats-p">' . $pHtml . '</p>';
                }
            } elseif ($child->localName === 'table-wrap') {
                $html .= self::renderTableWrap($child, $xpath);
            } elseif ($child->localName === 'sec') {
                $subId = $child->getAttribute('id') ?: ($secId . '-sub');
                $subId = 'kisi-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $subId);
                $html .= self::renderSectionElement($child, $xpath, $subId, $headingLevel + 1);
            }
        }

        $html .= '</section>';
        return $html;
    }

    /**
     * Render a JATS <table-wrap> into a responsive, publication-grade Health-Science 3-line academic HTML table
     */
    private static function renderTableWrap(DOMElement $twNode, DOMXPath $xpath): string
    {
        $twId = $twNode->getAttribute('id') ?: 'T';
        $label = self::getNodeText($xpath, './label', $twNode);
        $captionTitle = self::getNodeText($xpath, './caption/title', $twNode)
            ?: self::getNodeText($xpath, './caption/p', $twNode);

        $html = '<figure id="kisi-table-' . htmlspecialchars($twId, ENT_QUOTES, 'UTF-8') . '" class="kisi-jats-table-wrap">';
        if ($label !== '' || $captionTitle !== '') {
            $html .= '<figcaption class="kisi-jats-table-caption">';
            if ($label !== '') {
                $html .= '<strong>' . htmlspecialchars(rtrim($label, '.') . '.', ENT_QUOTES, 'UTF-8') . '</strong> ';
            }
            if ($captionTitle !== '' && strcasecmp($captionTitle, $label) !== 0) {
                $html .= htmlspecialchars($captionTitle, ENT_QUOTES, 'UTF-8');
            }
            $html .= '</figcaption>';
        }

        $tableNode = $xpath->query('./table', $twNode)->item(0);
        if ($tableNode instanceof DOMElement) {
            $html .= '<div class="kisi-jats-table-scroll"><table class="kisi-jats-table">';

            foreach (['thead', 'tbody', 'tfoot'] as $part) {
                $partNode = $xpath->query('./' . $part, $tableNode)->item(0);
                if ($partNode instanceof DOMElement) {
                    $html .= '<' . $part . '>';
                    foreach ($xpath->query('./tr', $partNode) as $tr) {
                        $html .= self::renderTableRow($tr, $part === 'thead');
                    }
                    $html .= '</' . $part . '>';
                }
            }

            // Handle tables that place <tr> directly inside <table>
            $directRows = $xpath->query('./tr', $tableNode);
            if ($directRows && $directRows->length > 0) {
                $html .= '<tbody>';
                foreach ($directRows as $idx => $tr) {
                    $html .= self::renderTableRow($tr, $idx === 0);
                }
                $html .= '</tbody>';
            }

            $html .= '</table></div>';
        }

        // Render <table-wrap-foot> footnotes directly under the bottom horizontal rule
        $footNodes = $xpath->query('./table-wrap-foot//p', $twNode);
        if ($footNodes && $footNodes->length > 0) {
            $html .= '<div class="kisi-jats-table-foot">';
            foreach ($footNodes as $fp) {
                $fHtml = self::renderInlineContent($fp);
                if (trim($fHtml) !== '') {
                    $html .= '<p>' . $fHtml . '</p>';
                }
            }
            $html .= '</div>';
        }

        $html .= '</figure>';
        return $html;
    }

    private static function renderTableRow(DOMElement $trNode, bool $isHeaderSection = false): string
    {
        $trType = $trNode->getAttribute('content-type');
        $trCls = in_array($trType, ['group', 'subitem', 'total'], true) ? (' class="kisi-row-' . htmlspecialchars($trType, ENT_QUOTES, 'UTF-8') . '"') : '';
        $html = '<tr' . $trCls . '>';
        $cellIdx = 0;

        foreach ($trNode->childNodes as $cell) {
            if (!($cell instanceof DOMElement)) {
                continue;
            }
            if ($cell->localName !== 'th' && $cell->localName !== 'td') {
                continue;
            }
            $tag = ($cell->localName === 'th' || $isHeaderSection) ? 'th' : 'td';
            $attrs = '';
            $colspan = (int) $cell->getAttribute('colspan');
            if ($colspan > 1) {
                $attrs .= ' colspan="' . $colspan . '"';
            }
            $rowspan = (int) $cell->getAttribute('rowspan');
            if ($rowspan > 1) {
                $attrs .= ' rowspan="' . $rowspan . '"';
            }

            $align = $cell->getAttribute('align');
            if (!in_array($align, ['left', 'center', 'right'], true)) {
                $align = ($cellIdx === 0 && !$isHeaderSection) ? 'left' : (($tag === 'th' && $cellIdx === 0 && $colspan === 1) ? 'left' : 'center');
            }

            $cellClasses = ['kisi-align-' . $align];
            $cellType = $cell->getAttribute('content-type') ?: $trType;
            if ($tag === 'td' && $cellType === 'subitem' && $cellIdx === 0) {
                $cellClasses[] = 'kisi-cell-subitem';
            } elseif ($tag === 'td' && $cellType === 'group') {
                $cellClasses[] = 'kisi-cell-group';
            } elseif ($tag === 'td' && $cellType === 'total') {
                $cellClasses[] = 'kisi-cell-total';
            } elseif ($tag === 'th' && $colspan > 1) {
                $cellClasses[] = 'kisi-th-straddle';
            }

            $attrs .= ' class="' . implode(' ', $cellClasses) . '"';
            $innerHtml = self::renderInlineContent($cell);
            $html .= '<' . $tag . $attrs . '>' . (trim(strip_tags($innerHtml)) !== '' ? $innerHtml : '&nbsp;') . '</' . $tag . '>';
            $cellIdx++;
        }
        $html .= '</tr>';
        return $html;
    }

    /**
     * Render <ref-list> into academic reference list
     */
    private static function renderReferencesSection($refNodes, DOMXPath $xpath, string $title): string
    {
        $html = '<section id="kisi-sec-references" class="kisi-jats-section">';
        $html .= '<h2 class="kisi-jats-h2">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
        $html .= '<ol class="kisi-jats-ref-list">';

        foreach ($refNodes as $ref) {
            if (!($ref instanceof DOMElement)) {
                continue;
            }
            $refId = $ref->getAttribute('id');
            $mc = $xpath->query('./mixed-citation|./element-citation', $ref)->item(0);
            $refHtml = $mc ? self::renderInlineContent($mc) : htmlspecialchars(trim($ref->textContent), ENT_QUOTES, 'UTF-8');
            $html .= '<li id="kisi-ref-' . htmlspecialchars($refId, ENT_QUOTES, 'UTF-8') . '" class="kisi-jats-ref-item">' . $refHtml . '</li>';
        }

        $html .= '</ol></section>';
        return $html;
    }

    /**
     * Render Publisher's Note & Re-typeset 2026 Framed Box
     */
    private static function renderPublishersNote($fnParagraphs): string
    {
        $pTexts = [];
        foreach ($fnParagraphs as $p) {
            $t = trim($p->textContent);
            if ($t !== '') {
                $pTexts[] = $t;
            }
        }
        if (empty($pTexts)) {
            return '';
        }

        $html = '<aside class="kisi-jats-publishers-note">';
        $html .= '<div class="kisi-jats-pubnote-title"><em>Publisher’s Note</em></div>';
        if (isset($pTexts[0])) {
            $html .= '<p class="kisi-jats-pubnote-p"><em>' . htmlspecialchars($pTexts[0], ENT_QUOTES, 'UTF-8') . '</em></p>';
        }
        if (isset($pTexts[1])) {
            $html .= '<div class="kisi-jats-retypeset-box"><strong><em>' . htmlspecialchars($pTexts[1], ENT_QUOTES, 'UTF-8') . '</em></strong></div>';
        }
        $html .= '</aside>';
        return $html;
    }

    /**
     * Render inline JATS content (<italic>, <bold>, <sup>, <sub>, <ext-link>, <xref>)
     */
    private static function renderInlineContent(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= htmlspecialchars($child->nodeValue, ENT_QUOTES, 'UTF-8');
            } elseif ($child instanceof DOMElement) {
                $ln = $child->localName;
                $inner = self::renderInlineContent($child);
                if ($ln === 'italic') {
                    $out .= '<em>' . $inner . '</em>';
                } elseif ($ln === 'bold') {
                    $out .= '<strong>' . $inner . '</strong>';
                } elseif ($ln === 'sup') {
                    $out .= '<sup>' . $inner . '</sup>';
                } elseif ($ln === 'sub') {
                    $out .= '<sub>' . $inner . '</sub>';
                } elseif ($ln === 'ext-link') {
                    $href = $child->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                        ?: $child->getAttribute('xlink:href')
                        ?: trim($child->textContent);
                    $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $inner . '</a>';
                } elseif ($ln === 'xref') {
                    $rid = $child->getAttribute('rid');
                    $out .= '<a href="#kisi-' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</a>';
                } else {
                    $out .= $inner;
                }
            }
        }
        return $out;
    }

    private static function getNodeText(DOMXPath $xpath, string $query, ?DOMNode $contextNode = null): string
    {
        $n = $contextNode ? $xpath->query($query, $contextNode)->item(0) : $xpath->query($query)->item(0);
        return $n ? trim($n->textContent) : '';
    }

    /**
     * Embedded responsive CSS for the Full-Text Article Digest on the OJS landing page
     */
    private static function getFullTextCss(): string
    {
        return <<<HTML
<style>
.kisi-jats-digest-wrapper {
    margin: 28px 0;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Georgia, sans-serif;
    color: #1e293b;
    line-height: 1.75;
    font-size: 15.5px;
    border-top: 3px solid #0a1d30;
    padding-top: 20px;
}
.kisi-jats-metabar {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #0a1d30;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 18px;
}
.kisi-jats-metabar-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}
.kisi-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.kisi-badge-oa { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
.kisi-badge-sdg { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
.kisi-badge-type { background: #0a1d30; color: #ffffff; }
.kisi-badge-eloc { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.kisi-jats-metabar-details {
    font-size: 12.5px;
    color: #475569;
    line-height: 1.5;
}
.kisi-jats-toc {
    position: sticky;
    top: 0;
    z-index: 40;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(6px);
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
}
.kisi-jats-toc-label {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 6px;
}
.kisi-jats-toc-links {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.kisi-jats-toc-pill {
    display: inline-block;
    padding: 4px 11px;
    background: #f1f5f9;
    color: #0a1d30 !important;
    text-decoration: none !important;
    border-radius: 6px;
    font-size: 12.5px;
    font-weight: 600;
    transition: all 0.15s ease;
}
.kisi-jats-toc-pill:hover {
    background: #0a1d30;
    color: #ffffff !important;
}
.kisi-jats-abstract-box {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 18px 20px;
    margin-bottom: 26px;
}
.kisi-jats-abstract-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 10px;
    margin-bottom: 14px;
}
.kisi-abs-tab {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 5px 12px;
    font-size: 12.5px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
}
.kisi-abs-tab.active {
    background: #0a1d30;
    color: #ffffff;
    border-color: #0a1d30;
}
.kisi-abs-subhead {
    margin: 10px 0 4px 0;
    font-size: 14px;
    font-weight: 700;
    color: #0a1d30;
}
.kisi-abs-p {
    margin: 0 0 10px 0;
    text-align: justify;
    font-size: 14.5px;
}
.kisi-abs-keywords {
    margin: 12px 0 0 0;
    padding-top: 8px;
    border-top: 1px dotted #cbd5e1;
    font-size: 13.5px;
}
.kisi-jats-section {
    margin-bottom: 28px;
}
.kisi-jats-h2 {
    font-size: 18px;
    font-weight: 800;
    color: #0a1d30;
    border-bottom: 2px solid #0a1d30;
    padding-bottom: 6px;
    margin: 26px 0 14px 0;
    letter-spacing: 0.02em;
}
.kisi-jats-h3 {
    font-size: 15.5px;
    font-weight: 700;
    color: #0f172a;
    margin: 18px 0 8px 0;
}
.kisi-jats-p {
    margin: 0 0 14px 0;
    text-align: justify;
    text-indent: 2em;
}
.kisi-jats-table-wrap {
    margin: 22px 0;
    background: #ffffff;
}
.kisi-jats-table-caption {
    font-size: 14px;
    color: #0f172a;
    margin-bottom: 8px;
}
.kisi-jats-table-scroll {
    overflow-x: auto;
}
table.kisi-jats-table {
    width: 100%;
    border-collapse: collapse;
    border-top: 2px solid #0a1d30;
    border-bottom: 2px solid #0a1d30;
    font-size: 13.5px;
    font-variant-numeric: tabular-nums;
}
table.kisi-jats-table thead {
    border-bottom: 1.5px solid #0a1d30;
}
table.kisi-jats-table th {
    padding: 8px 10px;
    font-weight: 700;
    background: #ffffff;
    color: #0a1d30;
    vertical-align: middle;
    border: none;
}
table.kisi-jats-table th.kisi-th-straddle {
    border-bottom: 1px solid #64748b;
}
table.kisi-jats-table td {
    border: none;
    padding: 6px 10px;
    vertical-align: middle;
}
table.kisi-jats-table .kisi-align-left { text-align: left; }
table.kisi-jats-table .kisi-align-center { text-align: center; }
table.kisi-jats-table .kisi-align-right { text-align: right; }
table.kisi-jats-table tr.kisi-row-group td,
table.kisi-jats-table td.kisi-cell-group {
    font-weight: 700;
    color: #0a1d30;
    padding-top: 10px;
}
table.kisi-jats-table tbody tr.kisi-row-group:not(:first-child) td {
    border-top: 1px solid #e2e8f0;
}
table.kisi-jats-table td.kisi-cell-subitem {
    padding-left: 24px;
}
table.kisi-jats-table tr.kisi-row-total td,
table.kisi-jats-table td.kisi-cell-total {
    font-weight: 700;
    border-top: 1px solid #475569;
}
.kisi-jats-table-foot {
    margin-top: 8px;
    font-size: 12.5px;
    color: #475569;
    line-height: 1.5;
    text-align: left;
}
.kisi-jats-table-foot p {
    margin: 0 0 4px 0;
}
.kisi-jats-ref-list {
    margin: 0;
    padding-left: 22px;
}
.kisi-jats-ref-item {
    margin-bottom: 10px;
    text-align: justify;
    font-size: 14px;
    line-height: 1.6;
}
.kisi-jats-publishers-note {
    margin-top: 32px;
    padding-top: 16px;
    border-top: 1px solid #cbd5e1;
    font-size: 13.5px;
    color: #334155;
}
.kisi-jats-pubnote-title {
    font-weight: 700;
    margin-bottom: 4px;
}
.kisi-jats-pubnote-p {
    margin: 0 0 12px 0;
}
.kisi-jats-retypeset-box {
    border: 1.5px solid #0f172a;
    background: #ffffff;
    padding: 12px 16px;
    font-size: 13.5px;
    color: #0f172a;
}
</style>
HTML;
    }
}
