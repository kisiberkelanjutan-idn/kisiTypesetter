<?php
/**
 * @file plugins/generic/kisiTypesetter/templates/studioView.php
 *
 * Kisi Berkelanjutan Typesetter — Studio & AmeliCA JATS XML UI for OJS 3.5
 */
$defaultArticle = $activeArticleData ?: [
    'submission_id' => 76,
    'elocation_id' => 'e76',
    'article_type' => 'ORIGINAL ARTICLE',
    'form_mode' => (($settings['formLanguageMode'] ?? 'en') === 'bilingual') ? 'bilingual' : 'en',
    'title_id' => '',
    'title_en' => '',
    'authors' => [
        ['name' => '', 'affiliation' => '', 'email' => '', 'is_corresponding' => true],
    ],
    'corresponding_name' => '',
    'corresponding_email' => '',
    'citation' => '',
    'doi' => '',
    'review_editor' => '',
    'received_date' => '',
    'accepted_date' => '',
    'published_date' => 'March 30, 2024',
    'copyright_year' => '2024',
    'volume' => '1',
    'issue_number' => '1',
    'footer_issue_line' => 'Volume 1 Nomor 1, Januari-Maret 2024',
    'article_url' => 'https://kisiberkelanjutan.com/index.php/smk/article/view/76',
    'journal_path' => $context ? $context->getPath() : 'smk',
    'journal_title' => $settings['journalTitle'] ?? 'Kisi Berkelanjutan: Sains Medis Dan Kesehatan',
    'journal_short_footer' => $settings['journalShortFooter'] ?? 'Kisi Berkelanjutan: SMK',
    'publisher_name' => $settings['publisherName'] ?? 'PT Karya Inovasi Berkelanjutan',
    'eissn' => $settings['eissn'] ?? '3062-9063',
    'reference_style' => ($settings['referenceStyle'] ?? 'auto') !== 'auto' ? $settings['referenceStyle'] : 'vancouver',
    'funding' => $settings['defaultFunding'] ?? 'none',
    'competing_interests' => $settings['defaultCompetingInterests'] ?? 'The authors have declared that no competing interests exist.',
    'contributor_roles' => '',
    'retypeset_note' => $settings['retypesetNote'] ?? "This article was re-typeset in 2026 to standardize the journal's visual layout. No changes were made to the scientific substance, data, or metadata of the original Version of Record.",
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
];
$initialEnOnly = (($defaultArticle['form_mode'] ?? ($settings['formLanguageMode'] ?? 'en')) === 'en');
$studioFaviconUrl = !empty($journalFaviconUrl) ? (string) $journalFaviconUrl : (!empty($pluginFaviconUrl) ? (string) $pluginFaviconUrl : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kisi Berkelanjutan Typesetter — OJS 3.5</title>
    <?php if ($studioFaviconUrl !== ''): ?>
        <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($studioFaviconUrl, ENT_QUOTES, 'UTF-8'); ?>">
        <link rel="shortcut icon" href="<?php echo htmlspecialchars($studioFaviconUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <style>
        :root {
            --kisi-navy: #0b1f33;
            --kisi-navy-soft: #163756;
            --kisi-blue: #1d4ed8;
            --kisi-teal: #0f766e;
            --kisi-bg: #f1f5f9;
            --kisi-card: #ffffff;
            --kisi-border: #e2e8f0;
            --kisi-border-strong: #cbd5e1;
            --kisi-text: #0f172a;
            --kisi-muted: #64748b;
            --kisi-radius: 10px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--kisi-bg);
            color: var(--kisi-text);
            padding-bottom: 78px;
            font-size: 13px;
            -webkit-font-smoothing: antialiased;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(11, 31, 51, 0.96);
            backdrop-filter: blur(8px);
            color: #fff;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.16);
        }
        .topbar-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .topbar-logo {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: #fff;
            padding: 2px;
            object-fit: contain;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
        }
        .topbar-title h1 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .topbar-title span.badge {
            background: rgba(56, 189, 248, 0.18);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #bae6fd;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .btn-topbar {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 5px 11px;
            font-size: 12px;
            border-radius: 7px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.15s ease;
        }
        .btn-topbar:hover { background: rgba(255, 255, 255, 0.18); }
        .container {
            max-width: 1520px;
            margin: 12px auto;
            padding: 0 16px;
        }
        .card {
            background: var(--kisi-card);
            border: 1px solid var(--kisi-border);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 10px rgba(15, 23, 42, 0.02);
        }
        .card-compact { padding: 11px 14px; }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--kisi-border);
        }
        .card-header h2 {
            margin: 0;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--kisi-navy);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .grid-selector {
            display: grid;
            grid-template-columns: 240px 1fr auto;
            gap: 8px;
            align-items: end;
        }
        .source-strip {
            margin-top: 9px;
            padding-top: 9px;
            border-top: 1px dashed var(--kisi-border-strong);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .studio-layout {
            display: grid;
            grid-template-columns: 345px 1fr;
            gap: 12px;
            align-items: start;
        }
        .sidebar-sticky {
            position: sticky;
            top: 62px;
            max-height: calc(100vh - 134px);
            overflow-y: auto;
            scrollbar-width: thin;
        }
        @media (max-width: 1080px) {
            .grid-selector, .studio-layout { grid-template-columns: 1fr; }
            .sidebar-sticky { position: static; max-height: none; }
        }
        label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 3px;
        }
        label span.hint {
            font-weight: 400;
            font-size: 11px;
            color: var(--kisi-muted);
        }
        input[type="text"], input[type="password"], select, textarea {
            width: 100%;
            padding: 6px 9px;
            border: 1px solid var(--kisi-border-strong);
            border-radius: 7px;
            font-size: 12.5px;
            font-family: inherit;
            color: var(--kisi-text);
            background: #ffffff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input[type="text"]:focus, input[type="password"]:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--kisi-blue);
            box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.12);
        }
        textarea {
            resize: vertical;
            line-height: 1.48;
        }
        .field-group { margin-bottom: 8px; }
        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .row-65-35 { display: grid; grid-template-columns: 1.65fr 1fr; gap: 8px; }
        .row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
        @media (max-width: 720px) {
            .row-2, .row-65-35, .row-3 { grid-template-columns: 1fr; }
        }
        details.meta-group {
            border: 1px solid var(--kisi-border);
            border-radius: 9px;
            background: #f8fafc;
            margin-bottom: 8px;
            overflow: hidden;
        }
        details.meta-group > summary {
            list-style: none;
            cursor: pointer;
            padding: 7px 10px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: var(--kisi-navy);
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }
        details.meta-group > summary::-webkit-details-marker { display: none; }
        details.meta-group > summary::after {
            content: '▾';
            font-size: 12px;
            color: var(--kisi-muted);
            transition: transform 0.15s ease;
        }
        details.meta-group:not([open]) > summary::after { transform: rotate(-90deg); }
        .meta-group-body { padding: 8px 10px 4px; background: #fff; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-primary { background: var(--kisi-blue); color: #fff; }
        .btn-primary:hover { background: #1e40af; }
        .btn-navy { background: var(--kisi-navy); color: #fff; }
        .btn-navy:hover { background: var(--kisi-navy-soft); }
        .btn-teal { background: var(--kisi-teal); color: #fff; }
        .btn-teal:hover { background: #115e59; }
        .btn-ai { background: linear-gradient(135deg, #6d28d9, #2563eb); color: #fff; }
        .btn-ai:hover { opacity: 0.92; }
        .btn-outline { background: #fff; border-color: var(--kisi-border-strong); color: #334155; }
        .btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-sm { padding: 5px 9px; font-size: 12px; }
        .btn-xs { padding: 2px 7px; font-size: 11px; line-height: 1.3; border-radius: 5px; }
        .tabs {
            display: flex;
            gap: 4px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 9px;
            border: 1px solid var(--kisi-border);
            margin-bottom: 12px;
        }
        .tab-btn {
            padding: 6px 12px;
            background: transparent;
            border: none;
            border-radius: 7px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--kisi-muted);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .tab-btn:hover { color: var(--kisi-navy); background: rgba(255, 255, 255, 0.55); }
        .tab-btn.active {
            background: #ffffff;
            color: var(--kisi-blue);
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .author-row {
            display: grid;
            grid-template-columns: 48px 1.15fr 1.65fr 1fr auto 28px;
            gap: 6px;
            align-items: center;
            margin-bottom: 5px;
            background: #f8fafc;
            padding: 5px 7px;
            border-radius: 7px;
            border: 1px solid var(--kisi-border);
        }
        @media (max-width: 900px) {
            .author-row { grid-template-columns: 1fr; }
        }
        .word-badge {
            font-size: 10.5px;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #334155;
        }
        .section-card {
            margin-bottom: 12px;
            border: 1px solid var(--kisi-border-strong);
            border-radius: 10px;
            padding: 11px 12px;
            background: #ffffff;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }
        .section-card.section-drag-over { border-color: #2563eb; background: #eff6ff; }
        .section-dropzone-empty {
            border: 1.5px dashed var(--kisi-border-strong);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            color: var(--kisi-muted);
            font-size: 12px;
            background: #f8fafc;
        }
        .block-item {
            border: 1px solid var(--kisi-border);
            border-left: 3px solid #94a3b8;
            background: #f8fafc;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 7px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .block-item[data-b-type="paragraph"] { border-left-color: #3b82f6; }
        .block-item[data-b-type="subheading"],
        .block-item[data-b-type="subheading_l3"] { border-left-color: #0b1f33; background: #f1f5f9; }
        .block-item[data-b-type="table"] { border-left-color: #0f766e; background: #ffffff; }
        .block-item:hover { border-color: #cbd5e1; box-shadow: 0 1px 4px rgba(15, 23, 42, 0.05); }
        .block-item.is-dragging { opacity: 0.45; border-style: dashed; }
        .block-item.drop-above { border-top: 3px solid #2563eb; }
        .block-item.drop-below { border-bottom: 3px solid #2563eb; }
        .block-item.block-flash { animation: kisiBlockFlash 1.1s ease; }
        @keyframes kisiBlockFlash {
            0% { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.45); background: #eff6ff; }
            100% { box-shadow: none; background: #f8fafc; }
        }
        .block-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 6px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--kisi-muted);
        }
        .block-header-left, .block-header-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }
        .block-drag-handle {
            cursor: grab;
            padding: 2px 6px;
            border-radius: 4px;
            background: #e2e8f0;
            color: #334155;
            font-size: 12px;
            user-select: none;
            letter-spacing: -1px;
        }
        .block-drag-handle:active { cursor: grabbing; }
        .block-select-mini {
            width: auto !important;
            padding: 2px 6px !important;
            font-size: 11px !important;
            font-weight: 600;
            border-radius: 5px;
            background: #ffffff;
            color: #0f172a;
        }
        table.preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
            font-size: 12px;
            background: #fff;
            border-top: 2px solid #0b1f33;
            border-bottom: 2px solid #0b1f33;
            font-variant-numeric: tabular-nums;
        }
        table.preview-table thead tr:last-child th { border-bottom: 1.5px solid #0b1f33; }
        table.preview-table th, table.preview-table td { border-left: none; border-right: none; padding: 4px 6px; vertical-align: middle; }
        table.preview-table th { font-weight: 700; background: #f8fafc; border-bottom: 1px solid #cbd5e1; }
        table.preview-table td { border-bottom: 1px dashed #e2e8f0; }
        table.preview-table tr.row-type-group td { background: #f1f5f9; font-weight: 700; color: #0b1f33; }
        table.preview-table tr.row-type-subitem td:first-child input { padding-left: 16px; }
        table.preview-table tr.row-type-total td { border-top: 1.5px solid #475569; font-weight: 700; background: #f8fafc; }
        .table-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 6px;
            padding: 5px 8px;
            background: #f1f5f9;
            border-radius: 6px;
        }
        .col-align-bar { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; font-size: 11px; color: #475569; }
        .audit-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }
        .audit-stat-box { background: #f8fafc; border: 1px solid var(--kisi-border); border-radius: 8px; padding: 8px 10px; }
        .audit-stat-num { font-size: 18px; font-weight: 800; color: var(--kisi-navy); }
        .audit-stat-label { font-size: 11px; color: var(--kisi-muted); font-weight: 600; }
        .audit-issue-card {
            border: 1px solid var(--kisi-border);
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 8px;
            background: #ffffff;
        }
        .audit-issue-card.sev-error { border-left-color: #dc2626; }
        .audit-badge { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; }
        .audit-badge-err { background: #fee2e2; color: #b91c1c; }
        .audit-badge-warn { background: #fef3c7; color: #92400e; }
        .audit-badge-lang { background: #e0f2fe; color: #0369a1; }
        .audit-badge-src { background: #f1f5f9; color: #334155; }
        .audit-context {
            background: #f8fafc;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 12px;
            margin: 5px 0;
            color: #334155;
            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
        }
        .sticky-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(8px);
            border-top: 1px solid var(--kisi-border);
            padding: 9px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.06);
            z-index: 999;
        }
        .status-toast { padding: 5px 11px; border-radius: 6px; font-size: 12px; font-weight: 600; display: none; }
        .status-toast.info { display: inline-block; background: #e0f2fe; color: #0369a1; }
        .status-toast.success { display: inline-block; background: #dcfce7; color: #15803d; }
        .status-toast.error { display: inline-block; background: #fee2e2; color: #b91c1c; }
        .aff-preview-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 7px;
            padding: 7px 10px;
            font-size: 11.5px;
            color: #166534;
            margin-top: 6px;
            line-height: 1.45;
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-title">
        <?php if ($studioFaviconUrl !== ''): ?>
            <img src="<?php echo htmlspecialchars($studioFaviconUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Kisi Berkelanjutan" class="topbar-logo">
        <?php endif; ?>
        <h1>Kisi Berkelanjutan Typesetter</h1>
        <span class="badge">OJS 3.5 · JATS XML &amp; DOCX</span>
    </div>
    <div class="topbar-actions">
        <button type="button" class="btn-topbar" onclick="toggleSettingsDrawer()">
            ⚙️ Pengaturan AI &amp; Jurnal
        </button>
        <a href="<?php echo htmlspecialchars($dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'manageIssues'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-topbar">
            📚 Terbitan (Issues)
        </a>
        <a href="<?php echo htmlspecialchars($dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'dashboard', 'editorial'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-topbar">
            ← Dashboard OJS
        </a>
    </div>
</div>

<div class="container">

    <!-- Collapsible Settings Drawer -->
    <div id="settingsDrawer" class="card" style="display:none;border-color:#93c5fd;background:#f8fafc;">
        <div class="card-header">
            <h2>⚙️ Pengaturan Plugin (API Key Gemini &amp; Identitas Jurnal Kisi Berkelanjutan)</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="savePluginSettings()">💾 Simpan Pengaturan</button>
        </div>
        <div class="row-3">
            <div class="field-group">
                <label>Google Gemini API Key <span class="hint">(Untuk otomatis pecah abstrak &amp; struktur)</span></label>
                <input type="password" id="cfg_geminiApiKey" value="<?php echo htmlspecialchars($settings['geminiApiKey'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="AIzaSy...">
            </div>
            <div class="field-group">
                <label>Model Gemini</label>
                <input type="text" id="cfg_geminiModel" value="<?php echo htmlspecialchars($settings['geminiModel'] ?? 'gemini-2.5-flash', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="field-group">
                <label>E-ISSN Jurnal</label>
                <input type="text" id="cfg_eissn" value="<?php echo htmlspecialchars($settings['eissn'] ?? '3062-9063', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
        <div class="row-3">
            <div class="field-group">
                <label>Nama Jurnal Lengkap (Untuk Sitasi Artikel &amp; JATS XML)</label>
                <input type="text" id="cfg_journalTitle" value="<?php echo htmlspecialchars($settings['journalTitle'] ?? 'Kisi Berkelanjutan: Sains Medis Dan Kesehatan', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="field-group">
                <label>Nama Pendek Jurnal (Untuk Footer DOCX)</label>
                <input type="text" id="cfg_journalShortFooter" value="<?php echo htmlspecialchars($settings['journalShortFooter'] ?? 'Kisi Berkelanjutan: SMK', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="field-group">
                <label>Nama Penerbit</label>
                <input type="text" id="cfg_publisherName" value="<?php echo htmlspecialchars($settings['publisherName'] ?? 'PT Karya Inovasi Berkelanjutan', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
        <div class="row-2">
            <div class="field-group">
                <label>Teks Kotak Catatan Publisher's Note Box <span class="hint">(Opsional — kosongkan bila tidak diperlukan)</span></label>
                <input type="text" id="cfg_retypesetNote" value="<?php echo htmlspecialchars($settings['retypesetNote'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="field-group">
                <label>Format Referensi Menu References OJS <span class="hint">(Sesuai gaya sitasi resmi jurnal)</span></label>
                <select id="cfg_referenceStyle">
                    <option value="auto" <?php echo (($settings['referenceStyle'] ?? 'auto') === 'auto') ? 'selected' : ''; ?>>🔄 Otomatis Ikuti Pengaturan CSL OJS (Default: Vancouver)</option>
                    <option value="vancouver" <?php echo (($settings['referenceStyle'] ?? 'auto') === 'vancouver') ? 'selected' : ''; ?>>📚 Vancouver (ICMJE Bernomor: 1. Author AA...)</option>
                    <option value="ieee" <?php echo (($settings['referenceStyle'] ?? 'auto') === 'ieee') ? 'selected' : ''; ?>>📘 IEEE ([1] Author AA...)</option>
                    <option value="apa" <?php echo (($settings['referenceStyle'] ?? 'auto') === 'apa') ? 'selected' : ''; ?>>📙 APA (Author, A. A. (Tahun)...)</option>
                </select>
            </div>
        </div>
        <div class="row-2">
            <div class="field-group">
                <label>Digest Otomatis JATS XML ke Halaman Full-Text Website Jurnal</label>
                <select id="cfg_enableFullTextDigest">
                    <option value="1" <?php echo (($settings['enableFullTextDigest'] ?? '1') !== '0') ? 'selected' : ''; ?>>✅ Aktif (Tampilkan Full-Text + Abstrak di `/article/view/{id}` &amp; Galley XML)</option>
                    <option value="0" <?php echo (($settings['enableFullTextDigest'] ?? '1') === '0') ? 'selected' : ''; ?>>Plugin Eksternal (JATS Parser / Lens — KisiTypesetter tetap isi Abstrak OJS yang kosong)</option>
                </select>
            </div>
            <div class="field-group">
                <label>Posisi &amp; Tampilan Abstrak di Halaman Full-Text Artikel</label>
                <select id="cfg_replaceDefaultAbstract">
                    <option value="1" <?php echo (($settings['replaceDefaultAbstract'] ?? '1') !== '0') ? 'selected' : ''; ?>>✅ Tampilkan Abstrak Terstruktur dari JATS XML (Upgrade blok abstrak halaman)</option>
                    <option value="0" <?php echo (($settings['replaceDefaultAbstract'] ?? '1') === '0') ? 'selected' : ''; ?>>Pertahankan blok Abstrak bawaan OJS + tetap tampilkan Abstrak di Full-Text XML</option>
                </select>
            </div>
        </div>
        <div class="row-2">
            <div class="field-group" style="margin-bottom:0;">
                <label>Mode Output JATS XML &amp; Bahasa Formulir <span class="hint">(Studio &amp; PDF tetap tampilkan Abstrak ID &amp; EN)</span></label>
                <select id="cfg_formLanguageMode" onchange="applyFormLanguageMode(); syncStateFromInputs();">
                    <option value="en" <?php echo (($settings['formLanguageMode'] ?? 'en') === 'en') ? 'selected' : ''; ?>>🇬🇧 JATS English-Only (1 Abstrak, Judul, Keyword &amp; SDGs EN — Studio/PDF tetap ID &amp; EN)</option>
                    <option value="auto" <?php echo (($settings['formLanguageMode'] ?? 'en') === 'auto') ? 'selected' : ''; ?>>🔄 Otomatis Ikuti Konten JATS</option>
                    <option value="bilingual" <?php echo (($settings['formLanguageMode'] ?? 'en') === 'bilingual') ? 'selected' : ''; ?>>🇮🇩🇬🇧 Bilingual Penuh (ID &amp; EN pada Studio, PDF &amp; JATS XML)</option>
                </select>
            </div>
            <div class="field-group" style="margin-bottom:0;display:flex;align-items:flex-end;">
                <div style="font-size:11.5px;color:var(--kisi-muted);background:#f8fafc;border:1px solid var(--kisi-border);padding:7px 10px;border-radius:7px;width:100%;">
                    💡 Dalam mode standar <strong>JATS English-Only</strong>, form Abstrak Indonesia tetap tersedia di Studio agar muncul di file DOCX/PDF, sedangkan output JATS XML hanya memuat 1 abstrak, judul, kata kunci, &amp; SDGs berbahasa Inggris (<code>xml:lang=&quot;en&quot;</code>).
                </div>
            </div>
        </div>
    </div>

    <!-- Step 1: Compact Control Strip (Select Issue / Article & Load DOCX) -->
    <div class="card card-compact">
        <div class="card-header" style="margin-bottom:8px;padding-bottom:6px;">
            <h2>1️⃣ Pilih Artikel OJS &amp; Sumber Naskah (.docx)</h2>
            <span id="statusToast" class="status-toast"></span>
        </div>
        <form method="get" action="<?php echo htmlspecialchars($baseUrl . '/studio', ENT_QUOTES, 'UTF-8'); ?>" class="grid-selector">
            <div>
                <label>Filter Edisi</label>
                <select name="issueId" onchange="this.form.submit()">
                    <option value="0">— Semua Edisi (Berjalan &amp; Arsip) —</option>
                    <?php foreach ($issuesList as $iss): ?>
                        <option value="<?php echo (int) $iss['id']; ?>" <?php echo ($selectedIssueId === (int) $iss['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($iss['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Artikel OJS <span class="hint">(Auto-tarik Judul, Penulis, DOI &amp; Review Editor)</span></label>
                <select name="submissionId" onchange="this.form.submit()">
                    <option value="0">— Pilih Artikel untuk Diformat —</option>
                    <?php foreach ($submissionsList as $sub): ?>
                        <option value="<?php echo (int) $sub['id']; ?>" <?php echo ($selectedSubmissionId === (int) $sub['id']) ? 'selected' : ''; ?>>
                            [#<?php echo (int) $sub['id']; ?> / <?php echo htmlspecialchars($sub['pages'], ENT_QUOTES, 'UTF-8'); ?>] <?php echo htmlspecialchars(mb_strimwidth($sub['title'], 0, 95, '...'), ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($sub['authors'], ENT_QUOTES, 'UTF-8'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-navy btn-sm">🔄 Muat</button>
            </div>
        </form>

        <div class="source-strip">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:700;color:var(--kisi-navy);">📄 Sumber Naskah:</span>
                <?php if (!empty($attachedDocxFiles)): ?>
                    <select id="ojsDocxFileSelect" style="width:auto;max-width:340px;">
                        <?php foreach ($attachedDocxFiles as $df): ?>
                            <option value="<?php echo (int) $df['id']; ?>">
                                [#<?php echo (int) $df['id']; ?>] <?php echo htmlspecialchars($df['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline btn-sm" onclick="parseOjsDocxFile()">📂 Ekstrak OJS</button>
                <?php else: ?>
                    <span style="font-size:11.5px;color:var(--kisi-muted);">Belum ada .docx di tahapan OJS, unggah lokal:</span>
                <?php endif; ?>

                <label class="btn btn-outline btn-sm" style="margin:0;cursor:pointer;">
                    📤 Upload .docx
                    <input type="file" id="uploadDocxInput" accept=".docx" style="display:none;" onchange="uploadAndParseLocalDocx(this)">
                </label>
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-outline btn-sm" onclick="quickOpenLanguageAudit()">
                    🔍 Audit Bahasa
                </button>
                <button type="button" class="btn btn-ai btn-sm" onclick="runAiRestructure()">
                    ✨ AI Struktur &amp; Terjemahan
                </button>
            </div>
        </div>
    </div>

    <!-- Step 2: Interactive Workbench & Compact Editor -->
    <div class="studio-layout">

        <!-- Left Column: Sticky Compact Metadata Sidebar -->
        <div class="card card-compact sidebar-sticky">
            <div class="card-header" style="margin-bottom:8px;padding-bottom:6px;">
                <h2>📌 Metadata &amp; Sidebar</h2>
                <button type="button" class="btn btn-outline btn-xs" onclick="rebuildApaCitation()" title="Susun ulang sitasi APA dari judul, penulis, volume &amp; URL">🔄 Rakit Sitasi</button>
            </div>

            <details class="meta-group" open>
                <summary>1. Kategori, Edisi &amp; Tautan</summary>
                <div class="meta-group-body">
                    <div class="row-65-35">
                        <div class="field-group">
                            <label>Kategori Artikel</label>
                            <select id="f_article_type_preset" onchange="onArticleTypePresetChange()">
                                <option value="ORIGINAL ARTICLE">ORIGINAL ARTICLE</option>
                                <option value="REVIEW ARTICLE">REVIEW ARTICLE</option>
                                <option value="DATA REPORT">DATA REPORT (Tanpa Abstrak)</option>
                                <option value="LETTER">LETTER (Tanpa Abstrak)</option>
                                <option value="CASE REPORT">CASE REPORT</option>
                                <option value="SHORT COMMUNICATION">SHORT COMMUNICATION</option>
                                <option value="CUSTOM">Kustom (Manual)...</option>
                            </select>
                            <input type="text" id="f_article_type" style="margin-top:5px;display:none;" placeholder="Ketik kategori..." oninput="syncStateFromInputs(); applyArticleTypeLayoutMode();">
                        </div>
                        <div class="field-group">
                            <label>ID (e-ID)</label>
                            <input type="text" id="f_elocation_id" placeholder="e32" oninput="syncStateFromInputs()">
                        </div>
                    </div>

                    <div class="row-3">
                        <div class="field-group">
                            <label>Volume</label>
                            <input type="text" id="f_volume" oninput="syncStateFromInputs(); applyVolumeTemplateMode();">
                        </div>
                        <div class="field-group">
                            <label>Nomor</label>
                            <input type="text" id="f_issue_number" oninput="syncStateFromInputs();">
                        </div>
                        <div class="field-group">
                            <label>Tahun</label>
                            <input type="text" id="f_copyright_year" oninput="syncStateFromInputs();">
                        </div>
                    </div>

                    <div id="volumeTemplateModeBadge" style="font-size:11px;font-weight:600;padding:4px 8px;border-radius:6px;margin:-4px 0 8px;background:#f1f5f9;color:#334155;"></div>

                    <div id="vol3DatesRow" class="row-2" style="display:none;">
                        <div class="field-group">
                            <label>Received <span class="hint">(Vol 3+)</span></label>
                            <input type="text" id="f_received_date" placeholder="August 11, 2026" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)">
                        </div>
                        <div class="field-group">
                            <label>Accepted <span class="hint">(Vol 3+)</span></label>
                            <input type="text" id="f_accepted_date" placeholder="September 25, 2026" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)">
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="field-group">
                            <label>Published</label>
                            <input type="text" id="f_published_date" placeholder="March 30, 2024" oninput="syncStateFromInputs()">
                        </div>
                        <div class="field-group">
                            <label>
                                <span>Footer Edisi <span class="hint">(Bulan ID)</span></span>
                                <button type="button" class="btn btn-outline btn-xs" onclick="rebuildFooterIssueLine()" title="Susun footer edisi berbahasa Indonesia">↻ ID</button>
                            </label>
                            <input type="text" id="f_footer_issue_line" placeholder="Volume 3 Nomor 3, Juli-September 2026" oninput="syncStateFromInputs()" onblur="normalizeFooterMonthsInput(this)">
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="field-group">
                            <label>DOI</label>
                            <input type="text" id="f_doi" placeholder="10.5281/zenodo..." oninput="syncStateFromInputs()">
                        </div>
                        <div class="field-group">
                            <label>URL Artikel</label>
                            <input type="text" id="f_article_url" oninput="syncStateFromInputs()">
                        </div>
                    </div>
                </div>
            </details>

            <details class="meta-group" open>
                <summary>2. Editorial &amp; Sitasi</summary>
                <div class="meta-group-body">
                    <div class="field-group">
                        <label>Review Editor <span class="hint">(OJS Subject)</span></label>
                        <textarea id="f_review_editor" rows="2" placeholder="Nama Editor (Afiliasi)" oninput="syncStateFromInputs()"></textarea>
                    </div>

                    <div class="field-group">
                        <label>
                            <span>Sitasi Artikel <span class="hint">(Sidebar Metadata)</span></span>
                            <button type="button" class="btn btn-outline btn-xs" onclick="rebuildApaCitation()">↻ Auto</button>
                        </label>
                        <textarea id="f_citation" rows="2" oninput="syncStateFromInputs()"></textarea>
                    </div>
                </div>
            </details>

            <details class="meta-group" open>
                <summary>3. Funding, Konflik &amp; Contributor Roles</summary>
                <div class="meta-group-body">
                    <div class="field-group">
                        <label>Funding</label>
                        <input type="text" id="f_funding" oninput="syncStateFromInputs()">
                    </div>
                    <div class="field-group">
                        <label>Competing Interests</label>
                        <input type="text" id="f_competing_interests" oninput="syncStateFromInputs()">
                    </div>
                    <div class="field-group" id="vol3ContribRolesGroup" style="display:none;">
                        <label>
                            <span>Contributor’s Roles <span class="hint">(Vol 3+)</span></span>
                            <button type="button" class="btn btn-outline btn-xs" onclick="rebuildContributorRolesDefault()">↻ Inisial</button>
                        </label>
                        <textarea id="f_contributor_roles" rows="2" placeholder="ARAP (Conceptualization, Methodology, Writing – Original Draft Preparation), ..." oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                    </div>
                    <div class="field-group" id="retypesetNoteGroup">
                        <label>Catatan Re-typeset 2026 <span class="hint" id="retypesetNoteHint">(Vol 1–2 Arsip)</span></label>
                        <textarea id="f_retypeset_note" rows="2" oninput="syncStateFromInputs()"></textarea>
                    </div>
                </div>
            </details>
        </div>

        <!-- Right Column: Main Article Content -->
        <div class="card card-compact">
            <div class="card-header" style="margin-bottom:8px;padding-bottom:6px;">
                <h2>📝 Editor Naskah, Abstrak &amp; Struktur (DOCX + JATS XML)</h2>
            </div>

            <!-- Article Titles (English-Only or Bilingual depending on Form Mode) -->
            <div id="titleFieldsRow" class="row-2"<?php echo $initialEnOnly ? ' style="grid-template-columns:1fr;"' : ''; ?>>
                <div class="field-group" id="titleIdGroup"<?php echo $initialEnOnly ? ' style="display:none;"' : ''; ?>>
                    <label>Judul Artikel (Indonesia — Bold 14pt)</label>
                    <textarea id="f_title_id" rows="2" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                </div>
                <div class="field-group" id="titleEnGroup">
                    <label id="titleEnLabel"><?php echo $initialEnOnly ? 'Article Title / Judul Artikel (English — Bold 14pt)' : 'Judul Artikel (Inggris — Italic 14pt)'; ?></label>
                    <textarea id="f_title_en" rows="2" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                </div>
            </div>

            <!-- Authors & Corresponding Strip (Compact) -->
            <div class="field-group" style="margin-top:4px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;gap:8px;flex-wrap:wrap;">
                    <label style="margin:0;">Penulis &amp; Afiliasi <span class="hint">(Afiliasi identik otomatis digabung nomornya)</span></label>
                    <button type="button" class="btn btn-outline btn-xs" onclick="addAuthorRow()">+ Penulis</button>
                </div>
                <div id="authorsContainer"></div>
                <div id="affDeduplicationPreview" class="aff-preview-box"></div>
            </div>

            <div class="row-2" style="margin-top:6px;background:#f8fafc;padding:7px 9px;border-radius:7px;border:1px solid var(--kisi-border);">
                <div class="field-group" style="margin-bottom:0;">
                    <label>Penulis Koresponden (*)</label>
                    <input type="text" id="f_corresponding_name" placeholder="Nama lengkap..." oninput="syncStateFromInputs()">
                </div>
                <div class="field-group" style="margin-bottom:0;">
                    <label>Email Koresponden</label>
                    <input type="text" id="f_corresponding_email" placeholder="email@institusi.ac.id" oninput="syncStateFromInputs()">
                </div>
            </div>

            <!-- Content Tabs -->
            <div class="tabs" style="margin-top:12px;flex-wrap:wrap;">
                <button type="button" class="tab-btn active" data-tab="tab-abstracts" onclick="switchTab('tab-abstracts', this)">
                    1. Abstrak <span id="absTabStatusBadge" style="font-weight:500;"><?php echo $initialEnOnly ? '(ID PDF &amp; EN JATS)' : '(ID &amp; EN)'; ?></span>
                </button>
                <button type="button" class="tab-btn" data-tab="tab-body" onclick="switchTab('tab-body', this)">
                    2. Isi &amp; Tabel Kesehatan
                </button>
                <button type="button" class="tab-btn" data-tab="tab-references" onclick="switchTab('tab-references', this)">
                    3. Pustaka (<span id="refCountBadge">0</span>)
                </button>
                <button type="button" class="tab-btn" data-tab="tab-fulltext-preview" onclick="switchTab('tab-fulltext-preview', this); loadFullTextDigestPreview();">
                    4. 👁️ Pratinjau Full-Text
                </button>
                <button type="button" class="tab-btn" data-tab="tab-language-audit" onclick="switchTab('tab-language-audit', this)">
                    5. 🔍 Audit Bahasa (<span id="auditCountBadge">0</span>)
                </button>
            </div>

            <!-- TAB 1: Bilingual Structured Abstracts -->
            <div id="tab-abstracts" class="tab-pane active">
                <div id="noAbstractNotice" style="display:none;background:#eff6ff;border:1px solid #93c5fd;color:#1e3a8a;padding:10px 12px;border-radius:8px;margin-bottom:10px;font-size:12.5px;">
                    📄 <strong>Mode Tanpa Abstrak Aktif (<span id="noAbstractCatLabel">DATA REPORT / LETTER</span>):</strong>
                    Sesuai template resmi, kategori ini tidak menampilkan kolom <em>ABSTRAK</em>, <em>ABSTRACT</em>, maupun <em>Kata Kunci / Keywords</em> pada DOCX maupun JATS XML. Silakan langsung sunting isi naskah di <strong>Tab 2 (Isi &amp; Tabel)</strong>.
                </div>
                <div id="abstractInputsGrid" class="row-2">
                    <!-- Indonesian Abstract (Always visible in Studio for DOCX/PDF rendering) -->
                    <div id="abstractIdCard" style="background:#f8fafc;padding:10px 12px;border-radius:8px;border:1px solid var(--kisi-border);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:6px;flex-wrap:wrap;">
                            <h3 style="margin:0;font-size:13px;color:var(--kisi-navy);">🇮🇩 ABSTRAK (Indonesia — <span id="abstractIdRoleHint"><?php echo $initialEnOnly ? 'Khusus DOCX/PDF' : 'PDF &amp; JATS XML'; ?></span>)</h3>
                            <span id="idAbsWordCount" class="word-badge">0 kata</span>
                        </div>
                        <div class="field-group">
                            <label>Pendahuluan dan Metode</label>
                            <textarea id="f_ab_id_pm" rows="4" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                        <div class="field-group">
                            <label>Hasil</label>
                            <textarea id="f_ab_id_hasil" rows="3" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                        <div class="field-group">
                            <label>Kesimpulan dan Saran</label>
                            <textarea id="f_ab_id_kes" rows="3" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                        <div class="field-group">
                            <label>Kata Kunci <span class="hint">(Pisah titik koma ;)</span></label>
                            <input type="text" id="f_keywords_id" placeholder="Sosial budaya; Pemberian ASI" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)">
                        </div>
                        <div class="field-group" id="vol3SdgsIdGroup" style="display:none;margin-bottom:0;">
                            <label>Kontribusi terhadap Target Pembangunan Berkelanjutan (TPB/SDGs) <span class="hint">(Vol 3+)</span></label>
                            <textarea id="f_sdgs_id" rows="2" placeholder="Penelitian ini berkontribusi pada pencapaian Tujuan Pembangunan Berkelanjutan (TPB/SDGs)..." oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                    </div>

                    <!-- English Abstract -->
                    <div id="abstractEnCard" style="background:#f8fafc;padding:10px 12px;border-radius:8px;border:1px solid var(--kisi-border);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <h3 id="abstractEnHeading" style="margin:0;font-size:13px;color:var(--kisi-navy);"><?php echo $initialEnOnly ? '🇬🇧 ABSTRACT (English — PDF &amp; JATS XML)' : '🇬🇧 ABSTRACT (English — Italic)'; ?></h3>
                            <span id="enAbsWordCount" class="word-badge">0 words</span>
                        </div>
                        <div class="field-group">
                            <label>Introduction and Methods</label>
                            <textarea id="f_ab_en_im" rows="4" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                        <div class="field-group">
                            <label>Results</label>
                            <textarea id="f_ab_en_res" rows="3" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                        <div class="field-group">
                            <label>Conclusions and Recommendations</label>
                            <textarea id="f_ab_en_conc" rows="3" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                        <div class="field-group">
                            <label>Keywords <span class="hint">(Separate with ;)</span></label>
                            <input type="text" id="f_keywords_en" placeholder="Breastfeeding; Socio-cultural" oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)">
                        </div>
                        <div class="field-group" id="vol3SdgsEnGroup" style="display:none;margin-bottom:0;">
                            <label>Contribution to Sustainable Development Goals (SDGs) <span class="hint">(Vol 3+ — Italic)</span></label>
                            <textarea id="f_sdgs_en" rows="2" placeholder="This study contributes to achieving the Sustainable Development Goals (SDGs)..." oninput="syncStateFromInputs()" onblur="autoNormalizeInputElement(this)"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: Main Body Sections & Tables -->
            <div id="tab-body" class="tab-pane">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;background:#f8fafc;border:1px solid #cbd5e1;padding:10px 14px;border-radius:8px;">
                    <div style="font-size:12.5px;color:#334155;">
                        💡 <strong>Atur Urutan &amp; Level Manual:</strong> Seret ikon <strong>⋮⋮</strong> atau klik tombol <strong>▲ / ▼</strong> untuk menggeser blok naik-turun maupun lintas bab (<em>PENDAHULUAN ↔ METODE ↔ HASIL ↔ PEMBAHASAN ↔ KESIMPULAN</em>). Gunakan dropdown <strong>Level Blok</strong> untuk ubah <em>Paragraf ↔ Sub-Judul</em>.
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" id="btnMergeImradToBody" class="btn btn-outline btn-sm" style="display:none;" onclick="mergeImradSectionsIntoDirectBody()">
                            📥 Gabungkan IMRaD ke Badan Artikel Langsung
                        </button>
                        <button type="button" id="btnToggleOptionalImrad" class="btn btn-outline btn-sm" style="display:none;" onclick="toggleOptionalImradSections()">
                            ➕ Bagian IMRaD Opsional
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="normalizeAllArticleSpacing(true)">
                            ✂️ Rapikan Spasi (&le; 1 Spasi)
                        </button>
                        <button type="button" class="btn btn-teal btn-sm" onclick="formatAllTablesHealthStandard(true)">
                            🏥 Format Semua Tabel (Standar Ilmiah Kesehatan)
                        </button>
                    </div>
                </div>
                <div id="bodySectionsContainer"></div>
            </div>

            <!-- TAB 3: References (Journal Style Synchronized + OJS References Menu Parser) -->
            <div id="tab-references" class="tab-pane">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;background:#f8fafc;border:1px solid #cbd5e1;padding:10px 14px;border-radius:8px;">
                    <div style="font-size:12.5px;color:#334155;">
                        📚 <strong>Daftar Sumber Pustaka (<span id="journalRefStyleLabel">Gaya Vancouver</span>):</strong> Otomatis sinkron dengan sitasi naskah, tercetak di DOCX &amp; JATS XML, serta dapat diparser langsung ke menu <strong>References OJS</strong>.
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-teal btn-sm" onclick="formatVancouverReferencesAjax()">
                            📚 Format Sesuai Gaya Jurnal
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" onclick="syncReferencesToOjsMenu()">
                            📤 Parser ke Menu References OJS
                        </button>
                    </div>
                </div>
                <div id="vancouverSyncStatusBox" style="font-size:12px;padding:7px 12px;border-radius:7px;margin-bottom:10px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;"></div>
                <div class="field-group">
                    <label>Daftar Sumber Pustaka — 1 Entri Per Baris <span class="hint">(Gaya Vancouver: Penulis Inisial. Judul. Jurnal. Tahun;Vol(No):Hal. doi:...)</span></label>
                    <textarea id="f_referensi" rows="16" oninput="syncReferencesFromInput()" placeholder="1. Ahmed SOM, Ashgar RI, Abdelgader AAM, Hamid HIA, Mathkor DM, Ali MAA, et al. Exclusive breastfeeding: Impact on infant health. Clin Nutr Open Sci. 2023;51:44–51. doi:10.1016/j.nutos.2023.08.003"></textarea>
                </div>
            </div>

            <!-- TAB 4: Full-Text Digest Preview (Exactly as rendered on /article/view/{id}) -->
            <div id="tab-fulltext-preview" class="tab-pane">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;background:#f0fdf4;border:1px solid #bbf7d0;padding:10px 14px;border-radius:8px;">
                    <div style="font-size:13px;color:#166534;">
                        ✅ <strong>Pratinjau Live Digest JATS XML:</strong> Ini adalah tampilan <em>Full-Text HTML</em> yang otomatis muncul di halaman utama artikel jurnal (<code>/article/view/ID</code>) saat Anda menyimpan JATS XML ke Galley OJS.
                    </div>
                    <button type="button" class="btn btn-teal btn-sm" onclick="loadFullTextDigestPreview()">🔄 Segarkan Pratinjau Full-Text</button>
                </div>
                <div id="fullTextDigestPreviewBox" style="border:1px solid #cbd5e1;border-radius:10px;padding:16px 24px;background:#ffffff;min-height:300px;">
                    Klik "Segarkan Pratinjau Full-Text" untuk melihat hasil digest JATS XML...
                </div>
            </div>

            <!-- TAB 5: Bilingual Typo & Non-Standard Word Auditor -->
            <div id="tab-language-audit" class="tab-pane">
                <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:14px;margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
                        <div>
                            <strong style="font-size:14px;color:var(--kisi-navy);">🔍 Pemeriksa Typo &amp; Kata Tidak Baku (Bahasa Indonesia &amp; Inggris)</strong>
                            <div style="font-size:12px;color:var(--kisi-muted);margin-top:2px;">
                                Sumber terpercaya: <strong>KBBI VI &amp; EYD V (Badan Bahasa Kemendikbudristek)</strong>, <strong>Hunspell ID</strong>, <strong>SCOWL US English</strong>, serta <strong>AMA Manual of Style / Merriam-Webster</strong>.
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="normalizeAllArticleSpacing(true)">✂️ Rapikan Spasi (&le; 1 Spasi)</button>
                            <button type="button" class="btn btn-primary btn-sm" onclick="runLanguageAudit()">🔍 Jalankan Audit Bahasa</button>
                            <button type="button" class="btn btn-teal btn-sm" id="btnApplyAllSafeFixes" onclick="applyAllSafeAuditFixes()" style="display:none;">✨ Terapkan Semua Perbaikan Aman</button>
                        </div>
                    </div>

                    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding-top:8px;border-top:1px dashed #cbd5e1;font-size:12.5px;">
                        <label style="margin:0;display:flex;align-items:center;gap:6px;">
                            <span>Bahasa Isi Artikel:</span>
                            <select id="auditBodyLang" style="width:auto;padding:4px 8px;font-size:12px;">
                                <option value="auto">Deteksi Otomatis (ID / EN)</option>
                                <option value="id">Bahasa Indonesia (KBBI &amp; EYD V)</option>
                                <option value="en">English (SCOWL &amp; AMA Style)</option>
                            </select>
                        </label>
                        <label style="margin:0;display:flex;align-items:center;gap:5px;cursor:pointer;font-weight:500;">
                            <input type="checkbox" id="auditUseLanguageTool"> Sertakan LanguageTool API (Verifikasi Grammar EN)
                        </label>
                        <label style="margin:0;display:flex;align-items:center;gap:5px;cursor:pointer;font-weight:500;">
                            <input type="checkbox" id="auditUseGemini"> Deep Audit Gemini AI (KBBI/EYD V + AMA)
                        </label>
                    </div>
                </div>

                <div id="languageAuditResultsBox">
                    <div style="padding:28px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:8px;background:#fff;">
                        Klik tombol <strong>"🔍 Jalankan Audit Bahasa"</strong> untuk memeriksa typo, kata tidak baku (mis. <em>analisa → analisis</em>, <em>resiko → risiko</em>, <em>mengkonsumsi → mengonsumsi</em>, <em>di lakukan → dilakukan</em>), serta ejaan Bahasa Inggris (US/AMA vs UK &amp; typo medis).
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Sticky Bottom Action Bar -->
<div class="sticky-footer">
    <div style="font-size:13px;color:var(--kisi-muted);">
        💡 <strong>Tips:</strong> Pastikan bagian <em>Review Editor</em> dan <em>ABSTRAK 3 bagian</em> sudah sesuai di pratinjau sebelum mengunduh atau menyimpan ke Galley.
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-navy btn-sm" onclick="downloadGeneratedFile('generateDocx')">
            📄 Download DOCX
        </button>
        <button type="button" class="btn btn-teal btn-sm" onclick="downloadGeneratedFile('generateJats')">
            🧬 Download JATS XML
        </button>
        <button type="button" class="btn btn-primary btn-sm" onclick="saveDirectlyToOjsGalley()">
            🚀 Simpan ke Galley &amp; References OJS
        </button>
    </div>
</div>

<!-- Hidden form for file downloads -->
<form id="downloadForm" method="post" target="_blank" style="display:none;">
    <input type="hidden" name="articleJson" id="downloadArticleJson">
</form>

<script>
const BASE_URL = <?php echo json_encode($baseUrl, JSON_UNESCAPED_UNICODE); ?>;
let articleState = <?php echo json_encode($defaultArticle, JSON_UNESCAPED_UNICODE); ?>;

const ARTICLE_TYPE_PRESETS = ['ORIGINAL ARTICLE', 'REVIEW ARTICLE', 'DATA REPORT', 'LETTER', 'CASE REPORT', 'SHORT COMMUNICATION'];
let showOptionalImradForNoAbs = false;

function isNoAbstractType(artType) {
    const u = String(artType || '').toUpperCase().trim();
    return /\b(DATA\s*REPORT|LETTER|LETTERS?\s+TO\s+(?:THE\s+)?EDITOR|LAPORAN\s*DATA|SURAT\s+(?:KE\s+)?REDAKSI)\b/.test(u);
}

const SECTION_LABELS = {
    badan_artikel: 'BADAN ARTIKEL LANGSUNG (Tanpa Heading IMRaD — Standar Data Report / Letter)',
    pendahuluan: 'PENDAHULUAN',
    metode: 'METODE',
    hasil: 'HASIL (Paragraf & Tabel Penelitian)',
    pembahasan: 'PEMBAHASAN (Sub-Judul & Paragraf)',
    kesimpulan_saran: 'KESIMPULAN DAN SARAN'
};

function showToast(msg, type = 'info') {
    const el = document.getElementById('statusToast');
    el.className = 'status-toast ' + type;
    el.textContent = msg;
}

function toggleSettingsDrawer() {
    const d = document.getElementById('settingsDrawer');
    d.style.display = (d.style.display === 'none') ? 'block' : 'none';
}

function switchTab(tabId, btnEl) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    btnEl.classList.add('active');
}

function syncArticleTypeControlFromState() {
    const val = String(articleState.article_type || 'ORIGINAL ARTICLE').toUpperCase().trim();
    const sel = document.getElementById('f_article_type_preset');
    const customInput = document.getElementById('f_article_type');
    customInput.value = val;
    if (ARTICLE_TYPE_PRESETS.includes(val)) {
        sel.value = val;
        customInput.style.display = 'none';
    } else {
        sel.value = 'CUSTOM';
        customInput.style.display = 'block';
    }
    applyArticleTypeLayoutMode();
}

function onArticleTypePresetChange() {
    const sel = document.getElementById('f_article_type_preset');
    const customInput = document.getElementById('f_article_type');
    if (sel.value === 'CUSTOM') {
        customInput.style.display = 'block';
        customInput.focus();
    } else {
        customInput.style.display = 'none';
        customInput.value = sel.value;
    }
    syncStateFromInputs();
    applyArticleTypeLayoutMode(true);
}

function applyArticleTypeLayoutMode(userInitiated = false) {
    const noAbs = isNoAbstractType(articleState.article_type);
    const notice = document.getElementById('noAbstractNotice');
    const catLbl = document.getElementById('noAbstractCatLabel');
    const absGrid = document.getElementById('abstractInputsGrid');
    const absBadge = document.getElementById('absTabStatusBadge');

    if (notice) notice.style.display = noAbs ? 'block' : 'none';
    if (catLbl) catLbl.textContent = String(articleState.article_type || 'DATA REPORT / LETTER').toUpperCase();
    if (absGrid) {
        absGrid.style.opacity = noAbs ? '0.45' : '1';
        absGrid.style.pointerEvents = noAbs ? 'none' : 'auto';
    }
    if (absBadge) {
        const modeLabel = (getActiveFormLanguageModeJs() === 'en') ? '(ID PDF & EN JATS)' : '(ID & EN)';
        absBadge.textContent = noAbs ? '(Tanpa Abstrak)' : modeLabel;
    }

    if (userInitiated && noAbs) {
        // Automatically jump to Tab 2 when user picks DATA REPORT / LETTER while viewing Tab 1
        const tab1Active = document.getElementById('tab-abstracts').classList.contains('active');
        if (tab1Active) {
            const tab2Btn = document.querySelector('.tab-btn[data-tab="tab-body"]');
            if (tab2Btn) switchTab('tab-body', tab2Btn);
        }
        showToast(`Mode ${articleState.article_type}: Abstrak dihilangkan & langsung ke Badan Artikel.`, 'info');
    }
    applyFormLanguageMode();
    renderBodySectionsEditor();
}

function toggleOptionalImradSections() {
    showOptionalImradForNoAbs = !showOptionalImradForNoAbs;
    renderBodySectionsEditor();
}

function mergeImradSectionsIntoDirectBody() {
    if (!articleState.sections) articleState.sections = {};
    if (!Array.isArray(articleState.sections.badan_artikel)) articleState.sections.badan_artikel = [];
    const imradKeys = ['pendahuluan', 'metode', 'hasil', 'pembahasan', 'kesimpulan_saran'];
    let moved = 0;
    imradKeys.forEach(k => {
        const blocks = articleState.sections[k] || [];
        while (blocks.length > 0) {
            articleState.sections.badan_artikel.push(blocks.shift());
            moved++;
        }
    });
    renderBodySectionsEditor({ sec: 'badan_artikel', idx: Math.max(0, articleState.sections.badan_artikel.length - 1) });
    showToast(`📥 ${moved} blok dari bagian IMRaD digabungkan ke Badan Artikel Langsung.`, 'success');
}

function isVolume3Plus(vol) {
    const num = parseInt(String(vol || '').replace(/\D+/g, ''), 10);
    return !isNaN(num) && num >= 3;
}

function translateFooterMonthsToId(str) {
    if (typeof str !== 'string' || !str.trim()) return '';
    const rules = [
        [/\bJanuary\b/gi, 'Januari'],
        [/\bJan\.?\b/gi, 'Januari'],
        [/\bFebruary\b/gi, 'Februari'],
        [/\bFeb\.?\b/gi, 'Februari'],
        [/\bMarch\b/gi, 'Maret'],
        [/\bMar\.?\b/gi, 'Maret'],
        [/\bApril\b/gi, 'April'],
        [/\bApr\.?\b/gi, 'April'],
        [/\bMay\b/g, 'Mei'],
        [/\bJune\b/gi, 'Juni'],
        [/\bJun\.?\b/gi, 'Juni'],
        [/\bJuly\b/gi, 'Juli'],
        [/\bJul\.?\b/gi, 'Juli'],
        [/\bAugust\b/gi, 'Agustus'],
        [/\bAug\.?\b/gi, 'Agustus'],
        [/\bAgt\.?\b/gi, 'Agustus'],
        [/\bSeptember\b/gi, 'September'],
        [/\bSept?\.?\b/gi, 'September'],
        [/\bOctober\b/gi, 'Oktober'],
        [/\bOct\.?\b/gi, 'Oktober'],
        [/\bOkt\.?\b/gi, 'Oktober'],
        [/\bNopember\b/gi, 'November'],
        [/\bNovember\b/gi, 'November'],
        [/\bNop\.?\b/gi, 'November'],
        [/\bNov\.?\b/gi, 'November'],
        [/\bDecember\b/gi, 'Desember'],
        [/\bDec\.?\b/gi, 'Desember'],
        [/\bDes\.?\b/gi, 'Desember'],
    ];
    let out = str;
    rules.forEach(([re, rep]) => { out = out.replace(re, rep); });
    const idMonths = '(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)';
    out = out.replace(new RegExp(`\\b${idMonths}\\s*[–—-]\\s*${idMonths}\\b`, 'g'), '$1-$2');
    return normalizeParagraphSpacingJs(out);
}

function normalizeFooterMonthsInput(el) {
    if (!el || typeof el.value !== 'string') return;
    const translated = translateFooterMonthsToId(el.value);
    if (translated !== el.value) {
        el.value = translated;
        syncStateFromInputs();
    }
}

function rebuildFooterIssueLine() {
    syncStateFromInputs();
    const vol = String(articleState.volume || '1').trim() || '1';
    const no = String(articleState.issue_number || '1').trim() || '1';
    const yr = String(articleState.copyright_year || '2026').trim();
    const quarterDefaults = {
        '1': 'Januari-Maret',
        '2': 'April-Juni',
        '3': 'Juli-September',
        '4': 'Oktober-Desember',
    };
    let current = String(articleState.footer_issue_line || '');
    current = current.replace(/^Volume\s+\S+\s+Nomor\s+\S+\s*,?\s*/i, '');
    if (yr) current = current.replace(new RegExp(`(?:\\s*,)?\\s*\\b${yr}\\b\\s*$`), '');
    current = translateFooterMonthsToId(current);
    const period = current || quarterDefaults[no] || 'Januari-Maret';
    const rebuilt = `Volume ${vol} Nomor ${no}, ${period}${yr ? ' ' + yr : ''}`;
    articleState.footer_issue_line = rebuilt;
    document.getElementById('f_footer_issue_line').value = rebuilt;
    showToast('Footer edisi disusun ulang dengan nama bulan Indonesia.', 'success');
}

function rebuildContributorRolesDefault() {
    syncStateFromInputs();
    const authors = Array.isArray(articleState.authors) ? articleState.authors : [];
    const parts = [];
    authors.forEach((a, idx) => {
        const nameOnly = String((a && a.name) || '').split(',')[0].trim();
        if (!nameOnly) return;
        const initials = nameOnly.split(/\s+/).map(w => w.charAt(0).toUpperCase()).filter(Boolean).join('');
        if (!initials) return;
        const roles = idx === 0
            ? 'Conceptualization, Data Curation, Investigation, Methodology, Writing – Original Draft Preparation, Writing – Review & Editing'
            : 'Supervision, Validation';
        parts.push(`${initials} (${roles})`);
    });
    if (!parts.length) {
        showToast('Isi nama penulis terlebih dahulu untuk membuat inisial Contributor Roles.', 'info');
        return;
    }
    const text = parts.join(', ') + '.';
    articleState.contributor_roles = text;
    document.getElementById('f_contributor_roles').value = text;
    showToast('Inisial Contributor’s Roles (CRediT) diperbarui.', 'success');
}

function applyVolumeTemplateMode() {
    const vol3Plus = isVolume3Plus(articleState.volume);
    const badge = document.getElementById('volumeTemplateModeBadge');
    const datesRow = document.getElementById('vol3DatesRow');
    const rolesGrp = document.getElementById('vol3ContribRolesGroup');
    const sdgsIdGrp = document.getElementById('vol3SdgsIdGroup');
    const sdgsEnGrp = document.getElementById('vol3SdgsEnGroup');
    const retNoteGrp = document.getElementById('retypesetNoteGroup');
    const retNoteHint = document.getElementById('retypesetNoteHint');

    if (badge) {
        if (vol3Plus) {
            badge.textContent = '✨ Mode Templat Vol 3+: Received/Accepted, CRediT, TPB/SDGs, Tanpa Boks Re-typeset 2026';
            badge.style.background = '#ecfdf5';
            badge.style.color = '#065f46';
            badge.style.border = '1px solid #a7f3d0';
        } else {
            badge.textContent = '📚 Mode Arsip Vol 1–2: Dengan Boks Re-typeset 2026 (Footer tetap Bulan Indonesia)';
            badge.style.background = '#f1f5f9';
            badge.style.color = '#334155';
            badge.style.border = '1px solid #cbd5e1';
        }
    }
    if (datesRow) datesRow.style.display = vol3Plus ? 'grid' : 'none';
    if (rolesGrp) rolesGrp.style.display = vol3Plus ? 'block' : 'none';
    if (sdgsIdGrp) sdgsIdGrp.style.display = vol3Plus ? 'block' : 'none';
    if (sdgsEnGrp) sdgsEnGrp.style.display = vol3Plus ? 'block' : 'none';
    if (retNoteGrp) retNoteGrp.style.opacity = vol3Plus ? '0.45' : '1';
    if (retNoteHint) retNoteHint.textContent = vol3Plus ? '(Otomatis dihapus di Vol 3+)' : '(Vol 1–2 Arsip)';
}

function getActiveFormLanguageModeJs() {
    const sel = document.getElementById('cfg_formLanguageMode');
    const cfg = sel ? String(sel.value || 'en').toLowerCase() : String(articleState.form_mode || 'en').toLowerCase();
    if (cfg === 'en' || cfg === 'english') return 'en';
    if (cfg === 'bilingual' || cfg === 'id_en') return 'bilingual';
    const hasDualTitles = !!(
        articleState.title_id && String(articleState.title_id).trim() &&
        articleState.title_en && String(articleState.title_en).trim()
    );
    return hasDualTitles ? 'bilingual' : 'en';
}

function applyFormLanguageMode() {
    const mode = getActiveFormLanguageModeJs();
    const enOnly = (mode === 'en');
    articleState.form_mode = mode;

    const titleRow = document.getElementById('titleFieldsRow');
    const titleIdGrp = document.getElementById('titleIdGroup');
    const titleEnLbl = document.getElementById('titleEnLabel');
    const absGrid = document.getElementById('abstractInputsGrid');
    const absIdCard = document.getElementById('abstractIdCard');
    const absIdRoleHint = document.getElementById('abstractIdRoleHint');
    const absEnHead = document.getElementById('abstractEnHeading');
    const absBadge = document.getElementById('absTabStatusBadge');

    if (titleIdGrp) titleIdGrp.style.display = enOnly ? 'none' : 'block';
    if (titleRow) titleRow.style.gridTemplateColumns = enOnly ? '1fr' : '';
    if (titleEnLbl) {
        titleEnLbl.textContent = enOnly
            ? 'Article Title / Judul Artikel (English — Bold 14pt)'
            : 'Judul Artikel (Inggris — Italic 14pt)';
    }

    // Keep Indonesian Abstract card visible in Studio because DOCX/PDF retains Indonesian abstract!
    if (absIdCard) absIdCard.style.display = 'block';
    if (absGrid) absGrid.style.gridTemplateColumns = '';
    if (absIdRoleHint) {
        absIdRoleHint.textContent = enOnly ? 'Khusus DOCX/PDF' : 'PDF & JATS XML';
    }
    if (absEnHead) {
        absEnHead.textContent = enOnly
            ? '🇬🇧 ABSTRACT (English — PDF & JATS XML)'
            : '🇬🇧 ABSTRACT (English — Italic)';
    }
    if (absBadge && !isNoAbstractType(articleState.article_type)) {
        absBadge.textContent = enOnly ? '(ID PDF & EN JATS)' : '(ID & EN)';
    }
}

function populateFormFromState() {
    syncArticleTypeControlFromState();
    document.getElementById('f_elocation_id').value = articleState.elocation_id || ('e' + (articleState.submission_id || '76'));
    document.getElementById('f_volume').value = articleState.volume || '1';
    document.getElementById('f_issue_number').value = articleState.issue_number || '1';
    document.getElementById('f_copyright_year').value = articleState.copyright_year || '2024';
    document.getElementById('f_footer_issue_line').value = translateFooterMonthsToId(articleState.footer_issue_line || 'Volume 1 Nomor 1, Januari-Maret 2024');
    document.getElementById('f_article_url').value = articleState.article_url || '';
    document.getElementById('f_citation').value = articleState.citation || '';
    document.getElementById('f_doi').value = articleState.doi || '';
    document.getElementById('f_review_editor').value = articleState.review_editor || '';
    document.getElementById('f_received_date').value = articleState.received_date || '';
    document.getElementById('f_accepted_date').value = articleState.accepted_date || '';
    document.getElementById('f_published_date').value = articleState.published_date || '';
    document.getElementById('f_funding').value = articleState.funding || (isVolume3Plus(articleState.volume) ? 'None' : 'none');
    document.getElementById('f_competing_interests').value = articleState.competing_interests || '';
    document.getElementById('f_contributor_roles').value = articleState.contributor_roles || '';
    document.getElementById('f_retypeset_note').value = articleState.retypeset_note || '';

    document.getElementById('f_title_id').value = articleState.title_id || '';
    document.getElementById('f_title_en').value = articleState.title_en || '';
    document.getElementById('f_corresponding_name').value = articleState.corresponding_name || '';
    document.getElementById('f_corresponding_email').value = articleState.corresponding_email || '';

    const abId = articleState.abstract_id || {};
    document.getElementById('f_ab_id_pm').value = abId.pendahuluan_metode || '';
    document.getElementById('f_ab_id_hasil').value = abId.hasil || '';
    document.getElementById('f_ab_id_kes').value = abId.kesimpulan_saran || '';
    document.getElementById('f_keywords_id').value = articleState.keywords_id || '';
    document.getElementById('f_sdgs_id').value = articleState.sdgs_id || '';

    const abEn = articleState.abstract_en || {};
    document.getElementById('f_ab_en_im').value = abEn.intro_methods || '';
    document.getElementById('f_ab_en_res').value = abEn.results || '';
    document.getElementById('f_ab_en_conc').value = abEn.conclusions_recommendations || '';
    document.getElementById('f_keywords_en').value = articleState.keywords_en || '';
    document.getElementById('f_sdgs_en').value = articleState.sdgs_en || '';

    const refs = Array.isArray(articleState.referensi) ? articleState.referensi : [];
    const cleanRefs = refs.map(r => stripRefPrefixJs(r)).filter(Boolean);
    articleState.referensi = cleanRefs;
    const activeRefStyle = getActiveJournalRefStyleJs();
    const styleLbl = document.getElementById('journalRefStyleLabel');
    if (styleLbl) styleLbl.textContent = 'Gaya ' + activeRefStyle.toUpperCase();
    document.getElementById('f_referensi').value = formatRefsListForTextareaJs(cleanRefs, activeRefStyle);
    document.getElementById('refCountBadge').textContent = cleanRefs.length;

    applyVolumeTemplateMode();
    applyFormLanguageMode();
    renderAuthorsEditor();
    renderBodySectionsEditor();
    updateAbstractWordCounts();
    refreshVancouverSyncBannerJs();
}

function syncStateFromInputs() {
    articleState.form_mode = getActiveFormLanguageModeJs();
    articleState.article_type = document.getElementById('f_article_type').value;
    articleState.elocation_id = document.getElementById('f_elocation_id').value;
    articleState.volume = document.getElementById('f_volume').value;
    articleState.issue_number = document.getElementById('f_issue_number').value;
    articleState.copyright_year = document.getElementById('f_copyright_year').value;
    articleState.footer_issue_line = translateFooterMonthsToId(document.getElementById('f_footer_issue_line').value);
    articleState.article_url = document.getElementById('f_article_url').value;
    articleState.citation = document.getElementById('f_citation').value;
    articleState.doi = document.getElementById('f_doi').value;
    articleState.review_editor = document.getElementById('f_review_editor').value;
    articleState.received_date = document.getElementById('f_received_date').value;
    articleState.accepted_date = document.getElementById('f_accepted_date').value;
    articleState.published_date = document.getElementById('f_published_date').value;
    articleState.funding = document.getElementById('f_funding').value;
    articleState.competing_interests = document.getElementById('f_competing_interests').value;
    articleState.contributor_roles = document.getElementById('f_contributor_roles').value;
    articleState.retypeset_note = document.getElementById('f_retypeset_note').value;

    articleState.title_id = document.getElementById('f_title_id').value;
    articleState.title_en = document.getElementById('f_title_en').value;
    articleState.corresponding_name = document.getElementById('f_corresponding_name').value;
    articleState.corresponding_email = document.getElementById('f_corresponding_email').value;

    if (!articleState.abstract_id) articleState.abstract_id = {};
    articleState.abstract_id.pendahuluan_metode = document.getElementById('f_ab_id_pm').value;
    articleState.abstract_id.hasil = document.getElementById('f_ab_id_hasil').value;
    articleState.abstract_id.kesimpulan_saran = document.getElementById('f_ab_id_kes').value;
    articleState.keywords_id = document.getElementById('f_keywords_id').value;
    articleState.sdgs_id = document.getElementById('f_sdgs_id').value;

    if (!articleState.abstract_en) articleState.abstract_en = {};
    articleState.abstract_en.intro_methods = document.getElementById('f_ab_en_im').value;
    articleState.abstract_en.results = document.getElementById('f_ab_en_res').value;
    articleState.abstract_en.conclusions_recommendations = document.getElementById('f_ab_en_conc').value;
    articleState.keywords_en = document.getElementById('f_keywords_en').value;
    articleState.sdgs_en = document.getElementById('f_sdgs_en').value;

    updateAbstractWordCounts();
}

function countWordsSafe(str) {
    const clean = String(str || '').trim();
    return clean ? clean.split(/\s+/).length : 0;
}

function updateAbstractWordCounts() {
    const idEl = document.getElementById('idAbsWordCount');
    const enEl = document.getElementById('enAbsWordCount');
    const abId = articleState.abstract_id || {};
    const abEn = articleState.abstract_en || {};
    if (idEl) {
        const wId = countWordsSafe(abId.pendahuluan_metode) + countWordsSafe(abId.hasil) + countWordsSafe(abId.kesimpulan_saran);
        idEl.textContent = `${wId} kata`;
    }
    if (enEl) {
        const wEn = countWordsSafe(abEn.intro_methods) + countWordsSafe(abEn.results) + countWordsSafe(abEn.conclusions_recommendations);
        enEl.textContent = `${wEn} words`;
    }
}

function getActiveJournalRefStyleJs() {
    const sel = document.getElementById('cfg_referenceStyle');
    if (sel && sel.value && sel.value !== 'auto') {
        return sel.value.toLowerCase();
    }
    return String(articleState.reference_style || 'vancouver').toLowerCase();
}

function formatRefsListForTextareaJs(cleanRefs, style) {
    if (style === 'apa') {
        return cleanRefs.join('\n');
    }
    if (style === 'ieee') {
        return cleanRefs.map((r, i) => `[${i + 1}] ${r}`).join('\n');
    }
    return cleanRefs.map((r, i) => `${i + 1}. ${r}`).join('\n');
}

function stripRefPrefixJs(line) {
    let s = String(line || '').trim();
    s = s.replace(/^\s*(?:\[\s*\d+\s*\]|\(\s*\d+\s*\)|\d+\s*[\.\)])\s*/, '');
    s = s.replace(/^\s*(?:\[\s*\d+\s*\]|\(\s*\d+\s*\)|\d+\s*[\.\)])\s*/, '');
    return s.trim();
}

function refreshVancouverSyncBannerJs() {
    const box = document.getElementById('vancouverSyncStatusBox');
    if (!box) return;
    const refs = Array.isArray(articleState.referensi) ? articleState.referensi.filter(Boolean) : [];
    const bodyTexts = [];
    SECTION_KEYS.forEach(secKey => {
        ((articleState.sections && articleState.sections[secKey]) || []).forEach(blk => {
            const t = typeof blk === 'string' ? blk : (blk && blk.text ? blk.text : '');
            if (t) bodyTexts.push(t);
        });
    });
    const fullText = bodyTexts.join('\n');
    const citedSet = new Set();
    const citeRx = /(\(|\[)([1-9]\d{0,2}(?:\s*(?:,|[-–—])\s*[1-9]\d{0,2})*)(\)|\])/g;
    let m;
    while ((m = citeRx.exec(fullText)) !== null) {
        if ((m[1] === '(' && m[3] !== ')') || (m[1] === '[' && m[3] !== ']')) continue;
        const spec = m[2];
        if (m[1] === '(' && !/[,–—-]/.test(spec)) {
            const prev = m.index > 0 ? fullText.charAt(m.index - 1) : '';
            if (!prev || /\s/.test(prev)) continue;
        }
        spec.split(',').forEach(part => {
            const rm = part.trim().match(/^(\d+)\s*[-–—]\s*(\d+)$/);
            if (rm) {
                const a = parseInt(rm[1], 10), b = parseInt(rm[2], 10);
                if (a >= 1 && b >= a && (b - a) <= 30) {
                    for (let k = a; k <= b; k++) citedSet.add(k);
                }
            } else if (/^\d+$/.test(part.trim())) {
                const n = parseInt(part.trim(), 10);
                if (n >= 1 && n <= 250) citedSet.add(n);
            }
        });
    }
    const apaRefs = refs.filter(r => /^[^(\[\]]{2,160}\(\s*(?:19|20)\d{2}[a-z]?\s*\)\.?/.test(r) || /^[^.;]{2,80},\s*(?:19|20)\d{2}[a-z]?\.\s+\S/.test(r));
    const overRange = Array.from(citedSet).filter(n => refs.length > 0 && n > refs.length).sort((a, b) => a - b);

    if (apaRefs.length > 0 || overRange.length > 0) {
        const warns = [];
        if (apaRefs.length > 0) warns.push(`${apaRefs.length} referensi masih berpola APA (tahun kurung)`);
        if (overRange.length > 0) warns.push(`nomor sitasi melebihi jumlah pustaka: (${overRange.join(', ')})`);
        box.style.background = '#fef2f2';
        box.style.color = '#991b1b';
        box.style.borderColor = '#fecaca';
        box.innerHTML = `⚠️ <strong>Perlu Sinkronisasi Vancouver:</strong> ${escapeHtml(warns.join(' · '))}. Klik tombol <em>"Konversi &amp; Sinkronkan Gaya Vancouver"</em> untuk merapikan otomatis.`;
    } else {
        box.style.background = '#ecfdf5';
        box.style.color = '#065f46';
        box.style.borderColor = '#a7f3d0';
        box.innerHTML = `✅ <strong>Sinkron (Gaya Vancouver):</strong> ${refs.length} entri sumber pustaka bernomor urut · ${citedSet.size} nomor sitasi terdeteksi dalam naskah.`;
    }
}

async function formatVancouverReferencesAjax() {
    syncStateFromInputs();
    syncReferencesFromInput();
    showToast('Mengonversi & menyinkronkan Sumber Pustaka ke gaya referensi jurnal...', 'info');
    const fd = new FormData();
    fd.append('articleJson', serializeArticleForServer());
    try {
        const res = await fetch(BASE_URL + '/formatVancouverReferences', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok && data.article) {
            articleState = data.article;
            populateFormFromState();
            showToast(data.message || 'Sumber pustaka disinkronkan ke gaya jurnal.', 'success');
        } else {
            showToast(data.error || 'Gagal menyinkronkan referensi.', 'error');
        }
    } catch (e) {
        showToast('Error jaringan saat konversi referensi: ' + e.message, 'error');
    }
}

async function syncReferencesToOjsMenu() {
    syncStateFromInputs();
    syncReferencesFromInput();
    showToast('📤 Memparser daftar pustaka langsung ke menu References OJS...', 'info');
    const fd = new FormData();
    fd.append('articleJson', serializeArticleForServer());
    try {
        const res = await fetch(BASE_URL + '/syncReferencesToOjs', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            if (data.article) {
                articleState = data.article;
                populateFormFromState();
            }
            showToast(data.message || 'Referensi berhasil diparser ke menu References OJS.', 'success');
        } else {
            showToast(data.error || 'Gagal memparser ke menu References OJS.', 'error');
        }
    } catch (e) {
        showToast('Error jaringan saat menyinkronkan menu References OJS: ' + e.message, 'error');
    }
}

function syncReferencesFromInput() {
    const raw = document.getElementById('f_referensi').value;
    articleState.referensi = raw.split(/\r?\n/).map(s => stripRefPrefixJs(s)).filter(Boolean);
    document.getElementById('refCountBadge').textContent = articleState.referensi.length;
    refreshVancouverSyncBannerJs();
}

const SECTION_KEYS = ['badan_artikel', 'pendahuluan', 'metode', 'hasil', 'pembahasan', 'kesimpulan_saran'];

function renderAuthorsEditor() {
    const container = document.getElementById('authorsContainer');
    container.innerHTML = '';
    if (!Array.isArray(articleState.authors) || articleState.authors.length === 0) {
        articleState.authors = [{ name: '', affiliation: '', email: '', is_corresponding: true }];
    }

    articleState.authors.forEach((au, idx) => {
        const row = document.createElement('div');
        row.className = 'author-row';
        row.innerHTML = `
            <div style="display:flex;gap:3px;">
                <button type="button" class="btn btn-outline btn-xs" onclick="moveAuthorRow(${idx}, -1)" ${idx === 0 ? 'disabled' : ''} title="Naik">▲</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="moveAuthorRow(${idx}, 1)" ${idx === articleState.authors.length - 1 ? 'disabled' : ''} title="Turun">▼</button>
            </div>
            <input type="text" placeholder="Nama Penulis" value="${escapeHtml(au.name || '')}" oninput="updateAuthor(${idx}, 'name', this.value)">
            <input type="text" placeholder="Afiliasi (Prodi, Fakultas, Instansi, Negara)" value="${escapeHtml(au.affiliation || '')}" oninput="updateAuthor(${idx}, 'affiliation', this.value)">
            <input type="text" placeholder="Email" value="${escapeHtml(au.email || '')}" oninput="updateAuthor(${idx}, 'email', this.value)">
            <label style="margin:0;display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap;font-size:11.5px;">
                <input type="checkbox" ${au.is_corresponding ? 'checked' : ''} onchange="updateAuthor(${idx}, 'is_corresponding', this.checked)"> * Koresp
            </label>
            <button type="button" class="btn btn-outline btn-xs" onclick="removeAuthorRow(${idx})" title="Hapus Penulis">✕</button>
        `;
        container.appendChild(row);
    });

    updateAffDeduplicationPreview();
}

function moveAuthorRow(idx, delta) {
    const target = idx + delta;
    if (target < 0 || target >= articleState.authors.length) return;
    const tmp = articleState.authors[idx];
    articleState.authors[idx] = articleState.authors[target];
    articleState.authors[target] = tmp;
    renderAuthorsEditor();
}

function updateAuthor(idx, key, val) {
    articleState.authors[idx][key] = val;
    if (key === 'is_corresponding' && val) {
        articleState.corresponding_name = articleState.authors[idx].name || '';
        articleState.corresponding_email = articleState.authors[idx].email || '';
        document.getElementById('f_corresponding_name').value = articleState.corresponding_name;
        document.getElementById('f_corresponding_email').value = articleState.corresponding_email;
    }
    updateAffDeduplicationPreview();
}

function addAuthorRow() {
    const defaultAff = (articleState.authors[0] && articleState.authors[0].affiliation) ? articleState.authors[0].affiliation : '';
    articleState.authors.push({ name: '', affiliation: defaultAff, email: '', is_corresponding: false });
    renderAuthorsEditor();
}

function removeAuthorRow(idx) {
    if (articleState.authors.length <= 1) return;
    articleState.authors.splice(idx, 1);
    renderAuthorsEditor();
}

function updateAffDeduplicationPreview() {
    const uniqueAffs = [];
    const authorFormatted = [];
    (articleState.authors || []).forEach(au => {
        const aff = (au.affiliation || 'Indonesia').trim() || 'Indonesia';
        let pos = uniqueAffs.indexOf(aff);
        if (pos === -1) {
            uniqueAffs.push(aff);
            pos = uniqueAffs.length - 1;
        }
        const num = pos + 1;
        authorFormatted.push(`${escapeHtml(au.name || 'Penulis')}<sup>${num}</sup>${au.is_corresponding ? '*' : ''}`);
    });

    const affLines = uniqueAffs.map((a, i) => `<div><strong>${i + 1}.</strong> ${escapeHtml(a)}</div>`).join('');
    document.getElementById('affDeduplicationPreview').innerHTML = `
        <div style="margin-bottom:4px;"><strong>Pratinjau Baris Penulis di DOCX:</strong> ${authorFormatted.join(', ')}</div>
        ${affLines}
    `;
}

let dragBlockState = { srcSec: null, srcIdx: null };

function getActiveSectionKeys() {
    if (!articleState.sections) articleState.sections = {};
    const noAbs = isNoAbstractType(articleState.article_type);
    return SECTION_KEYS.filter(secKey => {
        const blocks = Array.isArray(articleState.sections[secKey]) ? articleState.sections[secKey] : [];
        if (noAbs) {
            if (secKey === 'badan_artikel') return true;
            return showOptionalImradForNoAbs || blocks.length > 0;
        }
        return secKey !== 'badan_artikel' || blocks.length > 0;
    });
}

function renderBodySectionsEditor(flashTarget = null) {
    const container = document.getElementById('bodySectionsContainer');
    container.innerHTML = '';
    if (!articleState.sections) articleState.sections = {};

    const noAbs = isNoAbstractType(articleState.article_type);
    const imradKeys = ['pendahuluan', 'metode', 'hasil', 'pembahasan', 'kesimpulan_saran'];
    const hasAnyImradBlocks = imradKeys.some(k => Array.isArray(articleState.sections[k]) && articleState.sections[k].length > 0);
    const btnMerge = document.getElementById('btnMergeImradToBody');
    const btnToggleOpt = document.getElementById('btnToggleOptionalImrad');
    if (btnMerge) btnMerge.style.display = (noAbs && hasAnyImradBlocks) ? 'inline-flex' : 'none';
    if (btnToggleOpt) {
        btnToggleOpt.style.display = noAbs ? 'inline-flex' : 'none';
        btnToggleOpt.textContent = showOptionalImradForNoAbs ? '➖ Sembunyikan IMRaD Kosong' : '➕ Bagian IMRaD Opsional';
    }

    const activeSectionKeys = getActiveSectionKeys();

    activeSectionKeys.forEach((secKey, secPos) => {
        if (!Array.isArray(articleState.sections[secKey])) {
            articleState.sections[secKey] = [];
        }
        const blocks = articleState.sections[secKey];
        const secCard = document.createElement('div');
        secCard.className = 'section-card';
        if (secKey === 'badan_artikel') {
            secCard.style.borderColor = '#93c5fd';
            secCard.style.background = '#f8fbff';
        }
        secCard.dataset.secKey = secKey;

        secCard.addEventListener('dragover', (ev) => {
            if (!dragBlockState.srcSec) return;
            ev.preventDefault();
            secCard.classList.add('section-drag-over');
        });
        secCard.addEventListener('dragleave', (ev) => {
            if (!secCard.contains(ev.relatedTarget)) {
                secCard.classList.remove('section-drag-over');
            }
        });
        secCard.addEventListener('drop', (ev) => {
            if (!dragBlockState.srcSec) return;
            ev.preventDefault();
            secCard.classList.remove('section-drag-over');
            performBlockRelocation(dragBlockState.srcSec, dragBlockState.srcIdx, secKey, articleState.sections[secKey].length);
        });

        let headerHtml = `
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                <h3 style="margin:0;font-size:14px;color:var(--kisi-navy);">${SECTION_LABELS[secKey]} (${blocks.length} blok)</h3>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="addSectionBlock('${secKey}', 'subheading')">+ Sub-Judul (L2)</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addSectionBlock('${secKey}', 'subheading_l3')">+ Sub-Sub (L3)</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addSectionBlock('${secKey}', 'paragraph')">+ Paragraf</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addSectionBlock('${secKey}', 'image')">+ Gambar</button>
                    <button type="button" class="btn btn-teal btn-sm" onclick="addSectionBlock('${secKey}', 'table')">+ Tabel Hasil</button>
                </div>
            </div>
        `;

        const blocksContainer = document.createElement('div');
        if (blocks.length === 0) {
            const emptyDrop = document.createElement('div');
            emptyDrop.className = 'section-dropzone-empty';
            emptyDrop.textContent = 'Belum ada blok di bab ini. Klik tombol "+ ..." di atas atau seret blok dari bab lain ke sini.';
            blocksContainer.appendChild(emptyDrop);
        }

        blocks.forEach((blk, bIdx) => {
            if (typeof blk === 'string') {
                blk = { type: 'paragraph', text: blk };
                articleState.sections[secKey][bIdx] = blk;
            }
            const item = document.createElement('div');
            const shouldFlash = flashTarget && flashTarget.sec === secKey && flashTarget.idx === bIdx;
            item.className = 'block-item' + (shouldFlash ? ' block-flash' : '');
            item.dataset.secKey = secKey;
            item.dataset.bIdx = String(bIdx);
            item.dataset.bType = blk.type || 'paragraph';

            const canMoveUp = !(secPos === 0 && bIdx === 0);
            const canMoveDown = !(secPos === activeSectionKeys.length - 1 && bIdx === blocks.length - 1);

            const moveSecOptions = SECTION_KEYS.map(k => {
                const lbl = (k === 'badan_artikel') ? 'BADAN LANGSUNG' : k.replace('_', ' ').toUpperCase();
                return `<option value="${k}" ${k === secKey ? 'selected' : ''}>Bab: ${escapeHtml(lbl)}</option>`;
            }).join('');

            const isTextBlock = (blk.type !== 'table' && blk.type !== 'image');
            const levelSelectHtml = isTextBlock ? `
                <select class="block-select-mini" title="Ubah Level Blok (Paragraf / Sub-Judul)" onchange="changeBlockLevel('${secKey}', ${bIdx}, this.value)">
                    <option value="paragraph" ${(blk.type === 'paragraph' || !blk.type) ? 'selected' : ''}>Level: Paragraf</option>
                    <option value="subheading" ${blk.type === 'subheading' ? 'selected' : ''}>Level: Sub-Judul (L2)</option>
                    <option value="subheading_l3" ${blk.type === 'subheading_l3' ? 'selected' : ''}>Level: Sub-Sub-Judul (L3)</option>
                </select>
            ` : '';

            const badgeText = blk.type === 'table'
                ? '📊 TABEL PENELITIAN'
                : (blk.type === 'image'
                    ? '🖼️ GAMBAR / FIGURE'
                    : (blk.type === 'subheading' ? '🔖 SUB-JUDUL (L2 BOLD)' : (blk.type === 'subheading_l3' ? '🏷️ SUB-SUB-JUDUL (L3)' : '¶ PARAGRAF')));

            const blockHeaderHtml = `
                <div class="block-item-header">
                    <div class="block-header-left">
                        <span class="block-drag-handle" title="Seret atas-bawah atau pindah ke bab lain" onmousedown="armBlockDrag(this)">⋮⋮</span>
                        <span>${badgeText}</span>
                    </div>
                    <div class="block-header-controls">
                        ${levelSelectHtml}
                        <select class="block-select-mini" title="Pindahkan blok ke bab/level lain" onchange="moveBlockToSection('${secKey}', ${bIdx}, this.value)">
                            ${moveSecOptions}
                        </select>
                        <button type="button" class="btn btn-outline btn-xs" onclick="moveBlockVertical('${secKey}', ${bIdx}, -1)" ${!canMoveUp ? 'disabled' : ''} title="${bIdx === 0 && secPos > 0 ? 'Geser naik ke bab sebelumnya' : 'Geser ke atas'}">▲</button>
                        <button type="button" class="btn btn-outline btn-xs" onclick="moveBlockVertical('${secKey}', ${bIdx}, 1)" ${!canMoveDown ? 'disabled' : ''} title="${bIdx === blocks.length - 1 && secPos < SECTION_KEYS.length - 1 ? 'Geser turun ke bab berikutnya' : 'Geser ke bawah'}">▼</button>
                        <button type="button" class="btn btn-outline btn-xs" onclick="removeSectionBlock('${secKey}', ${bIdx})" title="Hapus blok">✕</button>
                    </div>
                </div>
            `;

            if (blk.type === 'table') {
                item.innerHTML = blockHeaderHtml + `
                    <div class="field-group" style="margin-bottom:8px;">
                        <label>Judul Tabel (Caption — otomatis di atas tabel, bold pada "Tabel N.")</label>
                        <input type="text" value="${escapeHtml(blk.caption || '')}" oninput="articleState.sections['${secKey}'][${bIdx}].caption = this.value" onblur="this.value = normalizeParagraphSpacingJs(this.value); articleState.sections['${secKey}'][${bIdx}].caption = this.value;">
                    </div>
                    ${renderTablePreviewHtml(secKey, bIdx, blk)}
                    <div class="field-group" style="margin-top:8px;margin-bottom:0;">
                        <label>Catatan Kaki Tabel (Footnote / Singkatan / Uji Statistik — otomatis di bawah garis penutup)</label>
                        <textarea rows="2" placeholder="Contoh: Keterangan: F/n, frekuensi; χ², nilai uji Chi-square; p < 0,05 menunjukkan hubungan bermakna secara statistik." oninput="articleState.sections['${secKey}'][${bIdx}].footnote = this.value" onblur="this.value = normalizeParagraphSpacingJs(this.value); articleState.sections['${secKey}'][${bIdx}].footnote = this.value;">${escapeHtml(blk.footnote || '')}</textarea>
                    </div>
                `;
            } else if (blk.type === 'image') {
                const imgSrc = blk.data || blk.src || '';
                const imgPreview = imgSrc
                    ? `<img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(blk.alt || blk.caption || 'Gambar')}" style="max-width:100%;max-height:280px;display:block;margin:0 auto 8px;border:1px solid #e2e8f0;border-radius:6px;">`
                    : '<div style="padding:18px;text-align:center;color:#94a3b8;border:1px dashed #cbd5e1;border-radius:6px;margin-bottom:8px;">Belum ada gambar. Pilih file gambar di bawah ini.</div>';
                item.innerHTML = blockHeaderHtml + `
                    ${imgPreview}
                    <div class="field-group" style="margin-bottom:8px;">
                        <label>Ganti / Pilih Gambar (PNG, JPG, GIF, BMP, TIFF — tersimpan di dalam DOCX, JATS &amp; HTML)</label>
                        <input type="file" accept="image/png,image/jpeg,image/gif,image/bmp,image/tiff" onchange="setBlockImageFromFile('${secKey}', ${bIdx}, this)">
                        ${imgSrc ? `<div style="margin-top:6px;"><button type="button" class="btn btn-outline btn-xs" onclick="clearBlockImage('${secKey}', ${bIdx})">🗑️ Hapus Gambar</button></div>` : ''}
                    </div>
                    <div class="field-group" style="margin-bottom:8px;">
                        <label>Judul Gambar (Caption — otomatis di bawah gambar, bold pada "Gambar N.")</label>
                        <input type="text" value="${escapeHtml(blk.caption || '')}" placeholder="Gambar 1. Distribusi responden berdasarkan kelompok umur" oninput="articleState.sections['${secKey}'][${bIdx}].caption = this.value">
                    </div>
                    <div class="field-group" style="margin-bottom:0;">
                        <label>Teks Alternatif (Alt Text — untuk aksesibilitas HTML)</label>
                        <input type="text" value="${escapeHtml(blk.alt || '')}" placeholder="Deskripsi singkat isi gambar" oninput="articleState.sections['${secKey}'][${bIdx}].alt = this.value">
                    </div>
                `;
            } else if (blk.type === 'subheading' || blk.type === 'subheading_l3') {
                item.innerHTML = blockHeaderHtml + `
                    <input type="text" style="font-weight:700;${blk.type === 'subheading_l3' ? 'font-style:italic;' : ''}" value="${escapeHtml(blk.text || '')}" oninput="articleState.sections['${secKey}'][${bIdx}].text = this.value" onblur="this.value = normalizeParagraphSpacingJs(this.value); articleState.sections['${secKey}'][${bIdx}].text = this.value;">
                `;
            } else {
                item.innerHTML = blockHeaderHtml + `
                    <textarea rows="3" oninput="articleState.sections['${secKey}'][${bIdx}].text = this.value" onblur="this.value = normalizeParagraphSpacingJs(this.value); articleState.sections['${secKey}'][${bIdx}].text = this.value;">${escapeHtml(blk.text || '')}</textarea>
                `;
            }

            // Drag-and-drop handlers on .block-item
            item.addEventListener('dragstart', (ev) => {
                dragBlockState = { srcSec: secKey, srcIdx: bIdx };
                ev.dataTransfer.effectAllowed = 'move';
                ev.dataTransfer.setData('text/plain', secKey + ':' + bIdx);
                item.classList.add('is-dragging');
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('is-dragging');
                item.setAttribute('draggable', 'false');
                document.querySelectorAll('.block-item').forEach(el => el.classList.remove('drop-above', 'drop-below'));
                document.querySelectorAll('.section-card').forEach(el => el.classList.remove('section-drag-over'));
                dragBlockState = { srcSec: null, srcIdx: null };
            });
            item.addEventListener('dragover', (ev) => {
                if (!dragBlockState.srcSec) return;
                ev.preventDefault();
                ev.stopPropagation();
                const rect = item.getBoundingClientRect();
                const isBefore = (ev.clientY - rect.top) < (rect.height / 2);
                item.classList.toggle('drop-above', isBefore);
                item.classList.toggle('drop-below', !isBefore);
            });
            item.addEventListener('dragleave', () => {
                item.classList.remove('drop-above', 'drop-below');
            });
            item.addEventListener('drop', (ev) => {
                if (!dragBlockState.srcSec) return;
                ev.preventDefault();
                ev.stopPropagation();
                const rect = item.getBoundingClientRect();
                const isBefore = (ev.clientY - rect.top) < (rect.height / 2);
                item.classList.remove('drop-above', 'drop-below');
                const insertIdx = isBefore ? bIdx : bIdx + 1;
                performBlockRelocation(dragBlockState.srcSec, dragBlockState.srcIdx, secKey, insertIdx);
            });

            blocksContainer.appendChild(item);
            if (shouldFlash) {
                setTimeout(() => item.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
            }
        });

        secCard.innerHTML = headerHtml;
        secCard.appendChild(blocksContainer);
        container.appendChild(secCard);
    });
}

function armBlockDrag(handleEl) {
    const item = handleEl.closest('.block-item');
    if (item) {
        item.setAttribute('draggable', 'true');
    }
}

function performBlockRelocation(srcSec, srcIdx, targetSec, rawTargetIdx) {
    if (!articleState.sections[srcSec] || srcIdx < 0 || srcIdx >= articleState.sections[srcSec].length) return;
    const [moved] = articleState.sections[srcSec].splice(srcIdx, 1);
    let targetIdx = Math.max(0, Math.min(rawTargetIdx, articleState.sections[targetSec].length));
    if (srcSec === targetSec && srcIdx < rawTargetIdx) {
        targetIdx = Math.max(0, rawTargetIdx - 1);
    }
    articleState.sections[targetSec].splice(targetIdx, 0, moved);
    dragBlockState = { srcSec: null, srcIdx: null };
    renderBodySectionsEditor({ sec: targetSec, idx: targetIdx });
}

function moveBlockVertical(secKey, bIdx, delta) {
    const blocks = articleState.sections[secKey] || [];
    const activeKeys = getActiveSectionKeys();
    const secPos = activeKeys.indexOf(secKey);

    if (delta < 0) {
        if (bIdx > 0) {
            const tmp = blocks[bIdx - 1];
            blocks[bIdx - 1] = blocks[bIdx];
            blocks[bIdx] = tmp;
            renderBodySectionsEditor({ sec: secKey, idx: bIdx - 1 });
        } else if (secPos > 0) {
            const prevSec = activeKeys[secPos - 1];
            const [moved] = blocks.splice(bIdx, 1);
            if (!Array.isArray(articleState.sections[prevSec])) articleState.sections[prevSec] = [];
            articleState.sections[prevSec].push(moved);
            renderBodySectionsEditor({ sec: prevSec, idx: articleState.sections[prevSec].length - 1 });
            showToast('Blok dipindahkan naik ke akhir bab ' + prevSec.replace('_', ' ').toUpperCase(), 'info');
        }
    } else if (delta > 0) {
        if (bIdx < blocks.length - 1) {
            const tmp = blocks[bIdx + 1];
            blocks[bIdx + 1] = blocks[bIdx];
            blocks[bIdx] = tmp;
            renderBodySectionsEditor({ sec: secKey, idx: bIdx + 1 });
        } else if (secPos >= 0 && secPos < activeKeys.length - 1) {
            const nextSec = activeKeys[secPos + 1];
            const [moved] = blocks.splice(bIdx, 1);
            if (!Array.isArray(articleState.sections[nextSec])) articleState.sections[nextSec] = [];
            articleState.sections[nextSec].unshift(moved);
            renderBodySectionsEditor({ sec: nextSec, idx: 0 });
            showToast('Blok dipindahkan turun ke awal bab ' + nextSec.replace('_', ' ').toUpperCase(), 'info');
        }
    }
}

function moveBlockToSection(srcSec, bIdx, targetSec) {
    if (srcSec === targetSec) return;
    const [moved] = articleState.sections[srcSec].splice(bIdx, 1);
    articleState.sections[targetSec].push(moved);
    renderBodySectionsEditor({ sec: targetSec, idx: articleState.sections[targetSec].length - 1 });
    showToast('Blok dipindahkan ke bab ' + targetSec.replace('_', ' ').toUpperCase(), 'success');
}

function changeBlockLevel(secKey, bIdx, newType) {
    const blk = articleState.sections[secKey][bIdx];
    if (!blk || blk.type === 'table' || blk.type === 'image') return;
    blk.type = newType;
    renderBodySectionsEditor({ sec: secKey, idx: bIdx });
}

function renderTablePreviewHtml(secKey, bIdx, blk) {
    const rows = Array.isArray(blk.rows) ? blk.rows : [];
    let maxCols = 1;
    rows.forEach(r => {
        let sum = 0;
        (r || []).forEach(c => { sum += Math.max(1, parseInt(c.colspan || 1, 10)); });
        if (sum > maxCols) maxCols = sum;
    });
    const aligns = Array.isArray(blk.col_alignments) ? blk.col_alignments : [];

    let alignHtml = '<div class="col-align-bar"><span><strong>Perataan Kolom (Standar Kesehatan):</strong></span>';
    for (let ci = 0; ci < maxCols; ci++) {
        const curA = aligns[ci] || (ci === 0 ? 'left' : 'center');
        alignHtml += `
            <label style="margin:0;display:inline-flex;align-items:center;gap:3px;font-weight:500;">
                Kol ${ci + 1}:
                <select class="block-select-mini" onchange="setTableColAlign('${secKey}', ${bIdx}, ${ci}, this.value)">
                    <option value="left" ${curA === 'left' ? 'selected' : ''}>Kiri</option>
                    <option value="center" ${curA === 'center' ? 'selected' : ''}>Tengah</option>
                    <option value="right" ${curA === 'right' ? 'selected' : ''}>Kanan</option>
                </select>
            </label>
        `;
    }
    alignHtml += '</div>';

    let html = `
        <div class="table-toolbar">
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <button type="button" class="btn btn-outline btn-xs" onclick="addTableRow('${secKey}', ${bIdx}, 'data')">+ Baris Data</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="addTableRow('${secKey}', ${bIdx}, 'group')">+ Grup Variabel</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="addTableRow('${secKey}', ${bIdx}, 'total')">+ Baris Total</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="addTableColumn('${secKey}', ${bIdx})">+ Kolom</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="removeLastTableColumn('${secKey}', ${bIdx})">- Kolom</button>
            </div>
            <button type="button" class="btn btn-teal btn-xs" onclick="formatAllTablesHealthStandard(true)">🏥 Terapkan Standar Kesehatan</button>
        </div>
        ${alignHtml}
        <div style="overflow-x:auto;"><table class="preview-table">
    `;

    // Separate visual thead vs tbody
    let inThead = false;
    rows.forEach((r, rIdx) => {
        const rowType = (r[0] && r[0].row_type) ? r[0].row_type : ((rIdx === 0 || (r[0] && r[0].header)) ? 'header' : 'data');
        const isHdr = rowType === 'header';
        if (isHdr && !inThead) {
            html += '<thead>';
            inThead = true;
        } else if (!isHdr && inThead) {
            html += '</thead><tbody>';
            inThead = false;
        } else if (rIdx === 0 && !isHdr) {
            html += '<tbody>';
        }

        html += `<tr class="row-type-${escapeHtml(rowType)}">`;
        let logicalCol = 0;
        r.forEach((c, cIdx) => {
            if (c.vmerge === 'continue') {
                logicalCol += Math.max(1, parseInt(c.colspan || 1, 10));
                return;
            }
            const tag = (isHdr || c.header) ? 'th' : 'td';
            const cs = (c.colspan && c.colspan > 1) ? ` colspan="${c.colspan}"` : '';
            const rs = (c.rowspan && c.rowspan > 1) ? ` rowspan="${c.rowspan}"` : '';
            const cellAlign = c.align || aligns[logicalCol] || (logicalCol === 0 ? 'left' : 'center');
            const isBold = isHdr || rowType === 'group' || rowType === 'total';
            html += `<${tag}${cs}${rs} style="text-align:${cellAlign};">
                <input type="text" style="border:none;background:transparent;text-align:${cellAlign};width:100%;font-weight:${isBold ? '700' : '400'};"
                    value="${escapeHtml(c.text || '')}"
                    oninput="articleState.sections['${secKey}'][${bIdx}].rows[${rIdx}][${cIdx}].text = this.value">
            </${tag}>`;
            logicalCol += Math.max(1, parseInt(c.colspan || 1, 10));
        });

        // Row controls cell (ignored in export, purely for Studio UI)
        const ctrlTag = isHdr ? 'th' : 'td';
        html += `<${ctrlTag} style="width:160px;white-space:nowrap;text-align:right;background:#f8fafc;">
            <select class="block-select-mini" onchange="setTableRowType('${secKey}', ${bIdx}, ${rIdx}, this.value)" title="Tipe/Level Baris">
                <option value="header" ${rowType === 'header' ? 'selected' : ''}>Header</option>
                <option value="group" ${rowType === 'group' ? 'selected' : ''}>Grup Var</option>
                <option value="subitem" ${rowType === 'subitem' ? 'selected' : ''}>Sub-item</option>
                <option value="data" ${rowType === 'data' ? 'selected' : ''}>Data</option>
                <option value="total" ${rowType === 'total' ? 'selected' : ''}>Total</option>
            </select>
            <button type="button" class="btn btn-outline btn-xs" onclick="moveTableRow('${secKey}', ${bIdx}, ${rIdx}, -1)" ${rIdx === 0 ? 'disabled' : ''}>▲</button>
            <button type="button" class="btn btn-outline btn-xs" onclick="moveTableRow('${secKey}', ${bIdx}, ${rIdx}, 1)" ${rIdx === rows.length - 1 ? 'disabled' : ''}>▼</button>
            <button type="button" class="btn btn-outline btn-xs" onclick="removeTableRow('${secKey}', ${bIdx}, ${rIdx})" ${rows.length <= 1 ? 'disabled' : ''}>✕</button>
        </${ctrlTag}>`;
        html += '</tr>';
    });
    if (inThead) {
        html += '</thead>';
    } else if (rows.length > 0) {
        html += '</tbody>';
    }
    html += '</table></div>';
    return html;
}

function setTableColAlign(secKey, bIdx, colIdx, align) {
    const blk = articleState.sections[secKey][bIdx];
    if (!blk.col_alignments) blk.col_alignments = [];
    blk.col_alignments[colIdx] = align;
    (blk.rows || []).forEach(r => {
        let g = 0;
        r.forEach(c => {
            if (g === colIdx && !(c.header && (c.colspan || 1) > 1)) {
                c.align = align;
            }
            g += Math.max(1, parseInt(c.colspan || 1, 10));
        });
    });
    renderBodySectionsEditor();
}

function setTableRowType(secKey, bIdx, rIdx, rowType) {
    const blk = articleState.sections[secKey][bIdx];
    const row = blk.rows[rIdx];
    if (!row) return;
    if (rowType === 'group' && row.length > 1) {
        // Merge empty trailing cells into a clean spanning variable-group row if appropriate
        const hasOtherText = row.slice(1).some(c => (c.text || '').trim() !== '');
        if (!hasOtherText) {
            let totalCols = 0;
            row.forEach(c => totalCols += Math.max(1, parseInt(c.colspan || 1, 10)));
            blk.rows[rIdx] = [{
                text: row[0].text || 'Variabel',
                colspan: totalCols,
                rowspan: 1,
                vmerge: '',
                header: false,
                align: 'left',
                row_type: 'group'
            }];
            renderBodySectionsEditor();
            return;
        }
    }
    row.forEach(c => {
        c.row_type = rowType;
        c.header = (rowType === 'header');
    });
    renderBodySectionsEditor();
}

function moveTableRow(secKey, bIdx, rIdx, delta) {
    const rows = articleState.sections[secKey][bIdx].rows;
    const target = rIdx + delta;
    if (target < 0 || target >= rows.length) return;
    const tmp = rows[rIdx];
    rows[rIdx] = rows[target];
    rows[target] = tmp;
    renderBodySectionsEditor();
}

function addTableRow(secKey, bIdx, rowType) {
    const blk = articleState.sections[secKey][bIdx];
    const rows = blk.rows || [];
    let colCount = 3;
    if (rows.length > 0) {
        colCount = 0;
        rows[0].forEach(c => colCount += Math.max(1, parseInt(c.colspan || 1, 10)));
    }
    if (rowType === 'group') {
        rows.push([{ text: 'Karakteristik / Variabel', colspan: colCount, rowspan: 1, vmerge: '', header: false, align: 'left', row_type: 'group' }]);
    } else {
        const newRow = [];
        for (let i = 0; i < colCount; i++) {
            newRow.push({
                text: i === 0 ? (rowType === 'total' ? 'Jumlah' : '') : '',
                colspan: 1,
                rowspan: 1,
                vmerge: '',
                header: false,
                align: i === 0 ? 'left' : 'center',
                row_type: rowType
            });
        }
        rows.push(newRow);
    }
    renderBodySectionsEditor();
}

function removeTableRow(secKey, bIdx, rIdx) {
    const rows = articleState.sections[secKey][bIdx].rows;
    if (rows.length <= 1) return;
    rows.splice(rIdx, 1);
    renderBodySectionsEditor();
}

function addTableColumn(secKey, bIdx) {
    const blk = articleState.sections[secKey][bIdx];
    (blk.rows || []).forEach((r, rIdx) => {
        const rt = (r[0] && r[0].row_type) ? r[0].row_type : (rIdx === 0 ? 'header' : 'data');
        if (rt === 'group' && r.length === 1) {
            r[0].colspan = (r[0].colspan || 1) + 1;
        } else {
            r.push({ text: rIdx === 0 ? 'Kolom Baru' : '', colspan: 1, rowspan: 1, vmerge: '', header: (rt === 'header'), align: 'center', row_type: rt });
        }
    });
    renderBodySectionsEditor();
}

function removeLastTableColumn(secKey, bIdx) {
    const blk = articleState.sections[secKey][bIdx];
    (blk.rows || []).forEach(r => {
        if (r.length > 1) {
            r.pop();
        } else if (r.length === 1 && (r[0].colspan || 1) > 1) {
            r[0].colspan = r[0].colspan - 1;
        }
    });
    renderBodySectionsEditor();
}

async function formatAllTablesHealthStandard(forceReclassify = true) {
    syncStateFromInputs();
    showToast('🏥 Memformat seluruh tabel sesuai standar ilmiah kesehatan...', 'info');
    const fd = new FormData();
    fd.append('articleJson', serializeArticleForServer());
    fd.append('forceReclassify', forceReclassify ? '1' : '0');
    fd.append('suggestFootnote', '1');
    try {
        const res = await fetch(BASE_URL + '/formatHealthTables', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok && data.article) {
            articleState = data.article;
            renderBodySectionsEditor();
            showToast(data.message || 'Tabel berhasil diformat.', 'success');
        } else {
            showToast(data.error || 'Gagal memformat tabel.', 'error');
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    }
}

function addSectionBlock(secKey, type) {
    if (type === 'table') {
        const nextNum = countAllTables() + 1;
        articleState.sections[secKey].push({
            type: 'table',
            caption: `Tabel ${nextNum}. Karakteristik Responden`,
            footnote: 'Keterangan: n, frekuensi; %, persentase.',
            col_alignments: ['left', 'center', 'center'],
            rows: [
                [
                    { text: 'Karakteristik', colspan: 1, rowspan: 1, vmerge: '', header: true, align: 'left', row_type: 'header' },
                    { text: 'Frekuensi (n)', colspan: 1, rowspan: 1, vmerge: '', header: true, align: 'center', row_type: 'header' },
                    { text: 'Persentase (%)', colspan: 1, rowspan: 1, vmerge: '', header: true, align: 'center', row_type: 'header' }
                ],
                [
                    { text: 'Kelompok Umur (tahun)', colspan: 3, rowspan: 1, vmerge: '', header: false, align: 'left', row_type: 'group' }
                ],
                [
                    { text: '20–30', colspan: 1, rowspan: 1, vmerge: '', header: false, align: 'left', row_type: 'subitem' },
                    { text: '0', colspan: 1, rowspan: 1, vmerge: '', header: false, align: 'center', row_type: 'subitem' },
                    { text: '0,0', colspan: 1, rowspan: 1, vmerge: '', header: false, align: 'center', row_type: 'subitem' }
                ],
                [
                    { text: 'Total', colspan: 1, rowspan: 1, vmerge: '', header: false, align: 'left', row_type: 'total' },
                    { text: '0', colspan: 1, rowspan: 1, vmerge: '', header: false, align: 'center', row_type: 'total' },
                    { text: '100,0', colspan: 1, rowspan: 1, vmerge: '', header: false, align: 'center', row_type: 'total' }
                ]
            ]
        });
    } else if (type === 'image') {
        articleState.sections[secKey].push({
            type: 'image',
            data: '',
            src: '',
            caption: `Gambar ${countAllFigures() + 1}. `,
            alt: '',
            width: 0,
            height: 0
        });
    } else {
        articleState.sections[secKey].push({ type: type, text: '' });
    }
    renderBodySectionsEditor({ sec: secKey, idx: articleState.sections[secKey].length - 1 });
}

function countAllTables() {
    let count = 0;
    SECTION_KEYS.forEach(k => {
        (articleState.sections[k] || []).forEach(b => {
            if (b && b.type === 'table') count++;
        });
    });
    return count;
}

function countAllFigures() {
    let count = 0;
    SECTION_KEYS.forEach(k => {
        (articleState.sections[k] || []).forEach(b => {
            if (b && b.type === 'image') count++;
        });
    });
    return count;
}

/**
 * Build the article JSON that is posted to the server.
 *
 * Figures live on the server (content-addressed media_key); their base64 data URI is kept
 * in the browser for the live preview only. Sending it back with every action used to blow
 * past the web server request limit and produce 413 Request Entity Too Large.
 */
function serializeArticleForServer() {
    let snapshot;
    try {
        snapshot = JSON.parse(JSON.stringify(articleState));
    } catch (e) {
        return JSON.stringify(articleState);
    }
    const sections = snapshot && snapshot.sections;
    if (sections) {
        Object.keys(sections).forEach(k => {
            const blocks = sections[k];
            if (!Array.isArray(blocks)) return;
            blocks.forEach(b => {
                if (b && b.type === 'image' && b.media_key) {
                    b.data = '';
                    b.src = '';
                }
            });
        });
    }
    return JSON.stringify(snapshot);
}

async function uploadBlockImageFile(file) {
    const fd = new FormData();
    fd.append('image_file', file);
    const res = await fetch(BASE_URL + '/uploadImage', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Gagal menyimpan gambar ke server.');
    return data;
}

function setBlockImageFromFile(secKey, bIdx, inputEl) {
    const file = (inputEl && inputEl.files) ? inputEl.files[0] : null;
    if (!file) return;
    const blk = articleState.sections[secKey] && articleState.sections[secKey][bIdx];
    if (!blk) return;

    const applyImage = (dataUri, width, height, mediaKey) => {
        blk.data = dataUri;
        blk.src = '';
        blk.media_key = mediaKey || '';
        blk.filename = file.name;
        blk.mime = file.type || 'image/png';
        blk.ext = (String(file.name).split('.').pop() || 'png').toLowerCase();
        blk.width = width || 0;
        blk.height = height || 0;
    };

    const persistImage = async () => {
        try {
            const uploaded = await uploadBlockImageFile(file);
            const current = articleState.sections[secKey] && articleState.sections[secKey][bIdx];
            if (current) {
                current.media_key = uploaded.media_key || '';
                if (uploaded.data) current.data = uploaded.data;
                if (uploaded.mime) current.mime = uploaded.mime;
                if (uploaded.ext) current.ext = uploaded.ext;
            }
            showToast('Gambar disisipkan & tersimpan di server. Caption dapat diubah di bawah pratinjau.', 'success');
        } catch (e) {
            showToast('Gambar tampil di pratinjau, tetapi gagal disimpan di server: ' + e.message, 'error');
        }
    };

    const reader = new FileReader();
    reader.onload = () => {
        const dataUri = String(reader.result || '');
        const probe = new Image();
        probe.onload = () => {
            applyImage(dataUri, probe.naturalWidth, probe.naturalHeight, '');
            renderBodySectionsEditor({ sec: secKey, idx: bIdx });
            persistImage();
        };
        probe.onerror = () => {
            // Some formats (e.g. TIFF) cannot be decoded by the browser but are still valid figures.
            applyImage(dataUri, 0, 0, '');
            renderBodySectionsEditor({ sec: secKey, idx: bIdx });
            persistImage();
        };
        probe.src = dataUri;
    };
    reader.readAsDataURL(file);
}

function clearBlockImage(secKey, bIdx) {
    const blk = articleState.sections[secKey] && articleState.sections[secKey][bIdx];
    if (!blk) return;
    blk.data = '';
    blk.src = '';
    blk.media_key = '';
    blk.width = 0;
    blk.height = 0;
    renderBodySectionsEditor({ sec: secKey, idx: bIdx });
}

function removeSectionBlock(secKey, bIdx) {
    articleState.sections[secKey].splice(bIdx, 1);
    renderBodySectionsEditor();
}

// =========================================================================
// Bilingual Typo & Non-Standard Word Auditor (ID KBBI/EYD V + EN SCOWL/AMA)
// =========================================================================
let auditState = { issues: [], filter: 'all', sources: [], bodyLang: 'id' };

function quickOpenLanguageAudit() {
    const tabBtn = document.querySelector('.tab-btn[data-tab="tab-language-audit"]');
    if (tabBtn) {
        switchTab('tab-language-audit', tabBtn);
    }
    runLanguageAudit();
}

async function runLanguageAudit() {
    syncStateFromInputs();
    syncReferencesFromInput();
    const box = document.getElementById('languageAuditResultsBox');
    box.innerHTML = '<div style="padding:28px;text-align:center;color:#64748b;">⏳ Memeriksa ejaan &amp; kata baku (KBBI VI, EYD V, SCOWL US English, AMA Style)...</div>';

    const fd = new FormData();
    fd.append('articleJson', serializeArticleForServer());
    fd.append('bodyLang', document.getElementById('auditBodyLang').value);
    fd.append('useLanguageTool', document.getElementById('auditUseLanguageTool').checked ? '1' : '0');
    fd.append('useGemini', document.getElementById('auditUseGemini').checked ? '1' : '0');

    try {
        const res = await fetch(BASE_URL + '/auditLanguage', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok || !data.audit) {
            box.innerHTML = '<div style="color:#b91c1c;padding:14px;">Gagal menjalankan audit bahasa: ' + escapeHtml(data.error || 'Unknown error') + '</div>';
            return;
        }
        auditState.issues = data.audit.issues || [];
        auditState.sources = data.audit.sources || [];
        auditState.bodyLang = data.audit.body_lang_detected || 'id';
        auditState.filter = 'all';
        renderLanguageAuditResults();
        showToast(`Audit bahasa selesai: ditemukan ${auditState.issues.length} catatan.`, auditState.issues.length === 0 ? 'success' : 'info');
    } catch (e) {
        box.innerHTML = '<div style="color:#b91c1c;padding:14px;">Error jaringan saat audit bahasa: ' + escapeHtml(e.message) + '</div>';
    }
}

function renderLanguageAuditResults() {
    const issues = auditState.issues || [];
    document.getElementById('auditCountBadge').textContent = issues.length;
    const safeFixCount = issues.filter(i => i.auto_fixable && i.suggestions && i.suggestions.length > 0).length;
    const btnSafeAll = document.getElementById('btnApplyAllSafeFixes');
    if (btnSafeAll) {
        btnSafeAll.style.display = safeFixCount > 0 ? 'inline-flex' : 'none';
        btnSafeAll.textContent = `✨ Terapkan ${safeFixCount} Perbaikan Otomatis Aman`;
    }

    const box = document.getElementById('languageAuditResultsBox');
    if (issues.length === 0) {
        box.innerHTML = `
            <div style="padding:26px;text-align:center;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;color:#166534;">
                ✅ <strong>Bagus! Tidak ditemukan typo atau kata tidak baku yang tersisa.</strong>
                <div style="font-size:12px;margin-top:6px;">Bahasa isi terdeteksi: <strong>${auditState.bodyLang === 'en' ? 'English' : 'Bahasa Indonesia'}</strong> · Sumber aktif: ${auditState.sources.map(escapeHtml).join(' · ')}</div>
            </div>
        `;
        return;
    }

    const cntTidakBaku = issues.filter(i => i.category === 'kata_tidak_baku' || i.category === 'serapan_medis').length;
    const cntTypo = issues.filter(i => i.category === 'typo' || i.category === 'english_typo').length;
    const cntEyd = issues.filter(i => ['eyd_v', 'imbuhan_eyd', 'preposisi_eyd', 'pleonasme', 'spasi_ganda'].includes(i.category)).length;
    const cntEn = issues.filter(i => ['english_uk_us_ama', 'english_nonstandard', 'languagetool'].includes(i.category)).length;
    const cntVan = issues.filter(i => ['vancouver_reference_style', 'vancouver_citation_sync'].includes(i.category)).length;

    const filtered = issues.filter(i => {
        if (auditState.filter === 'all') return true;
        if (auditState.filter === 'baku') return i.category === 'kata_tidak_baku' || i.category === 'serapan_medis';
        if (auditState.filter === 'typo') return i.category === 'typo' || i.category === 'english_typo';
        if (auditState.filter === 'eyd') return ['eyd_v', 'imbuhan_eyd', 'preposisi_eyd', 'pleonasme', 'spasi_ganda'].includes(i.category);
        if (auditState.filter === 'en') return ['english_uk_us_ama', 'english_nonstandard', 'languagetool'].includes(i.category);
        if (auditState.filter === 'vancouver') return ['vancouver_reference_style', 'vancouver_citation_sync'].includes(i.category);
        return true;
    });

    let html = `
        <div class="audit-summary-grid">
            <div class="audit-stat-box"><div class="audit-stat-num">${issues.length}</div><div class="audit-stat-label">Total Temuan</div></div>
            <div class="audit-stat-box"><div class="audit-stat-num">${cntTidakBaku}</div><div class="audit-stat-label">Kata Tidak Baku (KBBI)</div></div>
            <div class="audit-stat-box"><div class="audit-stat-num">${cntTypo}</div><div class="audit-stat-label">Dugaan Typo</div></div>
            <div class="audit-stat-box"><div class="audit-stat-num">${cntEyd}</div><div class="audit-stat-label">Kaidah EYD V</div></div>
            <div class="audit-stat-box"><div class="audit-stat-num">${cntVan}</div><div class="audit-stat-label">Sitasi &amp; Pustaka (Vancouver)</div></div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
            <button type="button" class="btn btn-xs ${auditState.filter === 'all' ? 'btn-navy' : 'btn-outline'}" onclick="setAuditFilter('all')">Semua (${issues.length})</button>
            <button type="button" class="btn btn-xs ${auditState.filter === 'baku' ? 'btn-navy' : 'btn-outline'}" onclick="setAuditFilter('baku')">Kata Tidak Baku (${cntTidakBaku})</button>
            <button type="button" class="btn btn-xs ${auditState.filter === 'typo' ? 'btn-navy' : 'btn-outline'}" onclick="setAuditFilter('typo')">Typo (${cntTypo})</button>
            <button type="button" class="btn btn-xs ${auditState.filter === 'eyd' ? 'btn-navy' : 'btn-outline'}" onclick="setAuditFilter('eyd')">EYD V (${cntEyd})</button>
            <button type="button" class="btn btn-xs ${auditState.filter === 'en' ? 'btn-navy' : 'btn-outline'}" onclick="setAuditFilter('en')">English / AMA (${cntEn})</button>
            <button type="button" class="btn btn-xs ${auditState.filter === 'vancouver' ? 'btn-navy' : 'btn-outline'}" onclick="setAuditFilter('vancouver')">Vancouver (${cntVan})</button>
        </div>
    `;

    filtered.forEach(iss => {
        const isErr = iss.severity === 'error';
        const suggs = Array.isArray(iss.suggestions) ? iss.suggestions : [];
        const defaultSugg = suggs[0] || '';
        const suggCtrl = suggs.length > 1
            ? `<select id="audit_sugg_${iss.id}" class="block-select-mini" style="min-width:160px;">${suggs.map(s => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('')}</select>`
            : `<input type="text" id="audit_sugg_${iss.id}" value="${escapeHtml(defaultSugg)}" placeholder="Ketik perbaikan manual..." style="width:190px;padding:3px 7px;font-size:12px;">`;

        html += `
            <div class="audit-issue-card ${isErr ? 'sev-error' : ''}">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <span class="audit-badge ${isErr ? 'audit-badge-err' : 'audit-badge-warn'}">${escapeHtml(iss.category_label || 'Temuan')}</span>
                        <span class="audit-badge audit-badge-lang">${iss.language === 'en' ? 'EN' : 'ID'}</span>
                        <strong style="font-size:12.5px;color:#0f172a;">${escapeHtml(iss.location_label || iss.field_path)}</strong>
                    </div>
                    <span class="audit-badge audit-badge-src">📚 ${escapeHtml(iss.source || 'KBBI / SCOWL')}</span>
                </div>
                <div class="audit-context">${escapeHtml(iss.context || iss.found)}</div>
                <div style="font-size:12.5px;color:#475569;margin-bottom:8px;">${escapeHtml(iss.reason || '')}</div>
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12.5px;">
                        <span>Ditemukan: <code style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;">${escapeHtml(iss.found)}</code></span>
                        <span>→ Perbaikan:</span>
                        ${suggCtrl}
                    </div>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-teal btn-xs" onclick="applyAuditFix('${iss.id}')">✅ Terapkan</button>
                        <button type="button" class="btn btn-outline btn-xs" onclick="ignoreAuditIssue('${iss.id}')">🙈 Abaikan</button>
                    </div>
                </div>
            </div>
        `;
    });

    box.innerHTML = html;
}

function setAuditFilter(f) {
    auditState.filter = f;
    renderLanguageAuditResults();
}

function getFieldPathValue(path) {
    const parts = path.split('.');
    let cur = articleState;
    for (let i = 0; i < parts.length; i++) {
        if (cur == null) return null;
        cur = cur[parts[i]];
    }
    return cur;
}

function setFieldPathValue(path, newVal) {
    const parts = path.split('.');
    let cur = articleState;
    for (let i = 0; i < parts.length - 1; i++) {
        if (cur[parts[i]] == null) return false;
        cur = cur[parts[i]];
    }
    cur[parts[parts.length - 1]] = newVal;
    return true;
}

function replaceOccurrenceSafely(text, found, replacement) {
    if (typeof text !== 'string' || !found) return text;
    if (/^[A-Za-zÀ-ÿ0-9\-\s]+$/u.test(found)) {
        const escaped = found.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const rx = new RegExp('(^|[^A-Za-zÀ-ÿ0-9])' + escaped + '(?=$|[^A-Za-zÀ-ÿ0-9])', 'u');
        if (rx.test(text)) {
            return text.replace(rx, (_, p1) => p1 + replacement);
        }
    }
    return text.replace(found, replacement);
}

function applyAuditFix(issueId) {
    const idx = auditState.issues.findIndex(i => i.id === issueId);
    if (idx === -1) return;
    const iss = auditState.issues[idx];
    const el = document.getElementById('audit_sugg_' + issueId);
    const replacement = el ? el.value.trim() : ((iss.suggestions && iss.suggestions[0]) || '');
    if (!replacement) {
        showToast('Isi kata perbaikan terlebih dahulu sebelum menerapkan.', 'error');
        return;
    }

    const curText = getFieldPathValue(iss.field_path);
    if (typeof curText !== 'string') {
        showToast('Lokasi field tidak ditemukan di state artikel.', 'error');
        return;
    }

    const cleanRep = iss.field_path.startsWith('referensi.') ? stripRefPrefixJs(replacement) : replacement;
    const updatedText = replaceOccurrenceSafely(curText, iss.found, cleanRep);
    setFieldPathValue(iss.field_path, updatedText);
    auditState.issues.splice(idx, 1);

    populateFormFromState();
    renderLanguageAuditResults();
    showToast(`Diperbaiki: "${iss.found}" → "${cleanRep}"`, 'success');
}

function applyAllSafeAuditFixes() {
    let applied = 0;
    const remaining = [];
    auditState.issues.forEach(iss => {
        if (iss.auto_fixable && Array.isArray(iss.suggestions) && iss.suggestions[0]) {
            const curText = getFieldPathValue(iss.field_path);
            if (typeof curText === 'string') {
                const updated = replaceOccurrenceSafely(curText, iss.found, iss.suggestions[0]);
                if (updated !== curText) {
                    setFieldPathValue(iss.field_path, updated);
                    applied++;
                    return;
                }
            }
        }
        remaining.push(iss);
    });
    auditState.issues = remaining;
    populateFormFromState();
    renderLanguageAuditResults();
    showToast(`✨ ${applied} perbaikan baku/EYD/typo aman berhasil diterapkan!`, 'success');
}

function ignoreAuditIssue(issueId) {
    auditState.issues = auditState.issues.filter(i => i.id !== issueId);
    renderLanguageAuditResults();
}

function rebuildApaCitation() {
    syncStateFromInputs();
    const firstAuthor = (articleState.authors && articleState.authors[0] && articleState.authors[0].name)
        ? articleState.authors[0].name.trim().split(',')[0].trim().split(/\s+/).pop()
        : 'Author';
    const year = articleState.copyright_year || '2024';
    const title = (articleState.title_en || articleState.title_id || '').replace(/\.$/, '');
    const jTitle = document.getElementById('cfg_journalTitle').value || 'Kisi Berkelanjutan: Sains Medis Dan Kesehatan';
    const vol = articleState.volume || '1';
    const num = articleState.issue_number || '1';
    const eloc = articleState.elocation_id || 'e76';
    const url = articleState.article_url || '';

    articleState.citation = `${firstAuthor} et al., (${year}). ${title}. ${jTitle}, ${vol}(${num}), ${eloc}. ${url}`;
    document.getElementById('f_citation').value = articleState.citation;
    showToast('Format Citation APA berhasil diperbarui.', 'success');
}

async function savePluginSettings() {
    showToast('Menyimpan pengaturan...', 'info');
    const fd = new FormData();
    ['geminiApiKey', 'geminiModel', 'eissn', 'journalTitle', 'journalShortFooter', 'publisherName', 'referenceStyle', 'formLanguageMode', 'retypesetNote', 'enableFullTextDigest', 'replaceDefaultAbstract'].forEach(k => {
        const el = document.getElementById('cfg_' + k);
        if (el) fd.append(k, el.value);
    });
    try {
        const res = await fetch(BASE_URL + '/saveSettings', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            showToast(data.message, 'success');
        } else {
            showToast(data.error || 'Gagal menyimpan pengaturan.', 'error');
        }
    } catch (e) {
        showToast('Error jaringan: ' + e.message, 'error');
    }
}

async function parseOjsDocxFile() {
    const sel = document.getElementById('ojsDocxFileSelect');
    if (!sel || !sel.value) return;
    showToast('Mengekstrak isi dan tabel dari file .docx OJS...', 'info');
    const fd = new FormData();
    fd.append('submissionFileId', sel.value);
    await sendParseDocxRequest(fd);
}

async function uploadAndParseLocalDocx(inputEl) {
    if (!inputEl.files || !inputEl.files[0]) return;
    showToast('Mengunggah & mengekstrak struktur file .docx...', 'info');
    const fd = new FormData();
    fd.append('docx_file', inputEl.files[0]);
    await sendParseDocxRequest(fd);
    inputEl.value = '';
}

async function sendParseDocxRequest(formData) {
    try {
        const res = await fetch(BASE_URL + '/parseDocx', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.ok) {
            showToast(data.error || 'Gagal mengekstrak file .docx', 'error');
            return;
        }
        const p = data.parsed;
        const enOnly = (getActiveFormLanguageModeJs() === 'en');
        if (p.article_type && (!articleState.article_type || articleState.article_type === 'ORIGINAL ARTICLE')) articleState.article_type = p.article_type;
        if (enOnly) {
            const parsedEnTitle = p.title_en || p.title_id || '';
            if (parsedEnTitle && !articleState.title_en) articleState.title_en = parsedEnTitle;
            articleState.title_id = '';
        } else {
            if (p.title_id && !articleState.title_id) articleState.title_id = p.title_id;
            if (p.title_en && !articleState.title_en) articleState.title_en = p.title_en;
        }
        if (p.review_editor && !articleState.review_editor) articleState.review_editor = p.review_editor;
        if (p.received_date && !articleState.received_date) articleState.received_date = p.received_date;
        if (p.accepted_date && !articleState.accepted_date) articleState.accepted_date = p.accepted_date;
        if (p.published_date && !articleState.published_date) articleState.published_date = p.published_date;
        if (p.contributor_roles && !articleState.contributor_roles) articleState.contributor_roles = p.contributor_roles;
        if (p.footer_issue_line && !articleState.footer_issue_line) articleState.footer_issue_line = translateFooterMonthsToId(p.footer_issue_line);
        if (p.volume && !articleState.volume) articleState.volume = p.volume;
        if (p.issue_number && !articleState.issue_number) articleState.issue_number = p.issue_number;
        if (p.doi && !articleState.doi) articleState.doi = p.doi;
        if (p.keywords_id && !articleState.keywords_id) articleState.keywords_id = p.keywords_id;
        if (p.keywords_en && !articleState.keywords_en) articleState.keywords_en = p.keywords_en;
        if (p.sdgs_id && !articleState.sdgs_id) articleState.sdgs_id = p.sdgs_id;
        if (p.sdgs_en && !articleState.sdgs_en) articleState.sdgs_en = p.sdgs_en;
        if (p.authors && p.authors.length > 0 && (!articleState.authors || !articleState.authors[0].name)) {
            articleState.authors = p.authors;
            articleState.corresponding_name = p.corresponding_name || '';
            articleState.corresponding_email = p.corresponding_email || '';
        }
        if (p.abstract_id && (p.abstract_id.pendahuluan_metode || p.abstract_id.raw)) {
            articleState.abstract_id = p.abstract_id;
        }
        if (p.abstract_en && (p.abstract_en.intro_methods || p.abstract_en.raw)) {
            articleState.abstract_en = p.abstract_en;
        }
        articleState.sections = p.sections || articleState.sections;
        articleState.referensi = p.referensi || articleState.referensi;

        populateFormFromState();
        showToast('Berhasil mengekstrak naskah & tabel dari: ' + data.fileName, 'success');
    } catch (e) {
        showToast('Gagal memproses file .docx: ' + e.message, 'error');
    }
}

async function runAiRestructure() {
    syncStateFromInputs();
    showToast('✨ Memproses restrukturisasi abstrak & metadata dengan AI...', 'info');
    const fd = new FormData();
    fd.append('articleJson', serializeArticleForServer());
    try {
        const res = await fetch(BASE_URL + '/aiRestructure', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok && data.article) {
            const keptMode = getActiveFormLanguageModeJs();
            articleState = data.article;
            articleState.form_mode = keptMode;
            populateFormFromState();
            showToast(data.message || 'Abstrak berhasil dirapikan!', 'success');
        } else {
            showToast(data.error || 'Gagal menjalankan restrukturisasi AI.', 'error');
        }
    } catch (e) {
        showToast('Error jaringan saat memanggil AI: ' + e.message, 'error');
    }
}

function normalizeParagraphSpacingJs(str) {
    if (typeof str !== 'string') return '';
    return str
        .replace(/[\t\r\n\u00A0\u1680\u2000-\u200B\u202F\u205F\u3000]+/g, ' ')
        .replace(/ {2,}/g, ' ')
        .trim();
}

function autoNormalizeInputElement(el) {
    if (!el || typeof el.value !== 'string') return;
    const cleaned = normalizeParagraphSpacingJs(el.value);
    if (cleaned !== el.value) {
        el.value = cleaned;
        syncStateFromInputs();
    }
}

function normalizeAllArticleSpacing(showNotice = true) {
    syncStateFromInputs();
    syncReferencesFromInput();
    let changes = 0;

    const norm = (val) => {
        if (typeof val !== 'string') return val;
        const cleaned = normalizeParagraphSpacingJs(val);
        if (cleaned !== val) changes++;
        return cleaned;
    };

    ['article_type', 'title_id', 'title_en', 'keywords_id', 'keywords_en', 'sdgs_id', 'sdgs_en', 'citation',
     'review_editor', 'received_date', 'accepted_date', 'published_date', 'funding', 'competing_interests',
     'contributor_roles', 'corresponding_name', 'journal_short_footer', 'footer_issue_line', 'retypeset_note'].forEach(k => {
        if (typeof articleState[k] === 'string') articleState[k] = norm(articleState[k]);
    });
    if (typeof articleState.footer_issue_line === 'string') {
        const transFooter = translateFooterMonthsToId(articleState.footer_issue_line);
        if (transFooter !== articleState.footer_issue_line) {
            articleState.footer_issue_line = transFooter;
            changes++;
        }
    }

    if (Array.isArray(articleState.authors)) {
        articleState.authors.forEach(a => {
            if (a && typeof a.name === 'string') a.name = norm(a.name);
            if (a && typeof a.affiliation === 'string') a.affiliation = norm(a.affiliation);
        });
    }

    ['abstract_id', 'abstract_en'].forEach(abKey => {
        if (articleState[abKey] && typeof articleState[abKey] === 'object') {
            Object.keys(articleState[abKey]).forEach(sub => {
                if (typeof articleState[abKey][sub] === 'string') {
                    articleState[abKey][sub] = norm(articleState[abKey][sub]);
                }
            });
        }
    });

    SECTION_KEYS.forEach(secKey => {
        const blocks = (articleState.sections && articleState.sections[secKey]) || [];
        blocks.forEach((blk, bIdx) => {
            if (typeof blk === 'string') {
                blocks[bIdx] = norm(blk);
            } else if (blk && typeof blk === 'object') {
                ['text', 'caption', 'footnote'].forEach(bk => {
                    if (typeof blk[bk] === 'string') blk[bk] = norm(blk[bk]);
                });
                if (Array.isArray(blk.rows)) {
                    blk.rows.forEach(row => {
                        if (!Array.isArray(row)) return;
                        row.forEach((cell, cIdx) => {
                            if (cell && typeof cell === 'object' && typeof cell.text === 'string') {
                                cell.text = norm(cell.text);
                            } else if (typeof cell === 'string') {
                                row[cIdx] = norm(cell);
                            }
                        });
                    });
                }
            }
        });
    });

    if (Array.isArray(articleState.referensi)) {
        articleState.referensi = articleState.referensi.map(r => stripRefPrefixJs(norm(r))).filter(Boolean);
    }

    if (changes > 0) {
        populateFormFromState();
        if (Array.isArray(auditState.issues) && auditState.issues.length > 0) {
            auditState.issues = auditState.issues.filter(i => i.category !== 'spasi_ganda');
            renderLanguageAuditResults();
        }
    }
    if (showNotice) {
        showToast(
            changes > 0
                ? `✂️ ${changes} paragraf/bidang dirapikan sesuai aturan baku maksimal 1 spasi.`
                : '✅ Seluruh paragraf sudah mematuhi aturan maksimal 1 spasi tunggal.',
            'success'
        );
    }
    return changes;
}

function downloadGeneratedFile(endpointOp) {
    normalizeAllArticleSpacing(false);
    const form = document.getElementById('downloadForm');
    form.action = BASE_URL + '/' + endpointOp;
    document.getElementById('downloadArticleJson').value = serializeArticleForServer();
    form.submit();
    showToast(endpointOp === 'generateDocx' ? '📄 Mengunduh file DOCX Template 2026...' : '🧬 Mengunduh file AmeliCA JATS XML...', 'success');
}

async function loadFullTextDigestPreview() {
    normalizeAllArticleSpacing(false);
    const box = document.getElementById('fullTextDigestPreviewBox');
    box.innerHTML = '<div style="padding:30px;text-align:center;color:#64748b;">⏳ Membangun JATS 1.2 XML &amp; me-render Full-Text HTML...</div>';
    const fd = new FormData();
    fd.append('articleJson', serializeArticleForServer());
    try {
        const res = await fetch(BASE_URL + '/previewFullText', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok && data.html) {
            box.innerHTML = data.html;
        } else {
            box.innerHTML = '<div style="color:#b91c1c;">Gagal memuat pratinjau: ' + escapeHtml(data.error || 'Unknown error') + '</div>';
        }
    } catch (e) {
        box.innerHTML = '<div style="color:#b91c1c;">Error: ' + escapeHtml(e.message) + '</div>';
    }
}

async function saveDirectlyToOjsGalley() {
    normalizeAllArticleSpacing(false);
    showToast('🚀 Menyimpan JATS XML, DOCX, dan References ke OJS 3.5...', 'info');
    const fd = new FormData();
    fd.append('articleJson', serializeArticleForServer());
    fd.append('syncReferences', '1');
    try {
        const res = await fetch(BASE_URL + '/saveToGalley', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            showToast(data.message, 'success');
        } else {
            showToast(data.error || 'Gagal menyimpan ke Galley OJS.', 'error');
        }
    } catch (e) {
        showToast('Error saat menyimpan ke Galley OJS: ' + e.message, 'error');
    }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', populateFormFromState);
</script>
</body>
</html>
