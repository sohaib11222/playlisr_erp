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
.pp-shell .pp-header { margin: 12px 4px 20px; padding-bottom: 18px; border-bottom: 2px solid var(--d-ink); }
.pp-shell .pp-eyebrow { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: var(--d-accent-text); margin: 0 0 8px; }
.pp-shell .pp-header h1 { font-size: 30px; font-weight: 800; letter-spacing: -.015em; margin: 0; line-height: 1.15; }
.pp-shell .pp-header p { font-size: 15px; color: var(--d-ink-2); margin: 10px 0 0; line-height: 1.55; max-width: 62ch; }
.pp-shell .pp-header .who { font-size: 13px; color: var(--d-ink-3); margin-top: 12px; }

/* section */
.pp-shell section { margin-top: 34px; }
.pp-shell .sec-head { display: flex; align-items: baseline; gap: 12px; margin-bottom: 12px; }
.pp-shell .sec-num { font-size: 15px; font-weight: 800; color: var(--d-accent-text); background: var(--d-accent); border: 1px solid var(--d-accent-deep); border-radius: 999px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.pp-shell .sec-head h2 { font-size: 22px; font-weight: 800; letter-spacing: -.01em; margin: 0; line-height: 1.2; }
.pp-shell .lede { font-size: 15px; color: var(--d-ink-2); margin: 0 0 16px; line-height: 1.55; max-width: 64ch; }
.pp-shell h3 { font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--d-ink-3); margin: 22px 0 10px; }

/* card */
.pp-shell .card { background: var(--d-surface); border: 1px solid var(--d-line); border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06); padding: 16px 20px; }
.pp-shell .card p { margin: 0; font-size: 15px; line-height: 1.6; }

/* numbered flow */
.pp-shell .flow { list-style: none; padding: 0; margin: 0; counter-reset: step; }
.pp-shell .flow > li { position: relative; padding: 14px 18px 14px 54px; background: var(--d-surface); border: 1px solid var(--d-line); border-radius: var(--d-radius-sm); box-shadow: 0 1px 2px rgba(31,27,22,.05); counter-increment: step; }
.pp-shell .flow > li + li { margin-top: 10px; }
.pp-shell .flow > li::before { content: counter(step); position: absolute; left: 14px; top: 14px; width: 28px; height: 28px; border-radius: 999px; background: var(--d-accent); border: 1px solid var(--d-accent-deep); color: var(--d-accent-text); font-weight: 800; font-size: 14px; display: flex; align-items: center; justify-content: center; }
.pp-shell .flow strong { display: block; font-size: 15.5px; margin-bottom: 2px; }
.pp-shell .flow .sub { color: var(--d-ink-2); font-size: 14px; line-height: 1.5; }

/* branch (if / then) */
.pp-shell .branch { display: grid; gap: 10px; margin-top: 12px; }
.pp-shell .branch .row { display: grid; grid-template-columns: 1fr auto 1.3fr; align-items: center; gap: 12px; background: var(--d-surface); border: 1px solid var(--d-line); border-left: 4px solid var(--d-accent-deep); border-radius: var(--d-radius-sm); padding: 12px 16px; }
.pp-shell .branch .cond { font-weight: 700; font-size: 14.5px; }
.pp-shell .branch .arrow { color: var(--d-accent-text); font-weight: 800; font-size: 18px; }
.pp-shell .branch .act { color: var(--d-ink-2); font-size: 14.5px; }
@media (max-width: 560px) { .pp-shell .branch .row { grid-template-columns: 1fr; gap: 3px; } .pp-shell .branch .arrow { display: none; } .pp-shell .branch .act::before { content: "\2192  "; color: var(--d-accent-text); font-weight: 800; } }

