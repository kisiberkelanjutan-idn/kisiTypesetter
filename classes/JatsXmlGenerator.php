<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/JatsXmlGenerator.php
 *
 * Generates a standards-compliant JATS 1.2 / JATS4R / AmeliCA XML document
 * compatible with OJS 3.5 eLife Lens Viewer, JATS Parser Plugin, PubMed Central,
 * DOAJ, and Redalyc/AmeliCA specifications.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

require_once __DIR__ . '/HealthTableFormatter.php';
require_once __DIR__ . '/LanguageAuditor.php';
require_once __DIR__ . '/VancouverFormatter.php';

use DOMDocument;
use DOMElement;

class JatsXmlGenerator
{
    public const NS_XLINK = 'http://www.w3.org/1999/xlink';
    public const NS_MML = 'http://www.w3.org/1998/Math/MathML';

    /**
     * Generate JATS 1.2 XML string from structured article data
     */
    public static function generate(array $data): string
    {
        $data = LanguageAuditor::normalizeArticleParagraphSpaces($data);

        $imp = new \DOMImplementation();
        $dtd = $imp->createDocumentType(
            'article',
            '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN',
            'https://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd'
        );

        $dom = $imp->createDocument('', '', $dtd);
        $dom->encoding = 'UTF-8';
        $dom->formatOutput = true;

        $isEnglishOnly = LanguageAuditor::isEnglishOnlyArticle($data);

        $article = $dom->createElement('article');
        $article->setAttribute('article-type', self::mapJatsArticleType((string) ($data['article_type'] ?? 'ORIGINAL ARTICLE')));
        $article->setAttribute('dtd-version', '1.2');
        $article->setAttribute('xml:lang', $isEnglishOnly ? 'en' : 'id');
        $article->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xlink', self::NS_XLINK);
        $article->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:mml', self::NS_MML);
        $dom->appendChild($article);

        // 1. <front>
        $front = $dom->createElement('front');
        $article->appendChild($front);
        self::buildJournalMeta($dom, $front, $data);
        self::buildArticleMeta($dom, $front, $data);

        // 2. <body>
        $body = $dom->createElement('body');
        $article->appendChild($body);
        self::buildBody($dom, $body, $data);

        // 3. <back>
        $back = $dom->createElement('back');
        $article->appendChild($back);
        self::buildBack($dom, $back, $data);

        return $dom->saveXML();
    }

    /**
     * Build <journal-meta>
     */
    private static function buildJournalMeta(DOMDocument $dom, DOMElement $front, array $data): void
    {
        $jm = $dom->createElement('journal-meta');
        $front->appendChild($jm);

        $jid = $dom->createElement('journal-id', $data['journal_path'] ?? 'smk');
        $jid->setAttribute('journal-id-type', 'publisher-id');
        $jm->appendChild($jid);

        $jtg = $dom->createElement('journal-title-group');
        $jt = $dom->createElement('journal-title', $data['journal_title'] ?? 'Kisi Berkelanjutan: Sains Medis dan Kesehatan');
        $jtg->appendChild($jt);
        $ajt = $dom->createElement('abbrev-journal-title', $data['journal_short_footer'] ?? 'Kisi Berkelanjutan: SMK');
        $jtg->appendChild($ajt);
        $jm->appendChild($jtg);

        $issn = $dom->createElement('issn', $data['eissn'] ?? '3062-9063');
        $issn->setAttribute('pub-type', 'epub');
        $jm->appendChild($issn);

        $pub = $dom->createElement('publisher');
        $pubName = $dom->createElement('publisher-name', $data['publisher_name'] ?? 'PT Karya Inovasi Berkelanjutan');
        $pubLoc = $dom->createElement('publisher-loc', 'Kendari, Sulawesi Tenggara, Indonesia');
        $pub->appendChild($pubName);
        $pub->appendChild($pubLoc);
        $jm->appendChild($pub);
    }

