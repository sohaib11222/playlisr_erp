@extends('layouts.app')
@section('title', 'Web Order Customers')

@section('content')
<section class="content-header">
    <h1>Web Order Customers</h1>
    <p>
        Old website sales were booked on throwaway "Website Customer" contacts. This moves each one onto the
        buyer's real customer account so you can see their order history and give them store credit.
        New web orders already go to the buyer's own account.
    </p>
    <p><strong>{{ $remaining }}</strong> throwaway contacts left. Showing up to 300 at a time.</p>
</section>

<section class="content">
    @if(session('status'))
        @php $st = session('status'); @endphp
        <div class="alert alert-{{ ($st['type'] ?? '') === 'success' ? 'success' : 'danger' }}">{{ $st['msg'] ?? '' }}</div>
    @endif
    @if($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    @if(count($rows))
    <form method="POST" action="{{ url('/admin/web-order-contacts/apply') }}" style="margin-bottom:12px;">
        @csrf
        <button type="submit" class="btn btn-primary">Move these {{ collect($rows)->where('action', '!=', 'skip')->count() }} to the real customers</button>
        <span style="margin-left:8px;">A backup of every contact is saved first. Merged throwaway contacts are archived, not deleted.</span>
    </form>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Contact #</th>
                        <th>Web order</th>
                        <th style="text-align:right;">Sales</th>
                        <th>Buyer</th>
                        <th>Email</th>
                        <th>What happens</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td>{{ $r->fake_id }}</td>
                        <td>{{ $r->order_number ? '#' . $r->order_number : ($r->order_id ?: '-') }}</td>
                        <td style="text-align:right;">{{ $r->sales }}</td>
                        <td>{{ trim($r->first_name . ' ' . $r->last_name) }}</td>
                        <td>{{ $r->email ?: '-' }}</td>
                        <td>
                            @if($r->action === 'merge')
                                Move sales to existing customer {{ $r->real_name }} (#{{ $r->real_id }})
                            @elseif($r->action === 'rename')
                                Becomes {{ trim($r->first_name . ' ' . $r->last_name) }}'s account
                            @else
                                Skip (website has no buyer email)
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No throwaway web-order contacts left.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