/* triage */
.pp-shell .triage { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 620px) { .pp-shell .triage { grid-template-columns: 1fr; } }
.pp-shell .tcol { border-radius: var(--d-radius); padding: 18px 20px; border: 1px solid var(--d-line); }
.pp-shell .tcol.hot { background: var(--d-accent-soft); border-color: var(--d-accent-deep); }
.pp-shell .tcol.calm { background: var(--d-calm-bg); border-color: var(--d-calm-line); }
.pp-shell .tcol .tag { display: inline-block; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 800; padding: 4px 10px; border-radius: 999px; margin-bottom: 10px; }
.pp-shell .tcol.hot .tag { background: var(--d-accent); color: var(--d-accent-text); border: 1px solid var(--d-accent-deep); }
.pp-shell .tcol.calm .tag { background: var(--d-calm); color: #fff; }
.pp-shell .tcol h4 { margin: 0 0 3px; font-size: 16.5px; font-weight: 800; }
.pp-shell .tcol .goal { font-size: 13.5px; color: var(--d-ink-2); margin: 0 0 10px; }
.pp-shell .tcol ul { margin: 0; padding-left: 18px; }
.pp-shell .tcol li { margin: 5px 0; font-size: 14.5px; }

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
.pp-shell .dd li { margin: 6px 0; font-size: 14.5px; }

/* script */
.pp-shell .script { border-left: 3px solid var(--d-accent-deep); background: var(--d-surface); border-radius: 0 var(--d-radius-sm) var(--d-radius-sm) 0; padding: 12px 18px; margin: 12px 0; box-shadow: 0 1px 2px rgba(31,27,22,.04); }
.pp-shell .script .label { font-size: 11px; letter-spacing: .07em; text-transform: uppercase; color: var(--d-ink-3); font-weight: 800; margin-bottom: 4px; }
.pp-shell .script p { margin: 4px 0; font-size: 16px; line-height: 1.45; }
.pp-shell .say::before { content: "\201C"; color: var(--d-accent-text); }
.pp-shell .say::after { content: "\201D"; color: var(--d-accent-text); }

/* word swap table */
.pp-shell .word-swap { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid var(--d-line); border-radius: var(--d-radius); overflow: hidden; box-shadow: 0 1px 2px rgba(31,27,22,.05); }
.pp-shell .word-swap .h { padding: 9px 14px; font-size: 11px; letter-spacing: .07em; text-transform: uppercase; font-weight: 800; border-bottom: 1px solid var(--d-line); }
.pp-shell .word-swap .h.no { background: var(--d-bad-bg); color: var(--d-bad); }
.pp-shell .word-swap .h.yes { background: var(--d-good-bg); color: var(--d-good); }
.pp-shell .word-swap .cell { padding: 10px 14px; font-size: 14.5px; border-bottom: 1px solid var(--d-line); }
.pp-shell .word-swap .cell.no { color: var(--d-ink-2); }
.pp-shell .word-swap .cell.no s { color: var(--d-ink-3); text-decoration-color: var(--d-bad); }
.pp-shell .word-swap .cell:nth-last-child(-n+2) { border-bottom: none; }

/* callout */
.pp-shell .callout { background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep); border-radius: var(--d-radius-sm); padding: 14px 18px; margin: 16px 0 0; font-size: 14.5px; color: var(--d-ink-2); line-height: 1.55; }
.pp-shell .callout strong { color: var(--d-accent-text); }

/* quick list */
.pp-shell .quick-list { list-style: none; margin: 0; padding: 0; }
.pp-shell .quick-list li { display: grid; grid-template-columns: 1fr 1.5fr; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--d-line); align-items: baseline; }
.pp-shell .quick-list li:last-child { border-bottom: none; }
.pp-shell .quick-list .q { font-weight: 700; font-size: 14.5px; }
.pp-shell .quick-list .a { color: var(--d-ink-2); font-size: 14px; }
@media (max-width: 560px) { .pp-shell .quick-list li { grid-template-columns: 1fr; gap: 3px; } }

.pp-shell a { color: var(--d-accent-text); }

