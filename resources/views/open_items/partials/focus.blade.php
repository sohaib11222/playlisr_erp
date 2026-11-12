@php
    // Famous-musician posts (best performing social). Extend before 12/12/26.
    $socialCal = [
        '2026-11-14' => 'Aaron Copland (born 1900)', '2026-11-15' => 'Petula Clark (born 1932)',
        '2026-11-16' => 'W.C. Handy (born 1873)', '2026-11-17' => 'Gordon Lightfoot (born 1938)',
        '2026-11-20' => 'Duane Allman (born 1946) or Joe Walsh (born 1947)', '2026-11-21' => 'Björk (born 1965)',
        '2026-11-22' => 'Hoagy Carmichael (born 1899)', '2026-11-23' => 'Miley Cyrus (born 1992)',
        '2026-11-24' => 'Freddie Mercury (remembering him, died 1991)', '2026-11-25' => 'Percy Sledge (born 1940)',
        '2026-11-26' => 'Tina Turner (born 1939)', '2026-11-27' => 'Jimi Hendrix (born 1942)',
        '2026-11-28' => 'Randy Newman (born 1943)', '2026-11-29' => 'George Harrison (remembering him, died 2001)',
        '2026-11-30' => 'Billy Idol (born 1955)', '2026-12-01' => 'Bette Midler (born 1945)',
        '2026-12-02' => 'Britney Spears (born 1981)', '2026-12-03' => 'Ozzy Osbourne (born 1948)',
        '2026-12-04' => 'Jay-Z (born 1969)', '2026-12-05' => 'Little Richard (born 1932)',
        '2026-12-06' => 'Dave Brubeck (born 1920)', '2026-12-07' => 'Tom Waits (born 1949)',
        '2026-12-08' => 'John Lennon (remembering him, died 1980) or Jim Morrison (born 1943)',
        '2026-12-12' => 'Frank Sinatra (born 1915)',
    ];
    $tzNow = \Carbon\Carbon::now('America/Los_Angeles')->startOfDay();
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $d = $tzNow->copy()->addDays($i);
        $week[] = [$d->format('m/d/y'), $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : $d->format('D')), $socialCal[$d->format('Y-m-d')] ?? 'No birthday: post a rare find or new arrival'];
    }
    $jobs = [
        ['Turn seller leads into buys', 'Answer every seller the same day by text or email (use the seller prompt below) and stay on them until they book a time to bring the collection in. Collection buys are where the biggest margin is.'],
        ['Fix "can\'t find" orders before they become cancels', 'Get the cashier to recheck the bins the same day, offer a swap, and only refund as a last step.'],
        ['Own venue bookings and event partners end to end', 'Reply by email, quote, book and collect payment yourself instead of forwarding leads. Run every reply through Claude first.'],
        ['Post on social every day', 'One post a day on Instagram and TikTok, never several at once. Use the famous-musician plan below, plus rare finds, new arrivals (ask the cashier on shift for photos), big buys coming in, and every event before its day.'],
        ['Run listening party prep', 'Giveaway box, photo, staffing and playback link for every party. It shows up above when a step is due.'],
        ['Push preorders before every listening party', 'A week before each party, text everyone who RSVP\'d but hasn\'t preordered one short message with the preorder link. Again the day before.'],
        ['Win back abandoned carts', 'Every morning open Abandoned Carts and email or text anyone who left $40+ in the last 2 days: "Still want [record]? It\'s here and we can hold it or ship today."'],
        ['Turn record requests into special orders', 'When someone asks for a record we don\'t have, check AMS and Alliance. If it\'s in print, quote the price, take a deposit and order it. Reply within a day either way.'],
        ['Find sellers every week', 'Once a week post a "We buy records and CDs, cash on the spot, Pico and Hollywood" ad on Craigslist LA, Nextdoor and local Facebook groups.'],
        ['Get and answer Google reviews', 'Text the review link to every happy customer. Thank every review by name within a day, and answer bad ones with how we fixed it. Post one update a week on each store\'s Google profile.'],
        ['Categorize QuickBooks weekly', 'Use the bank rules and past categories. Only leave what is truly new.'],
        ['Draft the weekly reorder', 'Build the order from the Weekly Reorder page for Jon to approve.'],
    ];
    $jobLinks = [0 => '/website-inbox/messages', 2 => '/website-carts?tab=bookings', 6 => '/website-carts', 11 => '/reports/reorder'];
    $prompts = [
        ['Customer replies', 'A customer wrote this: [paste]. Write a short, warm reply from Nivessa Records that answers the question and says exactly what we\'ll do next. Proper grammar, no emojis.'],
        ['Seller leads', 'Someone wants to sell us this collection: [paste]. Write a warm reply asking for photos, a rough count and formats, and offering a time to bring it to our Pico or Hollywood store.'],
        ['Social captions', 'Write a 2-sentence Instagram caption for [Artist]\'s birthday. Mention one well-known album and that we have [album] on vinyl at Nivessa [store]. Add 4 hashtags.'],
        ['Event partners', 'This person wants to do an event at our Hollywood store: [paste]. Write a warm reply with our stage options, ask for their date and expected crowd, and suggest a call this week.'],
        ['Review replies', 'Here is a Google review: [paste]. Write a short, warm reply that thanks them by name. If it is negative, say what we fixed.'],
        ['When stuck', 'I need to [task]. Here is what I know: [details]. Give me the steps.'],
    ];
@endphp

<div class="oi-card" id="oi-bigger">
    <div class="oi-head"><h2>When the list above is clear: bigger jobs</h2></div>
    <p class="oi-how">Most important first. These are what bring in money and keep customers happy.</p>
    @foreach($jobs as $i => $j)
        <div class="oi-done"><strong>{{ $i + 1 }}. {{ $j[0] }}.</strong> {{ $j[1] }} @if(isset($jobLinks[$i]))<a href="{{ url($jobLinks[$i]) }}" style="color:var(--d-accent-text);font-weight:700">Open</a>@endif</div>
    @endforeach
</div>

<div class="oi-card" id="oi-social">
    <div class="oi-head"><h2>Social: one famous-musician post a day</h2></div>
    <p class="oi-how">These posts did best. The day before, find the artist in Products and ask the cashier at the store that has a copy for one clear photo of the record in the store. Caption: "Happy birthday to [Artist], born [date, year]. [One true line about them.] We have [album] on vinyl at Nivessa [store] right now." Post around 11am, one a day, never several at once. No copy in the store? Post a rare find instead.</p>
    @foreach($week as $w)
        <div class="oi-done"><strong>{{ $w[0] }} ({{ $w[1] }}):</strong> {{ $w[2] }}</div>
    @endforeach
</div>

<div class="oi-card" id="oi-claude">
    <div class="oi-head"><h2>Use Claude for every message</h2><a class="oi-btn" href="https://claude.ai" target="_blank">Open Claude</a></div>
    <p class="oi-how">Every message to a customer, seller or partner goes through Claude first so it sounds warm. Paste the real details, read what it writes, fix anything wrong, then send. Never paste card numbers, passwords or addresses.</p>
    @foreach($prompts as $p)
        <div class="oi-done"><strong>{{ $p[0] }}:</strong> "{{ $p[1] }}"</div>
    @endforeach
</div>
