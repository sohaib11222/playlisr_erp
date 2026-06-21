@extends('layouts.app')
@php
    $heading = !empty($module_category_data['heading']) ? $module_category_data['heading'] : __('category.categories');
    $navbar = !empty($module_category_data['navbar']) ? $module_category_data['navbar'] : null;
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

/* card */
body.taxonomy-v2 .tax-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }

/* add button — POS accent */
body.taxonomy-v2 .tax-add { display:inline-flex; align-items:center; gap:7px; min-height:42px; padding:9px 18px; border:1px solid #E4D38A; border-radius:8px; font-family:inherit; font-weight:700; font-size:14px; cursor:pointer; background:#FFF2B3; color:#1F1B16; margin-bottom:16px; }
body.taxonomy-v2 .tax-add:hover { background:#FAE89A; }

/* table */
body.taxonomy-v2 #category_table { width:100% !important; border-collapse: collapse; border:0; }
body.taxonomy-v2 #category_table thead th { color:#8E8273; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.4px; background:#F7F1E3; border:0; border-bottom:1px solid #ECE3CF; padding:11px 12px; }
body.taxonomy-v2 #category_table tbody td { padding:10px 12px; border:0; border-bottom:1px solid #F0E9D8; font-size:14px; vertical-align:middle; color:#1F1B16; }
body.taxonomy-v2 #category_table.table-striped tbody tr:nth-of-type(odd) { background:#FFFDF8; }
body.taxonomy-v2 #category_table tbody tr:hover { background:#FFF9E8; }

/* product-count link */
body.taxonomy-v2 #category_table td a { color:#2F6B3E; font-weight:700; text-decoration:none; }
body.taxonomy-v2 #category_table td a:hover { text-decoration:underline; }

/* row action buttons */
body.taxonomy-v2 .edit_category_button, body.taxonomy-v2 .delete_category_button { display:inline-flex; align-items:center; gap:4px; min-height:30px; padding:4px 11px; border-radius:7px; border:1px solid transparent; font-family:inherit; font-weight:600; font-size:12px; }
body.taxonomy-v2 .edit_category_button { background:#1F1B16; border-color:#1F1B16; color:#FAF6EE; }
body.taxonomy-v2 .edit_category_button:hover { background:#000; }
body.taxonomy-v2 .delete_category_button { background:#FFFFFF; border-color:#E0B4AC; color:#8A3A2E; }
body.taxonomy-v2 .delete_category_button:hover { background:#F8D7DA; }

/* DataTables chrome */
body.taxonomy-v2 .dataTables_wrapper .dataTables_length select,
body.taxonomy-v2 .dataTables_wrapper .dataTables_filter input { padding:7px 11px; border:1px solid #D7CDB6; border-radius:8px; background:#FFFCF5; font-family:inherit; font-size:14px; color:#1F1B16; }
body.taxonomy-v2 .dataTables_wrapper .dataTables_length, body.taxonomy-v2 .dataTables_wrapper .dataTables_filter, body.taxonomy-v2 .dataTables_wrapper .dataTables_info { color:#5A5045; font-size:13px; }
body.taxonomy-v2 .dataTables_wrapper .dt-buttons .btn,
body.taxonomy-v2 .dataTables_wrapper .dt-button { background:#FFFFFF !important; border:1px solid #D7CDB6 !important; border-radius:8px !important; color:#5A5045 !important; font-family:inherit !important; font-weight:600; font-size:13px; padding:7px 12px; margin-right:6px; box-shadow:none !important; }
body.taxonomy-v2 .dataTables_wrapper .dt-buttons .btn:hover,
body.taxonomy-v2 .dataTables_wrapper .dt-button:hover { background:#F7F1E3 !important; }
body.taxonomy-v2 .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius:7px !important; border:1px solid #ECE3CF !important; padding:5px 11px !important; color:#5A5045 !important; background:#FFFFFF !important; }
body.taxonomy-v2 .dataTables_wrapper .dataTables_paginate .paginate_button.current { background:#FFF2B3 !important; border-color:#E4D38A !important; color:#1F1B16 !important; }
body.taxonomy-v2 .dataTables_wrapper .dataTables_paginate .paginate_button:hover { background:#F7F1E3 !important; border-color:#D7CDB6 !important; color:#1F1B16 !important; }
body.taxonomy-v2 .text-muted { color:#B7AC97 !important; }
</style>

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>{{$heading }}
        <small>
            {{ $module_category_data['sub_heading'] ?? __( 'category.manage_your_categories' ) }}
        </small>
        @if(isset($module_category_data['heading_tooltip']))
            @show_tooltip($module_category_data['heading_tooltip'])
        @endif
    </h1>
</section>

<!-- Main content -->
<section class="content">
    @php
        $cat_code_enabled = isset($module_category_data['enable_taxonomy_code']) && !$module_category_data['enable_taxonomy_code'] ? false : true;
    @endphp
    <input type="hidden" id="category_type" value="{{request()->get('type')}}">
    @php
        $can_add = true;
        if(request()->get('type') == 'product' && !auth()->user()->can('category.create')) {
            $can_add = false;
        }
    @endphp
    <div class="tax-wrap">
        @if($can_add)
            <button type="button" class="tax-add btn-modal"
                data-href="{{action('TaxonomyController@create')}}?type={{request()->get('type')}}"
                data-container=".category_modal">
                <i class="fa fa-plus"></i> @lang( 'messages.add' )
            </button>
        @endif

        <div class="tax-card">
            <div class="table-responsive">
                <table class="table table-striped" id="category_table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>@if(!empty($module_category_data['taxonomy_label'])) {{$module_category_data['taxonomy_label']}} @else @lang( 'category.category' ) @endif</th>
                            <th>Parent</th>
                            @if($cat_code_enabled)
                                <th>{{ $module_category_data['taxonomy_code_label'] ?? __( 'category.code' )}}</th>
                            @endif
                            <th>@lang( 'lang_v1.description' )</th>
                            <th>Products</th>
                            <th>@lang( 'messages.action' )</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade category_modal" tabindex="-1" role="dialog"
    	aria-labelledby="gridSystemModalLabel">
    </div>

</section>
<!-- /.content -->
@stop
@section('javascript')
@includeIf('taxonomy.taxonomies_js')
@endsection
