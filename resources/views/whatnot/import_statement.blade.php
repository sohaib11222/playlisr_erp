@extends('layouts.app')
@section('title', 'Import Whatnot Statement')

@section('content')
<section class="content-header">
    <h1>Import Whatnot Monthly Statement</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-8">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Type the 5 numbers from the Whatnot financial statement PDF'])
                <form method="POST" action="{{ action('ImportWhatnotStatementController@store') }}">
                    @csrf
                    <p class="help-block">
                        Get the statement from <a href="https://whatnot.com/seller/finances" target="_blank">Whatnot Seller Hub → Finances → Statements</a>.
                        Open the PDF, copy these numbers in.
                    </p>

                    <div class="form-group">
                        <label>Statement month</label>
                        <input type="month" class="form-control" name="statement_month"
                               value="{{ now()->subMonth()->format('Y-m') }}" required>
                        <p class="help-block">Pick the period the statement covers (e.g. April 2026 → 2026-04).</p>
                    </div>

                    <div class="form-group">
                        <label>Location</label>
                        <select class="form-control" name="location_id" required>
                            @foreach($business_locations as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <p class="help-block">Whatnot doesn't have locations; pick any default for ERP-side reporting.</p>
                    </div>

                    <hr>
                    <h4 style="margin-top:0;">Earnings</h4>
                    <div class="form-group">
                        <label>Sales</label>
                        <div class="input-group">
                            <span class="input-group-addon">$</span>
                            <input type="number" step="0.01" min="0" class="form-control" name="sales" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Tips (optional)</label>
                        <div class="input-group">
                            <span class="input-group-addon">$</span>
                            <input type="number" step="0.01" min="0" class="form-control" name="tips" value="0">
                        </div>
                    </div>

                    <hr>
                    <h4 style="margin-top:0;">Fees & costs</h4>
                    <div class="form-group">
                        <label>Commission fees</label>
                        <div class="input-group">
                            <span class="input-group-addon">$</span>
                            <input type="number" step="0.01" min="0" class="form-control" name="commission_fees" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Payment processing fees</label>
                        <div class="input-group">
                            <span class="input-group-addon">$</span>
                            <input type="number" step="0.01" min="0" class="form-control" name="payment_processing_fees" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Seller paid shipping & handling</label>
                        <div class="input-group">
                            <span class="input-group-addon">$</span>
                            <input type="number" step="0.01" min="0" class="form-control" name="shipping_fees" value="0">
                        </div>
                    </div>

                    <hr>
                    <div class="form-group">
                        <label>Statement number (optional)</label>
                        <input type="text" class="form-control" name="statement_number" maxlength="50" placeholder="e.g. 1588755">
                        <p class="help-block">From the top of the Whatnot PDF — helps Sabina match against the original document.</p>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-upload"></i> Import statement
                        </button>
                        <a href="{{ url('/reports/whatnot') }}" class="btn btn-default">Cancel</a>
                    </div>

                    <p class="help-block" style="margin-top:14px;">
                        <strong>What this does:</strong> creates one sell transaction
                        (revenue, flagged <code>is_whatnot=1</code>) so the Whatnot report sums it up,
                        plus one expense row per fee type under a "Whatnot Fees" category so the expense report categorizes them cleanly.
                        Each statement is imported once — re-importing the same month fails with a clear message;
                        undo at <a href="/admin/admin-action-history">/admin/admin-action-history</a> if you need to redo.
                    </p>
                </form>
            @endcomponent
        </div>
    </div>
</section>
@endsection