    /**
     * Build <article-meta>
     */
    private static function buildArticleMeta(DOMDocument $dom, DOMElement $front, array $data): void
    {
        $am = $dom->createElement('article-meta');
        $front->appendChild($am);

        $subId = (string) ($data['submission_id'] ?? '76');
        $aidPub = $dom->createElement('article-id', $subId);
        $aidPub->setAttribute('pub-id-type', 'publisher-id');
        $am->appendChild($aidPub);

        $doi = trim($data['doi'] ?? '');
        if ($doi !== '') {
            $cleanDoi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
            $aidDoi = $dom->createElement('article-id', $cleanDoi);
            $aidDoi->setAttribute('pub-id-type', 'doi');
            $am->appendChild($aidDoi);
        }

        // Article Categories
        $ac = $dom->createElement('article-categories');
        $sg = $dom->createElement('subj-group');
        $sg->setAttribute('subj-group-type', 'heading');
        $sg->appendChild($dom->createElement('subject', $data['article_type'] ?? 'ORIGINAL ARTICLE'));
        $ac->appendChild($sg);
        $am->appendChild($ac);

        // Title Group (Single English Title when English-only; Bilingual ID + EN otherwise)
        $isEnglishOnly = LanguageAuditor::isEnglishOnlyArticle($data);
        $titleId = trim((string) ($data['title_id'] ?? ''));
        $titleEn = trim((string) ($data['title_en'] ?? ''));
        $tg = $dom->createElement('title-group');
        if ($isEnglishOnly || ($titleId === '' && $titleEn !== '')) {
            $tg->appendChild($dom->createElement('article-title', $titleEn !== '' ? $titleEn : $titleId));
        } else {
            $tg->appendChild($dom->createElement('article-title', $titleId));
            if ($titleEn !== '') {
                $ttg = $dom->createElement('trans-title-group');
                $ttg->setAttribute('xml:lang', 'en');
                $ttg->appendChild($dom->createElement('trans-title', $titleEn));
                $tg->appendChild($ttg);
            }
        }
        $am->appendChild($tg);

        // Authors & Deduplicated Affiliations
        $authors = $data['authors'] ?? [];
        $uniqueAffs = [];
        $authorAffIndices = [];
        foreach ($authors as $idx => $a) {
            $aff = trim($a['affiliation'] ?? 'Indonesia');
            if ($aff === '') {
                $aff = 'Indonesia';
            }
            $pos = array_search($aff, $uniqueAffs, true);
            if ($pos === false) {
                $uniqueAffs[] = $aff;
                $pos = count($uniqueAffs) - 1;
            }
            $authorAffIndices[$idx] = $pos + 1;
        }

        $cg = $dom->createElement('contrib-group');
        $cg->setAttribute('content-type', 'authors');
        foreach ($authors as $idx => $a) {
            $contrib = $dom->createElement('contrib');
            $contrib->setAttribute('contrib-type', 'author');
            $isCorr = !empty($a['is_corresponding']);
            if ($isCorr) {
                $contrib->setAttribute('corresp', 'yes');
            }

            [$given, $surname] = self::splitPersonName($a['name'] ?? 'Author');
            $nameEl = $dom->createElement('name');
            $nameEl->appendChild($dom->createElement('surname', $surname));
            if ($given !== '') {
                $nameEl->appendChild($dom->createElement('given-names', $given));
            }
            $contrib->appendChild($nameEl);

            $affIdx = $authorAffIndices[$idx] ?? 1;
            $xrefAff = $dom->createElement('xref', (string) $affIdx);
            $xrefAff->setAttribute('ref-type', 'aff');
            $xrefAff->setAttribute('rid', 'aff' . $affIdx);
            $contrib->appendChild($xrefAff);

            if ($isCorr) {
                $xrefCor = $dom->createElement('xref', '*');
                $xrefCor->setAttribute('ref-type', 'corresp');
                $xrefCor->setAttribute('rid', 'cor1');
                $contrib->appendChild($xrefCor);
            }

            $cg->appendChild($contrib);
        }
        $am->appendChild($cg);

        // Review Editor (from OJS Metadata > Subjects)
        $reviewEditor = trim($data['review_editor'] ?? '');
        if ($reviewEditor !== '') {
            $cgEd = $dom->createElement('contrib-group');
            $cgEd->setAttribute('content-type', 'editor');
            $contribEd = $dom->createElement('contrib');
            $contribEd->setAttribute('contrib-type', 'editor');

            $edNameStr = $reviewEditor;
            $edAffStr = '';
            if (preg_match('/^([^\(]+)\(([^\)]+)\)/u', $reviewEditor, $m)) {
                $edNameStr = trim($m[1]);
                $edAffStr = trim($m[2]);
            }

            [$edGiven, $edSurname] = self::splitPersonName($edNameStr);
            $nameEd = $dom->createElement('name');
            $nameEd->appendChild($dom->createElement('surname', $edSurname));
            if ($edGiven !== '') {
                $nameEd->appendChild($dom->createElement('given-names', $edGiven));
            }
            $contribEd->appendChild($nameEd);
            $contribEd->appendChild($dom->createElement('role', 'Review Editor'));

            if ($edAffStr !== '') {
                $affEd = $dom->createElement('aff');
                $affEd->appendChild($dom->createElement('institution', $edAffStr));
                $contribEd->appendChild($affEd);
            }
            $cgEd->appendChild($contribEd);
            $am->appendChild($cgEd);
        }

        // Affiliation elements
        foreach ($uniqueAffs as $i => $affText) {
            $affNum = $i + 1;
            $affEl = $dom->createElement('aff');
            $affEl->setAttribute('id', 'aff' . $affNum);
            $affEl->appendChild($dom->createElement('label', (string) $affNum));
            $affEl->appendChild($dom->createElement('institution', $affText));
            $am->appendChild($affEl);
        }

        // Author Notes (Corresponding & Competing Interests)
        $an = $dom->createElement('author-notes');
        $corrName = trim($data['corresponding_name'] ?? '');
        $corrEmail = trim($data['corresponding_email'] ?? '');
        $corresp = $dom->createElement('corresp');
        $corresp->setAttribute('id', 'cor1');
        $correspLabel = $isEnglishOnly ? '* Corresponding author: ' : '* Penulis koresponden: ';
        $corresp->appendChild($dom->createTextNode($correspLabel . $corrName . ($corrEmail !== '' ? ' (' : '')));
        if ($corrEmail !== '') {
            $corresp->appendChild($dom->createElement('email', $corrEmail));
            $corresp->appendChild($dom->createTextNode(')'));
        }
        $an->appendChild($corresp);

        $isVol3Plus = LanguageAuditor::isVolume3Plus($data['volume'] ?? '1');
        $contributorRoles = trim((string) ($data['contributor_roles'] ?? ''));
        if ($isVol3Plus && $contributorRoles === '') {
            $contributorRoles = LanguageAuditor::buildDefaultContributorRoles($authors);
        }
        if ($isVol3Plus && $contributorRoles !== '') {
            $fnContrib = $dom->createElement('fn');
            $fnContrib->setAttribute('fn-type', 'con');
            $fnContrib->appendChild($dom->createElement('p', "Contributor\u{2019}s Roles: " . $contributorRoles));
            $an->appendChild($fnContrib);
        }

        $competing = trim($data['competing_interests'] ?? 'The authors have declared that no competing interests exist.');
        $fnConflict = $dom->createElement('fn');
        $fnConflict->setAttribute('fn-type', 'conflict');
        $fnConflict->appendChild($dom->createElement('p', $competing));
        $an->appendChild($fnConflict);
        $am->appendChild($an);

        // Publication Date
        $ts = strtotime($data['published_date'] ?? '') ?: time();
        $pubDate = $dom->createElement('pub-date');
        $pubDate->setAttribute('date-type', 'pub');
        $pubDate->setAttribute('publication-format', 'electronic');
        $pubDate->appendChild($dom->createElement('day', date('d', $ts)));
        $pubDate->appendChild($dom->createElement('month', date('m', $ts)));
        $pubDate->appendChild($dom->createElement('year', date('Y', $ts)));
        $am->appendChild($pubDate);

        // Volume, Issue, Elocation-id
        $am->appendChild($dom->createElement('volume', (string) ($data['volume'] ?? '1')));
        $am->appendChild($dom->createElement('issue', (string) ($data['issue_number'] ?? '1')));
        $am->appendChild($dom->createElement('elocation-id', (string) ($data['elocation_id'] ?? ('e' . $subId))));

        // History dates (Received / Accepted for Volume 3+)
        $receivedRaw = trim((string) ($data['received_date'] ?? ''));
        $acceptedRaw = trim((string) ($data['accepted_date'] ?? ''));
        $recTs = ($receivedRaw !== '') ? strtotime($receivedRaw) : false;
        $accTs = ($acceptedRaw !== '') ? strtotime($acceptedRaw) : false;
        if ($recTs || $accTs) {
            $hist = $dom->createElement('history');
            if ($recTs) {
                $dRec = $dom->createElement('date');
                $dRec->setAttribute('date-type', 'received');
                $dRec->appendChild($dom->createElement('day', date('d', $recTs)));
                $dRec->appendChild($dom->createElement('month', date('m', $recTs)));
                $dRec->appendChild($dom->createElement('year', date('Y', $recTs)));
                $hist->appendChild($dRec);
            }
            if ($accTs) {
                $dAcc = $dom->createElement('date');
                $dAcc->setAttribute('date-type', 'accepted');
                $dAcc->appendChild($dom->createElement('day', date('d', $accTs)));
                $dAcc->appendChild($dom->createElement('month', date('m', $accTs)));
                $dAcc->appendChild($dom->createElement('year', date('Y', $accTs)));
                $hist->appendChild($dAcc);
            }
            $am->appendChild($hist);
        }

        // Permissions (CC BY-SA 4.0)
        $year = (string) ($data['copyright_year'] ?? date('Y', $ts));
        $firstAuthorLast = DocxGenerator::extractFirstAuthorLastName($authors);
        $perms = $dom->createElement('permissions');
        $perms->appendChild($dom->createElement('copyright-statement', '© ' . $year . ' ' . $firstAuthorLast . ' et al.'));
        $perms->appendChild($dom->createElement('copyright-year', $year));
        $perms->appendChild($dom->createElement('copyright-holder', $firstAuthorLast . ' et al.'));

        $lic = $dom->createElement('license');
        $lic->setAttribute('license-type', 'open-access');
        $lic->setAttributeNS(self::NS_XLINK, 'xlink:href', 'https://creativecommons.org/licenses/by-sa/4.0/');
        $lic->appendChild($dom->createElement(
            'license-p',
            'This is an open access article distributed under the terms of the Attribution-ShareAlike 4.0 International license (CC BY-SA 4.0), thus anyone, anywhere has the same opportunity to explore the knowledge and enhance opportunities for scientific discussion.'
        ));
        $perms->appendChild($lic);
        $am->appendChild($perms);

        // Self-URI
        if (!empty($data['article_url'])) {
            $selfUri = $dom->createElement('self-uri');
            $selfUri->setAttribute('content-type', 'html');
            $selfUri->setAttributeNS(self::NS_XLINK, 'xlink:href', $data['article_url']);
            $am->appendChild($selfUri);
        }

        $noAbstract = LanguageAuditor::isNoAbstractArticleType((string) ($data['article_type'] ?? 'ORIGINAL ARTICLE'));

        // The narrative abstract is omitted for DATA REPORT / LETTER, but Keywords and the TPB/SDGs
        // contribution statement remain mandatory for every category.
        if (!$noAbstract) {
            // Structured Abstract (Indonesian)
            $idParts = [
                'Pendahuluan dan Metode' => trim($data['abstract_id']['pendahuluan_metode'] ?? ''),
                'Hasil' => trim($data['abstract_id']['hasil'] ?? ''),
                'Kesimpulan dan Saran' => trim($data['abstract_id']['kesimpulan_saran'] ?? ''),
            ];
            $hasIdAbs = false;
            foreach ($idParts as $v) {
                if ($v !== '') {
                    $hasIdAbs = true;
                    break;
                }
            }
            if ($hasIdAbs && !$isEnglishOnly) {
                $absId = $dom->createElement('abstract');
                $absId->setAttribute('xml:lang', 'id');
                foreach ($idParts as $secTitle => $secBody) {
                    if ($secBody === '') {
                        continue;
                    }
                    $sec = $dom->createElement('sec');
                    $sec->appendChild($dom->createElement('title', $secTitle));
                    $sec->appendChild($dom->createElement('p', $secBody));
                    $absId->appendChild($sec);
                }
                $sdgsId = trim((string) ($data['sdgs_id'] ?? ''));
                if ($isVol3Plus && $sdgsId !== '') {
                    $secSdg = $dom->createElement('sec');
                    $secSdg->setAttribute('content-type', 'sdgs');
                    $secSdg->appendChild($dom->createElement('title', 'Kontribusi terhadap Target Pembangunan Berkelanjutan (TPB/SDGs)'));
                    $secSdg->appendChild($dom->createElement('p', $sdgsId));
                    $absId->appendChild($secSdg);
                }
                $am->appendChild($absId);
            }

            // Structured English Abstract: primary <abstract xml:lang="en"> when English-only (or no ID abstract), <trans-abstract> otherwise
            $enParts = [
                'Introduction and Methods' => trim($data['abstract_en']['intro_methods'] ?? ''),
                'Results' => trim($data['abstract_en']['results'] ?? ''),
                'Conclusions and Recommendations' => trim($data['abstract_en']['conclusions_recommendations'] ?? ''),
            ];
            if ($enParts['Introduction and Methods'] !== '' || $enParts['Results'] !== '' || $enParts['Conclusions and Recommendations'] !== '') {
                $enTagName = (!$hasIdAbs || $isEnglishOnly) ? 'abstract' : 'trans-abstract';
                $absEn = $dom->createElement($enTagName);
                $absEn->setAttribute('xml:lang', 'en');
                foreach ($enParts as $secTitle => $secBody) {
                    if ($secBody === '') {
                        continue;
                    }
                    $sec = $dom->createElement('sec');
                    $sec->appendChild($dom->createElement('title', $secTitle));
                    $sec->appendChild($dom->createElement('p', $secBody));
                    $absEn->appendChild($sec);
                }
                $sdgsEn = trim((string) ($data['sdgs_en'] ?? ''));
                if ($isVol3Plus && $sdgsEn !== '') {
                    $secSdgEn = $dom->createElement('sec');
                    $secSdgEn->setAttribute('content-type', 'sdgs');
                    $secSdgEn->appendChild($dom->createElement('title', 'Contribution to Sustainable Development Goals (SDGs)'));
                    $secSdgEn->appendChild($dom->createElement('p', $sdgsEn));
                    $absEn->appendChild($secSdgEn);
                }
                $am->appendChild($absEn);
            }
        }

        // Keywords ID (skip in English-only mode) — emitted for every category, including DATA REPORT / LETTER
        $kwIdList = $isEnglishOnly ? [] : array_filter(array_map('trim', preg_split('/[;,]/u', (string) ($data['keywords_id'] ?? ''))));
        if (!empty($kwIdList)) {
            $kwgId = $dom->createElement('kwd-group');
            $kwgId->setAttribute('xml:lang', 'id');
            $kwgId->appendChild($dom->createElement('title', 'Kata Kunci'));
            foreach ($kwIdList as $kw) {
                $kwgId->appendChild($dom->createElement('kwd', rtrim($kw, '.')));
            }
            $am->appendChild($kwgId);
        }

        // Keywords EN — emitted for every category, including DATA REPORT / LETTER
        $kwEnList = array_filter(array_map('trim', preg_split('/[;,]/u', (string) ($data['keywords_en'] ?? ''))));
        if (!empty($kwEnList)) {
            $kwgEn = $dom->createElement('kwd-group');
            $kwgEn->setAttribute('xml:lang', 'en');
            $kwgEn->appendChild($dom->createElement('title', 'Keywords'));
            foreach ($kwEnList as $kw) {
                $kwgEn->appendChild($dom->createElement('kwd', rtrim($kw, '.')));
            }
            $am->appendChild($kwgEn);
        }

        // Funding
        $funding = trim($data['funding'] ?? 'none');
        $fg = $dom->createElement('funding-group');
        $fg->appendChild($dom->createElement('funding-statement', $funding));
        $am->appendChild($fg);

        // TPB/SDGs statements for abstract-exempt categories (valid JATS 1.2 DTD position at tail of <article-meta>)
        if ($noAbstract) {
            $sdgsId = $isEnglishOnly ? '' : trim((string) ($data['sdgs_id'] ?? ''));
            $sdgsEn = trim((string) ($data['sdgs_en'] ?? ''));
            if ($sdgsId !== '' || $sdgsEn !== '') {
                $cmg = $dom->createElement('custom-meta-group');
                foreach ([['SDGs Contribution (ID)', $sdgsId], ['SDGs Contribution (EN)', $sdgsEn]] as $metaPair) {
                    if ($metaPair[1] === '') {
                        continue;
                    }
                    $cm = $dom->createElement('custom-meta');
                    $cm->appendChild($dom->createElement('meta-name', $metaPair[0]));
                    $cm->appendChild($dom->createElement('meta-value', $metaPair[1]));
                    $cmg->appendChild($cm);
                }
                $am->appendChild($cmg);
            }
        }
    }

