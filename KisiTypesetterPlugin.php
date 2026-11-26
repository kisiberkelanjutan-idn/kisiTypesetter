<?php
/**
 * @file plugins/generic/kisiTypesetter/KisiTypesetterPlugin.php
 *
 * Copyright (c) 2026 PT Karya Inovasi Berkelanjutan
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class KisiTypesetterPlugin
 * @brief Automated DOCX Re-Layout (2026 Template), AmeliCA/OJS JATS 1.2 XML Generator,
 *        and Direct Full-Text XML Digest Renderer on the OJS 3.5 Article Landing Page.
 */

namespace APP\plugins\generic\kisiTypesetter;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class KisiTypesetterPlugin extends GenericPlugin
{
    /** @var bool Track whether Templates::Article::Main already rendered full text in this request */
    protected bool $fullTextRenderedInHook = false;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled($mainContextId)) {
            // 1. Page handler for Kisi Berkelanjutan Typesetter Studio & JATS XML
            Hook::add('LoadHandler', [$this, 'setupHandler']);

            // 2. Direct Full-Text JATS XML Digest on public article landing page (/article/view/{id})
            Hook::add('Templates::Article::Main', [$this, 'renderFullTextOnArticlePage']);

            // 3. Backend quick-launch button & custom-theme fallback for Full-Text Digest
            Hook::add('TemplateManager::display', [$this, 'onTemplateDisplay']);

            // 4. Standalone Full-Text Reader for direct XML Galley URL (/article/view/{id}/{galleyId})
            Hook::add('ArticleHandler::view::galley', [$this, 'renderXmlGalleyFullText'], Hook::SEQUENCE_LAST);
        }

        return $success;
    }

    /**
     * Route /index.php/{journal}/kisiTypesetter/{op} to KisiTypesetterHandler
     */
    public function setupHandler(string $hookName, array $args): bool
    {
        $page = $args[0] ?? '';
        if ($page !== 'kisiTypesetter') {
            return false;
        }

        $this->loadPluginClasses();

        if (array_key_exists(3, $args)) {
            $args[3] = new \APP\plugins\generic\kisiTypesetter\classes\KisiTypesetterHandler($this);
            return true;
        }

        $sourceFile =& $args[2];
        $sourceFile = $this->getPluginPath() . '/classes/KisiTypesetterHandler.php';
        if (!defined('HANDLER_CLASS')) {
            define('HANDLER_CLASS', 'APP\plugins\generic\kisiTypesetter\classes\KisiTypesetterHandler');
        }
        return true;
    }

    /**
     * Load helper classes on demand
     */
    protected function loadPluginClasses(): void
    {
        require_once($this->getPluginPath() . '/classes/HealthTableFormatter.php');
        require_once($this->getPluginPath() . '/classes/LanguageAuditor.php');
        require_once($this->getPluginPath() . '/classes/VancouverFormatter.php');
        require_once($this->getPluginPath() . '/classes/DocxParser.php');
        require_once($this->getPluginPath() . '/classes/DocxGenerator.php');
        require_once($this->getPluginPath() . '/classes/JatsXmlGenerator.php');
        require_once($this->getPluginPath() . '/classes/JatsHtmlRenderer.php');
        require_once($this->getPluginPath() . '/classes/GeminiRestructurer.php');
        require_once($this->getPluginPath() . '/classes/KisiTypesetterHandler.php');
    }

    /**
     * Hook: Templates::Article::Main
     * Automatically digests the article's JATS XML file and renders Full-Text HTML
     * directly on the public OJS 3.5 article landing page!
     */
    public function renderFullTextOnArticlePage(string $hookName, array $args): bool
    {
        $smarty =& $args[1];
        $output =& $args[2];

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }

        $article = $smarty->getTemplateVars('article') ?: $smarty->getTemplateVars('submission');
        $publication = $smarty->getTemplateVars('publication');
        if (!$publication && $article && method_exists($article, 'getCurrentPublication')) {
            $publication = $article->getCurrentPublication();
        }
        if (!$article) {
            return false;
        }

        $xmlContent = $this->findArticleJatsXmlContent((int) $article->getId(), $publication);
        if ($xmlContent === '') {
            return false;
        }

        require_once($this->getPluginPath() . '/classes/JatsHtmlRenderer.php');
        $formLangMode = (string) ($this->getSetting($context->getId(), 'formLanguageMode') ?: 'en');
        $this->populateMissingPublicationAbstractFromXml($publication, $xmlContent, $formLangMode);

        // Check if built-in full-text digest is enabled (default: enabled)
        $digestEnabled = $this->getSetting($context->getId(), 'enableFullTextDigest');
        if ($digestEnabled === '0' || $digestEnabled === false) {
            return false;
        }

        $hideThemeAbstract = ($this->getSetting($context->getId(), 'replaceDefaultAbstract') !== '0');

        // Always render structured <abstract>/<trans-abstract> inside Full-Text HTML when present in JATS XML
        $html = \APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer::renderFullTextHtml($xmlContent, [
            'include_abstract' => true,
            'form_language_mode' => $formLangMode,
        ]);

        if ($html !== '') {
            $hideFlagJs = $hideThemeAbstract ? 'true' : 'false';
            $html .= <<<HTML
<script>
(function() {
    function syncKisiAbstractWithTheme() {
        var absSec = document.getElementById('kisi-sec-abstract');
        var container = document.getElementById('kisi-jats-fulltext-container');
        if (!absSec || !container || !document.body.contains(absSec)) return;
        if (!absSec.textContent || absSec.textContent.replace(/\s+/g, '').length < 20) return;

        var isFrontiersTheme = !!document.querySelector('.obj_article_details.frontiers-layout, .frontiers-center-main');
        if (isFrontiersTheme) {
            var outlineList = document.querySelector('.frontiers-outline-list');
            var kisiToc = container.querySelector('.kisi-jats-toc');
            if (outlineList && kisiToc) {
                kisiToc.style.display = 'none';
            }
        }

        if (!{$hideFlagJs}) return;

        var defaultAbs = document.querySelectorAll('#section-abstract, .obj_article_details .item.abstract, .article-details .article-abstract, section.item.abstract, .article-abstract');
        var placedTop = false;
        defaultAbs.forEach(function(el) {
            if (!el || el.contains(absSec) || el.contains(container)) return;
            if (!placedTop && el.parentNode) {
                absSec.style.marginTop = '8px';
                absSec.style.marginBottom = '18px';
                el.parentNode.insertBefore(absSec, el);
                placedTop = true;
            }
            var rect = absSec.getBoundingClientRect();
            if (document.body.contains(absSec) && rect && rect.height > 10 && window.getComputedStyle(absSec).display !== 'none') {
                el.style.display = 'none';
            } else {
                el.style.display = '';
            }
        });
        document.querySelectorAll('.frontiers-outline-list a[href="#section-abstract"]').forEach(function(a) {
            a.setAttribute('href', '#kisi-sec-abstract');
        });
    }
    function scheduleSync() {
        setTimeout(syncKisiAbstractWithTheme, 50);
        setTimeout(syncKisiAbstractWithTheme, 350);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleSync);
    } else {
        scheduleSync();
    }
})();
</script>
HTML;
            $output .= $html;
            $this->fullTextRenderedInHook = true;
        }

        return false;
    }

    /**
     * Hook: TemplateManager::display
     * 1. Populates missing OJS abstract in memory from attached JATS XML (even when external JATS Parser / theme renders full-text)
     * 2. Provides automatic fallback Full-Text injection on frontend/pages/article.tpl for custom OJS themes
     * 3. Injects backend sidebar shortcut & floating quick-launch button in OJS editorial backend
     */
    public function onTemplateDisplay(string $hookName, array $args): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $template = (string) ($args[1] ?? '');

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }

        // A. Public Article Landing Page: sync missing abstract in-memory & fallback render if Templates::Article::Main didn't run
        if (strpos($template, 'frontend/pages/article.tpl') !== false) {
            $article = $templateMgr->getTemplateVars('article') ?: $templateMgr->getTemplateVars('submission');
            $publication = $templateMgr->getTemplateVars('publication');
            if (!$publication && $article && method_exists($article, 'getCurrentPublication')) {
                $publication = $article->getCurrentPublication();
            }
            $xmlContent = $article ? $this->findArticleJatsXmlContent((int) $article->getId(), $publication) : '';
            $formLangMode = (string) ($this->getSetting($context->getId(), 'formLanguageMode') ?: 'en');

            if ($xmlContent !== '') {
                require_once($this->getPluginPath() . '/classes/JatsHtmlRenderer.php');
                $this->populateMissingPublicationAbstractFromXml($publication, $xmlContent, $formLangMode);
                if ($publication) {
                    $templateMgr->assign('publication', $publication);
                }
            }

            $digestEnabled = $this->getSetting($context->getId(), 'enableFullTextDigest');
            if ($xmlContent !== '' && !$this->fullTextRenderedInHook && $digestEnabled !== '0' && $digestEnabled !== false) {
                $hideThemeAbstract = ($this->getSetting($context->getId(), 'replaceDefaultAbstract') !== '0');
                $hideFlagJs = $hideThemeAbstract ? 'true' : 'false';
                $html = \APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer::renderFullTextHtml($xmlContent, [
                    'include_abstract' => true,
                    'form_language_mode' => $formLangMode,
                ]);
                if ($html !== '') {
                    $jsonHtml = json_encode($html, JSON_UNESCAPED_UNICODE);
                    $fallbackScript = <<<HTML
<script>
window.kisiSwitchAbsLang = window.kisiSwitchAbsLang || function(mode, btn) {
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
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('kisi-jats-fulltext-container')) return;
    var target = document.querySelector('.obj_article_details .main_entry')
        || document.querySelector('.article-details .article-main')
        || document.querySelector('.article-fulltext')
        || document.querySelector('.item.abstract')
        || document.querySelector('.obj_article_details')
        || document.querySelector('.pkp_structure_main article')
        || document.querySelector('main');
    if (target) {
        var wrapper = document.createElement('div');
        wrapper.innerHTML = {$jsonHtml};
        var absSec = wrapper.querySelector('#kisi-sec-abstract');
        var hasValidAbs = !!(absSec && absSec.textContent && absSec.textContent.replace(/\s+/g, '').length >= 20);
        if (target.classList.contains('abstract')) {
            target.parentNode.insertBefore(wrapper, target.nextSibling);
            setTimeout(function() {
                if ({$hideFlagJs} && hasValidAbs && document.body.contains(absSec) && absSec.getBoundingClientRect().height > 10) {
                    target.style.display = 'none';
                }
            }, 60);
        } else {
            target.appendChild(wrapper);
            var syncFallback = function() {
                var isFrontiersTheme = !!document.querySelector('.obj_article_details.frontiers-layout, .frontiers-center-main');
                if (isFrontiersTheme && hasValidAbs) {
                    var cm = document.querySelector('.frontiers-center-main');
                    var ftw = document.getElementById('section-fulltext');
                    if (cm && ftw && !cm.contains(wrapper)) ftw.appendChild(wrapper);
                    var ol = document.querySelector('.frontiers-outline-list');
                    var kt = wrapper.querySelector('.kisi-jats-toc');
                    if (ol && kt) kt.style.display = 'none';
                }
                if ({$hideFlagJs} && hasValidAbs) {
                    var placedTop = false;
                    document.querySelectorAll('#section-abstract, .obj_article_details .item.abstract, .article-details .article-abstract, section.item.abstract, .article-abstract').forEach(function(el) {
                        if (!el.contains(wrapper) && !el.contains(absSec)) {
                            if (!placedTop && el.parentNode) {
                                absSec.style.marginTop = '8px';
                                absSec.style.marginBottom = '18px';
                                el.parentNode.insertBefore(absSec, el);
                                placedTop = true;
                            }
                            if (document.body.contains(absSec) && absSec.getBoundingClientRect().height > 10 && window.getComputedStyle(absSec).display !== 'none') {
                                el.style.display = 'none';
                            }
                        }
                    });
                    document.querySelectorAll('.frontiers-outline-list a[href="#section-abstract"]').forEach(function(a) {
                        a.setAttribute('href', '#kisi-sec-abstract');
                    });
                }
            };
            setTimeout(syncFallback, 60);
            setTimeout(syncFallback, 350);
        }
    }
});
</script>
HTML;
                    $existingHead = (string) $templateMgr->getTemplateVars('additionalHeadData');
                    $templateMgr->assign('additionalHeadData', $existingHead . "\n" . $fallbackScript);
                }
            }
        }

        // B. Editorial Backend Menu (directly below Issues) & Quick-Launch Shortcut
        $router = $request->getRouter();
        if (!method_exists($router, 'getRequestedPage')) {
            return false;
        }

        $page = (string) $router->getRequestedPage($request);
        $dispatcher = $request->getDispatcher();
        $studioUrl = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'kisiTypesetter',
            'studio'
        );

        // Inject "Kisi Typesetter" directly below "Issues" in OJS 3.5 <pkp-side-nav :links="menu">
        $this->injectBackendSidebarMenu($templateMgr, $request, $context, $studioUrl, $page);

        if (!in_array($page, ['dashboard', 'dashboards', 'workflow', 'authorDashboard', 'submissions', 'manageIssues', 'management', 'stats'], true)) {
            return false;
        }

        $escapedUrl = htmlspecialchars($studioUrl, ENT_QUOTES, 'UTF-8');

        $script = <<<HTML
