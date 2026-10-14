@extends('layouts.app')
@section('title', $title)

@section('content')
<section class="content-header">
  <h1>{{ $title }}</h1>
</section>
<section class="content">
  <div class="box box-solid"><div class="box-body">
    @if (count($results))
      <p>{{ count(array_filter(array_column($results, 'sent'))) }} sent, {{ count(array_filter($results, function ($r) { return !$r['sent']; })) }} not sent. The PINs are only in each person's Slack DM.</p>
      <table class="table table-condensed" style="max-width:640px;">
        <thead><tr><th>Name</th><th>Slack DM</th></tr></thead>
        <tbody>
        @foreach ($results as $r)
          <tr>
            <td>{{ $r['name'] }}</td>
            <td>
              @if ($r['sent'])
                <span class="label label-success">Sent</span>
              @else
                <span class="label label-danger">Not sent</span> <span class="text-muted">{{ $r['why'] }}</span>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @else
      <p>Everyone on the clock list already has a PIN.</p>
    @endif
    <a href="{{ url('/time-clock/settings') }}" class="btn btn-default btn-sm">Back to Time clock settings</a>
  </div></div>
</section>
@endsection