    /**
     * Map journal section category to standard JATS <article article-type="..."> value
     */
    public static function mapJatsArticleType(string $articleType): string
    {
        $u = mb_strtoupper(trim($articleType), 'UTF-8');
        if (preg_match('/\bLETTER\b/u', $u)) {
            return 'letter';
        }
        if (preg_match('/\bDATA\s*REPORT\b/u', $u)) {
            return 'data-report';
        }
        if (preg_match('/\bREVIEW\b/u', $u)) {
            return 'review-article';
        }
        if (preg_match('/\bCASE\s*REPORT\b/u', $u)) {
            return 'case-report';
        }
        if (preg_match('/\b(SHORT\s*COMMUNICATION|BRIEF\s*REPORT)\b/u', $u)) {
            return 'brief-report';
        }
        return 'research-article';
    }

    /**
     * Build <body> sections, direct body blocks (DATA REPORT / LETTER), nested subheadings, and JATS tables
     */
    private static function buildBody(DOMDocument $dom, DOMElement $body, array $data): void
    {
        $tableCounter = 1;
        $figureCounter = 1;
        $maxRefCount = count(array_filter(
            array_map(static fn ($r) => trim((string) $r), (array) ($data['referensi'] ?? [])),
            static fn ($s) => $s !== ''
        ));

        $appendBlocksInto = static function (DOMElement $rootSec, string $baseId, array $blocks) use ($dom, &$tableCounter, &$figureCounter, $maxRefCount): void {
            $currentSubSec = null;
            $currentSubSecL3 = null;
            $subCounter = 1;
            $subL3Counter = 1;

            foreach ($blocks as $block) {
                if (is_string($block)) {
                    $block = ['type' => 'paragraph', 'text' => $block];
                }
                $bType = $block['type'] ?? 'paragraph';

                if ($bType === 'subheading') {
                    $subTitle = trim($block['text'] ?? '');
                    if ($subTitle !== '') {
                        $currentSubSec = $dom->createElement('sec');
                        $currentSubSec->setAttribute('id', $baseId . '-' . $subCounter++);
                        $currentSubSec->appendChild($dom->createElement('title', $subTitle));
                        $rootSec->appendChild($currentSubSec);
                        $currentSubSecL3 = null;
                        $subL3Counter = 1;
                    }
                } elseif ($bType === 'subheading_l3') {
                    $subTitle = trim($block['text'] ?? '');
                    if ($subTitle !== '') {
                        $parentSec = $currentSubSec ?? $rootSec;
                        $parentId = $parentSec->getAttribute('id') ?: $baseId;
                        $currentSubSecL3 = $dom->createElement('sec');
                        $currentSubSecL3->setAttribute('id', $parentId . '-' . $subL3Counter++);
                        $currentSubSecL3->appendChild($dom->createElement('title', $subTitle));
                        $parentSec->appendChild($currentSubSecL3);
                    }
                } elseif ($bType === 'table') {
                    $targetContainer = $currentSubSecL3 ?? $currentSubSec ?? $rootSec;
                    $tw = self::buildJatsTableWrap($dom, $block, $tableCounter++);
                    $targetContainer->appendChild($tw);
                } elseif ($bType === 'image') {
                    $targetContainer = $currentSubSecL3 ?? $currentSubSec ?? $rootSec;
                    $figEl = self::buildJatsFigure($dom, $block, $figureCounter++);
                    if ($figEl instanceof DOMElement) {
                        $targetContainer->appendChild($figEl);
                    }
                } else {
                    $targetContainer = $currentSubSecL3 ?? $currentSubSec ?? $rootSec;
                    $pText = trim($block['text'] ?? '');
                    if ($pText !== '') {
                        $pEl = $dom->createElement('p');
                        self::appendTextWithVancouverXrefs($dom, $pEl, $pText, $maxRefCount);
                        $targetContainer->appendChild($pEl);
                    }
                }
            }
        };

        $badanBlocks = $data['sections']['badan_artikel'] ?? [];
        if (!empty($badanBlocks)) {
            $appendBlocksInto($body, 'sec-body', $badanBlocks);
        }

        $noAbstract = LanguageAuditor::isNoAbstractArticleType((string) ($data['article_type'] ?? 'ORIGINAL ARTICLE'));
        $onlyIntroPopulated = empty($badanBlocks)
            && !empty($data['sections']['pendahuluan'])
            && empty($data['sections']['metode'])
            && empty($data['sections']['hasil'])
            && empty($data['sections']['pembahasan'])
            && empty($data['sections']['kesimpulan_saran']);

        $sectionSpecs = [
            'pendahuluan' => ['id' => 'sec-intro', 'type' => 'intro', 'title' => 'PENDAHULUAN'],
            'metode' => ['id' => 'sec-methods', 'type' => 'methods', 'title' => 'METODE'],
            'hasil' => ['id' => 'sec-results', 'type' => 'results', 'title' => 'HASIL'],
            'pembahasan' => ['id' => 'sec-discussion', 'type' => 'discussion', 'title' => 'PEMBAHASAN'],
            'kesimpulan_saran' => ['id' => 'sec-conclusions', 'type' => 'conclusions', 'title' => 'KESIMPULAN DAN SARAN'],
        ];

        foreach ($sectionSpecs as $secKey => $spec) {
            $blocks = $data['sections'][$secKey] ?? [];
            if (empty($blocks)) {
                continue;
            }

            if ($noAbstract && $onlyIntroPopulated && $secKey === 'pendahuluan') {
                $appendBlocksInto($body, 'sec-body', $blocks);
                continue;
            }

            $sec = $dom->createElement('sec');
            $sec->setAttribute('id', $spec['id']);
            $sec->setAttribute('sec-type', $spec['type']);
            $sec->appendChild($dom->createElement('title', $spec['title']));
            $appendBlocksInto($sec, $spec['id'], $blocks);
            $body->appendChild($sec);
        }
    }

