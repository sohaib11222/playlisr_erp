@extends('layouts.app')
@section('title', 'Sourcing')

@section('content')
<section class="content-header">
    <h1>Sourcing <small>what to buy, what to pay, where to find it</small></h1>
</section>

<section class="content">

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <div class="box box-solid">
        <div class="box-header with-border" style="display:flex;align-items:center;flex-wrap:wrap;">
            <form method="GET" action="{{ action('SourcingController@index') }}" class="form-inline">
                <label style="margin-right:5px;">Sales window</label>
                <select name="days" class="form-control" onchange="this.form.submit()">
                    @foreach($dayRanges as $range)
                        <option value="{{ $range }}" @if($days === $range) selected @endif>Last {{ $range }} days</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="box-body table-responsive">
            @forelse($groups as $group)
                @php $parent = $group['parent']; @endphp
                <table class="table table-bordered table-striped" style="margin-bottom:25px;">
                    <thead>
                        <tr>
                            <th style="width:18%;">Category</th>
                            <th>Priority</th>
                            <th>Target buy price</th>
                            <th>Avg sell price</th>
                            <th>Est. margin</th>
                            <th>Units sold</th>
                            <th>Revenue</th>
                            <th style="width:22%;">Sourcing tips</th>
                        </tr>
                    </thead>
                    <tbody>
                        @include('sourcing.partials.row', ['row' => $parent, 'isParent' => true, 'priorityLabels' => $priorityLabels, 'canManage' => $canManage])
                        @foreach($group['children'] as $child)
                            @include('sourcing.partials.row', ['row' => $child, 'isParent' => false, 'priorityLabels' => $priorityLabels, 'canManage' => $canManage])
                        @endforeach
                    </tbody>
                </table>
            @empty
                <p class="text-muted">No sourcing categories yet.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
