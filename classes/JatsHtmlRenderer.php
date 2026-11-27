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
     * @param array $options Optional rendering flags ('include_abstract' => bool, 'form_language_mode' => 'auto'|'en'|'bilingual')
     * @return string Rendered HTML block
     */
    public static function renderFullTextHtml(string $xmlContent, array $options = []): string
    {
        $dom = self::loadJatsDom($xmlContent);
        if (!$dom) {
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
        $contribRolesText = self::getNodeText($xpath, '//article-meta/author-notes/fn[@fn-type="con"]/p');
        $conflictText = self::getNodeText($xpath, '//article-meta/author-notes/fn[@fn-type="conflict"]/p');
        $fundingText = self::getNodeText($xpath, '//article-meta/funding-group/funding-statement');
        $licenseText = self::getNodeText($xpath, '//article-meta/permissions/license/license-p');
        $copyrightStmt = self::getNodeText($xpath, '//article-meta/permissions/copyright-statement');

        $formatHistoryDate = static function (string $dateType) use ($xpath): string {
            $y = self::getNodeText($xpath, "//article-meta/history/date[@date-type=\"{$dateType}\"]/year");
            $m = self::getNodeText($xpath, "//article-meta/history/date[@date-type=\"{$dateType}\"]/month");
            $d = self::getNodeText($xpath, "//article-meta/history/date[@date-type=\"{$dateType}\"]/day");
            if ($y === '' || $m === '' || $d === '') {
                return '';
            }
            $ts = strtotime(sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d));
            return $ts ? date('F j, Y', $ts) : '';
        };
        $receivedDisp = $formatHistoryDate('received');
        $acceptedDisp = $formatHistoryDate('accepted');

        // Build TOC & Body Sections HTML
        $tocItems = [];
        $bodyHtmlParts = [];

        // Optional Structured Abstract Digest (always rendered cleanly for English-Only or Bilingual JATS)
        $includeAbstract = $options['include_abstract'] ?? true;
        if ($includeAbstract) {
            $absHtml = self::renderBilingualAbstracts($xpath, $options);
            $absTocLabel = (strtolower((string) ($options['form_language_mode'] ?? 'auto')) === 'en')
                ? 'Abstract'
                : 'Abstrak / Abstract';
            if ($absHtml === '') {
                // DATA REPORT / LETTER: no narrative abstract, but Keywords and TPB/SDGs stay mandatory
                $absHtml = self::renderKeywordSdgsSummary($xpath, $options);
                $absTocLabel = 'Keywords & SDGs';
            }
            if ($absHtml !== '') {
                $tocItems[] = ['id' => 'kisi-sec-abstract', 'title' => $absTocLabel];
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
                } elseif ($child->localName === 'fig' || $child->localName === 'figure') {
                    $directBodyBuffer .= self::renderFigureElement($child, $xpath);
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
        if ($receivedDisp !== '') {
            $metaDetails[] = '<strong>Received:</strong> ' . htmlspecialchars($receivedDisp, ENT_QUOTES, 'UTF-8');
        }
        if ($acceptedDisp !== '') {
            $metaDetails[] = '<strong>Accepted:</strong> ' . htmlspecialchars($acceptedDisp, ENT_QUOTES, 'UTF-8');
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
        if ($contribRolesText !== '') {
            $cleanRoles = (string) preg_replace('/^Contributor[’\']?s?\s+Roles\s*:\s*/iu', '', $contribRolesText);
            $metaDetails[] = '<strong>Contributor’s Roles:</strong> ' . htmlspecialchars($cleanRoles, ENT_QUOTES, 'UTF-8');
        }
        if (!empty($metaDetails)) {
            $metaBarHtml .= '<div class="kisi-jats-metabar-details">' . implode(' &nbsp;|&nbsp; ', $metaDetails) . '</div>';
        }
        $metaBarHtml .= '</div>';

        $css = self::getFullTextCss();
        $fullHtml = $css
            . '<div id="kisi-jats-fulltext-container" class="kisi-jats-digest-wrapper article-fulltext">'
            . $metaBarHtml
            . $tocHtml
            . '<div class="kisi-jats-article-body">'
            . implode("\n", $bodyHtmlParts)
            . '</div>'
            . '</div>';

        return $fullHtml;
    }

    /**
     * Robustly load external or generated JATS XML across namespaces, prefixes, BOM, and named HTML entities.
     */
    private static function loadJatsDom(string $xmlContent): ?DOMDocument
    {
        $clean = (string) preg_replace('/^\x{FEFF}+|\A(?:\xEF\xBB\xBF)+/u', '', $xmlContent);
        $clean = ltrim($clean, " \t\n\r\0\x0B");
        if ($clean === '') {
            return null;
        }

        // Strip unprefixed default xmlns declarations anywhere in the JATS tree (preserving xlink/mml prefixes)
        $clean = (string) preg_replace(
            '/(<(?:article|front|journal-meta|article-meta|abstract|trans-abstract|body|back|kwd-group|sec|table-wrap|ref-list|fn-group|sub-article)\b[^>]*?)\s+xmlns=(["\'])[^"\']*\2/iu',
            '$1',
            $clean
        );
        // Strip jats: namespace prefix if external converter produced <jats:article>, <jats:abstract>, etc.
        if (stripos($clean, '<jats:') !== false) {
            $clean = (string) preg_replace('/\s+xmlns:jats=(["\'])[^"\']*\1/iu', '', $clean);
            $clean = (string) preg_replace('/<(\/?)jats:([a-zA-Z0-9_-]+)/u', '<$1$2', $clean);
        }

        // Normalize common typography / HTML entities when LIBXML_NONET disables remote DTD fetch
        $entityMap = [
            '&nbsp;' => '&#160;', '&ensp;' => '&#8194;', '&emsp;' => '&#8195;', '&thinsp;' => '&#8201;',
            '&ndash;' => '&#8211;', '&mdash;' => '&#8212;', '&minus;' => '&#8722;',
            '&lsquo;' => '&#8216;', '&rsquo;' => '&#8217;', '&ldquo;' => '&#8220;', '&rdquo;' => '&#8221;',
            '&bull;' => '&#8226;', '&hellip;' => '&#8230;', '&prime;' => '&#8242;', '&Prime;' => '&#8243;',
            '&copy;' => '&#169;', '&reg;' => '&#174;', '&trade;' => '&#8482;',
            '&deg;' => '&#176;', '&plusmn;' => '&#177;', '&times;' => '&#215;', '&divide;' => '&#247;',
            '&micro;' => '&#181;', '&le;' => '&#8804;', '&ge;' => '&#8805;', '&ne;' => '&#8800;', '&asymp;' => '&#8776;',
            '&alpha;' => '&#945;', '&beta;' => '&#946;', '&gamma;' => '&#947;', '&delta;' => '&#948;',
            '&epsilon;' => '&#949;', '&mu;' => '&#956;', '&sigma;' => '&#963;', '&omega;' => '&#969;',
        ];
        $clean = strtr($clean, $entityMap);

        $dom = new DOMDocument('1.0', 'UTF-8');
        if (@$dom->loadXML($clean, LIBXML_NOENT | LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return $dom;
        }

        // Recovery pass: escape unescaped bare ampersands and retry with LIBXML_RECOVER
        $repaired = (string) preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#\d+;|#x[0-9a-fA-F]+;)/u', '&amp;', $clean);
        $dom = new DOMDocument('1.0', 'UTF-8');
        if (@$dom->loadXML($repaired, LIBXML_NOENT | LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return $dom;
        }

        return null;
    }

    /**
     * Heuristic check to distinguish Indonesian vs English prose for untagged <abstract> nodes.
     */
    private static function looksIndonesianText(string $text): bool
    {
        $idHits = preg_match_all('/\b(dan|yang|dengan|pada|untuk|dari|dalam|penelitian|metode|hasil|kesimpulan|saran|responden|ibu|bayi|kesehatan)\b/iu', $text);
        $enHits = preg_match_all('/\b(the|and|with|for|from|in|this|study|methods?|results?|conclusions?|recommendations?|patients?|were|was|among)\b/iu', $text);
        return (int) $idHits > (int) $enHits;
    }

    /**
     * Collect the best Indonesian and English <abstract>/<trans-abstract> DOM nodes and keywords.
     */
    private static function collectAbstractNodesAndKeywords(DOMXPath $xpath): array
    {
        $candidates = $xpath->query('//article-meta/abstract | //article-meta/trans-abstract');
        if (!$candidates || $candidates->length === 0) {
            $candidates = $xpath->query('//abstract | //trans-abstract');
        }

        $rootArticle = $xpath->query('/article')->item(0);
        $rootLang = ($rootArticle instanceof DOMElement)
            ? strtolower(trim($rootArticle->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: $rootArticle->getAttribute('xml:lang')))
            : '';

        $bestIdNode = null;
        $bestIdScore = -1;
        $bestEnNode = null;
        $bestEnScore = -1;

        if ($candidates && $candidates->length > 0) {
            foreach ($candidates as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                $txt = trim((string) $node->textContent);
                if ($txt === '') {
                    continue;
                }

                $absType = strtolower(trim($node->getAttribute('abstract-type')));
                $lang = strtolower(trim($node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: $node->getAttribute('xml:lang')));

                // Score favors main/structured abstracts over minor graphical/teaser snippets
                $score = strlen($txt);
                if ($absType === '' || in_array($absType, ['abstract', 'summary', 'author', 'short'], true)) {
                    $score += 10000;
                } elseif (in_array($absType, ['graphical', 'toc', 'teaser'], true)) {
                    $score -= 5000;
                }
                if ($xpath->query('.//sec | .//p', $node)->length > 0) {
                    $score += 1000;
                }

                $bucket = '';
                if ($lang !== '' && strpos($lang, 'en') === 0) {
                    $bucket = 'en';
                } elseif ($lang !== '' && (strpos($lang, 'id') === 0 || strpos($lang, 'in') === 0)) {
                    $bucket = 'id';
                } elseif ($node->localName === 'trans-abstract') {
                    $bucket = self::looksIndonesianText($txt) ? 'id' : 'en';
                } elseif (self::looksIndonesianText($txt)) {
                    $bucket = 'id';
                } elseif (strpos($rootLang, 'en') === 0) {
                    $bucket = 'en';
                } elseif (strpos($rootLang, 'id') === 0 || strpos($rootLang, 'in') === 0) {
                    $bucket = 'id';
                } else {
                    $bucket = 'en';
                }

                if ($bucket === 'en') {
                    if ($score > $bestEnScore) {
                        $bestEnScore = $score;
                        $bestEnNode = $node;
                    }
                } else {
                    if ($score > $bestIdScore) {
                        $bestIdScore = $score;
                        $bestIdNode = $node;
                    }
                }
            }
        }

        $kwId = [];
        foreach ($xpath->query('//article-meta/kwd-group[starts-with(@xml:lang,"id") or starts-with(@xml:lang,"in")]/kwd') as $k) {
            if (trim($k->textContent) !== '') {
                $kwId[] = trim($k->textContent);
            }
        }
        $kwEn = [];
        foreach ($xpath->query('//article-meta/kwd-group[starts-with(@xml:lang,"en")]/kwd') as $k) {
            if (trim($k->textContent) !== '') {
                $kwEn[] = trim($k->textContent);
            }
        }
        if (empty($kwId) && empty($kwEn)) {
            $untaggedKws = [];
            foreach ($xpath->query('//article-meta/kwd-group[not(@xml:lang)]/kwd | //kwd-group[not(@xml:lang)]/kwd') as $k) {
                if (trim($k->textContent) !== '') {
                    $untaggedKws[] = trim($k->textContent);
                }
            }
            if (!empty($untaggedKws)) {
                if ($bestEnNode && !$bestIdNode) {
                    $kwEn = $untaggedKws;
                } elseif (strpos($rootLang, 'en') === 0) {
                    $kwEn = $untaggedKws;
                } else {
                    $kwId = $untaggedKws;
                }
            }
        }

        return [
            'id_node' => $bestIdNode,
            'en_node' => $bestEnNode,
            'kw_id' => $kwId,
            'kw_en' => $kwEn,
            'root_lang' => $rootLang,
        ];
    }

    /**
     * Extract clean HTML & plain abstract strings keyed by locale ('en', 'id') from any JATS XML.
     */
    public static function extractAbstractTextsByLocale(string $xmlContent): array
    {
        $dom = self::loadJatsDom($xmlContent);
        if (!$dom) {
            return ['en' => ['html' => '', 'plain' => ''], 'id' => ['html' => '', 'plain' => '']];
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        $info = self::collectAbstractNodesAndKeywords($xpath);

        $extract = static function (?DOMElement $node, array $keywords, bool $isEn) use ($xpath): array {
            if (!$node) {
                return ['html' => '', 'plain' => ''];
            }
            $htmlBody = self::renderSingleAbstractBody($node, $xpath, [], $isEn);
            // Strip outer <em> wrapper if building clean OJS DB <p> abstract HTML while preserving structured labels
            $cleanBody = trim(preg_replace('/\s+/u', ' ', strip_tags($htmlBody)) ?? '');
            $paragraphs = [];
            $secs = $xpath->query('.//sec[not(@content-type="sdgs")]', $node);
            if ($secs && $secs->length > 0) {
                foreach ($secs as $s) {
                    $title = self::getNodeText($xpath, './title', $s);
                    $pTxt = [];
                    foreach ($xpath->query('./p | ./list/list-item/p | ./boxed-text//p | ./disp-quote//p', $s) as $p) {
                        $t = trim($p->textContent);
                        if ($t !== '') {
                            $pTxt[] = $t;
                        }
                    }
                    if (!empty($pTxt)) {
                        $prefix = $title !== '' ? ('<strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ':</strong> ') : '';
                        $paragraphs[] = '<p>' . $prefix . htmlspecialchars(implode(' ', $pTxt), ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                }
            }
            if (empty($paragraphs)) {
                foreach ($xpath->query('.//p', $node) as $p) {
                    if ($p->parentNode instanceof DOMElement && $p->parentNode->getAttribute('content-type') === 'sdgs') {
                        continue;
                    }
                    $t = trim($p->textContent);
                    if ($t !== '') {
                        $paragraphs[] = '<p>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                }
            }
            if (empty($paragraphs) && trim($node->textContent) !== '') {
                $paragraphs[] = '<p>' . htmlspecialchars(trim(preg_replace('/\s+/u', ' ', $node->textContent)), ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $html = implode('', $paragraphs);
            return [
                'html' => $html,
                'plain' => $cleanBody !== '' ? $cleanBody : trim(strip_tags($html)),
                'keywords' => $keywords,
            ];
        };

        return [
            'en' => $extract($info['en_node'], $info['kw_en'], true),
            'id' => $extract($info['id_node'], $info['kw_id'], false),
        ];
    }

    /**
     * Render Bilingual / English-Only Structured & Unstructured Abstracts (<abstract> & <trans-abstract>)
     */
    private static function renderBilingualAbstracts(DOMXPath $xpath, array $options = []): string
    {
        $collected = self::collectAbstractNodesAndKeywords($xpath);
        $absIdNode = $collected['id_node'];
        $absEnNode = $collected['en_node'];
        $kwId = $collected['kw_id'];
        $kwEn = $collected['kw_en'];

        $formLangMode = strtolower(trim((string) ($options['form_language_mode'] ?? 'auto')));

        $idInner = $absIdNode ? self::renderSingleAbstractBody($absIdNode, $xpath, $kwId, false) : '';
        $enInner = $absEnNode ? self::renderSingleAbstractBody($absEnNode, $xpath, $kwEn, true) : '';

        if ($idInner === '' && $enInner === '') {
            return '';
        }

        // Strict English-only mode: render only English abstract when English exists (keep ID only if EN is missing)
        if (($formLangMode === 'en' || !empty($options['english_only'])) && $enInner !== '') {
            $idInner = '';
        }

        $html = '<section id="kisi-sec-abstract" class="kisi-jats-section kisi-jats-abstract-box">';

        if ($idInner !== '' && $enInner !== '') {
            $preferEnFirst = ($formLangMode === 'en');
            $showIdFirst = !$preferEnFirst;
            $html .= '<div class="kisi-jats-abstract-tabs">';
            $html .= '<button type="button" class="kisi-abs-tab ' . ($showIdFirst ? 'active' : '') . '" onclick="kisiSwitchAbsLang(\'id\', this)">🇮🇩 ABSTRAK (Indonesia)</button>';
            $html .= '<button type="button" class="kisi-abs-tab ' . (!$showIdFirst ? 'active' : '') . '" onclick="kisiSwitchAbsLang(\'en\', this)">🇬🇧 ABSTRACT (English)</button>';
            $html .= '<button type="button" class="kisi-abs-tab" onclick="kisiSwitchAbsLang(\'both\', this)">🔀 Tampilkan Keduanya</button>';
            $html .= '</div>';
            $html .= '<div id="kisi-abs-pane-id" class="kisi-abs-pane" style="display:' . ($showIdFirst ? 'block' : 'none') . ';">' . $idInner . '</div>';
            $html .= '<div id="kisi-abs-pane-en" class="kisi-abs-pane kisi-abs-en" style="display:' . ($showIdFirst ? 'none' : 'block') . ';">' . $enInner . '</div>';
            $html .= <<<JS
<script>
window.kisiSwitchAbsLang = function(mode, btn) {
    var container = btn.closest('.kisi-jats-abstract-box');
    if (container) {
        container.querySelectorAll('.kisi-abs-tab').forEach(function(b) { b.classList.remove('active'); });
    }
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
};
</script>
JS;
        } elseif ($enInner !== '') {
            $html .= '<div class="kisi-jats-abstract-single-head">ABSTRACT</div>';
            $html .= '<div id="kisi-abs-pane-en" class="kisi-abs-pane kisi-abs-en" style="display:block;">' . $enInner . '</div>';
        } else {
            $html .= '<div class="kisi-jats-abstract-single-head">ABSTRAK</div>';
            $html .= '<div id="kisi-abs-pane-id" class="kisi-abs-pane" style="display:block;">' . $idInner . '</div>';
        }

        $html .= '</section>';
        return $html;
    }

    /**
     * Render the mandatory Keywords + TPB/SDGs block for factual categories (DATA REPORT / LETTER)
     * that carry no narrative abstract.
     */
    private static function renderKeywordSdgsSummary(DOMXPath $xpath, array $options = []): string
    {
        $collected = self::collectAbstractNodesAndKeywords($xpath);
        $kwId = array_values(array_filter($collected['kw_id'], static fn ($k) => trim((string) $k) !== ''));
        $kwEn = array_values(array_filter($collected['kw_en'], static fn ($k) => trim((string) $k) !== ''));

        $sdgsId = '';
        $sdgsEn = '';
        foreach ($xpath->query('//article-meta/custom-meta-group/custom-meta') as $meta) {
            if (!($meta instanceof DOMElement)) {
                continue;
            }
            $name = strtoupper(trim(self::getNodeText($xpath, './meta-name', $meta)));
            $value = trim(self::getNodeText($xpath, './meta-value', $meta));
            if ($value === '') {
                continue;
            }
            if (strpos($name, '(EN)') !== false) {
                if ($sdgsEn === '') {
                    $sdgsEn = $value;
                }
            } elseif ($sdgsId === '') {
                $sdgsId = $value;
            }
        }

        if (empty($kwId) && empty($kwEn) && $sdgsId === '' && $sdgsEn === '') {
            return '';
        }

        $formLangMode = strtolower(trim((string) ($options['form_language_mode'] ?? 'auto')));
        $englishOnly = ($formLangMode === 'en' || !empty($options['english_only']));

        $html = '<section id="kisi-sec-abstract" class="kisi-jats-section kisi-jats-abstract-box">';
        if ($englishOnly && !empty($kwEn)) {
            $html .= '<p class="kisi-abs-keywords"><strong>Keywords:</strong> ' . htmlspecialchars(implode('; ', $kwEn) . '.', ENT_QUOTES, 'UTF-8') . '</p>';
        } elseif (!empty($kwId) || !empty($kwEn)) {
            if (!empty($kwId)) {
                $html .= '<p class="kisi-abs-keywords"><strong>Kata Kunci:</strong> ' . htmlspecialchars(implode('; ', $kwId) . '.', ENT_QUOTES, 'UTF-8') . '</p>';
            }
            if (!empty($kwEn)) {
                $html .= '<p class="kisi-abs-keywords"><em><strong>Keywords:</strong> ' . htmlspecialchars(implode('; ', $kwEn) . '.', ENT_QUOTES, 'UTF-8') . '</em></p>';
            }
        }

        $appendSdgs = static function (string $title, string $body, bool $isEnglish) use (&$html): void {
            if ($body === '') {
                return;
            }
            if (!$isEnglish) {
                $html .= '<div style="margin-top:10px;padding:10px 12px;background:#eef2f7;border-radius:6px;">';
            }
            $html .= '<h4 class="kisi-abs-subhead"' . (!$isEnglish ? ' style="margin-top:0;"' : '') . '>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>';
            $bodyHtml = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
            $html .= '<p class="kisi-abs-p"' . (!$isEnglish ? ' style="margin-bottom:0;"' : '') . '>' . ($isEnglish ? '<em>' . $bodyHtml . '</em>' : $bodyHtml) . '</p>';
            if (!$isEnglish) {
                $html .= '</div>';
            }
        };
        $appendSdgs('Kontribusi terhadap Target Pembangunan Berkelanjutan (TPB/SDGs)', $sdgsId, false);
        $appendSdgs('Contribution to Sustainable Development Goals (SDGs)', $sdgsEn, true);

        $html .= '</section>';
        return $html;
    }

    /**
     * Recursively render child structures inside an abstract/sec node (<sec>, <p>, <list>, <disp-quote>, <boxed-text>).
     */
    private static function renderAbstractChildNodes(DOMElement $container, DOMXPath $xpath, bool $isEnglish): string
    {
        $out = '';
        $wrap = static fn (string $txt): string => $isEnglish ? ('<em>' . $txt . '</em>') : $txt;

        foreach ($container->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $ln = $child->localName;
            if ($ln === 'sec') {
                if ($child->getAttribute('content-type') === 'sdgs') {
                    continue;
                }
                $st = self::getNodeText($xpath, './title', $child);
                if ($st !== '') {
                    $out .= '<h4 class="kisi-abs-subhead">' . $wrap(htmlspecialchars($st, ENT_QUOTES, 'UTF-8')) . '</h4>';
                }
                $out .= self::renderAbstractChildNodes($child, $xpath, $isEnglish);
            } elseif ($ln === 'p') {
                $pHtml = self::renderInlineContent($child);
                if (trim(strip_tags($pHtml)) !== '') {
                    $out .= '<p class="kisi-abs-p">' . $wrap($pHtml) . '</p>';
                }
            } elseif ($ln === 'list') {
                $listTag = (strtolower($child->getAttribute('list-type')) === 'order') ? 'ol' : 'ul';
                $itemsHtml = '';
                foreach ($xpath->query('./list-item', $child) as $li) {
                    $liTxt = self::renderInlineContent($li);
                    if (trim(strip_tags($liTxt)) !== '') {
                        $itemsHtml .= '<li>' . $wrap($liTxt) . '</li>';
                    }
                }
                if ($itemsHtml !== '') {
                    $out .= '<' . $listTag . ' class="kisi-abs-list">' . $itemsHtml . '</' . $listTag . '>';
                }
            } elseif ($ln === 'boxed-text' || $ln === 'disp-quote') {
                $out .= self::renderAbstractChildNodes($child, $xpath, $isEnglish);
            }
        }

        return $out;
    }

    /**
     * Render paragraphs, structured <sec>, <list>, and SDGs inside one <abstract>/<trans-abstract> element.
     */
    private static function renderSingleAbstractBody(DOMElement $absNode, DOMXPath $xpath, array $keywords, bool $isEnglish): string
    {
        $wrap = static fn (string $txt): string => $isEnglish ? ('<em>' . $txt . '</em>') : $txt;
        $out = self::renderAbstractChildNodes($absNode, $xpath, $isEnglish);

        // Fallback if <abstract> wrapped text inside deeply non-standard elements
        if (trim(strip_tags($out)) === '') {
            $out = '';
            foreach ($xpath->query('.//p', $absNode) as $p) {
                if ($p->parentNode instanceof DOMElement && $p->parentNode->getAttribute('content-type') === 'sdgs') {
                    continue;
                }
                $pHtml = self::renderInlineContent($p);
                if (trim(strip_tags($pHtml)) !== '') {
                    $out .= '<p class="kisi-abs-p">' . $wrap($pHtml) . '</p>';
                }
            }
        }

        if (trim(strip_tags($out)) === '') {
            $plain = trim($absNode->textContent);
            if ($plain !== '') {
                $out .= '<p class="kisi-abs-p">' . $wrap(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
        }

        if (!empty($keywords)) {
            $kwLabel = $isEnglish ? 'Keywords:' : 'Kata Kunci:';
            $kwLine = '<strong>' . $kwLabel . '</strong> ' . htmlspecialchars(implode('; ', $keywords) . '.', ENT_QUOTES, 'UTF-8');
            $out .= '<p class="kisi-abs-keywords">' . $wrap($kwLine) . '</p>';
        }

        $sdgSec = $xpath->query('.//sec[@content-type="sdgs"]', $absNode)->item(0);
        if ($sdgSec instanceof DOMElement) {
            $st = self::getNodeText($xpath, './title', $sdgSec);
            $sp = '';
            foreach ($xpath->query('.//p', $sdgSec) as $spNode) {
                $sp .= self::renderInlineContent($spNode) . ' ';
            }
            $sp = trim($sp);
            if ($st !== '' || $sp !== '') {
                if (!$isEnglish) {
                    $out .= '<div style="margin-top:10px;padding:10px 12px;background:#eef2f7;border-radius:6px;">';
                }
                if ($st !== '') {
                    $out .= '<h4 class="kisi-abs-subhead"' . (!$isEnglish ? ' style="margin-top:0;"' : '') . '>' . $wrap(htmlspecialchars($st, ENT_QUOTES, 'UTF-8')) . '</h4>';
                }
                if ($sp !== '') {
                    $out .= '<p class="kisi-abs-p"' . (!$isEnglish ? ' style="margin-bottom:0;"' : '') . '>' . $wrap($sp) . '</p>';
                }
                if (!$isEnglish) {
                    $out .= '</div>';
                }
            }
        }

        return $out;
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
                    $hClass = ($headingLevel === 2) ? 'kisi-jats-h2 article-section-title' : 'kisi-jats-h3 article-subsection-title';
                    $html .= '<' . $hTag . ' class="' . $hClass . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</' . $hTag . '>';
                }
            } elseif ($child->localName === 'p') {
                $pHtml = self::renderInlineContent($child);
                if (trim($pHtml) !== '') {
                    $html .= '<p class="kisi-jats-p">' . $pHtml . '</p>';
                }
            } elseif ($child->localName === 'table-wrap') {
                $html .= self::renderTableWrap($child, $xpath);
            } elseif ($child->localName === 'fig' || $child->localName === 'figure') {
                $html .= self::renderFigureElement($child, $xpath);
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

    /**
     * Render a JATS <fig> element (label, caption, graphic) into a responsive HTML <figure> with <img>
     */
    private static function renderFigureElement(DOMElement $figNode, DOMXPath $xpath): string
    {
        $label = self::getNodeText($xpath, './label', $figNode);
        $captionTitle = self::getNodeText($xpath, './caption/title', $figNode)
            ?: self::getNodeText($xpath, './caption/p', $figNode);
        $altText = self::getNodeText($xpath, './alt-text', $figNode);

        $graphics = $xpath->query('./graphic', $figNode);
        if ($graphics->length === 0) {
            $graphics = $xpath->query('./media', $figNode);
        }
        if ($graphics->length === 0) {
            return '';
        }

        $alt = $altText !== '' ? $altText : ($captionTitle !== '' ? $captionTitle : $label);

        $html = '<figure class="kisi-jats-figure">';
        foreach ($graphics as $graphic) {
            if (!($graphic instanceof DOMElement)) {
                continue;
            }
            $href = $graphic->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                ?: $graphic->getAttribute('xlink:href');
            if (trim($href) === '') {
                continue;
            }
            $html .= '<img src="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" alt="'
                . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" loading="lazy">';
        }
        if ($label !== '' || $captionTitle !== '') {
            $html .= '<figcaption class="kisi-jats-figure-caption">';
            if ($label !== '') {
                $html .= '<strong>' . htmlspecialchars(rtrim($label, '.') . '.', ENT_QUOTES, 'UTF-8') . '</strong> ';
            }
            if ($captionTitle !== '' && strcasecmp($captionTitle, $label) !== 0) {
                $html .= htmlspecialchars($captionTitle, ENT_QUOTES, 'UTF-8');
            }
            $html .= '</figcaption>';
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
        $html .= '<h2 class="kisi-jats-h2 article-section-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
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
                    $refType = $child->getAttribute('ref-type');
                    $targetPrefix = ($refType === 'bibr' || strpos($rid, 'B') === 0) ? '#kisi-ref-' : '#kisi-';
                    $clsAttr = ($refType === 'bibr' || strpos($rid, 'B') === 0) ? ' class="kisi-jats-cite"' : '';
                    $out .= '<a href="' . $targetPrefix . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '"' . $clsAttr . '>' . $inner . '</a>';
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
     * Render a standalone responsive HTML5 Full-Text Reader document when viewing an XML Galley directly (/article/view/{id}/{galleyId}).
     */
    public static function renderStandaloneReaderPage(string $xmlContent, array $meta = []): string
    {
        $dom = self::loadJatsDom($xmlContent);
        if (!$dom) {
            return '';
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

        $titleEn = self::getNodeText($xpath, '//article-meta/title-group/trans-title-group[@xml:lang="en"]/trans-title')
            ?: self::getNodeText($xpath, '//article-meta/title-group/article-title');
        $titleId = self::getNodeText($xpath, '//article-meta/title-group/article-title');
        $formLangMode = strtolower((string) ($meta['form_language_mode'] ?? 'en'));
        $displayTitle = ($formLangMode === 'en' && $titleEn !== '') ? $titleEn : ($titleEn !== '' ? $titleEn : $titleId);
        if ($displayTitle === '' && !empty($meta['title'])) {
            $displayTitle = (string) $meta['title'];
        }

        $journalTitle = self::getNodeText($xpath, '//journal-meta/journal-title-group/journal-title')
            ?: (string) ($meta['journal_title'] ?? 'Kisi Berkelanjutan: Sains Medis Dan Kesehatan');
        $doi = self::getNodeText($xpath, '//article-meta/article-id[@pub-id-type="doi"]');

        $authors = [];
        foreach ($xpath->query('//article-meta/contrib-group[not(@content-type="editor")]/contrib[@contrib-type="author"]') as $c) {
            $surname = self::getNodeText($xpath, './name/surname', $c);
            $given = self::getNodeText($xpath, './name/given-names', $c);
            $nameStr = self::getNodeText($xpath, './string-name', $c) ?: trim($given . ' ' . $surname);
            if ($nameStr !== '') {
                $authors[] = $nameStr;
            }
        }
        $authorLine = !empty($authors) ? implode(', ', $authors) : (string) ($meta['authors'] ?? '');

        $digestHtml = self::renderFullTextHtml($xmlContent, [
            'include_abstract' => true,
            'form_language_mode' => $meta['form_language_mode'] ?? 'en',
        ]);
        if ($digestHtml === '') {
            return '';
        }

        $articleUrl = htmlspecialchars((string) ($meta['article_url'] ?? 'javascript:history.back()'), ENT_QUOTES, 'UTF-8');
        $escapedDocTitle = htmlspecialchars($displayTitle ?: 'Full-Text Article', ENT_QUOTES, 'UTF-8');
        $escapedJournal = htmlspecialchars($journalTitle, ENT_QUOTES, 'UTF-8');
        $escapedAuthors = htmlspecialchars($authorLine, ENT_QUOTES, 'UTF-8');
        $doiBadge = $doi !== '' ? ('<div style="margin-top:8px;font-size:13px;color:#0369a1;">DOI: <a href="https://doi.org/' . htmlspecialchars($doi, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">https://doi.org/' . htmlspecialchars($doi, ENT_QUOTES, 'UTF-8') . '</a></div>') : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$escapedDocTitle} — {$escapedJournal}</title>
    <style>
        body { margin: 0; background: #f1f5f9; color: #0f172a; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Georgia, sans-serif; }
        .kisi-reader-topbar { background: #0a1d30; color: #ffffff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .kisi-reader-back { background: rgba(255,255,255,0.14); color: #ffffff; text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .kisi-reader-back:hover { background: rgba(255,255,255,0.24); }
        .kisi-reader-main { max-width: 960px; margin: 24px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px 36px; box-shadow: 0 6px 24px rgba(15,23,42,0.06); }
        .kisi-reader-title { margin: 0 0 12px 0; font-size: 25px; line-height: 1.3; color: #0a1d30; }
        .kisi-reader-authors { font-size: 15px; color: #334155; font-weight: 500; }
        @media (max-width: 640px) { .kisi-reader-main { margin: 0; border-radius: 0; padding: 18px 16px; } }
    </style>
</head>
<body>
    <header class="kisi-reader-topbar">
        <div style="font-weight:700;font-size:14px;">{$escapedJournal} · Full-Text XML Reader</div>
        <a href="{$articleUrl}" class="kisi-reader-back">← Back to Article</a>
    </header>
    <main class="kisi-reader-main">
        <h1 class="kisi-reader-title">{$escapedDocTitle}</h1>
        <div class="kisi-reader-authors">{$escapedAuthors}</div>
        {$doiBadge}
        {$digestHtml}
    </main>
</body>
</html>
HTML;
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
.kisi-jats-abstract-single-head {
    font-size: 14px;
    font-weight: 800;
    color: #0a1d30;
    letter-spacing: 0.04em;
    border-bottom: 1px solid #cbd5e1;
    padding-bottom: 6px;
    margin-bottom: 12px;
}
.kisi-abs-list {
    margin: 4px 0 10px 20px;
    padding-left: 14px;
    font-size: 14.5px;
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
.kisi-jats-figure {
    margin: 24px 0;
    text-align: center;
}
.kisi-jats-figure img {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
    border-radius: 4px;
}
.kisi-jats-figure-caption {
    margin-top: 8px;
    font-size: 13.5px;
    line-height: 1.5;
    color: #0f172a;
    text-align: center;
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
    padding-left: 24px;
    list-style-type: decimal;
}
.kisi-jats-cite {
    color: #0369a1;
    font-weight: 600;
    text-decoration: none;
}
.kisi-jats-cite:hover {
    text-decoration: underline;
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
