@extends('layouts.app')
@section('title', 'Task Store Assignments')

@section('content')
<section class="content-header">
    <h1>Task Store Assignments</h1>
    <p class="text-muted">
        Sets which store's tasks each person sees on <code>/tasks</code> and the Employee Tasks board.
        Most staff have "all locations" POS access, so this can't be inferred automatically &mdash; pick each person's store below.
        Leave someone <strong>Unset</strong> to fall back to their POS location permissions.
    </p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-8">
        <div class="box box-solid">
            <div class="box-body">
                <form method="POST" action="{{ url('/admin/task-store-assignments/save') }}">
                    @csrf
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th style="width:220px;">Store</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($users as $user)
                            <tr>
                                <td>{{ trim($user->first_name . ' ' . $user->last_name) }}</td>
                                <td>
                                    <select name="home_store[{{ $user->id }}]" class="form-control">
                                        <option value="" {{ !$user->home_store ? 'selected' : '' }}>&mdash; Unset &mdash;</option>
                                        @foreach($storeLabels as $key => $label)
                                            <option value="{{ $key }}" {{ $user->home_store === $key ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primary btn-lg">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
</section>
@endsection
