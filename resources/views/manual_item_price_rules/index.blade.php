@extends('layouts.app')
@section('title', 'POS Manual Item Price Rules')

@section('content')
<section class="content-header">
    <h1>POS Manual Item Price Rules</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
        </div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Add Rule</h3>
        </div>
        <div class="box-body">
            <form method="POST" action="{{ route('manual-item-price-rules.store') }}">
                @csrf
                <div class="row">
                    <div class="col-md-3">
                        <label>Label</label>
                        <input type="text" name="label" class="form-control" placeholder="Coke" required>
                    </div>
                    <div class="col-md-5">
                        <label>Keywords (comma-separated)</label>
                        <input type="text" name="keywords" class="form-control" placeholder="coke, coca cola" required>
                    </div>
                    <div class="col-md-2">
                        <label>Price</label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="col-md-1">
                        <label>Order</label>
                        <input type="number" name="sort_order" class="form-control" min="0" value="0">
                    </div>
                    <div class="col-md-1">
                        <label>Active</label>
                        <select name="is_active" class="form-control">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <div class="col-md-12 text-right">
                        <button type="submit" class="btn btn-primary">Add Rule</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Existing Rules</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Keywords</th>
                        <th>Price</th>
                        <th>Order</th>
                        <th>Active</th>
                        <th style="width: 250px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr>
                            <form method="POST" action="{{ route('manual-item-price-rules.update', $rule->id) }}">
                                @csrf
                                <input type="hidden" name="_method" value="PUT">
                                <td><input type="text" name="label" class="form-control" value="{{ $rule->label }}" required></td>
                                <td><input type="text" name="keywords" class="form-control" value="{{ $rule->keywords }}" required></td>
                                <td><input type="number" name="price" class="form-control" step="0.01" min="0" value="{{ $rule->price }}" required></td>
                                <td><input type="number" name="sort_order" class="form-control" min="0" value="{{ $rule->sort_order }}"></td>
                                <td>
                                    <select name="is_active" class="form-control">
                                        <option value="1" @if($rule->is_active) selected @endif>Yes</option>
                                        <option value="0" @if(!$rule->is_active) selected @endif>No</option>
                                    </select>
                                </td>
                                <td>
                                    <button type="submit" class="btn btn-xs btn-primary">Save</button>
                            </form>
                                    <form method="POST" action="{{ route('manual-item-price-rules.destroy', $rule->id) }}" style="display:inline-block;" onsubmit="return confirm('Delete this rule?');">
                                        @csrf
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                                    </form>
                                </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No rules yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection

