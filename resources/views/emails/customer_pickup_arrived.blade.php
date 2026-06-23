@php
    $firstName = trim((string) ($contact->first_name ?? ''));
    $greeting = $firstName !== '' ? ('Hey ' . $firstName . ',') : 'Hey,';
@endphp
<p>{{ $greeting }}</p>

<p>Good news — the order you placed with us just arrived at <strong>{{ $storeName }}</strong>@if(!empty($label)):@endif</p>

@if(!empty($label))
<p style="padding:12px 16px; background:#FFF9DB; border-left:4px solid #E8CF68; font-size:15px;">
    <strong>{{ $label }}</strong>
</p>
@endif

<p>It's ready for pickup — we'll hold it behind the counter for you. Stop by whenever works.</p>

<p>Thanks,<br>
The Nivessa crew</p>

<p style="color:#8E8273; font-size:11px;">
    You're getting this because you placed a special order with us. If you have any questions, just reply to this email.
</p>