    /**
     * Build <table-wrap> element with <label>, <caption>, Health-Science 3-line XHTML <table>, and <table-wrap-foot>
     */
    private static function buildJatsTableWrap(DOMDocument $dom, array $tableBlock, int $tableNum): DOMElement
    {
        $formatted = HealthTableFormatter::formatTableBlock($tableBlock, [
            'table_num' => $tableNum,
            'suggest_footnote' => false,
        ]);

        $tw = $dom->createElement('table-wrap');
        $tw->setAttribute('id', 'T' . $tableNum);
        $tw->setAttribute('content-type', 'three-line-medical');

        $rawCaption = trim($formatted['caption'] ?? ('Tabel ' . $tableNum));
        $labelStr = 'Tabel ' . $tableNum . '.';
        $titleStr = $rawCaption;

        if (preg_match('/^((?:Tabel|Table)\s+\d+[\.\:]?)\s*(.*)$/iu', $rawCaption, $m)) {
            $labelStr = rtrim(trim($m[1]), '.:') . '.';
            $titleStr = trim($m[2]) !== '' ? rtrim(trim($m[2]), '.') : '';
        }

        $tw->appendChild($dom->createElement('label', $labelStr));
        $cap = $dom->createElement('caption');
        $cap->appendChild($dom->createElement('title', $titleStr !== '' ? $titleStr : $labelStr));
        $tw->appendChild($cap);

        $table = $dom->createElement('table');
        $table->setAttribute('frame', 'hsides');
        $table->setAttribute('rules', 'groups');

        $thead = $dom->createElement('thead');
        $tbody = $dom->createElement('tbody');

        $rows = $formatted['rows'] ?? [];
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
            $tr = $dom->createElement('tr');
            $isHeaderRow = ($rIdx <= $lastHeaderIdx);
            $rowType = (string) ($rowCells[0]['row_type'] ?? ($isHeaderRow ? 'header' : 'data'));
            if (!$isHeaderRow && in_array($rowType, ['group', 'subitem', 'total'], true)) {
                $tr->setAttribute('content-type', $rowType);
            }

            foreach ($rowCells as $cIdx => $cell) {
                if (!is_array($cell)) {
                    $cell = ['text' => (string) $cell, 'colspan' => 1, 'rowspan' => 1, 'vmerge' => '', 'header' => $isHeaderRow, 'align' => ($cIdx === 0 ? 'left' : 'center'), 'row_type' => $rowType];
                }
                if (($cell['vmerge'] ?? '') === 'continue') {
                    continue;
                }

                $tagName = (!empty($cell['header']) || $isHeaderRow) ? 'th' : 'td';
                $cellEl = $dom->createElement($tagName);

                $align = (string) ($cell['align'] ?? ($cIdx === 0 ? 'left' : 'center'));
                $cellEl->setAttribute('align', $align);
                $cellEl->setAttribute('valign', 'middle');

                $colspan = (int) ($cell['colspan'] ?? 1);
                if ($colspan > 1) {
                    $cellEl->setAttribute('colspan', (string) $colspan);
                }

                $rowspan = (int) ($cell['rowspan'] ?? 1);
                if ($rowspan > 1) {
                    $cellEl->setAttribute('rowspan', (string) $rowspan);
                }

                $cellRowType = (string) ($cell['row_type'] ?? $rowType);
                if ($tagName === 'td' && in_array($cellRowType, ['group', 'subitem', 'total'], true)) {
                    $cellEl->setAttribute('content-type', $cellRowType);
                    if ($cellRowType === 'subitem' && $cIdx === 0) {
                        $cellEl->setAttribute('style', 'padding-left: 16px;');
                    } elseif ($cellRowType === 'group') {
                        $cellEl->setAttribute('style', 'font-weight: bold;');
                    } elseif ($cellRowType === 'total') {
                        $cellEl->setAttribute('style', 'font-weight: bold; border-top: solid thin;');
                    }
                }

                $cellText = (string) ($cell['text'] ?? '');
                self::appendStatisticalFormattedText($dom, $cellEl, $cellText);
                $tr->appendChild($cellEl);
            }

            if ($isHeaderRow) {
                $thead->appendChild($tr);
            } else {
                $tbody->appendChild($tr);
            }
        }

