@extends('layouts.app')
@section('title', 'Phone Playbook')

@section('content')
{{-- Cream / pastel-yellow look to match /pos/create. Scoped under .pp-shell. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>

<style>
.pp-shell {
    --d-bg: #FAF6EE;
    --d-surface: #FFFFFF;
    --d-surface-2: #F7F1E3;
    --d-ink: #1F1B16;
    --d-ink-2: #5A5045;
    --d-ink-3: #8E8273;
    --d-line: #ECE3CF;
    --d-line-2: #DFD2B3;
    --d-accent: #FFF2B3;
    --d-accent-deep: #E8CF68;
    --d-accent-soft: #FFF9DB;
    --d-accent-text: #5A4410;
    --d-high: #B5502E;
    --d-high-bg: #FBEBDF;
    --d-high-line: #ECCDB6;
    --d-good: #2E7D32;
    --d-good-bg: #EAF3EA;
    --d-good-line: #C4DEC6;
    --d-bad: #A5402F;
    --d-bad-bg: #FBEAE5;
    --d-bad-line: #E6C7BF;
    --d-calm: #3F6D8C;
    --d-calm-bg: #EAF0F4;
    --d-calm-line: #CBDCE6;
    --d-radius: 12px;
    --d-radius-sm: 10px;

    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--d-ink);
    -webkit-font-smoothing: antialiased;
    background: var(--d-bg);
    max-width: 860px;
    margin: 12px auto 56px;
    padding: 0 16px;
}
.pp-shell *, .pp-shell *::before, .pp-shell *::after { box-sizing: border-box; }

/* header */
.pp-shell .pp-header { margin: 12px 4px 18px; padding-bottom: 14px; border-bottom: 2px solid var(--d-ink); }
.pp-shell .pp-eyebrow { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: var(--d-accent-text); margin: 0 0 6px; }
.pp-shell .pp-header h1 { font-size: 30px; font-weight: 800; letter-spacing: -.015em; margin: 0; line-height: 1.12; }
.pp-shell .pp-header p { font-size: 15px; color: var(--d-ink-2); margin: 8px 0 0; line-height: 1.5; max-width: 66ch; }
.pp-shell .pp-header .who { font-size: 13px; color: var(--d-ink-3); margin-top: 8px; }

/* section */
.pp-shell section { margin-top: 30px; scroll-margin-top: 14px; }
.pp-shell .sec-head { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
.pp-shell .sec-num { font-size: 15px; font-weight: 800; color: var(--d-accent-text); background: var(--d-accent); border: 1px solid var(--d-accent-deep); border-radius: 999px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.pp-shell .sec-head h2 { font-size: 22px; font-weight: 800; letter-spacing: -.01em; margin: 0; line-height: 1.2; }
.pp-shell .lede { font-size: 14.5px; color: var(--d-ink-2); margin: 0 0 14px; line-height: 1.5; max-width: 66ch; }
.pp-shell h3 { font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--d-ink-3); margin: 20px 0 9px; }

/* priority badge */
.pp-shell .prio { display: inline-block; font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; }
.pp-shell .prio.high { background: var(--d-high-bg); color: var(--d-high); border: 1px solid var(--d-high-line); }
.pp-shell .prio.low { background: var(--d-surface-2); color: var(--d-ink-3); border: 1px solid var(--d-line-2); }

/* card */
.pp-shell .card { background: var(--d-surface); border: 1px solid var(--d-line); border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06); padding: 16px 20px; }
.pp-shell .card[id] { scroll-margin-top: 14px; }
.pp-shell .card p { margin: 0; font-size: 14.5px; line-height: 1.55; }

/* numbered flow */
.pp-shell .flow { list-style: none; padding: 0; margin: 0; counter-reset: step; }
.pp-shell .flow > li { position: relative; padding: 13px 18px 13px 52px; background: var(--d-surface); border: 1px solid var(--d-line); border-radius: var(--d-radius-sm); box-shadow: 0 1px 2px rgba(31,27,22,.05); counter-increment: step; }
.pp-shell .flow > li + li { margin-top: 9px; }
.pp-shell .flow > li::before { content: counter(step); position: absolute; left: 13px; top: 13px; width: 27px; height: 27px; border-radius: 999px; background: var(--d-accent); border: 1px solid var(--d-accent-deep); color: var(--d-accent-text); font-weight: 800; font-size: 14px; display: flex; align-items: center; justify-content: center; }
.pp-shell .flow strong { display: block; font-size: 15px; margin-bottom: 1px; }
.pp-shell .flow .sub { color: var(--d-ink-2); font-size: 13.5px; line-height: 1.45; }

/* router / tree */
.pp-shell .pp-tree { background: var(--d-surface); border: 1px solid var(--d-line-2); border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06); padding: 18px 20px; margin: 0 0 6px; }
.pp-shell .pp-tree .tree-head h2 { font-size: 19px; font-weight: 800; margin: 0; letter-spacing: -.01em; }
.pp-shell .pp-tree .tree-head p { font-size: 13.5px; color: var(--d-ink-2); margin: 4px 0 14px; }
.pp-shell .tree-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 560px) { .pp-shell .tree-grid { grid-template-columns: 1fr; } }
.pp-shell .branch-card { display: flex; flex-direction: column; gap: 5px; padding: 12px 15px; border-radius: var(--d-radius-sm); border: 1px solid var(--d-line); text-decoration: none; color: var(--d-ink); transition: transform .1s ease, box-shadow .1s ease; }
.pp-shell .branch-card:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(31,27,22,.09); }
.pp-shell .branch-card.high { background: var(--d-accent-soft); border-color: var(--d-accent-deep); }
.pp-shell .branch-card.low { background: #FBF9F3; }
.pp-shell .branch-card .bc-q { font-weight: 700; font-size: 14.5px; line-height: 1.3; }
.pp-shell .branch-card .bc-go { font-size: 12px; font-weight: 800; color: var(--d-accent-text); text-transform: uppercase; letter-spacing: .03em; }
.pp-shell .branch-card.low .bc-go { color: var(--d-ink-3); }
.pp-shell .prio-legend { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-top: 14px; font-size: 13px; color: var(--d-ink-2); }
.pp-shell .prio-legend span { display: inline-flex; align-items: center; gap: 8px; }
.pp-shell .tree-foot { font-size: 13.5px; color: var(--d-ink-2); margin: 14px 0 0; line-height: 1.5; }

/* script / say */
.pp-shell .script { border-left: 3px solid var(--d-accent-deep); background: var(--d-surface); border-radius: 0 var(--d-radius-sm) var(--d-radius-sm) 0; padding: 12px 18px; margin: 12px 0; box-shadow: 0 1px 2px rgba(31,27,22,.04); }
.pp-shell .script .label { display: inline-flex; align-items: center; gap: 7px; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--d-ink-3); font-weight: 800; margin-bottom: 4px; }
.pp-shell .script p { margin: 4px 0; font-size: 15.5px; line-height: 1.45; }
.pp-shell .say::before { content: "\201C"; color: var(--d-accent-text); }
.pp-shell .say::after { content: "\201D"; color: var(--d-accent-text); }
.pp-shell .chan { font-size: 10px; font-weight: 800; letter-spacing: .05em; padding: 2px 7px; border-radius: 999px; }
.pp-shell .chan.call { background: var(--d-accent); color: var(--d-accent-text); border: 1px solid var(--d-accent-deep); }
.pp-shell .chan.text { background: var(--d-calm-bg); color: var(--d-calm); border: 1px solid var(--d-calm-line); }

