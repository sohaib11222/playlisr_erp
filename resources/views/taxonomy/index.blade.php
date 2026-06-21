@extends('layouts.app')
@php
    $heading = !empty($module_category_data['heading']) ? $module_category_data['heading'] : __('category.categories');
    $navbar = !empty($module_category_data['navbar']) ? $module_category_data['navbar'] : null;
    $cat_code_enabled = isset($module_category_data['enable_taxonomy_code']) && !$module_category_data['enable_taxonomy_code'] ? false : true;
    $can_add = true;
    if(request()->get('type') == 'product' && !auth()->user()->can('category.create')) {
        $can_add = false;
    }
@endphp
@section('title', $heading)

@section('content')
@if(!empty($navbar))
    @include($navbar)
@endif

<script>document.body.classList.add('taxonomy-v2');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.taxonomy-v2 { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.taxonomy-v2 .content-wrapper { background: #FAF6EE !important; }
body.taxonomy-v2 .content-header { background: transparent; padding: 28px 16px 8px; }
body.taxonomy-v2 .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.taxonomy-v2 .content-header h1 small { color: #8E8273; font-size: 14px; }
body.taxonomy-v2 .content { padding: 0 16px 60px; }
body.taxonomy-v2 .tax-wrap { max-width: 1180px; }

/* toolbar */
body.taxonomy-v2 .tax-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
body.taxonomy-v2 .tax-add { display:inline-flex; align-items:center; gap:7px; min-height:42px; padding:9px 18px; border:1px solid #E4D38A; border-radius:8px; font-family:inherit; font-weight:700; font-size:14px; cursor:pointer; background:#FFF2B3; color:#1F1B16; }
body.taxonomy-v2 .tax-add:hover { background:#FAE89A; }
body.taxonomy-v2 .tax-search { flex:1; min-width:200px; padding:10px 14px; border:1px solid #D7CDB6; border-radius:8px; background:#FFFCF5; font-family:inherit; font-size:15px; color:#1F1B16; }
body.taxonomy-v2 .tax-search::placeholder { color:#B7AC97; }
body.taxonomy-v2 .tax-expand-link { font-size:13px; font-weight:600; color:#5A5045; background:none; border:0; cursor:pointer; padding:6px 8px; }
body.taxonomy-v2 .tax-expand-link:hover { color:#1F1B16; text-decoration:underline; }

/* card + table */
body.taxonomy-v2 .tax-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 6px 4px; box-shadow: 0 1px 2px rgba(31,27,22,.06); overflow:hidden; }
body.taxonomy-v2 table.tax-tree { width:100%; border-collapse:collapse; }
body.taxonomy-v2 table.tax-tree thead th { color:#8E8273; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.4px; background:#F7F1E3; border-bottom:1px solid #ECE3CF; padding:11px 16px; text-align:left; }
body.taxonomy-v2 table.tax-tree td { padding:10px 16px; border-bottom:1px solid #F0E9D8; font-size:14px; vertical-align:middle; }
body.taxonomy-v2 table.tax-tree tr:last-child td { border-bottom:0; }

/* parent rows */
body.taxonomy-v2 tr.parent-row { cursor:pointer; }
body.taxonomy-v2 tr.parent-row:hover { background:#FFF9E8; }
body.taxonomy-v2 tr.parent-row td:first-child { font-weight:700; }
body.taxonomy-v2 .tax-caret { display:inline-block; width:14px; color:#8E8273; transition:transform .12s ease; margin-right:8px; }
body.taxonomy-v2 .tax-caret.open { transform:rotate(90deg); }
body.taxonomy-v2 .tax-caret.leaf { visibility:hidden; }
body.taxonomy-v2 .tax-subcount { color:#8E8273; font-weight:500; font-size:12px; margin-left:8px; }

/* child rows */
body.taxonomy-v2 tr.child-row { background:#FFFDF8; }
body.taxonomy-v2 tr.child-row:hover { background:#FFF9E8; }
body.taxonomy-v2 tr.child-row td:first-child { padding-left:42px; color:#3A332A; }
body.taxonomy-v2 .tax-desc { color:#8E8273; font-size:13px; }

/* count links */
body.taxonomy-v2 table.tax-tree td a.tax-count { color:#2F6B3E; font-weight:700; text-decoration:none; }
body.taxonomy-v2 table.tax-tree td a.tax-count:hover { text-decoration:underline; }
body.taxonomy-v2 .tax-zero { color:#B7AC97; }

/* action buttons */
body.taxonomy-v2 .edit_category_button, body.taxonomy-v2 .delete_category_button { display:inline-flex; align-items:center; gap:4px; min-height:28px; padding:3px 10px; border-radius:7px; border:1px solid transparent; font-family:inherit; font-weight:600; font-size:12px; }
body.taxonomy-v2 .edit_category_button { background:#1F1B16; border-color:#1F1B16; color:#FAF6EE; }
body.taxonomy-v2 .edit_category_button:hover { background:#000; }
body.taxonomy-v2 .delete_category_button { background:#FFFFFF; border-color:#E0B4AC; color:#8A3A2E; }
body.taxonomy-v2 .delete_category_button:hover { background:#F8D7DA; }
body.taxonomy-v2 .tax-actions { white-space:nowrap; text-align:right; }
body.taxonomy-v2 .tax-empty { padding:26px 16px; text-align:center; color:#8E8273; }
</style>

@php
    // Render helpers as closures so the markup below stays DRY.
    $actionBtns = function ($cat) use ($category_type, $can_edit, $can_delete) {
        $h = '';
        if ($can_edit) {
            $h .= '<button data-href="'.action('TaxonomyController@edit', [$cat->id]).'?type='.$category_type.'" class="edit_category_button"><i class="glyphicon glyphicon-edit"></i> '.__('messages.edit').'</button> ';
        }
        if ($can_delete) {
            $h .= '<button data-href="'.action('TaxonomyController@destroy', [$cat->id]).'" class="delete_category_button"><i class="glyphicon glyphicon-trash"></i> '.__('messages.delete').'</button>';
        }
        return $h;
    };
    $countCell = function ($count, $param, $id) {
        $count = (int) $count;
        if ($count === 0) {
            return '<span class="tax-zero">0</span>';
        }
        return '<a class="tax-count" target="_blank" href="'.url('/products').'?'.$param.'='.$id.'">'.$count.'</a>';
    };
@endphp

<section class="content-header">
    <h1>{{ $heading }}
        <small>{{ $module_category_data['sub_heading'] ?? __('category.manage_your_categories') }}</small>
    </h1>
</section>

<section class="content">
    <input type="hidden" id="category_type" value="{{ request()->get('type') }}">
    <div class="tax-wrap">
        <div class="tax-toolbar">
            @if($can_add)
                <button type="button" class="tax-add btn-modal"
                    data-href="{{ action('TaxonomyController@create') }}?type={{ request()->get('type') }}"
                    data-container=".category_modal">
                    <i class="fa fa-plus"></i> @lang('messages.add')
                </button>
            @endif
            <input type="text" id="tax_search" class="tax-search" placeholder="Search categories...">
            <button type="button" class="tax-expand-link" id="tax_expand_all">Expand all</button>
            <button type="button" class="tax-expand-link" id="tax_collapse_all">Collapse all</button>
        </div>

        <div class="tax-card">
            <table class="tax-tree">
                <thead>
                    <tr>
                        <th>@if(!empty($module_category_data['taxonomy_label'])) {{ $module_category_data['taxonomy_label'] }} @else @lang('category.category') @endif</th>
                        <th>@lang('lang_v1.description')</th>
                        <th>Products</th>
                        <th class="tax-actions">@lang('messages.action')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($parents as $parent)
                        @php
                            $kids = $childrenByParent->get($parent->id, collect());
                            $hasKids = $kids->count() > 0;
                        @endphp
                        <tr class="parent-row" data-pid="{{ $parent->id }}"
                            data-search="{{ strtolower($parent->name) }}">
                            <td>
                                <span class="tax-caret {{ $hasKids ? '' : 'leaf' }}">&#9656;</span>{{ $parent->name }}
                                @if($hasKids)<span class="tax-subcount">{{ $kids->count() }} sub@if($kids->count() != 1)s@endif</span>@endif
                            </td>
                            <td class="tax-desc">{{ $parent->description }}</td>
                            <td>{!! $countCell($parentCounts[$parent->id] ?? 0, 'category_id', $parent->id) !!}</td>
                            <td class="tax-actions">{!! $actionBtns($parent) !!}</td>
                        </tr>
                        @foreach($kids as $child)
                            <tr class="child-row" data-pid="{{ $parent->id }}"
                                data-search="{{ strtolower($child->name.' '.$child->description) }}" hidden>
                                <td>{{ $child->name }}</td>
                                <td class="tax-desc">{{ $child->description }}</td>
                                <td>{!! $countCell($subCounts[$child->id] ?? 0, 'sub_category_id', $child->id) !!}</td>
                                <td class="tax-actions">{!! $actionBtns($child) !!}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="4" class="tax-empty">No categories yet.</td></tr>
                    @endforelse

                    @if($ungrouped->count() > 0)
                        <tr class="parent-row" data-pid="ungrouped" data-search="ungrouped">
                            <td>
                                <span class="tax-caret">&#9656;</span>Ungrouped
                                <span class="tax-subcount">{{ $ungrouped->count() }} item@if($ungrouped->count() != 1)s@endif</span>
                            </td>
                            <td class="tax-desc">Sub-categories whose parent was deleted</td>
                            <td></td>
                            <td></td>
                        </tr>
                        @foreach($ungrouped as $child)
                            <tr class="child-row" data-pid="ungrouped"
                                data-search="{{ strtolower($child->name.' '.$child->description) }}" hidden>
                                <td>{{ $child->name }}</td>
                                <td class="tax-desc">{{ $child->description }}</td>
                                <td>{!! $countCell($subCounts[$child->id] ?? 0, 'sub_category_id', $child->id) !!}</td>
                                <td class="tax-actions">{!! $actionBtns($child) !!}</td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade category_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>
</section>

<script>
(function () {
    function esc(v) { return (window.CSS && CSS.escape) ? CSS.escape(v) : v; }
    function kids(pid) { return document.querySelectorAll('.child-row[data-pid="' + esc(pid) + '"]'); }
    function setOpen(pid, on) {
        kids(pid).forEach(function (r) { r.hidden = !on; });
        var p = document.querySelector('.parent-row[data-pid="' + esc(pid) + '"]');
        if (p) { var c = p.querySelector('.tax-caret'); if (c && !c.classList.contains('leaf')) c.classList.toggle('open', on); }
    }

    // Toggle a group when its parent row is clicked (ignore clicks on buttons/links).
    document.querySelectorAll('.parent-row').forEach(function (p) {
        p.addEventListener('click', function (e) {
            if (e.target.closest('button, a')) return;
            var caret = p.querySelector('.tax-caret');
            if (!caret || caret.classList.contains('leaf')) return;
            setOpen(p.dataset.pid, !caret.classList.contains('open'));
        });
    });

    var expandAll = document.getElementById('tax_expand_all');
    var collapseAll = document.getElementById('tax_collapse_all');
    if (expandAll) expandAll.addEventListener('click', function () {
        document.querySelectorAll('.parent-row').forEach(function (p) { setOpen(p.dataset.pid, true); });
    });
    if (collapseAll) collapseAll.addEventListener('click', function () {
        document.querySelectorAll('.parent-row').forEach(function (p) { setOpen(p.dataset.pid, false); });
    });

    var search = document.getElementById('tax_search');
    if (search) search.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        if (!q) {
            document.querySelectorAll('.parent-row').forEach(function (p) { p.hidden = false; setOpen(p.dataset.pid, false); });
            return;
        }
        document.querySelectorAll('.parent-row').forEach(function (p) {
            var pid = p.dataset.pid;
            var pMatch = (p.dataset.search || '').indexOf(q) !== -1;
            var anyKid = false;
            kids(pid).forEach(function (k) {
                var m = pMatch || (k.dataset.search || '').indexOf(q) !== -1;
                k.hidden = !m;
                if (m) anyKid = true;
            });
            var show = pMatch || anyKid;
            p.hidden = !show;
            var caret = p.querySelector('.tax-caret');
            if (caret && !caret.classList.contains('leaf')) caret.classList.toggle('open', show);
        });
    });
})();
</script>
@stop
@section('javascript')
@includeIf('taxonomy.taxonomies_js')
@endsection
