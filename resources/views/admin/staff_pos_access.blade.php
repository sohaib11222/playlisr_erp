@extends('layouts.app')
@section('title', 'Staff POS Access')

@section('content')
<section class="content-header">
    <h1>Staff POS Access</h1>
    <p class="text-muted">
        Diagnoses why a staff member can't ring sales on /pos. Anyone with a
        red badge below is blocked from POS — fix the listed blocker(s) and
        they'll be able to ring again.
    </p>
    <form method="GET" action="{{ url('/admin/staff-pos-access') }}" style="margin-top:8px;">
        <input type="text" name="user" value="{{ $highlight }}" placeholder="Highlight (name, username, email)…" style="padding:4px 8px; width:280px;">
        <button type="submit" class="btn btn-default btn-sm">Highlight</button>
        @if($highlight !== '')
            <a href="{{ url('/admin/staff-pos-access') }}" class="btn btn-link btn-sm">clear</a>
        @endif
    </form>
</section>

<section class="content">
<div class="box box-solid">
    <div class="box-body">
        <p style="font-size:12px; color:#666;">
            Four things must be true for POS to work:
            <code>status=active</code>, <code>allow_login=1</code>,
            <code>user_type=user</code>, and the user has the
            <code>sell.create</code> permission (Admin role implicitly has all
            perms). After that, opening /pos/create will redirect to
            /cash-register/create if the user doesn't have an open register —
            that's expected, not a bug.
        </p>

        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th style="text-align:center;">status</th>
                    <th style="text-align:center;">allow_login</th>
                    <th style="text-align:center;">sell.create</th>
                    <th style="text-align:center;">Open register</th>
                    <th>POS-ready?</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr style="{{ $r->highlight ? 'background:#fff8d6;' : '' }}">
                        <td>
                            <strong>{{ $r->name ?: '—' }}</strong>
                            @if($r->is_admin)
                                <span class="label label-primary" style="margin-left:6px;">Admin</span>
                            @endif
                        </td>
                        <td><code>{{ $r->username }}</code></td>
                        <td>{{ $r->role }}</td>
                        <td style="text-align:center;">
                            <span class="label label-{{ $r->status === 'active' ? 'success' : 'danger' }}">{{ $r->status }}</span>
                        </td>
                        <td style="text-align:center;">
                            <span class="label label-{{ $r->allow_login ? 'success' : 'danger' }}">{{ $r->allow_login ? '1' : '0' }}</span>
                        </td>
                        <td style="text-align:center;">
                            @if($r->can_sell_create || $r->is_admin)
                                <span class="label label-success">yes</span>
                            @else
                                <span class="label label-danger">no</span>
                            @endif
                        </td>
                        <td style="text-align:center;">
                            @if($r->has_open_reg)
                                <span class="label label-success" title="Opened {{ $r->open_reg_at }} at {{ $r->open_reg_loc }}">{{ $r->open_reg_loc ?: 'open' }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if(empty($r->blockers))
                                <span class="label label-success">ready</span>
                                @if(!$r->has_open_reg)
                                    <span class="text-muted" style="font-size:11px; margin-left:4px;">(needs to open a register at login)</span>
                                @endif
                            @else
                                <span class="label label-danger">{{ implode(' · ', $r->blockers) }}</span>
                                <a href="{{ url('/users/' . $r->id . '/edit') }}" class="btn btn-xs btn-default" style="margin-left:6px;">Edit user</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:16px; padding:12px; background:#f4f4f4; border-radius:4px;">
            <h4 style="margin-top:0;">How to fix the common blockers</h4>
            <ul style="margin:0;">
                <li><strong>no sell.create</strong> — go to <a href="{{ url('/roles') }}">/roles</a>, open the user's role (column above), and tick the <em>Sell &gt; Add sale</em> permission. Save. The user must log out and back in.</li>
                <li><strong>allow_login=0</strong> — go to <a href="{{ url('/users') }}">/users</a>, edit the user, set <em>Allow login</em> to Yes. (This flag is how former staff are kept from logging in.)</li>
                <li><strong>status≠active</strong> — same edit page, set status to Active.</li>
                <li><strong>user_type≠user</strong> — this means the row is a customer, not a staff member. Don't edit; create a proper staff record under /users.</li>
            </ul>
        </div>

        <details style="margin-top:16px;">
            <summary style="cursor:pointer; color:#666; font-size:12px;">Roles defined in this business ({{ $roles->count() }})</summary>
            <ul style="margin-top:8px; font-size:12px; color:#666;">
                @foreach ($roles as $role)
                    <li><code>{{ preg_replace('/#\d+$/', '', $role->name) }}</code></li>
                @endforeach
            </ul>
        </details>
    </div>
</div>
</section>
@endsection
