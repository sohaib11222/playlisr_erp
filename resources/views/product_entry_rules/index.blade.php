@extends('layouts.app')
@section('title', 'Product Entry Rules')

@section('content')
<section class="content-header">
    <h1>Product Entry Rules</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
        </div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Add Rule</h3></div>
        <div class="box-body">
            <form method="POST" action="{{ route('product-entry-rules.store') }}">
                @csrf
                <div class="row">
                    <div class="col-md-2">
                        <label>Trigger Type</label>
                        <select name="trigger_type" class="form-control">
                            <option value="title">Title</option>
                            <option value="category_combo">Category Combo</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Trigger Value</label>
                        <input type="text" name="trigger_value" class="form-control" placeholder="coke, thriller, 11|22" required>
                    </div>
                    <div class="col-md-2">
                        <label>Artist</label>
                        <input type="text" name="artist" class="form-control" placeholder="Optional">
                    </div>
                    <div class="col-md-2">
                        <label>Purchase Price</label>
                        <input type="number" step="0.01" min="0" name="purchase_price" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label>Selling Price</label>
                        <input type="number" step="0.01" min="0" name="selling_price" class="form-control">
                    </div>
                    <div class="col-md-1">
                        <label>Order</label>
                        <input type="number" min="0" name="sort_order" value="0" class="form-control">
                    </div>
                </div>
                <div class="row" style="margin-top:10px;">
                    <div class="col-md-3">
                        <label>Category / Subcategory (set output)</label>
                        <select name="category_combo" class="form-control select2" style="width:100%;">
                            <option value="">None</option>
                            @foreach($category_combos ?? [] as $combo)
                                <option value="{{ $combo['id'] }}">{{ $combo['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>Active</label>
                        <select name="is_active" class="form-control">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="col-md-7 text-right">
                        <label style="display:block;">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">Add Rule</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Existing Rules</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Trigger</th>
                        <th>Artist</th>
                        <th>Purchase Price</th>
                        <th>Selling Price</th>
                        <th>Category/Subcategory</th>
                        <th>Order</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        @php
                            $selected_combo = (!empty($rule->category_id) || !empty($rule->sub_category_id))
                                ? ((int) ($rule->category_id ?: 0) . '|' . (int) ($rule->sub_category_id ?: 0))
                                : '';
                        @endphp
                        <tr>
                            <form method="POST" action="{{ route('product-entry-rules.update', $rule->id) }}">
                                @csrf
                                <input type="hidden" name="_method" value="PUT">
                                <td>
                                    <select name="trigger_type" class="form-control">
                                        <option value="title" @if($rule->trigger_type === 'title') selected @endif>Title</option>
                                        <option value="category_combo" @if($rule->trigger_type === 'category_combo') selected @endif>Category Combo</option>
                                    </select>
                                </td>
                                <td><input type="text" name="trigger_value" class="form-control" value="{{ $rule->trigger_value }}" required></td>
                                <td><input type="text" name="artist" class="form-control" value="{{ $rule->artist }}"></td>
                                <td><input type="number" step="0.01" min="0" name="purchase_price" class="form-control" value="{{ $rule->purchase_price }}"></td>
                                <td><input type="number" step="0.01" min="0" name="selling_price" class="form-control" value="{{ $rule->selling_price }}"></td>
                                <td>
                                    <select name="category_combo" class="form-control select2" style="width:100%;">
                                        <option value="">None</option>
                                        @foreach($category_combos ?? [] as $combo)
                                            <option value="{{ $combo['id'] }}" @if($selected_combo === (string) $combo['id']) selected @endif>{{ $combo['label'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" min="0" name="sort_order" class="form-control" value="{{ $rule->sort_order }}"></td>
                                <td>
                                    <select name="is_active" class="form-control">
                                        <option value="1" @if($rule->is_active) selected @endif>Yes</option>
                                        <option value="0" @if(!$rule->is_active) selected @endif>No</option>
                                    </select>
                                </td>
                                <td>
                                    <button type="submit" class="btn btn-xs btn-primary">Save</button>
                            </form>
                                    <form method="POST" action="{{ route('product-entry-rules.destroy', $rule->id) }}" style="display:inline-block;" onsubmit="return confirm('Delete this rule?');">
                                        @csrf
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                                    </form>
                                </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted">No rules yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script>
    $(document).ready(function() {
        $('.select2').select2();
    });
</script>
@endsection

