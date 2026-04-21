@php
    $artist = trim((string) ($want->artist ?? ''));
    $title = trim((string) ($want->title ?? ''));
    $label = trim(implode(' — ', array_filter([$artist, $title])));
    if (!empty($want->format)) $label .= ' (' . $want->format . ')';
    $storeName = optional($want->location)->name ?: 'Nivessa';
    $firstName = trim((string) ($contact->first_name ?? ''));
    $greeting = $firstName !== '' ? ('Hey ' . $firstName . ',') : 'Hey,';
@endphp
<p>{{ $greeting }}</p>

<p>We just got your wanted item in at <strong>{{ $storeName }}</strong>:</p>

<p style="padding:12px 16px; background:#FFF9DB; border-left:4px solid #E8CF68; font-size:15px;">
    <strong>{{ $label }}</strong>
</p>

<p>Drop by when you can — we'll hold it behind the counter. If someone else beats you to it, ask a staff member and we can keep an eye out for the next one.</p>

<p>Thanks,<br>
The Nivessa crew</p>

<p style="color:#8E8273; font-size:11px;">
    You're getting this because you asked us to keep an eye out for this record. If you'd like us to stop, just reply and let us know.
</p>
