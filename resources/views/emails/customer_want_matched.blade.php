@php
    $artist = trim((string) ($want->artist ?? ''));
    $title = trim((string) ($want->title ?? ''));
    $label = trim(implode(', ', array_filter([$artist, $title])));
    if (!empty($want->format)) $label .= ' (' . $want->format . ')';
    $firstName = trim((string) ($contact->first_name ?? ''));
    $greeting = $firstName !== '' ? ('Hi ' . $firstName . ',') : 'Hi,';
@endphp
<p>{{ $greeting }}</p>

<p>Good news! The record you asked us to look out for just came in at <strong>{{ $store['name'] }}</strong>:</p>

<p style="padding:12px 16px; background:#FFF9DB; border-left:4px solid #E8CF68; font-size:15px;">
    <strong>{{ $label }}</strong>
</p>

@if (!empty($link))
<p>If you want it, please place a pickup order here: <a href="{{ $link }}">{{ $link }}</a></p>

<p>Pickup orders are pulled in 1 to 2 days. If you want it sooner, just come by!</p>
@else
<p>If you want it, come by soon!</p>
@endif

<p>{{ $store['name'] }}<br>{{ $store['address'] }}</p>

<p>Thanks,<br>
The Nivessa crew</p>

<p style="color:#8E8273; font-size:11px;">
    You're getting this because you asked us to keep an eye out for this record. If you'd like us to stop, just reply and let us know.
</p>
