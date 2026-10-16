{{-- Venue booking requests nobody paid for (Sarah, 2026-10-15: "just do
     abandoned cart"). The website saves the booking before payment, so a
     closed PayPal/Stripe page leaves an unpaid request behind. --}}
<p class="wt-note">Venue booking requests where the customer filled in the form but never finished paying. They are not bookings and never show on Venue Bookings.</p>

<div class="wt-grid" style="margin:14px 0;">
  <div class="wt-stat"><div class="l">Requests</div><div class="v">{{ count($bookingRows) }}</div></div>
  <div class="wt-stat"><div class="l">Unpaid value</div><div class="v">${{ number_format(array_sum(array_column($bookingRows, 'total')), 2) }}</div></div>
</div>

@if($error)
  <div class="alert alert-danger">{{ $error }}</div>
@elseif(!count($bookingRows))
  <p class="wt-note">No unpaid booking requests.</p>
@else
  <div style="overflow-x:auto;">
    <table class="wt-tbl">
      <thead><tr><th>Booking</th><th>Customer</th><th>Venue</th><th>Event date</th><th>Amount</th><th>Started</th><th>Payment</th></tr></thead>
      <tbody>
        @foreach($bookingRows as $r)
          <tr>
            <td><strong>#{{ $r['bookingNumber'] }}</strong><div class="wt-note">{{ $r['title'] }}</div></td>
            <td><strong>{{ $r['customerName'] }}</strong><div>{{ $r['customerEmail'] }}</div> @if($r['customerPhone'] !== '')<div>{{ $r['customerPhone'] }}</div> @endif</td>
            <td>{{ $r['venueName'] }}</td>
            <td>{{ $r['dateLabel'] }}<div class="wt-note">{{ $r['timeLabel'] }}</div></td>
            <td><strong>${{ number_format($r['total'], 2) }}</strong></td>
            <td>{{ $r['createdAt'] }}</td>
            <td>Not paid @if($r['paymentMethod'] !== '') ({{ ucfirst($r['paymentMethod']) }}) @endif</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