/* quick-answer Q/A */
.pp-shell .qa { margin: 12px 0; }
.pp-shell .qa + .qa { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--d-line); }
.pp-shell .qa .q { font-weight: 700; font-size: 14.5px; margin: 0 0 5px; }
.pp-shell .qa .say { display: block; font-size: 15.5px; line-height: 1.5; color: var(--d-ink); }
.pp-shell .qa .note { font-size: 12.5px; color: var(--d-ink-3); margin: 5px 0 0; }

/* hours + address rows */
.pp-shell .facts { display: flex; flex-direction: column; gap: 8px; margin: 0 0 12px; }
.pp-shell .facts .frow { background: var(--d-surface-2); border: 1px solid var(--d-line); border-radius: var(--d-radius-sm); padding: 9px 14px; }
.pp-shell .facts .store { font-weight: 800; font-size: 14px; margin-right: 8px; }
.pp-shell .facts .val { font-size: 14px; color: var(--d-ink-2); line-height: 1.5; }

/* branch (if / then) */
.pp-shell .branch { display: grid; gap: 9px; margin-top: 12px; }
.pp-shell .branch .brow { display: grid; grid-template-columns: 0.9fr auto 1.6fr; align-items: center; gap: 14px; background: var(--d-surface); border: 1px solid var(--d-line); border-left: 4px solid var(--d-accent-deep); border-radius: var(--d-radius-sm); padding: 11px 16px; }
.pp-shell .branch .cond { font-weight: 700; font-size: 14px; }
.pp-shell .branch .arrow { color: var(--d-accent-text); font-weight: 800; font-size: 18px; }
.pp-shell .branch .act { color: var(--d-ink-2); font-size: 14px; }
@media (max-width: 560px) { .pp-shell .branch .brow { grid-template-columns: 1fr; gap: 3px; } .pp-shell .branch .arrow { display: none; } .pp-shell .branch .act::before { content: "\2192  "; color: var(--d-accent-text); font-weight: 800; } }

