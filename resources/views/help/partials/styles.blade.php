{{-- Help page styling — mirrors the POS-create design language: cream
     content-wrapper background, modern sans-serif typography, soft cards
     with light shadows. Scoped to body.help-v2 so nothing else on the
     site is affected. --}}
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
    line-height: 1.55;
    color: #2b3440;
}

/* Page header */
body.help-v2 .help-content-header h1 {
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.15;
    color: #1a1f29;
}
body.help-v2 .help-content-header h1 small {
    color: #6c7480;
    font-size: 16px;
    margin-left: 10px;
    font-weight: 400;
    letter-spacing: 0;
    display: inline-block;
    vertical-align: middle;
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
    margin-bottom: 18px;
    overflow: hidden;
}
body.help-v2 .help-card-header {
    background: #f7f4ec;
    border-bottom: 1px solid #ece6d8;
    padding: 14px 18px;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.1px;
    color: #1f2630;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
body.help-v2 .help-card-header--article {
    font-size: 22px;
    line-height: 1.25;
}
body.help-v2 .help-card-body {
    padding: 18px;
}

/* Search */
body.help-v2 .help-card--search { padding: 22px; }
body.help-v2 .help-search-label {
    font-size: 14px;
    font-weight: 600;
    color: #4a5260;
    letter-spacing: 0;
    margin-bottom: 8px;
    display: block;
}
body.help-v2 .help-search-wrap {
    position: relative;
    display: flex;
    gap: 8px;
}
body.help-v2 .help-search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #a0a8b3;
    pointer-events: none;
    font-size: 16px;
}
body.help-v2 .help-search-input {
    height: 50px;
    padding: 6px 16px 6px 42px;
    font-size: 16px;
    border-radius: 10px;
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
    height: 50px;
    padding: 0 26px;
    font-size: 15px;
    font-weight: 600;
    border-radius: 10px;
    border: none;
    background: #1a73e8;
    color: #fff;
}
body.help-v2 .help-search-btn:hover { background: #1561c4; }
body.help-v2 .help-search-clear { margin-top: 10px; font-size: 13px; }
body.help-v2 .help-search-clear a { color: #6c7480; text-decoration: none; }
body.help-v2 .help-search-clear a:hover { color: #d9534f; }

/* Pills */
body.help-v2 .help-pill {
    background: #ebe4d4;
    color: #6a624f;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    letter-spacing: 0.2px;
    white-space: nowrap;
}

/* Section grid */
body.help-v2 .help-section-heading {
    font-size: 22px;
    font-weight: 800;
    color: #1a1f29;
    letter-spacing: -0.3px;
    margin: 28px 0 12px;
}
body.help-v2 .help-section-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 18px;
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
    padding: 14px 18px;
    color: #1f2630;
    text-decoration: none;
    transition: background-color 0.12s ease;
}
body.help-v2 .help-article-link:hover {
    background: #fbf8f0;
    color: #1a73e8;
}
body.help-v2 .help-article-title {
    font-size: 16px;
    font-weight: 600;
    line-height: 1.35;
}
body.help-v2 .help-article-summary {
    font-size: 13.5px;
    color: #6c7480;
    margin-top: 4px;
    line-height: 1.45;
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
    padding: 14px 0;
    color: #1f2630;
    text-decoration: none;
}
body.help-v2 .help-result-link:hover { color: #1a73e8; }
body.help-v2 .help-result-title { font-size: 17px; font-weight: 600; line-height: 1.3; }
body.help-v2 .help-result-meta {
    margin-top: 5px;
    font-size: 13.5px;
    color: #6c7480;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
body.help-v2 .help-section-tag {
    background: #ebe4d4;
    color: #6a624f;
    font-size: 12px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 999px;
    letter-spacing: 0.2px;
}
body.help-v2 .help-empty {
    background: #fffbe5;
    border: 1px solid #f5e9a8;
    color: #6a5d2a;
    padding: 14px 16px;
    border-radius: 8px;
    font-size: 14.5px;
}

/* Show page layout */
body.help-v2 .help-show-grid {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
}
@media (max-width: 900px) {
    body.help-v2 .help-show-grid { grid-template-columns: 1fr; }
}
body.help-v2 .help-show-side .help-empty-side {
    padding: 14px 18px;
    color: #8a9098;
    font-size: 13.5px;
}
body.help-v2 .help-back-btn {
    width: 100%;
    height: 42px;
    border-radius: 10px;
    border: 1px solid #d9d3c3;
    background: #fff;
    color: #4a5260;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
body.help-v2 .help-back-btn:hover {
    background: #fbf8f0;
    color: #1a73e8;
    text-decoration: none;
}

/* Article body */
body.help-v2 .help-article-lead {
    font-size: 17px;
    color: #4a5260;
    margin: 0 0 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid #f0ebe0;
    line-height: 1.5;
}
body.help-v2 .help-article-body { font-size: 15px; line-height: 1.65; }
body.help-v2 .help-article-body h2 {
    font-size: 22px;
    margin: 26px 0 12px;
    font-weight: 800;
    letter-spacing: -0.3px;
    color: #1a1f29;
}
body.help-v2 .help-article-body h3 {
    font-size: 18px;
    margin: 22px 0 10px;
    font-weight: 700;
    letter-spacing: -0.1px;
    color: #1f2630;
}
body.help-v2 .help-article-body p { margin: 0 0 12px; }
body.help-v2 .help-article-body strong { color: #1a1f29; }
body.help-v2 .help-article-body ol,
body.help-v2 .help-article-body ul { padding-left: 26px; margin: 0 0 14px; }
body.help-v2 .help-article-body li { margin-bottom: 7px; }
body.help-v2 .help-article-body a { color: #1a73e8; }
body.help-v2 .help-article-body code {
    background: #f4eee0;
    color: #6a5a30;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 13.5px;
}
body.help-v2 .help-article-body table {
    margin: 14px 0;
    border-radius: 8px;
    overflow: hidden;
    width: 100%;
    border: 1px solid #ece6d8;
}
body.help-v2 .help-article-body table th {
    background: #f7f4ec;
    color: #4a5260;
    font-size: 13px;
    font-weight: 700;
    padding: 10px 14px;
    text-align: left;
}
body.help-v2 .help-article-body table td { padding: 10px 14px; border-top: 1px solid #f0ebe0; font-size: 14px; }

/* Highlight callouts — beefed up so important info jumps off the page */
body.help-v2 .help-article-body .help-tip,
body.help-v2 .help-article-body .help-warn,
body.help-v2 .help-article-body .help-critical,
body.help-v2 .help-article-body .help-must-do {
    margin: 16px 0;
    padding: 14px 16px 14px 50px;
    border-radius: 10px;
    font-size: 15px;
    line-height: 1.55;
    position: relative;
    border: 1px solid transparent;
}
body.help-v2 .help-article-body .help-tip::before,
body.help-v2 .help-article-body .help-warn::before,
body.help-v2 .help-article-body .help-critical::before,
body.help-v2 .help-article-body .help-must-do::before {
    position: absolute;
    left: 16px;
    top: 14px;
    font-family: 'Font Awesome 5 Free', 'FontAwesome', sans-serif;
    font-weight: 900;
    font-size: 18px;
    line-height: 1;
}
body.help-v2 .help-article-body .help-tip {
    background: #fffbe5;
    border-color: #f5e9a8;
    color: #5a4d20;
}
body.help-v2 .help-article-body .help-tip::before { content: "\f0eb"; color: #d9a900; }
body.help-v2 .help-article-body .help-tip strong { color: #4a3f15; }

body.help-v2 .help-article-body .help-warn {
    background: #fdecea;
    border-color: #f5c6c1;
    color: #6e251c;
}
body.help-v2 .help-article-body .help-warn::before { content: "\f071"; color: #d9534f; }
body.help-v2 .help-article-body .help-warn strong { color: #5a1a13; }

body.help-v2 .help-article-body .help-critical {
    background: #d9534f;
    border-color: #b13a36;
    color: #fff;
    font-weight: 600;
    font-size: 15.5px;
    box-shadow: 0 2px 6px rgba(217,83,79,0.25);
}
body.help-v2 .help-article-body .help-critical::before { content: "\f06a"; color: #fff; }
body.help-v2 .help-article-body .help-critical strong { color: #fff; text-decoration: underline; text-decoration-thickness: 2px; text-underline-offset: 3px; }
body.help-v2 .help-article-body .help-critical a { color: #fff; text-decoration: underline; }

body.help-v2 .help-article-body .help-must-do {
    background: #e8f4ec;
    border-color: #b6dec3;
    color: #1f5732;
}
body.help-v2 .help-article-body .help-must-do::before { content: "\f00c"; color: #2e8b57; }
body.help-v2 .help-article-body .help-must-do strong { color: #133e22; }

body.help-v2 .help-article-footer {
    color: #8a9098;
    font-size: 13px;
    margin: 0;
}
body.help-v2 .help-article-footer a { color: #1a73e8; }
body.help-v2 .help-footnote {
    color: #8a9098;
    font-size: 14px;
    margin-top: 22px;
}
</style>
