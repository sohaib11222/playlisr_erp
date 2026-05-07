{{-- Help page styling — mirrors the POS-create design language: cream
     content-wrapper background, modern sans-serif typography, 14px base,
     38px form controls with 6px radius, soft cards with light shadows.
     Scoped to body.help-v2 so nothing else on the site is affected. --}}
<style>
body.help-v2 .content-wrapper {
    background: #FAF6EE !important;
}
body.help-v2 .help-page,
body.help-v2 .help-content-header,
body.help-v2 .help-page p,
body.help-v2 .help-page li,
body.help-v2 .help-page td,
body.help-v2 .help-page th,
body.help-v2 .help-page label,
body.help-v2 .help-page small {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: #2b3440;
}
body.help-v2 .help-content-header h1 {
    font-weight: 700;
    letter-spacing: -0.2px;
}
body.help-v2 .help-content-header h1 small {
    color: #6c7480;
    font-size: 13px;
    margin-left: 8px;
    font-weight: 400;
}
body.help-v2 .help-breadcrumb-link {
    color: inherit;
    text-decoration: none;
}
body.help-v2 .help-breadcrumb-link:hover { color: #1a73e8; }

/* Cards */
body.help-v2 .help-card {
    background: #fff;
    border: 1px solid #ece6d8;
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    margin-bottom: 16px;
    overflow: hidden;
}
body.help-v2 .help-card-header {
    background: #f7f4ec;
    border-bottom: 1px solid #ece6d8;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #4a5260;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
body.help-v2 .help-card-header--article {
    text-transform: none;
    font-size: 16px;
    letter-spacing: 0;
    color: #1f2630;
}
body.help-v2 .help-card-body {
    padding: 16px;
}

/* Search */
body.help-v2 .help-card--search { padding: 18px 18px 8px; }
body.help-v2 .help-search-label {
    font-size: 12px;
    font-weight: 600;
    color: #6c7480;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    display: block;
}
body.help-v2 .help-search-wrap {
    position: relative;
    display: flex;
    gap: 8px;
}
body.help-v2 .help-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #a0a8b3;
    pointer-events: none;
    font-size: 14px;
}
body.help-v2 .help-search-input {
    height: 44px;
    padding: 6px 14px 6px 36px;
    font-size: 15px;
    border-radius: 8px;
    border: 1px solid #d9d3c3;
    background: #fff;
    flex: 1 1 auto;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
body.help-v2 .help-search-input:focus {
    outline: none;
    border-color: #1a73e8;
    box-shadow: 0 0 0 3px rgba(26,115,232,0.12);
}
body.help-v2 .help-search-btn {
    height: 44px;
    padding: 0 22px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    border: none;
    background: #1a73e8;
    color: #fff;
}
body.help-v2 .help-search-btn:hover { background: #1561c4; }
body.help-v2 .help-search-clear { margin-top: 8px; font-size: 12px; }
body.help-v2 .help-search-clear a { color: #6c7480; text-decoration: none; }
body.help-v2 .help-search-clear a:hover { color: #d9534f; }

/* Pills */
body.help-v2 .help-pill {
    background: #ebe4d4;
    color: #6a624f;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}

/* Section grid */
body.help-v2 .help-section-heading {
    font-size: 13px;
    font-weight: 700;
    color: #4a5260;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 22px 0 8px;
}
body.help-v2 .help-section-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
}
body.help-v2 .help-card--section { margin-bottom: 0; }
body.help-v2 .help-article-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
body.help-v2 .help-article-list li {
    border-bottom: 1px solid #f0ebe0;
}
body.help-v2 .help-article-list li:last-child { border-bottom: none; }
body.help-v2 .help-article-link {
    display: block;
    padding: 12px 16px;
    color: #1f2630;
    text-decoration: none;
    transition: background-color 0.12s ease;
}
body.help-v2 .help-article-link:hover {
    background: #fbf8f0;
    color: #1a73e8;
}
body.help-v2 .help-article-title {
    font-size: 14px;
    font-weight: 600;
}
body.help-v2 .help-article-summary {
    font-size: 12.5px;
    color: #6c7480;
    margin-top: 3px;
    line-height: 1.4;
}

/* Search results */
body.help-v2 .help-result-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
body.help-v2 .help-result-list li { border-bottom: 1px solid #f0ebe0; }
body.help-v2 .help-result-list li:last-child { border-bottom: none; }
body.help-v2 .help-result-link {
    display: block;
    padding: 12px 0;
    color: #1f2630;
    text-decoration: none;
}
body.help-v2 .help-result-link:hover { color: #1a73e8; }
body.help-v2 .help-result-title { font-size: 15px; font-weight: 600; }
body.help-v2 .help-result-meta {
    margin-top: 4px;
    font-size: 13px;
    color: #6c7480;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
body.help-v2 .help-section-tag {
    background: #ebe4d4;
    color: #6a624f;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
body.help-v2 .help-empty {
    background: #fffbe5;
    border: 1px solid #f5e9a8;
    color: #6a5d2a;
    padding: 12px 14px;
    border-radius: 8px;
    font-size: 14px;
}

/* Show page layout */
body.help-v2 .help-show-grid {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 18px;
}
@media (max-width: 900px) {
    body.help-v2 .help-show-grid { grid-template-columns: 1fr; }
}
body.help-v2 .help-show-side .help-empty-side {
    padding: 12px 16px;
    color: #8a9098;
    font-size: 13px;
}
body.help-v2 .help-back-btn {
    width: 100%;
    height: 38px;
    border-radius: 8px;
    border: 1px solid #d9d3c3;
    background: #fff;
    color: #4a5260;
    font-weight: 600;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
body.help-v2 .help-back-btn:hover {
    background: #fbf8f0;
    color: #1a73e8;
    text-decoration: none;
}

/* Article body */
body.help-v2 .help-article-lead {
    font-size: 16px;
    color: #4a5260;
    margin: 0 0 14px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f0ebe0;
}
body.help-v2 .help-article-body { font-size: 14.5px; line-height: 1.6; }
body.help-v2 .help-article-body h2 { font-size: 17px; margin: 22px 0 10px; font-weight: 700; }
body.help-v2 .help-article-body h3 { font-size: 15px; margin: 18px 0 8px; font-weight: 700; color: #1f2630; }
body.help-v2 .help-article-body p { margin: 0 0 10px; }
body.help-v2 .help-article-body ol,
body.help-v2 .help-article-body ul { padding-left: 24px; margin: 0 0 12px; }
body.help-v2 .help-article-body li { margin-bottom: 6px; }
body.help-v2 .help-article-body a { color: #1a73e8; }
body.help-v2 .help-article-body code {
    background: #f4eee0;
    color: #6a5a30;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 12.5px;
}
body.help-v2 .help-article-body table {
    margin: 12px 0;
    border-radius: 6px;
    overflow: hidden;
    width: 100%;
    border: 1px solid #ece6d8;
}
body.help-v2 .help-article-body table th {
    background: #f7f4ec;
    color: #4a5260;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 8px 12px;
}
body.help-v2 .help-article-body table td { padding: 8px 12px; border-top: 1px solid #f0ebe0; }
body.help-v2 .help-article-body .help-tip {
    background: #fffbe5;
    border-left: 4px solid #f0c419;
    padding: 10px 14px;
    margin: 12px 0;
    border-radius: 0 6px 6px 0;
    font-size: 13.5px;
}
body.help-v2 .help-article-body .help-warn {
    background: #fdecea;
    border-left: 4px solid #d9534f;
    padding: 10px 14px;
    margin: 12px 0;
    border-radius: 0 6px 6px 0;
    font-size: 13.5px;
}
body.help-v2 .help-article-footer {
    color: #8a9098;
    font-size: 12.5px;
    margin: 0;
}
body.help-v2 .help-article-footer a { color: #1a73e8; }
body.help-v2 .help-footnote {
    color: #8a9098;
    font-size: 13px;
    margin-top: 18px;
}
</style>