/* word swap */
.pp-shell .word-swap { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid var(--d-line); border-radius: var(--d-radius); overflow: hidden; box-shadow: 0 1px 2px rgba(31,27,22,.05); }
.pp-shell .word-swap .h { padding: 9px 14px; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; font-weight: 800; border-bottom: 1px solid var(--d-line); }
.pp-shell .word-swap .h.no { background: var(--d-bad-bg); color: var(--d-bad); }
.pp-shell .word-swap .h.yes { background: var(--d-good-bg); color: var(--d-good); }
.pp-shell .word-swap .cell { padding: 10px 14px; font-size: 14px; border-bottom: 1px solid var(--d-line); }
.pp-shell .word-swap .cell.no { color: var(--d-ink-2); }
.pp-shell .word-swap .cell.no s { color: var(--d-ink-3); text-decoration-color: var(--d-bad); }
.pp-shell .word-swap .cell:nth-last-child(-n+2) { border-bottom: none; }

/* callout */
.pp-shell .callout { background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep); border-radius: var(--d-radius-sm); padding: 13px 18px; margin: 14px 0 0; font-size: 14px; color: var(--d-ink-2); line-height: 1.5; }
.pp-shell .callout strong { color: var(--d-accent-text); }

/* do / dont */
.pp-shell .dd { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 620px) { .pp-shell .dd { grid-template-columns: 1fr; } }
.pp-shell .dd .col { border-radius: var(--d-radius); padding: 16px 20px; border: 1px solid var(--d-line); }
.pp-shell .dd .do { background: var(--d-good-bg); border-color: var(--d-good-line); }
.pp-shell .dd .dont { background: var(--d-bad-bg); border-color: var(--d-bad-line); }
.pp-shell .dd h4 { margin: 0 0 10px; font-size: 12.5px; letter-spacing: .06em; text-transform: uppercase; font-weight: 800; }
.pp-shell .dd .do h4 { color: var(--d-good); }
.pp-shell .dd .dont h4 { color: var(--d-bad); }
.pp-shell .dd ul { margin: 0; padding-left: 18px; }
.pp-shell .dd li { margin: 6px 0; font-size: 14px; }

.pp-shell a { color: var(--d-accent-text); }
.pp-shell .say a, .pp-shell .note a, .pp-shell .lede a, .pp-shell .facts a, .pp-shell .flow a, .pp-shell .dd a, .pp-shell .pp-foot a { color: var(--d-high); font-weight: 700; text-decoration: underline; text-decoration-color: var(--d-high-line); text-underline-offset: 2px; }
.pp-shell .say a:hover, .pp-shell .note a:hover, .pp-shell .lede a:hover, .pp-shell .facts a:hover, .pp-shell .flow a:hover, .pp-shell .dd a:hover, .pp-shell .pp-foot a:hover { text-decoration-color: var(--d-high); }
.pp-shell .branch-card { text-decoration: none; }
.pp-shell .pp-foot { margin-top: 36px; padding-top: 16px; border-top: 1px solid var(--d-line); font-size: 12.5px; color: var(--d-ink-3); }
</style>

