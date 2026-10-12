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

@php
    // Oct 2026 hours. Index 0 = Sunday. Shown as today's hours only (Sarah 10/12).
    $ppHours = [
        'hollywood' => ['9am–11pm', '10am–11pm', '10am–11pm', '10am–11pm', '10am–11pm', '10am–1am', '10am–1am'],
        'pico'      => ['10am–8pm', '11am–7pm', '11am–7pm', '11am–6pm', '11am–8pm', '11am–8pm', '10am–8pm'],
    ];
    $ppNow = \Carbon\Carbon::now('America/Los_Angeles');
    $ppDow = $ppNow->dayOfWeek;
    $ppHw = $ppHours['hollywood'][$ppDow];
    $ppPico = $ppHours['pico'][$ppDow];
    $ppHwClose = explode('–', $ppHw)[1];
    $ppPicoClose = explode('–', $ppPico)[1];
    $ppDay = $ppNow->format('l');
@endphp
<div class="pp-shell">

    <header class="pp-header">
        <p class="pp-eyebrow">Nivessa Records</p>
        <h1>Answering Phones &amp; Texts</h1>
    </header>

    {{-- Rewritten 10/12 for new staff (Insha): one job, one card per question, Oct hours. --}}
    <section id="job">
        <div class="sec-head"><span class="sec-num">1</span><h2>Your job</h2></div>
        <ol class="flow">
            <li><strong>Reply fast and warm.</strong><span class="sub">Calls: &ldquo;Thanks for calling Nivessa Records! How can I help you?&rdquo; Texts: &ldquo;Hi! Thanks for reaching out to Nivessa Records. What are you looking for?&rdquo;</span></li>
            <li><strong>Please provide a direct answer the same day.</strong><span class="sub">If you don't know it, say you're checking in with the staff at the store and then come back with the answer. Please do not leave a customer without a reply.</span></li>
            <li><strong>Invite them in.</strong><span class="sub">End with a reason to visit or buy: &ldquo;Want us to hold it for you?&rdquo; or &ldquo;See you soon!&rdquo;</span></li>
        </ol>
    </section>

    <section id="questions">
        <div class="sec-head"><span class="sec-num">2</span><h2>What do they want?</h2></div>
        <p class="lede">Find the question, send the reply (change the parts in [brackets]), then do what the note says.</p>

        <div class="card" id="record" style="margin-top:14px">
            <h3 style="margin-top:0">&ldquo;Do you have this record?&rdquo; <span class="prio high">Most important</span></h3>
            <div class="qa">
                <p class="q">First reply</p>
                <span class="say">Looking this up for you!</span>
                <p class="note">First check the ERP, then have the person on shift confirm it's on the shelf, at both stores. If one store doesn't have it, the other one might. If it's only in the warehouse, only Nick can confirm it.</p>
            </div>
            <div class="qa">
                <p class="q">We have it</p>
                <span class="say">Yes, we have [title] at [store] for $[price]! Would you like us to hold it under your name?</span>
                <p class="note">If yes, tell the front desk person to put it in the hold bin, and add a pickup request in the ERP: <a href="/customer-pickups/create" target="_blank" rel="noopener">add pickup request</a>.</p>
            </div>
            <div class="qa">
                <p class="q">Only in the warehouse</p>
                <span class="say">Our shipping guy is out for the day and needs to check the warehouse for it. He'll be in tomorrow morning and we'll let you know!</span>
                <p class="note">Message Nick so he checks first thing, then text the customer his answer.</p>
            </div>
            <div class="qa">
                <p class="q">We don't have it</p>
                <span class="say">We don't have that one right now, but we have [other format / other title by the artist]. Want me to check if we can order it for you?</span>
                <p class="note">Check every format and the other store before saying no. Never a flat &ldquo;no.&rdquo;</p>
            </div>
        </div>
        <div class="card" id="buying" style="margin-top:14px">
            <h3 style="margin-top:0">&ldquo;Do you buy records?&rdquo; / selling a collection <span class="prio high">Most important</span></h3>
            <div class="qa">
                <p class="q">Reply</p>
                <span class="say">Yes we do! Come by either store anytime and we'll give you a quote. Hollywood is at 6434 Hollywood Blvd, Los Angeles, CA 90028, open until {{ $ppHwClose }} today, and Pico is at 5770 W Pico Blvd, Los Angeles, CA 90019, open until {{ $ppPicoClose }} today.</span>
            </div>
            <div class="qa">
                <p class="q">Big collection (10+ boxes)</p>
                <span class="say">That sounds great! Can you send a few photos and tell me roughly how many and what type of music? I will have someone from the store reach out to you.</span>
                <p class="note">Get photos, a rough count and their name, then alert Jon the same day to see if he's interested. Never quote a price yourself.</p>
            </div>
        </div>
        <div class="card" id="order" style="margin-top:14px">
            <h3 style="margin-top:0">&ldquo;Where's my order?&rdquo; <span class="prio high">Most important</span></h3>
            <div class="qa">
                <p class="q">Reply</p>
                <span class="say">What's your name or order number? I'll check on it right now.</span>
                <p class="note">Look it up in the ERP Orders page or ask Nick, then text them the real status the same day.</p>
            </div>
            <div class="qa">
                <p class="q">&ldquo;Do you ship?&rdquo;</p>
                <span class="say">Yes, we ship worldwide! You can order at <a href='https://nivessa.com' target='_blank' rel='noopener'>nivessa.com</a>.</span>
            </div>
        </div>
        <div class="card" id="upset" style="margin-top:14px">
            <h3 style="margin-top:0">Upset customer <span class="prio high">Most important</span></h3>
            <div class="qa">
                <p class="q">Reply right away</p>
                <span class="say">So sorry about the hassle, I'm here to help. Can you tell me what happened?</span>
                <p class="note">Don't argue or make excuses. If you can't fix it, message Sarah the same day.</p>
            </div>
        </div>
        <div class="card" id="hours" style="margin-top:14px">
            <h3 style="margin-top:0">Hours &amp; locations <span class="prio low">Quick</span></h3>
            <div class="facts">
                <div class="frow"><span class="store">Today ({{ $ppDay }})</span><span class="val">Hollywood {{ $ppHw }} &middot; Pico {{ $ppPico }}</span></div>
                <div class="frow"><span class="store">Addresses</span><span class="val">Hollywood: 6434 Hollywood Blvd, Los Angeles, CA 90028 &middot; Pico: 5770 W Pico Blvd, Los Angeles, CA 90019</span></div>
            </div>
            <div class="qa">
                <p class="q">&ldquo;Are you open today?&rdquo;</p>
                <span class="say">Yes, we're open until {{ $ppHwClose }} today in Hollywood and {{ $ppPicoClose }} at Pico. Come by!</span>
            </div>
            <div class="qa">
                <p class="q">&ldquo;Where are you located?&rdquo;</p>
                <span class="say">Hollywood is at 6434 Hollywood Blvd, Los Angeles, CA 90028 and Pico is at 5770 W Pico Blvd, Los Angeles, CA 90019. See you soon!</span>
            </div>
        </div>
        <div class="card" id="events" style="margin-top:14px">
            <h3 style="margin-top:0">Events, performing, renting the space <span class="prio low">Quick</span></h3>
            <div class="qa">
                <p class="q">Reply</p>
                <span class="say">We'd love to host your event! You can book a time at <a href='https://nivessa.com/venues' target='_blank' rel='noopener'>nivessa.com/venues</a>.</span>
            </div>
        </div>
        <div class="card" id="lost" style="margin-top:14px">
            <h3 style="margin-top:0">Left something at the store <span class="prio low">Quick</span></h3>
            <div class="qa">
                <p class="q">Reply</p>
                <span class="say">Sure, I'll ask someone at the store to look for it! What is it and about where did you leave it?</span>
                <p class="note">Call the person on shift, then text the customer back either way.</p>
            </div>
        </div>
        <div class="card" id="hiring" style="margin-top:14px">
            <h3 style="margin-top:0">&ldquo;Are you hiring?&rdquo; <span class="prio low">Quick</span></h3>
            <div class="qa">
                <p class="q">Reply</p>
                <span class="say"><a href='https://nivessa.com/careers' target='_blank' rel='noopener'>nivessa.com/careers</a></span>
                <p class="note">Most of these already get an automatic text.</p>
            </div>
        </div>
        <div class="card" id="brands" style="margin-top:14px">
            <h3 style="margin-top:0">Big brand or label wants to partner <span class="prio low">Quick</span></h3>
            <div class="qa">
                <p class="q">Reply</p>
                <span class="say">Sounds exciting! I'll pass this to Sarah, who handles our partnerships. Can you send your name and company to sarah@nivessa.com?</span>
            </div>
        </div>
    </section>

    <section id="store">
        <div class="sec-head"><span class="sec-num">3</span><h2>When you need the store</h2></div>
        <ol class="flow">
            <li><strong>Tell the customer you're checking.</strong><span class="sub">&ldquo;Checking with the store for you now, I'll text you back within the hour.&rdquo;</span></li>
            <li><strong>Call the person on shift at that store.</strong><span class="sub">Find who's working in Sling and call their cell. Staff are busy and often don't see Slack, so call. Only call people who are on shift.</span></li>
            <li><strong>Save up questions.</strong><span class="sub">If you have a few, make one call an hour with the whole list so the store isn't interrupted all day.</span></li>
            <li><strong>Text the customer the answer the same day.</strong></li>
        </ol>
    </section>

    <section id="words">
        <div class="sec-head"><span class="sec-num">4</span><h2>Words to use</h2></div>
        <div class="word-swap">
            <div class="h yes">Say</div>
            <div class="h no">Don't say</div>
            <div class="cell yes">Happy to help! What are you looking for? I'll check for you.</div>
            <div class="cell no">Please email us at hello@nivessa.com</div>
            <div class="cell yes">Come by anytime and we'll give you a quote on the spot!</div>
            <div class="cell no">Please bring it to either store and we will provide you with a quote.</div>
            <div class="cell yes">So sorry, that one sold out. Can I find something similar for you?</div>
            <div class="cell no">We issued you a full refund. (and nothing else)</div>
            <div class="cell yes">Hi, sorry we missed you! How can we help?</div>
            <div class="cell no">Please let us know what is it about?</div>
            <div class="cell yes">Awesome, see you soon!</div>
            <div class="cell no">Great, most welcome.</div>
            <div class="cell yes">Their name, or nothing</div>
            <div class="cell no">Buddy, bro, boss, brother, &ldquo;email ID&rdquo;</div>
        </div>
    </section>

    <div class="pp-foot">Nivessa Records &middot; Hollywood &amp; Pico &middot; <a href="https://nivessa.com" target="_blank" rel="noopener">nivessa.com</a></div>
</div>
@endsection