/* decision tree / router */
.pp-shell section { scroll-margin-top: 14px; }
.pp-shell .pp-tree { background: var(--d-surface); border: 1px solid var(--d-line-2); border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06); padding: 18px 20px; margin: 0 0 8px; }
.pp-shell .pp-tree .tree-head h2 { font-size: 19px; font-weight: 800; margin: 0; letter-spacing: -.01em; }
.pp-shell .pp-tree .tree-head p { font-size: 13.5px; color: var(--d-ink-2); margin: 4px 0 14px; }
.pp-shell .tree-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 560px) { .pp-shell .tree-grid { grid-template-columns: 1fr; } }
.pp-shell .branch-card { display: flex; flex-direction: column; gap: 3px; padding: 13px 16px; border-radius: var(--d-radius-sm); border: 1px solid var(--d-line); text-decoration: none; color: var(--d-ink); transition: transform .1s ease, box-shadow .1s ease; }
.pp-shell .branch-card:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(31,27,22,.09); }
.pp-shell .branch-card.hot { background: var(--d-accent-soft); border-color: var(--d-accent-deep); }
.pp-shell .branch-card.calm { background: var(--d-calm-bg); border-color: var(--d-calm-line); }
.pp-shell .branch-card .bc-q { font-weight: 700; font-size: 15px; line-height: 1.3; }
.pp-shell .branch-card .bc-go { font-size: 12.5px; font-weight: 800; color: var(--d-accent-text); text-transform: uppercase; letter-spacing: .03em; }
.pp-shell .branch-card.calm .bc-go { color: var(--d-calm); }
.pp-shell .tree-foot { font-size: 13.5px; color: var(--d-ink-2); margin: 14px 0 0; line-height: 1.55; }

/* channel tag on script labels (call vs text) */
.pp-shell .script .label { display: inline-flex; align-items: center; gap: 6px; }
.pp-shell .chan { font-size: 10px; font-weight: 800; letter-spacing: .06em; padding: 2px 7px; border-radius: 999px; }
.pp-shell .chan.call { background: var(--d-accent); color: var(--d-accent-text); border: 1px solid var(--d-accent-deep); }
.pp-shell .chan.text { background: var(--d-calm-bg); color: var(--d-calm); border: 1px solid var(--d-calm-line); }

.pp-shell .pp-foot { margin-top: 40px; padding-top: 18px; border-top: 1px solid var(--d-line); font-size: 12.5px; color: var(--d-ink-3); }
</style>