        if ($thead->hasChildNodes()) {
            $table->appendChild($thead);
        }
        if ($tbody->hasChildNodes()) {
            $table->appendChild($tbody);
        }
        $tw->appendChild($table);

        $footnote = trim((string) ($formatted['footnote'] ?? ''));
        if ($footnote !== '') {
            $twFoot = $dom->createElement('table-wrap-foot');
            $fnGroup = $dom->createElement('fn-group');
            $fn = $dom->createElement('fn');
            $fn->setAttribute('id', 'T' . $tableNum . '-fn1');
            $fnP = $dom->createElement('p');
            self::appendStatisticalFormattedText($dom, $fnP, $footnote);
            $fn->appendChild($fnP);
            $fnGroup->appendChild($fn);
            $twFoot->appendChild($fnGroup);
            $tw->appendChild($twFoot);
        }

        return $tw;
    }

    /**
     * Build a JATS <fig> element (<label>, <caption>, <alt-text>, <graphic>) for an image block.
     * The graphic href is the hosted URL when the editor supplied one, otherwise the embedded data URI,
     * so the full-text HTML digest can always display the picture.
     */
    private static function buildJatsFigure(DOMDocument $dom, array $block, int $figureNum): ?DOMElement
    {
        $href = trim((string) ($block['src'] ?? ''));
        if ($href === '') {
            $href = trim((string) ($block['data'] ?? ''));
        }
        if ($href === '') {
            return null;
        }

        $caption = trim((string) ($block['caption'] ?? ''));
        $alt = trim((string) ($block['alt'] ?? ''));
        $labelStr = 'Gambar ' . $figureNum . '.';
        $titleStr = $caption;
        if ($caption !== '' && preg_match('/^((?:Gambar|Figure|Fig\.?)\s+\d+[\.\:]?)\s*(.*)$/iu', $caption, $m)) {
            $labelStr = rtrim(trim($m[1]), '.:') . '.';
            $titleStr = trim($m[2]);
        }
        if ($titleStr === '') {
            $titleStr = $alt;
        }

        $fig = $dom->createElement('fig');
        $fig->setAttribute('id', 'F' . $figureNum);
        $fig->appendChild($dom->createElement('label', $labelStr));

        if ($titleStr !== '') {
            $captionEl = $dom->createElement('caption');
            $captionEl->appendChild($dom->createElement('title', $titleStr));
            $fig->appendChild($captionEl);
        }

        $altText = $alt !== '' ? $alt : $titleStr;
        if ($altText !== '') {
            $fig->appendChild($dom->createElement('alt-text', $altText));
        }

        $graphic = $dom->createElement('graphic');
        $graphic->setAttributeNS(self::NS_XLINK, 'xlink:href', $href);
        $fig->appendChild($graphic);

        // Optional second image sharing the same figure/caption.
        $href2 = trim((string) ($block['src2'] ?? ''));
        if ($href2 === '') {
            $href2 = trim((string) ($block['data2'] ?? ''));
        }
        if ($href2 !== '') {
            $graphic2 = $dom->createElement('graphic');
            $graphic2->setAttributeNS(self::NS_XLINK, 'xlink:href', $href2);
            $fig->appendChild($graphic2);
        }

        return $fig;
    }

    private static function appendStatisticalFormattedText(DOMDocument $dom, DOMElement $parent, string $text): void
    {
        if ($text === '') {
            return;
        }
        foreach (HealthTableFormatter::splitStatisticalInlineRuns($text) as $seg) {
            if ($seg['sup']) {
                $parent->appendChild($dom->createElement('sup', $seg['text']));
            } elseif ($seg['italic']) {
                $parent->appendChild($dom->createElement('italic', $seg['text']));
            } else {
                $parent->appendChild($dom->createTextNode($seg['text']));
            }
        }
    }

    /**
     * Build <back> (Publisher's Note + <ref-list>)
     */
    private static function buildBack(DOMDocument $dom, DOMElement $back, array $data): void
    {
        // Publisher's Note & Re-typeset 2026 declaration
        $fng = $dom->createElement('fn-group');
        $fng->appendChild($dom->createElement('title', 'Publisher’s Note'));
        $fn = $dom->createElement('fn');
        $fn->setAttribute('fn-type', 'other');
        $fn->appendChild($dom->createElement(
            'p',
            'The publisher of ' . ($data['publisher_name'] ?? 'PT Karya Inovasi Berkelanjutan') . ' states that it remains neutral with respect to the published ideas and from any institutional affiliation.'
        ));
        $isVol3Plus = LanguageAuditor::isVolume3Plus($data['volume'] ?? '1');
        $retypesetNote = $isVol3Plus
            ? ''
            : trim((string) ($data['retypeset_note'] ?? "This article was re-typeset in 2026 to standardize the journal's visual layout. No changes were made to the scientific substance, data, or metadata of the original Version of Record."));
        if ($retypesetNote !== '') {
            $fn->appendChild($dom->createElement('p', $retypesetNote));
        }
        $fng->appendChild($fn);
        $back->appendChild($fng);

        // References (<ref-list>) synchronized in Vancouver style
        $refs = $data['referensi'] ?? [];
        if (!empty($refs)) {
            $refList = $dom->createElement('ref-list');
            $refList->appendChild($dom->createElement('title', 'SUMBER PUSTAKA'));

            $refNum = 1;
            foreach ($refs as $refText) {
                $vanText = VancouverFormatter::formatReferenceBody((string) $refText);
                if ($vanText === '') {
                    continue;
                }
                $refEl = $dom->createElement('ref');
                $refEl->setAttribute('id', 'B' . $refNum);
                $refEl->appendChild($dom->createElement('label', $refNum . '.'));

                $mc = $dom->createElement('mixed-citation');
                $mc->setAttribute('publication-type', VancouverFormatter::inferPublicationType($vanText));

                // Automatically link URLs or doi: identifiers inside Vancouver references
                if (preg_match('#^(.*?)(https?://\S+|doi:[^\s,;]+)(.*)$#iu', $vanText, $m)) {
                    if ($m[1] !== '') {
                        $mc->appendChild($dom->createTextNode($m[1]));
                    }
                    $linkVal = $m[2];
                    $href = stripos($linkVal, 'doi:') === 0
                        ? ('https://doi.org/' . substr($linkVal, 4))
                        : $linkVal;
                    $extLink = $dom->createElement('ext-link', $linkVal);
                    $extLink->setAttribute('ext-link-type', stripos($linkVal, 'doi:') === 0 ? 'doi' : 'uri');
                    $extLink->setAttributeNS(self::NS_XLINK, 'xlink:href', $href);
                    $mc->appendChild($extLink);
                    if ($m[3] !== '') {
                        $mc->appendChild($dom->createTextNode($m[3]));
                    }
                } else {
                    $mc->appendChild($dom->createTextNode($vanText));
                }

                $refEl->appendChild($mc);
                $refList->appendChild($refEl);
                $refNum++;
            }

            if ($refNum > 1) {
                $back->appendChild($refList);
            }
        }
    }

    /**
     * Append paragraph prose while turning validated Vancouver numeric citations ((1), [1], (1,2), (1-3))
     * into JATS <xref ref-type="bibr" rid="B#"> cross-links.
     */
    private static function appendTextWithVancouverXrefs(DOMDocument $dom, DOMElement $pEl, string $text, int $maxRefCount): void
    {
        if ($maxRefCount <= 0) {
            $pEl->appendChild($dom->createTextNode($text));
            return;
        }

        $matches = VancouverFormatter::extractInTextCitationMatches($text);
        if (empty($matches)) {
            $pEl->appendChild($dom->createTextNode($text));
            return;
        }

        $cursor = 0;
        foreach ($matches as $m) {
            // Only rewrite matches where all cited indices map to existing references in SUMBER PUSTAKA
            $allInRange = true;
            foreach ($m['numbers'] as $num) {
                if ($num < 1 || $num > $maxRefCount) {
                    $allInRange = false;
                    break;
                }
            }
            if (!$allInRange) {
                continue;
            }

            $offset = $m['offset'];
            if ($offset > $cursor) {
                $pEl->appendChild($dom->createTextNode(substr($text, $cursor, $offset - $cursor)));
            }

            $pEl->appendChild($dom->createTextNode($m['open']));
            $innerSpec = substr($m['raw'], 1, -1);
            $parts = preg_split('/(\s*(?:,|[-–—])\s*)/u', $innerSpec, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts !== false) {
                foreach ($parts as $part) {
                    $trimmed = trim($part);
                    if ($trimmed !== '' && ctype_digit($trimmed)) {
                        $xref = $dom->createElement('xref', $trimmed);
                        $xref->setAttribute('ref-type', 'bibr');
                        $xref->setAttribute('rid', 'B' . (int) $trimmed);
                        $pEl->appendChild($xref);
                    } else {
                        $pEl->appendChild($dom->createTextNode($part));
                    }
                }
            }
            $pEl->appendChild($dom->createTextNode($m['close']));
            $cursor = $offset + $m['length'];
        }

        if ($cursor < strlen($text)) {
            $pEl->appendChild($dom->createTextNode(substr($text, $cursor)));
        }
    }

    /**
     * Split full Indonesian/academic name into [given-names, surname]
     */
    private static function splitPersonName(string $fullName): array
    {
        $clean = trim($fullName);
        if ($clean === '') {
            return ['', 'Author'];
        }
        // Separate academic degrees after comma if present (e.g., "Hikmawati, S.Kep., Ns., M.Kep")
        $commaParts = explode(',', $clean, 2);
        $baseName = trim($commaParts[0]);
        $words = preg_split('/\s+/u', $baseName, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) <= 1) {
            return ['', $baseName];
        }
        $surname = array_pop($words);
        $given = implode(' ', $words);
        return [$given, $surname];
    }
}