<div class="pp-shell">

    <header class="pp-header">
        <p class="pp-eyebrow">Nivessa Records &middot; Front-of-House Guide</p>
        <h1>Answering the Phone &amp; Finding Records</h1>
        <p>Exactly what to say on calls and texts &mdash; find a record across both stores, quote a collection, and answer the everyday questions fast.</p>
        <p class="who">For whoever's on the phones. Keep it open at the counter &mdash; tap what they're asking and read the script.</p>
    </header>

    {{-- router --}}
    <nav class="pp-tree" aria-label="Jump to the right script">
        <div class="tree-head">
            <h2>Start here &mdash; what are they asking?</h2>
            <p>Tap the one that fits and read the exact words. Handle the high-priority ones first.</p>
        </div>
        <div class="tree-grid">
            <a class="branch-card high" href="#record">
                <span class="prio high">High priority</span>
                <span class="bc-q">&ldquo;Do you have this record?&rdquo;</span>
                <span class="bc-go">Find &amp; confirm it &rarr;</span>
            </a>
            <a class="branch-card high" href="#buying">
                <span class="prio high">High priority</span>
                <span class="bc-q">&ldquo;Do you buy records? / I want to sell&rdquo;</span>
                <span class="bc-go">Get them a quote &rarr;</span>
            </a>
            <a class="branch-card low" href="#hours">
                <span class="prio low">Quick</span>
                <span class="bc-q">Hours &middot; open today? &middot; where are you?</span>
                <span class="bc-go">Hours &amp; locations &rarr;</span>
            </a>
            <a class="branch-card low" href="#shipping">
                <span class="prio low">Quick</span>
                <span class="bc-q">Do you ship? &middot; where's my order?</span>
                <span class="bc-go">Orders &amp; shipping &rarr;</span>
            </a>
            <a class="branch-card low" href="#events">
                <span class="prio low">Quick</span>
                <span class="bc-q">Events &middot; perform &middot; rent the venue</span>
                <span class="bc-go">Events &rarr;</span>
            </a>
            <a class="branch-card low" href="#hiring">
                <span class="prio low">Quick</span>
                <span class="bc-q">Are you hiring?</span>
                <span class="bc-go">Careers &rarr;</span>
            </a>
        </div>
        <div class="prio-legend">
            <span><span class="prio high">High priority</span> A sale or a buy on the line &mdash; give it full effort.</span>
            <span><span class="prio low">Quick</span> Fast info &mdash; answer warmly in under a minute.</span>
        </div>
        <p class="tree-foot">Not sure of an answer? Say &ldquo;Checking in with the cashiers at the store&hellip;&rdquo;, find out, and give them a real answer before you hang up. Need a person? See <a href="#coworker">reaching a coworker</a>. However it ends, <a href="#visit">invite them into the store</a>.</p>
    </nav>

    {{-- 1 --}}
    <section id="tone">
        <div class="sec-head"><span class="sec-num">1</span><h2>How to talk on the phone</h2></div>
        <p class="lede">Short, warm, top customer service. Sound glad they called and genuinely happy to help. Write like you talk &mdash; natural, easygoing American English, the way you'd text a friend. Point people to <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a> whenever it answers their question.</p>

        <div class="script">
            <div class="label"><span class="chan call">Call</span> Standard greeting</div>
            <p class="say">Thanks for calling Nivessa Records! How can I help you?</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> Opening a text back</div>
            <p class="say">Hi! Thanks for reaching out to Nivessa Records. What are you looking for?</p>
        </div>

        <div class="callout"><strong>If you don't know the answer, never guess.</strong> Say &ldquo;Checking in with the cashiers at the store&hellip;&rdquo;, find out, and give them a real answer. If it truly needs a follow-up, take their name and number and make sure someone handles it. Nothing gets ignored.</div>

        <h3>Words &amp; the way you say them</h3>
        <div class="word-swap">
            <div class="h yes">What we say</div>
            <div class="h no">What we don't say</div>
            <div class="cell yes">Their name if you have it, otherwise just talk to them</div>
            <div class="cell no"><s>Buddy</s>, bro, pal, boss, my friend, brother</div>
            <div class="cell yes">Sure, what can I help you find?</div>
            <div class="cell no">Yeah&hellip; what do you want?</div>
            <div class="cell yes">We don't have that one here &mdash; but we may have it at our other store or the warehouse.</div>
            <div class="cell no">We don't have it. (and stop)</div>
            <div class="cell yes">Looking this up for you!</div>
            <div class="cell no">Hold on. (silence)</div>
            <div class="cell yes">Yes! We're selling them tonight to everyone who preordered &mdash; did you get your preorder in?</div>
            <div class="cell no">We will be selling them now, to whom who preorder.</div>
            <div class="cell yes">Awesome &mdash; see you today!</div>
            <div class="cell no">Great, most welcome.</div>
            <div class="cell yes">One sec, checking our stock now!</div>
            <div class="cell no">Let us check and we will get back to you.</div>
        </div>

        <div class="callout"><strong>Talk like a local.</strong> Keep it natural and casual &mdash; the way you'd text a friend. Skip stiff or translated-sounding phrases like &ldquo;most welcome&rdquo; or &ldquo;to whom who preorder.&rdquo; Read it back before you send: if it doesn't sound like everyday American English, rewrite it.</div>

        <div class="callout"><strong>Never call a customer &ldquo;buddy&rdquo; or &ldquo;bro.&rdquo;</strong> Use their name if they gave it, or just speak to them directly. Please, thank you, and no problem go a long way.</div>
    </section>

    {{-- 2 --}}
    <section id="record">
        <div class="sec-head"><span class="sec-num">2</span><h2>Do you have this record?</h2><span class="prio high">High priority</span></div>
        <p class="lede">A sale on the line &mdash; reply fast. The system tells you what <em>should</em> be there; only someone's eyes confirm what <em>is</em>. Always finish with a yes, or a no plus an alternative.</p>

        <ol class="flow">
            <li><strong>Check <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a> and the ERP first.</strong><span class="sub">Search the title by artist and album. See which store it shows at &mdash; Pico or Hollywood.</span></li>
            <li><strong>Have someone lay eyes on it.</strong><span class="sub">DM whoever's on shift at that store on Slack to confirm the copy is really on the shelf. No Slack answer? Call their cell (on-shift only &mdash; see Section 6).</span></li>
            <li><strong>Not at one store? Check the other and the warehouse.</strong><span class="sub">If it's not at Pico, check Hollywood, and the warehouse, before you ever say we don't have it.</span></li>
            <li><strong>Fix the ERP if the count is wrong.</strong><span class="sub">If the shelf didn't match the system &mdash; none there, or extras &mdash; update the true inventory so the next person isn't misled.</span></li>
            <li><strong>Give a real answer.</strong><span class="sub">A clear yes, or a no with an alternative (another format, pressing, or ordering it in). Never a flat &ldquo;nope.&rdquo;</span></li>
        </ol>

        <div class="script">
            <div class="label"><span class="chan call">Call</span> While you check</div>
            <p class="say">Looking this up for you!</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> Not sure / not online yet</div>
            <p class="say">We get new and used records in every day! If you don't see it on <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a>, we're happy to dig for you.</p>
            <div class="label" style="margin-top:14px"><span class="chan call">Call</span> Not here right now</div>
            <p class="say">We don't have that one here, but we may have it at our other store or the warehouse.</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> The &ldquo;no, but&hellip;&rdquo;</div>
            <p class="say">No copy on CD right now, but we've got it on vinyl! Want me to hold it under your name, or order the CD in for you?</p>
            <div class="label" style="margin-top:14px"><span class="chan call">Call</span> / <span class="chan text">Text</span> &nbsp;Price, UPC, or move it to the other store</div>
            <p class="say">Pulling it up in our system right now! It's $[price], UPC [number] &mdash; and yes, I can have it moved to the Hollywood store for you. Want me to set it aside there?</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> They were told it's set aside for them</div>
            <p class="say">You're all set &mdash; it's tucked to the side with your name on it. See you at open!</p>
            <div class="label" style="margin-top:14px"><span class="chan text">Text</span> New release tonight / preorders</div>
            <p class="say">Yes! We're selling them tonight to everyone who preordered &mdash; did you get your preorder in? If so, you're all set to grab it today.</p>
        </div>

        <div class="callout"><strong>Look it up before you promise.</strong> For price, UPC, or &ldquo;do you have it,&rdquo; open the ERP first and tell them you're checking &mdash; never &ldquo;we'll check and get back to you.&rdquo; Give them the real number.</div>

        <div class="callout"><strong>Always offer an alternative.</strong> Before you say no, check every format for that artist (vinyl, CD, cassette) and their other titles. No One Direction CD but we have the vinyl? Say so. Nothing in stock? Offer to order it or take their number. A &ldquo;no&rdquo; with an option beats a flat &ldquo;no.&rdquo;</div>
    </section>

    {{-- 3 --}}
    <section id="buying">
        <div class="sec-head"><span class="sec-num">3</span><h2>Do you buy records? / Selling a collection</h2><span class="prio high">High priority</span></div>
        <p class="lede">A buy can be real money for the store. Make them feel great about calling, get the details, and hand it to a Nivessa buyer &mdash; never quote a price yourself.</p>

        <div class="script">
            <div class="label"><span class="chan call">Call</span> / <span class="chan text">Text</span> &nbsp;Do you buy records?</div>
            <p class="say">Yes! Please bring your collection into either store and we'll give you a fair quote. Do you need the address?</p>
            <div class="label" style="margin-top:12px"><span class="chan call">Call</span> / <span class="chan text">Text</span> &nbsp;Big collection</div>
            <p class="say">No appointment needed &mdash; just bring it by either store. For a large estate collection, 10 boxes or more, we can arrange a house pickup. Please share some details about what you have (genres, collection size, some titles) and a Nivessa buyer will reach out with a quote.</p>
        </div>

        <div class="callout"><strong>Get the details, not a price.</strong> Collect format, quantity, a few titles, and photos, plus their name and number, and pass it to a buyer. Never put a dollar figure on it over the phone.</div>
    </section>

    {{-- 4 --}}
    <section id="quick">
        <div class="sec-head"><span class="sec-num">4</span><h2>Quick answers</h2><span class="prio low">Quick &mdash; under a minute</span></div>
        <p class="lede">These have a known answer. Give it warmly, point to <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a> when it helps, and wrap up.</p>

        <div class="card" id="hours">
            <h3 style="margin-top:0">Hours &amp; locations</h3>
            <div class="facts">
                <div class="frow"><span class="store">Hollywood</span><span class="val">Mon&ndash;Thu 9:30am&ndash;11pm &middot; Fri &amp; Sat 9:30am&ndash;1am &middot; Sun 9am&ndash;11pm &middot; <a href="https://www.google.com/maps/search/?api=1&amp;query=6434+Hollywood+Blvd+Los+Angeles+CA+90028" target="_blank" rel="noopener">6434 Hollywood Blvd, Los Angeles, CA 90028</a></span></div>
                <div class="frow"><span class="store">Pico</span><span class="val">Sun&ndash;Wed 10am&ndash;7pm &middot; Thu&ndash;Sat 10am&ndash;8pm &middot; <a href="https://www.google.com/maps/search/?api=1&amp;query=5770+W+Pico+Blvd+Los+Angeles+CA+90019" target="_blank" rel="noopener">5770 W Pico Blvd, Los Angeles, CA 90019</a></span></div>
            </div>
            <div class="qa">
                <p class="q">&ldquo;What are your hours?&rdquo;</p>
                <span class="say">Hey! We're open every day. Hollywood: 9:30am&ndash;11pm Mon&ndash;Thu, til 1am Fri &amp; Sat, and 9am&ndash;11pm Sundays. Pico: 10am&ndash;7pm, and til 8pm Thursday&ndash;Saturday.</span>
                <p class="note">Give today's business hours for both stores.</p>
            </div>
            <div class="qa">
                <p class="q">&ldquo;Are you open today?&rdquo;</p>
                <span class="say">Yes, we're open til x pm today &mdash; come visit :)</span>
                <p class="note">(Or 1am on weekends. Pico has different hours &mdash; check above.)</p>
            </div>
            <div class="qa">
                <p class="q">&ldquo;Where are you located?&rdquo;</p>
                <span class="say"><a href="https://www.google.com/maps/search/?api=1&amp;query=6434+Hollywood+Blvd+Los+Angeles+CA+90028" target="_blank" rel="noopener">Hollywood: 6434 Hollywood Blvd, LA 90028</a>. <a href="https://www.google.com/maps/search/?api=1&amp;query=5770+W+Pico+Blvd+Los+Angeles+CA+90019" target="_blank" rel="noopener">Pico: 5770 W Pico Blvd, LA 90019</a>. See you there!</span>
                <p class="note">Maps and directions: <a href="https://nivessa.com/locations" target="_blank" rel="noopener">nivessa.com/locations</a></p>
            </div>
        </div>

        <div class="card" id="shipping" style="margin-top:14px">
            <h3 style="margin-top:0">Online orders &amp; shipping</h3>
            <div class="qa">
                <p class="q">&ldquo;Do you ship?&rdquo;</p>
                <span class="say">Yes, we ship worldwide! You can order through <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a>.</span>
            </div>
            <div class="qa">
                <p class="q">&ldquo;How long does shipping take?&rdquo;</p>
                <span class="say">Most orders go out within 1&ndash;3 business days. Delivery time depends on size and where you are, but we send tracking as soon as it ships.</span>
            </div>
            <div class="qa">
                <p class="q">&ldquo;Where's my order?&rdquo;</p>
                <span class="say">What's your name and order number? Our shipping team will look it up and reach out to you.</span>
                <p class="note">Take name + order number and pass it to the shipping team.</p>
            </div>
        </div>

        <div class="card" id="events" style="margin-top:14px">
            <h3 style="margin-top:0">Events &amp; venue</h3>
            <div class="qa">
                <p class="q">&ldquo;How do I perform / book a show?&rdquo;</p>
                <span class="say">Excited to host your show! You can book it at: <a href="https://nivessa.com/venues" target="_blank" rel="noopener">nivessa.com/venues</a>.</span>
            </div>
            <div class="qa">
                <p class="q">&ldquo;Can I rent the space?&rdquo;</p>
                <span class="say">Yes, you can book the space at <a href="https://nivessa.com/venues" target="_blank" rel="noopener">nivessa.com/venues</a>.</span>
            </div>
            <div class="qa">
                <p class="q">&ldquo;I made a song &mdash; any thoughts? Is it worth publishing?&rdquo;</p>
                <span class="say">Love that you're making music! We're a record shop &mdash; not a label or publisher &mdash; so we don't review or publish tracks. But we'd love to have you play: you can book a show at <a href="https://nivessa.com/venues" target="_blank" rel="noopener">nivessa.com/venues</a>, and if you press your own record we'd be glad to carry it.</span>
                <p class="note">Be encouraging and warm &mdash; we're not A&amp;R, so point them toward performing.</p>
            </div>
        </div>

        <div class="card" id="hiring" style="margin-top:14px">
            <h3 style="margin-top:0">Hiring</h3>
            <div class="qa">
                <p class="q">&ldquo;Are you hiring?&rdquo;</p>
                <span class="say">Check out <a href="https://nivessa.com/careers" target="_blank" rel="noopener">nivessa.com/careers</a> and you can apply there.</span>
            </div>
            <div class="qa">
                <p class="q">&ldquo;I applied but no one replied.&rdquo;</p>
                <span class="say">If there's a good fit you will hear back from us.</span>
            </div>
        </div>

        <div class="card" id="lost" style="margin-top:14px">
            <h3 style="margin-top:0">Lost &amp; found</h3>
            <div class="qa">
                <p class="q">&ldquo;I think I left something in the store &mdash; can you find it?&rdquo;</p>
                <span class="say">Yes sure, I'll check in with the cashier and let you know if we found them!</span>
                <p class="note">Get exactly what and where (e.g. black headphones, front-left corner), have a cashier look and safeguard it, then follow up.</p>
            </div>
        </div>
    </section>

    {{-- 5 --}}
    <section id="visit">
        <div class="sec-head"><span class="sec-num">5</span><h2>Turn every call into a visit</h2></div>
        <p class="lede">The best outcome of almost any call is a customer walking in. On every call, find a natural reason to invite them in &mdash; and a reason to come today.</p>

        <div class="branch">
            <div class="brow"><div class="cond">Record's in stock</div><div class="arrow">&rarr;</div><div class="act">&ldquo;We have it! Want to place a pickup order?&rdquo;</div></div>
            <div class="brow"><div class="cond">We don't have that title</div><div class="arrow">&rarr;</div><div class="act">Check all formats for that artist, and their other titles, before saying no.</div></div>
            <div class="brow"><div class="cond">Just a quick question</div><div class="arrow">&rarr;</div><div class="act">Answer it, then: &ldquo;See you soon :)&rdquo;</div></div>
        </div>

        <div class="callout"><strong>This one counts.</strong> Getting people through the door is one of the most valuable things you can do on the phone &mdash; there's a reward being set up for it, so keep track of who you bring in. (Ask Sarah for the current details.)</div>
    </section>

    {{-- 6 --}}
    <section id="coworker">
        <div class="sec-head"><span class="sec-num">6</span><h2>Reaching a coworker</h2></div>
        <p class="lede">Confirming a record, or a caller needs a specific person: Slack first, cell if no answer, and only ever reach someone who's actually on shift.</p>

        <ol class="flow">
            <li><strong>Check they're on shift.</strong><span class="sub">Only contact people who are working right now. Never message or call someone on their day off &mdash; find who's on shift instead.</span></li>
            <li><strong>DM them on Slack.</strong><span class="sub">Short and clear: what you need and that a customer's waiting. Give them a moment to reply.</span></li>
            <li><strong>No answer? Call their cell.</strong><span class="sub">If it's time-sensitive and Slack's quiet, call their cell directly. That's what the escalation is for.</span></li>
            <li><strong>Still nothing? Take a message.</strong><span class="sub">Name, number, what they need, and a callback time &mdash; then make sure it actually happens.</span></li>
        </ol>

        <div class="callout"><strong>Transferring to the other store?</strong> &ldquo;Let me connect you &mdash; one moment please.&rdquo; Slack before you call, and never ring someone who isn't on shift.</div>
    </section>

    {{-- 7 --}}
    <section id="reference">
        <div class="sec-head"><span class="sec-num">7</span><h2>Quick reference</h2></div>
        <div class="dd">
            <div class="col do">
                <h4>Always do</h4>
                <ul>
                    <li>Answer warm: &ldquo;Thanks for calling Nivessa Records!&rdquo;</li>
                    <li>Handle high-priority calls (records, buying) with full effort</li>
                    <li>Keep quick calls short and friendly</li>
                    <li>For records: check <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a> and the ERP, then confirm it's on the shelf</li>
                    <li>Not at one store? Check the other and the warehouse</li>
                    <li>Update the ERP when the count is wrong</li>
                    <li>Always end with a yes, or a no plus an alternative</li>
                    <li>Point people to <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a> when it answers them</li>
                    <li>Not sure? &ldquo;Checking in with the cashiers at the store&hellip;&rdquo;</li>
                    <li>Invite every caller into the store</li>
                </ul>
            </div>
            <div class="col dont">
                <h4>Never do</h4>
                <ul>
                    <li>Call a customer &ldquo;buddy,&rdquo; &ldquo;bro,&rdquo; or &ldquo;boss&rdquo;</li>
                    <li>Promise a record no one has physically seen</li>
                    <li>Say &ldquo;we don't have it&rdquo; before checking the other store + warehouse</li>
                    <li>Give a flat &ldquo;no&rdquo; with no alternative</li>
                    <li>Quote a price on a collection over the phone</li>
                    <li>Guess at an answer &mdash; find out for sure instead</li>
                    <li>Leave someone with no direct reply</li>
                    <li>Contact a coworker who's off today</li>
                </ul>
            </div>
        </div>
    </section>

    <div class="pp-foot">Nivessa Records front-of-house phone guide &middot; Hollywood &amp; Pico &middot; <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a> &middot; Keep at the counter.</div>

</div>
@endsection
