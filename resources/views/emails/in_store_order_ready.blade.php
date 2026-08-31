@php
    $firstName = trim(explode(' ', trim($order->customer_name))[0] ?? '');
    $greeting = $firstName !== '' ? ('Hey ' . $firstName . ',') : 'Hey,';
@endphp
<p>{{ $greeting }}</p>

<p>Good news — your order is ready at <strong>{{ $storeName }}</strong>:</p>

<p style="padding:12px 16px; background:#FFF9DB; border-left:4px solid #E8CF68; font-size:15px;">
    <strong>{{ $order->item_name }}</strong>
</p>

<p>It's ready for pickup — we'll hold it behind the counter for you. Stop by whenever works.</p>

<p>Thanks,<br>
The Nivessa crew</p>

<p style="color:#8E8273; font-size:11px;">
    You're getting this because you placed an in-store order with us. If you have any questions, just reply to this email.
</p>