<div class="pp-shell">

    <header class="pp-header">
        <p class="pp-eyebrow">Nivessa Records &middot; Front-of-House Guide</p>
        <h1>Answering the Phone &amp; Finding Records</h1>
        <p>A step-by-step handbook for taking calls, tracking down a record across both stores, and reaching the right coworker &mdash; without leaving a customer hanging.</p>
        <p class="who">Written for Fatteen. Keep it open at the counter. Tap what the caller needs and jump straight to the script.</p>
    </header>

    {{-- decision tree / router --}}
    <nav class="pp-tree" aria-label="Jump to the right script">
        <div class="tree-head">
            <h2>Start here &mdash; what are they asking?</h2>
            <p>Call or text, tap the one that fits and go straight to the words to use.</p>
        </div>
        <div class="tree-grid">
            <a class="branch-card hot" href="#record">
                <span class="bc-q">&ldquo;Do you have this record?&rdquo;</span>
                <span class="bc-go">Find &amp; confirm it &rarr;</span>
            </a>
            <a class="branch-card hot" href="#collection">
                <span class="bc-q">&ldquo;I want to sell my collection&rdquo;</span>
                <span class="bc-go">Get them in the door &rarr;</span>
            </a>
            <a class="branch-card calm" href="#quick">
                <span class="bc-q">Hours, parking, event, hiring?</span>
                <span class="bc-go">Quick answer &rarr;</span>
            </a>
            <a class="branch-card calm" href="#coworker">
                <span class="bc-q">&ldquo;Can I speak to someone?&rdquo;</span>
                <span class="bc-go">Reach a coworker &rarr;</span>
            </a>
        </div>
        <p class="tree-foot">However it ends, <a href="#visit">invite them into the store</a>. New to the phones? Start with <a href="#tone">how to sound</a> and <a href="#flow">the call, step by step</a>.</p>
    </nav>

    {{-- 1 --}}
    <section id="tone">
        <div class="sec-head"><span class="sec-num">1</span><h2>How to sound on the phone</h2></div>
        <p class="lede">Every call is the first impression of the store. Treat each one as a chance to connect and leave a lasting impression &mdash; not a task to get off the phone. The goal is warm, easygoing, and genuinely trying to help. Talk like a friendly neighbor who happens to know records.</p>

        <div class="script">
            <div class="label"><span class="chan call">Call</span> Standard greeting</div>
            <p class="say">Thanks for calling Nivessa Records, this is Fatteen. How can I help you?</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> Opening a text back</div>
            <p class="say">Hey, this is Fatteen at Nivessa Records &mdash; happy to help! What are you looking for?</p>
        </div>

        <h3>The tone in three words</h3>
        <div class="card">
            <p><strong>Friendly</strong> &mdash; smile while you talk; it carries through the phone. <strong>Approachable</strong> &mdash; no rushing, no annoyed sighs, let them finish. <strong>Helpful</strong> &mdash; your job on every call is to get them an answer or a next step, never a dead end.</p>
        </div>

        <h3>Words &amp; the way you say them</h3>
        <div class="word-swap">
            <div class="h no">Skip these</div>
            <div class="h yes">Say this instead</div>
            <div class="cell no"><s>Buddy</s>, bro, pal, boss, my friend, brother</div>
            <div class="cell yes">Their name if you have it, otherwise nothing &mdash; just talk to them</div>
            <div class="cell no">Yeah&hellip; what do you want?</div>
            <div class="cell yes">Sure, what can I help you find?</div>
            <div class="cell no">We don't have it. (and stop)</div>
            <div class="cell yes">We don't have that one here &mdash; but let me check our other store and find you an alternative.</div>
            <div class="cell no">Hold on. (silence)</div>
            <div class="cell yes">Looking this up for you &mdash; one quick moment.</div>
        </div>

        <div class="callout"><strong>Never call a customer &ldquo;buddy.&rdquo;</strong> It comes across as dismissive here. Use their name if they gave it, or just speak to them directly. Please, thank you, and no problem go a long way.</div>
    </section>

    {{-- 2 --}}
    <section id="triage">
        <div class="sec-head"><span class="sec-num">2</span><h2>Know which call you're on</h2></div>
        <p class="lede">The first thing to figure out on any call: is this a high-value call that deserves your full effort, or a quick question you should resolve in under a minute? Both matter &mdash; but they get handled differently.</p>

        <div class="triage">
            <div class="tcol hot">
                <span class="tag">Important &mdash; give it everything</span>
                <h4>Do you have this record?</h4>
                <p class="goal">A real sale on the line. Full flow &mdash; see Section 4.</p>
                <ul>
                    <li>Reply fast, but get it right</li>
                    <li>Check the ERP and confirm it's really there</li>
                    <li>Always end with a yes, a no, or an alternative</li>
                </ul>
                <h4 style="margin-top:14px">I want to sell my collection.</h4>
                <p class="goal">Could be a huge buy. Handle with real care &mdash; see Section 5.</p>
                <ul>
                    <li>Get them excited and get their info</li>
                    <li>Never quote a price on the phone</li>
                    <li>Route to the right person to look at it</li>
                </ul>
            </div>
            <div class="tcol calm">
                <span class="tag">Quick &mdash; under a minute</span>
                <h4>Fast questions</h4>
                <p class="goal">Answer, be warm, wrap it up &mdash; see Section 6.</p>
                <ul>
                    <li>Are you hiring?</li>
                    <li>What time is the event tonight?</li>
                    <li>Are you open? When do you close?</li>
                    <li>Where do I park?</li>
                </ul>
                <p style="font-size:13.5px;color:var(--d-ink-2);margin:12px 0 0">Quick doesn't mean cold. A warm 40-second call still leaves an impression.</p>
            </div>
        </div>
    </section>

    {{-- 3 --}}
    <section id="flow">
        <div class="sec-head"><span class="sec-num">3</span><h2>The call, start to finish</h2></div>
        <p class="lede">Run every call through these five steps. Most calls are someone looking for a record, someone wanting to sell, or a quick question.</p>
        <ol class="flow">
            <li><strong>Greet and give your name.</strong><span class="sub">Use the standard greeting. Let them know they reached a real person who's ready to help.</span></li>
            <li><strong>Find out what they actually need.</strong><span class="sub">A specific record? Selling a collection? Hours or parking? Repeat it back so they know you heard it: &ldquo;So you're after the Bowie <em>Low</em> reissue &mdash; got it.&rdquo;</span></li>
            <li><strong>Look before you promise.</strong><span class="sub">Don't guess. Record question &rarr; Section 4. Selling &rarr; Section 5. Quick question &rarr; Section 6.</span></li>
            <li><strong>Give a clear answer or a clear next step.</strong><span class="sub">Always land on something: yes we have it, no but here's an alternative, or I'll call you back within the hour.</span></li>
            <li><strong>Close warmly.</strong><span class="sub">&ldquo;Thanks for calling, have a good one.&rdquo; Make sure they got what they needed before you hang up.</span></li>
        </ol>
        <div class="callout">If you ever put someone on hold, <strong>check back every 30&ndash;45 seconds</strong> even if you're not done &mdash; &ldquo;Still looking, thanks for hanging on.&rdquo; A silent hold feels like being forgotten.</div>
    </section>

    {{-- 4 --}}
    <section id="record">
        <div class="sec-head"><span class="sec-num">4</span><h2>Important call: &ldquo;Do you have this record?&rdquo;</h2></div>
        <p class="lede">This is a sale waiting to happen, so reply quickly &mdash; but the system tells you what <em>should</em> be there, and only your eyes confirm what <em>is</em> there. Follow this in order.</p>

        <ol class="flow">
            <li><strong>Search the ERP.</strong><span class="sub">Look the title up by artist and title. See which store it shows at &mdash; Pico or Hollywood.</span></li>
            <li><strong>DM the employee at that store.</strong><span class="sub">Message whoever's on shift there on Slack and ask them to physically confirm the copy is on the shelf. Keep the caller on a short hold or offer a quick callback.</span></li>
            <li><strong>No answer? Call the employee.</strong><span class="sub">If they don't reply on Slack and the caller is waiting, call their cell. (Only ever reach people who are on shift &mdash; see Section 7.)</span></li>
            <li><strong>Not at one store? Check the other.</strong><span class="sub">If it's not at Pico, check Hollywood, and the other way around. Try both before you ever say we don't have it.</span></li>
            <li><strong>Fix the ERP if it's wrong.</strong><span class="sub">If the count didn't match the shelf &mdash; system said one copy, there were none, or there were extras &mdash; update the true inventory in the ERP so the next person isn't misled.</span></li>
            <li><strong>Give a real answer, every time.</strong><span class="sub">Land on a clear yes or no &mdash; and if it's no, offer an alternative (see below). Never leave them with just &ldquo;nope.&rdquo;</span></li>
        </ol>

        <h3>How the answer should land</h3>
        <div class="branch">
            <div class="row"><div class="cond">Confirmed on the shelf</div><div class="arrow">&rarr;</div><div class="act">&ldquo;Yes, we've got it &mdash; it's $28. Want me to set it aside for you?&rdquo;</div></div>
            <div class="row"><div class="cond">Not at this store</div><div class="arrow">&rarr;</div><div class="act">Check the other store; if it's there, offer to have them hold it.</div></div>
            <div class="row"><div class="cond">We don't have that title</div><div class="arrow">&rarr;</div><div class="act">Offer an alternative &mdash; a different format, pressing, or a similar record.</div></div>
            <div class="row"><div class="cond">ERP count was wrong</div><div class="arrow">&rarr;</div><div class="act">Update the real number in the ERP before moving on.</div></div>
        </div>

        <div class="callout"><strong>Always offer an alternative.</strong> If we don't have the One Direction CD but we do have it on vinyl &mdash; say so. No copy in stock? Offer to order it, take their number, or point them to something close. A &ldquo;no&rdquo; with an option beats a flat &ldquo;no.&rdquo;</div>

        <div class="script">
            <div class="label"><span class="chan call">Call</span> While you check</div>
            <p class="say">Let me look that up and have someone confirm it's actually on the shelf &mdash; one quick minute.</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> While you check</div>
            <p class="say">Great choice! Let me check the shelf and I'll text you right back to confirm.</p>
            <div class="label" style="margin-top:14px"><span class="chan call">Call</span> The &ldquo;no, but&hellip;&rdquo;</div>
            <p class="say">We don't have that one on CD right now &mdash; but we do have it on vinyl. Want me to hold that for you, or I can order the CD in?</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> The &ldquo;no, but&hellip;&rdquo;</div>
            <p class="say">No CD in stock right now, but we've got it on vinyl! Want me to hold it under your name, or order the CD in for you?</p>
        </div>
    </section>

    {{-- 5 --}}
    <section id="collection">
        <div class="sec-head"><span class="sec-num">5</span><h2>Important call: &ldquo;I want to sell my collection.&rdquo;</h2></div>
        <p class="lede">This can be a huge buy for the store, so it's one of the most important calls you'll take. Your job is to make them feel great about calling us and get it to the right person &mdash; not to price anything yourself.</p>

        <ol class="flow">
            <li><strong>Be genuinely excited.</strong><span class="sub">&ldquo;Oh nice &mdash; we'd love to take a look. Tell me a bit about what you've got.&rdquo; Enthusiasm here is what earns the visit.</span></li>
            <li><strong>Get the basics.</strong><span class="sub">Roughly how many pieces, what kind of music, vinyl or CDs, and what condition. You're getting a picture, not appraising.</span></li>
            <li><strong>Never quote a price on the phone.</strong><span class="sub">We have to see the collection in person. &ldquo;It really depends on what's in there &mdash; the best thing is to have someone take a look.&rdquo;</span></li>
            <li><strong>Get their name and number, and hand it off.</strong><span class="sub">Take their info and connect them with the right buyer, or set up a time to bring it in. Make sure someone actually follows up.</span></li>
        </ol>

        <div class="callout"><strong>Don't lose this call.</strong> A big collection is real money for the store. Even if the buyer isn't in, take full details and promise a callback &mdash; then make sure it happens.</div>

        <div class="script">
            <div class="label"><span class="chan call">Call</span> Opening</div>
            <p class="say">That's great &mdash; we're always interested in good collections. Roughly how many records are we talking, and what kind of stuff?</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> Opening</div>
            <p class="say">Love that &mdash; we'd be glad to take a look! Roughly how many records, and what kind of music?</p>
            <div class="label" style="margin-top:14px"><span class="chan call">Call</span> On price</div>
            <p class="say">I can't give you a number over the phone &mdash; it really depends on the titles and condition. Let's get someone to look at it. Can I grab your name and number?</p>
            <div class="label" style="margin-top:10px"><span class="chan text">Text</span> On price</div>
            <p class="say">Hard to price without seeing them &mdash; it comes down to the titles and condition. Best is to bring them by. Can I get your name and number to set it up?</p>
        </div>
    </section>

    {{-- 6 --}}
    <section id="quick">
        <div class="sec-head"><span class="sec-num">6</span><h2>Quick calls &mdash; under a minute</h2></div>
        <p class="lede">These have a simple, known answer. Give it warmly and wrap up &mdash; don't overthink them. Fast and friendly is the whole game here.</p>

        <div class="card">
            <ul class="quick-list">
                <li><span class="q">Are you hiring?</span><span class="a">Give the honest current answer; if unsure, point them to how we post openings rather than guessing.</span></li>
                <li><span class="q">What time is the event tonight?</span><span class="a">Give the start time straight from the events listing. Don't guess &mdash; check if you're not certain.</span></li>
                <li><span class="q">Are you open? When do you close?</span><span class="a">State today's hours plainly: &ldquo;Yep, we're open till 8 tonight.&rdquo;</span></li>
                <li><span class="q">Where do I park?</span><span class="a">Give the quick parking rundown for that store in a sentence.</span></li>
            </ul>
        </div>

        <div class="callout"><strong>Quick isn't the same as cold.</strong> A 40-second call still leaves an impression. Warm greeting, clear answer, friendly sign-off &mdash; then you're both moving on.</div>
    </section>

    {{-- 7 --}}
    <section id="visit">
        <div class="sec-head"><span class="sec-num">7</span><h2>Turn every call into a visit</h2></div>
        <p class="lede">The best outcome of almost any call is a customer walking through the door. On every call, look for a natural reason to invite them in &mdash; and give them a reason to come <em>today</em>. Getting people into the store is one of the most valuable things you can do on the phone.</p>

        <ol class="flow">
            <li><strong>Always extend the invite.</strong><span class="sub">Close warm and open: &ldquo;Come by and see us&rdquo; &mdash; not just &ldquo;okay, bye.&rdquo; Make them feel wanted in the shop.</span></li>
            <li><strong>Give them a reason to come now.</strong><span class="sub">Mention what's fresh &mdash; new arrivals, a record you can hold at the counter, or tonight's event. &ldquo;We just got a big batch in this week, worth a dig.&rdquo;</span></li>
            <li><strong>Make it easy to say yes.</strong><span class="sub">Offer to hold the record under their name, tell them today's hours, and where to park. Remove every little reason not to come.</span></li>
            <li><strong>Invite them to the event.</strong><span class="sub">If there's something on, personally invite them: &ldquo;We've got a listening party tonight at 7 &mdash; you should swing by.&rdquo;</span></li>
        </ol>

        <div class="branch">
            <div class="row"><div class="cond">Record's in stock</div><div class="arrow">&rarr;</div><div class="act">&ldquo;Want me to hold it at the counter so it's here when you come in?&rdquo;</div></div>
            <div class="row"><div class="cond">We don't have it</div><div class="arrow">&rarr;</div><div class="act">&ldquo;Come dig anyway &mdash; we get new stuff in constantly and I'll keep an eye out.&rdquo;</div></div>
            <div class="row"><div class="cond">Just a quick question</div><div class="arrow">&rarr;</div><div class="act">Answer it, then: &ldquo;Come say hi while you're around.&rdquo;</div></div>
        </div>

        <div class="callout"><strong>This one counts.</strong> Bringing customers into the store is a big deal &mdash; there's a reward being set up for it, so keep track of the people you get through the door. (Ask Sarah for the current details.)</div>
    </section>

    {{-- 8 --}}
    <section id="coworker">
        <div class="sec-head"><span class="sec-num">8</span><h2>Reaching a coworker</h2></div>
        <p class="lede">Whether you're confirming a record or a caller needs a specific person, the path is the same: Slack first, cell if no answer, and only ever reach someone who is actually on shift.</p>

        <ol class="flow">
            <li><strong>Check they're on shift.</strong><span class="sub">Only contact employees who are currently working. Don't message or call someone on their day off &mdash; find who's on shift instead.</span></li>
            <li><strong>DM them on Slack.</strong><span class="sub">Send a clear, short message: what you need and that a customer is waiting. Give them a moment to reply.</span></li>
            <li><strong>No answer on Slack? Call their cell.</strong><span class="sub">If they don't respond in a reasonable time and it's time-sensitive, call their cell directly. That's exactly what the escalation is for.</span></li>
            <li><strong>Still no answer? Take a message.</strong><span class="sub">Get the caller's name, number, and what they need. Set a callback time &mdash; and make sure it actually happens.</span></li>
        </ol>

        <div class="callout"><strong>Two hard rules:</strong> Slack before you call &mdash; and never call someone who isn't on shift. If Slack goes quiet and it matters, the cell phone is the right move.</div>
    </section>

    {{-- 9 --}}
    <section id="reference">
        <div class="sec-head"><span class="sec-num">9</span><h2>Quick reference</h2></div>
        <p class="lede">The whole guide in one glance. Read this before every shift until it's second nature.</p>
        <div class="dd">
            <div class="col do">
                <h4>Always do</h4>
                <ul>
                    <li>Answer with the store name and your name</li>
                    <li>Treat every call as a chance to connect</li>
                    <li>Know if it's an important call or a quick one</li>
                    <li>For records: check ERP, then confirm it's really on the shelf</li>
                    <li>If it's not at one store, check the other</li>
                    <li>Update the ERP when the count is wrong</li>
                    <li>Always end with a yes, a no, or an alternative</li>
                    <li>Get excited about collections; take full details</li>
                    <li>Invite every caller into the store &mdash; give them a reason to come today</li>
                    <li>Slack a coworker first; call their cell if no reply</li>
                </ul>
            </div>
            <div class="col dont">
                <h4>Never do</h4>
                <ul>
                    <li>Call a customer &ldquo;buddy,&rdquo; pal, or boss</li>
                    <li>Promise a record no one has physically seen</li>
                    <li>Say &ldquo;we don't have it&rdquo; before checking the other store</li>
                    <li>Give a flat &ldquo;no&rdquo; with no alternative</li>
                    <li>Quote a price on a collection over the phone</li>
                    <li>Leave someone on a silent hold</li>
                    <li>Contact a coworker who's off today</li>
                    <li>Drag out a simple hours-or-parking question</li>
                </ul>
            </div>
        </div>
    </section>

    <div class="pp-foot">Nivessa Records front-of-house phone guide &middot; Pico &amp; Hollywood &middot; Keep at the counter.</div>

</div>
@endsection
