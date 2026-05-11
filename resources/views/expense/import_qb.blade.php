@extends('layouts.app')
@section('title', 'Import QB Expenses')

@section('content')
<section class="content-header">
    <h1>Import Expenses from QuickBooks <small>Transaction List by Date</small></h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-8">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Upload QB export'])
                <form method="POST" action="{{ action('ImportQbExpenseController@store') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="form-group">
                        <label>QB file (CSV or XLSX)</label>
                        <input type="file" name="qb_file" class="form-control" accept=".csv,.xlsx,.xls,.txt" required>
                        <p class="help-block">
                            Export from QuickBooks: <em>Reports → Transaction List by Date → Export</em>.
                            Expected columns: Date, Transaction type, Num, Posting, Name, Memo, Account name, Split, Amount.
                            Negative amounts become Expenses, positive amounts become Expense Refunds.
                        </p>
                    </div>

                    <div class="form-group">
                        <label>Default location</label>
                        <select name="default_location_id" class="form-control" required>
                            @foreach($business_locations as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <p class="help-block">
                            QB doesn't track Nivessa store locations, so every imported row is assigned to this default.
                        </p>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-upload"></i> Import
                        </button>
                        <a href="{{ action('ExpenseController@index') }}" class="btn btn-default">Cancel</a>
                    </div>

                    <p class="help-block" style="margin-top:20px;">
                        <strong>Note:</strong> The import is reversible. If it goes wrong,
                        visit <a href="/admin/admin-action-history">/admin/admin-action-history</a>
                        and click Undo on the latest <code>qb-expense-import</code> snapshot.
                    </p>
                </form>
            @endcomponent
        </div>
    </div>
</section>
@stop
