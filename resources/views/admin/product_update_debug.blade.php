@extends('layouts.app')
@section('title', 'Product Update Debug')

@section('content')
<script>document.body.classList.add('role-picker');</script>

<style>
body.role-picker { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; }
body.role-picker .content-wrapper { background: #FAF6EE !important; }
body.role-picker .content-header { padding: 28px 16px 8px; }
body.role-picker .content-header h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
body.role-picker .content-header p { color: #5A5045; margin: 0; font-size: 14px; max-width: 760px; }
body.role-picker .pdg-wrap { max-width: 1200px; padding: 0 16px 60px; }
body.role-picker .pdg-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker .pdg-card h3 { margin: 0 0 10px; font-size: 15px; font-weight: 700; }
body.role-picker .pdg-pill { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
body.role-picker .pdg-pill.ok { background:#D9F0D3; color:#2F6B3E; }
body.role-picker .pdg-pill.bad { background:#F8D7DA; color:#8A3A2E; }
body.role-picker .pdg-meta { color:#5A5045; font-size:12px; margin-bottom:6px; }
body.role-picker .pdg-exc { background:#FFF6E6; border-left:4px solid #C68E17; padding:10px 14px; border-radius:6px; font-family: ui-monospace, SFMono-Regular, monospace; font-size:13px; color:#5A4410; white-space:pre-wrap; word-break:break-word; margin:10px 0; }
body.role-picker table.pdg-kv { width:100%; border-collapse: collapse; font-size:13px; }
body.role-picker table.pdg-kv td { padding:4px 8px; border-bottom:1px solid #F0E9D7; vertical-align: top; }
body.role-picker table.pdg-kv td:first-child { color:#8E8273; width:200px; }
body.role-picker code { background:#F7F1E3; padding:2px 6px; border-radius:4px; font-size:12px; }
</style>

<section class="content-header">
    <h1>Product Update Debug</h1>
    <p>The 50 most recent saves on <code>/products/{id}/edit</code>. Shows whether the save succeeded, what cost fields were submitted, and any exception that rolled the transaction back. Newest first.</p>
</section>

<section class="content">
    <div class="pdg-wrap">
        @if(empty($entries))
            <div class="pdg-card">No attempts captured yet. Have someone try saving a product, then refresh.</div>
        @endif

        @foreach($entries as $e)
            @php $ok = ($e['result'] ?? '') === 'success'; @endphp
            <div class="pdg-card">
                <div class="pdg-meta">
                    <span class="pdg-pill {{ $ok ? 'ok' : 'bad' }}">{{ $ok ? 'saved' : ($e['result'] ?? '?') }}</span>
                    &nbsp;{{ $e['timestamp'] ?? '?' }}
                    · product #<strong>{{ $e['product_id'] ?? '?' }}</strong>
                    · user {{ $e['user_name'] ?? '?' }} (#{{ $e['user_id'] ?? '?' }})
                    · submit_type: <code>{{ $e['submit_type'] ?? '—' }}</code>
                </div>

                @if(!empty($e['exception']))
                    <div class="pdg-exc">{{ $e['exception']['class'] ?? '?' }}: {{ $e['exception']['message'] ?? '' }}
@ {{ $e['exception']['file'] ?? '?' }}:{{ $e['exception']['line'] ?? '?' }}</div>
                @endif

                <table class="pdg-kv">
                    @foreach(($e['cost_fields'] ?? []) as $k => $v)
                        <tr><td>cost.{{ $k }}</td><td><code>{{ $v === null ? 'null' : $v }}</code></td></tr>
                    @endforeach
                    @if(!empty($e['variation_after']))
                        @foreach($e['variation_after'] as $k => $v)
                            <tr><td>after.{{ $k }}</td><td><code>{{ $v === null ? 'null' : $v }}</code></td></tr>
                        @endforeach
                    @endif
                    @foreach(($e['identity_fields'] ?? []) as $k => $v)
                        <tr><td>id.{{ $k }}</td><td><code>{{ $v === null ? 'null' : $v }}</code></td></tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>
</section>
@endsection
