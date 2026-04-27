@extends('layouts.app')
@section('title', 'Remainder Costs')

@section('content')
<section class="content-header">
    <h1>Remainder Costs</h1>
    <p class="text-muted">
        Categories with products still at $0 cost (after the cost-price-rules pass).
        Type a cost in the box for any category, leave blank to skip, then Apply.
        Only touches variations currently at $0 — never overwrites real costs.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif

<div class="row">
    <div class="col-md-8">
        @if (count($rows) === 0)
            <div class="alert alert-success">
                ✅ Every category's products have a non-zero cost. Nothing left to fill.
            </div>
        @else
        <div class="box box-solid">
            <div class="box-body">
                <form method="POST" action="{{ url('/admin/remainder-costs/run') }}"
                      onsubmit="return confirm('Apply costs to filled-in categories? Snapshot will be saved for undo.');">
                    @csrf
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th style="text-align:right;">Products at $0</th>
                                <th>Cost to apply (leave blank to skip)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td>{{ $r->name }}</td>
                                    <td style="text-align:right;"><strong>{{ number_format($r->zero_count) }}</strong></td>
                                    <td>
                                        <div class="input-group" style="max-width:200px;">
                                            <span class="input-group-addon">$</span>
                                            <input type="text" name="cost[{{ $r->id }}]"
                                                   class="form-control" placeholder="0.00"
                                                   pattern="[0-9]*\.?[0-9]+">
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div style="margin-top:16px;">
                        <button type="submit" class="btn btn-primary btn-lg">Apply costs to filled-in categories</button>
                        <span class="text-muted" style="margin-left:8px;">
                            (Categories with empty cost field are skipped.)
                        </span>
                    </div>
                </form>
            </div>
        </div>
        @endif

        @if ($uncategorized > 0)
            <div class="alert alert-warning" style="margin-top:16px;">
                <strong>{{ number_format($uncategorized) }} uncategorized variations</strong> also have $0 cost
                — these have no category set on the product, so a per-category rule
                can't fix them. Use the Bulk Update Categories tool on /products to
                assign categories first, then come back here.
            </div>
        @endif
    </div>
</div>

</section>
@endsection
