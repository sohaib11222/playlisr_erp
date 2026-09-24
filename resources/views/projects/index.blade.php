@extends('layouts.app')
@section('title', 'Projects')

@section('content')
@include('tasks.partials.asana_styles')
<style>
.as-proj .as-cols, .as-proj .as-row { grid-template-columns: minmax(0, 1fr) 132px 96px 104px 110px 170px 64px; }
@media (max-width: 767px) { .as-proj .as-row { grid-template-columns: minmax(0, 1fr) auto; } }
</style>

<section class="content">
<div class="as-wrap">

    <div class="as-head">
        <div>
            <h1 class="as-title">Projects</h1>
            <div class="as-sub">Longer-running work. Join a project to get credit for helping.</div>
        </div>
    </div>

    @include('tasks.partials.asana_nav')

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <div class="as-toolbar">
        <a href="{{ action('ProjectController@create') }}" class="as-btn"><i class="fa fa-plus"></i> Add project</a>
        <div class="as-tools">
            @if($canToggleStore)
                @php
                    $baseQuery = request()->except(['store', 'page']);
                @endphp
                <div class="as-seg">
                    <a href="{{ action('ProjectController@index') }}?{{ http_build_query($baseQuery) }}" class="{{ !$store ? 'on' : '' }}">All stores</a>
                    @foreach($storeLabels as $key => $label)
                        <a href="{{ action('ProjectController@index') }}?{{ http_build_query(array_merge($baseQuery, ['store' => $key])) }}" class="{{ $store === $key ? 'on' : '' }}">{{ $label }}</a>
                    @endforeach
                </div>
            @elseif($store)
                <span class="as-pill as-tag">{{ $storeLabels[$store] ?? ucfirst($store) }}</span>
            @endif
            @php
                    $vq = request()->except(['status', 'page']);
                    $viewUrls = [
                        'incomplete' => action('ProjectController@index') . '?' . http_build_query(array_merge($vq, ['status' => 'incomplete'])),
                        'completed' => action('ProjectController@index') . '?' . http_build_query(array_merge($vq, ['status' => 'complete'])),
                        'all' => action('ProjectController@index') . '?' . http_build_query($vq),
                    ];
                    $viewCurrent = $status === 'incomplete' ? 'incomplete' : ($status === 'complete' ? 'completed' : 'all');
                @endphp
                @include('tasks.partials.asana_view_toggle')
            <form method="GET" action="{{ action('ProjectController@index') }}" class="as-tools">
                @if($store)<input type="hidden" name="store" value="{{ $store }}">@endif
                @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                <select name="priority" class="as-filter" onchange="this.form.submit()">
                    <option value="" @if(!$priority) selected @endif>Any priority</option>
                    @foreach($priorityLabels as $key => $label)
                        <option value="{{ $key }}" @if($priority === $key) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    @php
        $groups = [
            'in_progress' => 'In progress',
            'not_started' => 'Not started',
            'complete' => 'Completed',
        ];
        $byStatus = $projects->getCollection()->groupBy('status');
        $taskCounts = \Schema::hasColumn('weekly_tasks', 'project_id')
            ? \App\WeeklyTask::whereIn('project_id', $projects->getCollection()->pluck('id'))
                ->selectRaw("project_id, count(*) as total, sum(status = 'complete') as done")
                ->groupBy('project_id')->get()->keyBy('project_id')
            : collect();
    @endphp

    <div class="as-table as-proj">
        <div class="as-cols">
            <div>Project name</div><div>Status</div><div>Priority</div><div>Store</div><div>Owner</div><div>Members</div><div></div>
        </div>

        @foreach($groups as $key => $title)
            @php
                $rows = $byStatus->get($key, collect());
            @endphp
            @if($rows->count() || (!$status && $key !== 'complete'))
            <div class="as-section {{ $key === 'complete' && $status !== 'complete' ? 'collapsed' : '' }}">
                <div class="as-section-h"><span class="as-caret"><i class="fa fa-caret-down"></i></span> {{ $title }} <span class="as-count">{{ $rows->count() }}</span></div>
                <div class="as-rows">
                    @forelse($rows as $p)
                        @php
                            $color = \App\Http\Controllers\TeamProgressController::AVATAR_COLORS[$p->id % count(\App\Http\Controllers\TeamProgressController::AVATAR_COLORS)];
                            $isMember = $p->contributors->contains('id', auth()->id());
                            if ($p->completed_at) {
                                $sub = 'Completed ' . $p->completed_at->format('M j') . ($p->completedBy ? ' by ' . $p->completedBy->first_name : '');
                            } elseif ($p->started_at) {
                                $sub = 'Started ' . $p->started_at->format('M j') . ($p->startedBy ? ' by ' . $p->startedBy->first_name : '');
                            } else {
                                $sub = 'Created ' . $p->created_at->format('M j') . ($p->creator ? ' by ' . $p->creator->first_name : '');
                            }
                        @endphp
                        <div class="as-row {{ $p->status === 'complete' ? 'done' : '' }}">
                            <div>
                                <span class="as-tile" style="background:{{ $color }}"><i class="fa fa-list-ul"></i></span>
                                <div class="as-pname">
                                    <a class="as-name" href="{{ action('ProjectController@edit', $p->id) }}" title="{{ $p->description }}">{{ $p->title }}</a>
                                    <span class="as-meta">@if($taskCounts->has($p->id)){{ (int) $taskCounts[$p->id]->done }} of {{ (int) $taskCounts[$p->id]->total }} tasks done &middot; @endif{{ $p->description ? \Illuminate\Support\Str::limit($p->description, 80) : $sub }}</span>
                                </div>
                            </div>
                            <div>
                                <form action="{{ action('ProjectController@updateStatus', $p->id) }}" method="POST" style="margin:0;">
                                    @csrf
                                    <select name="status" class="as-status as-st-{{ $p->status }}" onchange="this.form.submit()">
                                        <option value="not_started" @if($p->status === 'not_started') selected @endif>Not started</option>
                                        <option value="in_progress" @if($p->status === 'in_progress') selected @endif>In progress</option>
                                        <option value="complete" @if($p->status === 'complete') selected @endif>Complete</option>
                                    </select>
                                </form>
                            </div>
                            <div class="as-hide-sm"><span class="as-pill as-p-{{ $p->priority }}">{{ $priorityLabels[$p->priority] ?? ucfirst($p->priority) }}</span></div>
                            <div class="as-hide-sm"><span class="as-pill as-tag">{{ $p->store ? ($storeLabels[$p->store] ?? ucfirst($p->store)) : 'Both' }}</span></div>
                            <div class="as-hide-sm">
                                @if($p->assignees->count())
                                    <span class="as-avatars">
                                        @foreach($p->assignees->take(3) as $a)
                                            {!! \App\Http\Controllers\TeamProgressController::avatar($a->id, trim($a->first_name . ' ' . $a->last_name), 'sm') !!}
                                        @endforeach
                                    </span>
                                    @if($p->assignees->count() > 3)
                                        <span class="as-meta" style="margin-left:4px;">+{{ $p->assignees->count() - 3 }}</span>
                                    @endif
                                @else
                                    <span class="as-meta">Unassigned</span>
                                @endif
                            </div>
                            <div class="as-hide-sm">
                                <span class="as-avatars">
                                    @foreach($p->contributors->take(4) as $c)
                                        {!! \App\Http\Controllers\TeamProgressController::avatar($c->id, trim($c->first_name . ' ' . $c->last_name), 'sm') !!}
                                    @endforeach
                                </span>
                                @if($p->contributors->count() > 4)
                                    <span class="as-meta" style="margin-left:4px;">+{{ $p->contributors->count() - 4 }}</span>
                                @endif
                                @if($isMember)
                                    <form action="{{ action('ProjectController@removeContributor', [$p->id, auth()->id()]) }}" method="POST" style="display:inline;margin:0;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="as-leave" title="Leave this project">Leave</button>
                                    </form>
                                @else
                                    <form action="{{ action('ProjectController@join', $p->id) }}" method="POST" style="display:inline;margin:0;">
                                        @csrf
                                        <button type="submit" class="as-join"><i class="fa fa-plus"></i> Join</button>
                                    </form>
                                @endif
                            </div>
                            <div class="as-acts">
                                <a href="{{ action('ProjectController@edit', $p->id) }}" class="as-icon-btn" title="Edit"><i class="fa fa-pencil"></i></a>
                                @if(\App\Http\Controllers\TeamProgressController::canDelete($p))
                                <form action="{{ action('ProjectController@destroy', $p->id) }}" method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Delete this project?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="as-icon-btn del" title="Delete"><i class="fa fa-trash"></i></button>
                                </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="as-empty">No projects here.</div>
                    @endforelse
                </div>
            </div>
            @endif
        @endforeach
    </div>

    <div class="text-center">{{ $projects->links() }}</div>

</div>
</section>

@include('tasks.partials.asana_script')
@stop