<script>
(function() {
    if (document.getElementById('kisi-typesetter-fab')) return;
    function resolveTypesetterUrl(baseUrl) {
        var mPath = window.location.pathname.match(/\/workflow\/(?:index|access)\/(\d+)/);
        var mSearch = window.location.search.match(/(?:workflowSubmissionId|submissionId)=(\d+)/);
        var subId = (mPath && mPath[1]) || (mSearch && mSearch[1]) || '';
        if (!subId) return baseUrl;
        return baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + 'submissionId=' + encodeURIComponent(subId);
    }
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('kisi-typesetter-fab')) return;
        var baseUrl = "{$escapedUrl}";
        var btn = document.createElement('a');
        btn.id = 'kisi-typesetter-fab';
        btn.href = resolveTypesetterUrl(baseUrl);
        btn.innerHTML = '⚡ <strong>Kisi Berkelanjutan Typesetter</strong>';
        btn.title = 'Buka Kisi Berkelanjutan Typesetter (otomatis mendeteksi naskah yang sedang dibuka)';
        btn.style.cssText = 'position:fixed;bottom:22px;right:24px;z-index:99999;background:linear-gradient(135deg,#0a2e46,#1565c0);color:#fff;padding:10px 18px;border-radius:999px;font-family:system-ui,-apple-system,sans-serif;font-size:13px;text-decoration:none;box-shadow:0 6px 20px rgba(10,46,70,0.35);border:1px solid rgba(255,255,255,0.2);display:inline-flex;align-items:center;gap:8px;transition:transform 0.15s ease;';
        btn.addEventListener('click', function() {
            btn.href = resolveTypesetterUrl(baseUrl);
        });
        btn.onmouseenter = function() {
            btn.href = resolveTypesetterUrl(baseUrl);
            btn.style.transform = 'translateY(-2px)';
        };
        btn.onmouseleave = function() { btn.style.transform = 'none'; };
        document.body.appendChild(btn);
    });
})();
</script>
HTML;

        $existingHeader = (string) $templateMgr->getTemplateVars('additionalHeadData');
        $templateMgr->assign('additionalHeadData', $existingHeader . "\n" . $script);

        return false;
    }

    /**
     * Insert Kisi Typesetter directly below "Issues" in OJS 3.5 backend navigation (<pkp-side-nav>).
     * Called inside TemplateManager::display() after APP\template\TemplateManager::setupBackendPage()
     * has already populated 'issues' and right before Smarty assigns 'state'.
     */
    protected function injectBackendSidebarMenu($templateMgr, $request, $context, string $studioUrl, string $currentPage): void
    {
        if (!method_exists($templateMgr, 'getState') || !method_exists($templateMgr, 'setState')) {
            return;
        }

        $menu = $templateMgr->getState('menu');
        if (!is_array($menu) || empty($menu) || isset($menu['kisiTypesetter'])) {
            return;
        }

        $user = $request->getUser();
        if (!$user) {
            return;
        }

        $canUse = method_exists($user, 'hasRole')
            ? ($user->hasRole(
                [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
                $context->getId()
            ) || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::CONTEXT_ID_NONE))
            : true;

        if (!$canUse) {
            return;
        }

        $navLabel = __('plugins.generic.kisiTypesetter.navTitle');
        if ($navLabel === 'plugins.generic.kisiTypesetter.navTitle') {
            $navLabel = 'Kisi Typesetter';
        }

        $kisiLink = [
            'name' => $navLabel,
            'url' => $studioUrl,
            'isCurrent' => ($currentPage === 'kisiTypesetter'),
            'icon' => 'FileText',
        ];

        $updatedMenu = self::insertMenuItemAfterIssues($menu, $kisiLink, 'kisiTypesetter');
        $templateMgr->setState(['menu' => $updatedMenu]);
    }

    /**
     * Insert a navigation item directly after 'issues' (or after submission/dashboard items if 'issues' is hidden).
     */
    public static function insertMenuItemAfterIssues(array $menu, array $newItem, string $key = 'kisiTypesetter'): array
    {
        if (isset($menu[$key])) {
            return $menu;
        }

        $keys = array_keys($menu);
        $anchorIndex = array_search('issues', $keys, true);
        if ($anchorIndex === false) {
            foreach (['mySubmissions', 'reviewAssignments', 'dashboards'] as $fallbackKey) {
                $anchorIndex = array_search($fallbackKey, $keys, true);
                if ($anchorIndex !== false) {
                    break;
                }
            }
        }

        if ($anchorIndex === false || count($menu) <= $anchorIndex + 1) {
            $menu[$key] = $newItem;
            return $menu;
        }

        return array_slice($menu, 0, $anchorIndex + 1, true)
            + [$key => $newItem]
            + array_slice($menu, $anchorIndex + 1, null, true);
    }

    /**
     * Hook: ArticleHandler::view::galley
     * Renders a full standalone HTML5 reader (including Abstract, Metadata, Body, Tables & References)
     * when a reader opens an XML JATS galley directly (/article/view/{id}/{galleyId}).
     */
    public function renderXmlGalleyFullText(string $hookName, array $args): bool
    {
        $request = $args[0] ?? null;
        $galley = $args[2] ?? null;
        $article = $args[3] ?? null;
        $publication = $args[4] ?? null;

        if (!$request || !$galley) {
            return false;
        }
        $context = method_exists($request, 'getContext') ? $request->getContext() : null;
        if (!$context) {
            return false;
        }

        $digestEnabled = $this->getSetting($context->getId(), 'enableFullTextDigest');
        if ($digestEnabled === '0' || $digestEnabled === false) {
            return false;
        }

        $sfId = (int) $galley->getData('submissionFileId');
        if ($sfId <= 0) {
            return false;
        }

        $sf = Repo::submissionFile()->get($sfId);
        if (!$sf) {
            return false;
        }

        $xmlContent = $this->readXmlFromSubmissionFile($sf);
        if ($xmlContent === '' || (stripos($xmlContent, '<article') === false && stripos($xmlContent, '<abstract') === false && stripos($xmlContent, '<body') === false)) {
            return false;
        }

        require_once($this->getPluginPath() . '/classes/JatsHtmlRenderer.php');
        $formLangMode = (string) ($this->getSetting($context->getId(), 'formLanguageMode') ?: 'en');
        $this->populateMissingPublicationAbstractFromXml($publication, $xmlContent, $formLangMode);

        $articleUrl = '';
        if ($article && method_exists($request, 'getDispatcher')) {
            $articleUrl = $request->getDispatcher()->url(
                $request,
                PKPApplication::ROUTE_PAGE,
                $context->getPath(),
                'article',
                'view',
                [$article->getBestId()]
            );
        }

        $htmlPage = \APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer::renderStandaloneReaderPage($xmlContent, [
            'form_language_mode' => $formLangMode,
            'journal_title' => method_exists($context, 'getLocalizedName') ? $context->getLocalizedName() : '',
            'article_url' => $articleUrl,
            'title' => $publication && method_exists($publication, 'getLocalizedFullTitle') ? $publication->getLocalizedFullTitle() : '',
        ]);

        if ($htmlPage === '') {
            return false;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $htmlPage;
        return true;
    }

    /**
     * Populate empty runtime Publication abstract fields from attached JATS XML without creating unwanted extra locale keys.
     */
    protected function populateMissingPublicationAbstractFromXml($publication, string $xmlContent, string $formLangMode = 'en'): void
    {
        if (!$publication || !method_exists($publication, 'getData') || !method_exists($publication, 'setData')) {
            return;
        }
        $extracted = \APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer::extractAbstractTextsByLocale($xmlContent);
        $enHtml = trim((string) ($extracted['en']['html'] ?? ''));
        $idHtml = trim((string) ($extracted['id']['html'] ?? ''));
        $chosenHtml = ($formLangMode === 'en')
            ? ($enHtml !== '' ? $enHtml : $idHtml)
            : ($idHtml !== '' ? $idHtml : $enHtml);

        if ($chosenHtml === '') {
            return;
        }

        $existingAbs = (array) $publication->getData('abstract');
        $pubLocale = trim((string) ($publication->getData('locale') ?: 'en'));
        $titleKeys = array_keys(array_filter((array) $publication->getData('title'), static fn ($v) => trim((string) $v) !== ''));

        $targets = [];
        $activeUiLocale = '';
        if (class_exists('\PKP\facades\Locale') && method_exists('\PKP\facades\Locale', 'getLocale')) {
            $activeUiLocale = trim((string) \PKP\facades\Locale::getLocale());
        }
        if ($formLangMode === 'en') {
            foreach ($titleKeys as $tk) {
                if (stripos((string) $tk, 'en') === 0) {
                    $targets[] = (string) $tk;
                }
            }
            if ($pubLocale !== '') {
                $targets[] = $pubLocale;
            }
            if ($activeUiLocale !== '') {
                $targets[] = $activeUiLocale;
            }
            if (empty($targets)) {
                $targets[] = 'en';
            }
        } else {
            if ($idHtml !== '') {
                $targets['id'] = $idHtml;
            }
            if ($enHtml !== '') {
                $targets['en'] = $enHtml;
            }
        }

        $changed = false;
        if ($formLangMode === 'en') {
            foreach (array_unique($targets) as $locKey) {
                if (trim(strip_tags((string) ($existingAbs[$locKey] ?? ''))) === '') {
                    $existingAbs[$locKey] = $chosenHtml;
                    $changed = true;
                }
            }
        } else {
            foreach ($targets as $langPrefix => $htmlVal) {
                $matched = false;
                foreach ($titleKeys as $tk) {
                    if (stripos((string) $tk, $langPrefix) === 0) {
                        $matched = true;
                        if (trim(strip_tags((string) ($existingAbs[$tk] ?? ''))) === '') {
                            $existingAbs[$tk] = $htmlVal;
                            $changed = true;
                        }
                    }
                }
                if (!$matched && trim(strip_tags((string) ($existingAbs[$langPrefix] ?? ''))) === '') {
                    $existingAbs[$langPrefix] = $htmlVal;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $publication->setData('abstract', $existingAbs);
        }
    }

    /**
     * Find the best JATS XML file content attached to an article's Galleys or Production Ready Files
     * (prefers Galleys, newer uploads, and complete XML containing <abstract>/<body>/<ref-list>).
     */
    public function findArticleJatsXmlContent(int $submissionId, $publication = null): string
    {
        $bestXml = '';
        $bestScore = -1;

        $scoreXml = static function (string $xml, int $sourceBoost = 0): int {
            if (trim($xml) === '') {
                return -1;
            }
            $s = $sourceBoost;
            if (stripos($xml, '<article') !== false) {
                $s += 10;
            }
            if (stripos($xml, '<abstract') !== false || stripos($xml, '<trans-abstract') !== false) {
                $s += 25;
            }
            if (stripos($xml, '<body') !== false) {
                $s += 20;
            }
            if (stripos($xml, '<ref-list') !== false) {
                $s += 15;
            }
            return $s;
        };

        // 1. Check Publication Galleys first (highest priority, latest galley wins ties)
        if ($publication) {
            $galleyList = [];
            $galleys = $publication->getData('galleys');
            if ($galleys) {
                foreach ($galleys as $galley) {
                    $galleyList[] = $galley;
                }
            }
            if (empty($galleyList)) {
                try {
                    $galleys = Repo::galley()->getCollector()
                        ->filterByPublicationIds([(int) $publication->getId()])
                        ->getMany();
                    foreach ($galleys as $galley) {
                        $galleyList[] = $galley;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            foreach (array_reverse($galleyList) as $galley) {
                $sfId = (int) $galley->getData('submissionFileId');
                if ($sfId > 0) {
                    $sf = Repo::submissionFile()->get($sfId);
                    if ($sf) {
                        $xml = $this->readXmlFromSubmissionFile($sf);
                        $sc = $scoreXml($xml, 100);
                        if ($sc > $bestScore) {
                            $bestScore = $sc;
                            $bestXml = $xml;
                        }
                    }
                }
            }
        }

        // 2. Fallback: Check Submission Files (Production Ready / Proof / JATS stages), latest file first
        try {
            $subFiles = Repo::submissionFile()->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->getMany();
            $sfList = [];
            foreach ($subFiles as $sf) {
                $sfList[] = $sf;
            }
            usort($sfList, static fn ($a, $b) => (int) $b->getId() <=> (int) $a->getId());
            foreach ($sfList as $sf) {
                $xml = $this->readXmlFromSubmissionFile($sf);
                $sc = $scoreXml($xml, 10);
                if ($sc > $bestScore) {
                    $bestScore = $sc;
                    $bestXml = $xml;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $bestXml;
    }

    /**
     * Read XML content from an OJS 3.4/3.5 SubmissionFile (via disk path or Flysystem)
     */
    protected function readXmlFromSubmissionFile($sf): string
    {
        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/\\');
        $relPath = (string) ($sf->getData('path') ?? '');
        $mime = (string) ($sf->getData('mimetype') ?? '');
        $rawNames = $sf->getData('name');
        $allNames = is_array($rawNames) ? implode(' ', array_map('strval', $rawNames)) : (string) ($sf->getLocalizedData('name') ?: '');
        $fileSvc = null;

        if (($relPath === '' || $mime === '') && ($fileId = (int) $sf->getData('fileId')) > 0) {
            try {
                if (function_exists('app')) {
                    $fileSvc = app()->get('file');
                } elseif (class_exists('\APP\core\Services')) {
                    $fileSvc = \APP\core\Services::get('file');
                }
                if ($fileSvc && method_exists($fileSvc, 'get')) {
                    $fObj = $fileSvc->get($fileId);
                    if ($fObj) {
                        if ($relPath === '' && !empty($fObj->path)) {
                            $relPath = (string) $fObj->path;
                        }
                        if ($mime === '' && !empty($fObj->mimetype)) {
                            $mime = (string) $fObj->mimetype;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($relPath === '') {
            return '';
        }

        $isLikelyBinary = preg_match('/\.(pdf|docx?|xlsx?|pptx?|zip|png|jpe?g|gif|webp|mp4|mp3)$/i', $relPath . ' ' . $allNames)
            || stripos($mime, 'pdf') !== false
            || stripos($mime, 'wordprocessingml') !== false
            || stripos($mime, 'image/') !== false;
        if ($isLikelyBinary) {
            return '';
        }

        $isXml = preg_match('/\.xml$/i', $relPath)
            || preg_match('/\.xml\b/i', $allNames)
            || stripos($mime, 'xml') !== false;

        $content = '';
        $absPath = $filesDir !== '' ? ($filesDir . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\')) : '';
        if ($absPath === '' || !file_exists($absPath)) {
            if ($filesDir !== '' && defined('BASE_SYS_DIR')) {
                $altAbs = rtrim((string) BASE_SYS_DIR, '/\\') . DIRECTORY_SEPARATOR . $filesDir . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\');
                if (file_exists($altAbs)) {
                    $absPath = $altAbs;
                }
            }
        }

        if ($absPath !== '' && file_exists($absPath)) {
            $content = (string) file_get_contents($absPath);
        } else {
            try {
                if (!$fileSvc && function_exists('app')) {
                    $fileSvc = app()->get('file');
                }
                if ($fileSvc && isset($fileSvc->fs) && method_exists($fileSvc->fs, 'read')) {
                    $content = (string) $fileSvc->fs->read($relPath);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($content === '') {
            return '';
        }

        if (!$isXml) {
            $head = substr(ltrim($content, "\xEF\xBB\xBF \t\r\n"), 0, 512);
            if (stripos($head, '<?xml') === false && stripos($head, '<article') === false) {
                return '';
            }
        }

        return $content;
    }

    /**
     * Add direct launch action in the OJS Plugins table
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $context = $request->getContext();
        if (!$context) {
            return $actions;
        }

        $dispatcher = $request->getDispatcher();
        $studioUrl = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'kisiTypesetter',
            'studio'
        );

        array_unshift(
            $actions,
            new LinkAction(
                'openStudio',
                new RedirectAction($studioUrl),
                __('plugins.generic.kisiTypesetter.openStudio'),
                null
            )
        );

        return $actions;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.kisiTypesetter.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.kisiTypesetter.description');
    }

    /**
     * Helper to get path to embedded master DOCX template
     */
    public function getMasterTemplatePath(): string
    {
        return $this->getPluginPath() . '/resources/master_template.docx';
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\kisiTypesetter\KisiTypesetterPlugin', '\KisiTypesetterPlugin');
}
