<div class="pos-tab-content">
    @if(!empty($is_business_admin))
    <div class="row" id="database-backup-section">
        <div class="col-sm-12">
            <h4>@lang('business.database_backup_heading')</h4>
            <p class="text-muted">
                @lang('business.database_backup_intro', ['count' => 15])
            </p>
            <p>
                <button type="button" class="btn btn-primary" id="btn_database_backup_create">
                    <i class="fa fa-database"></i> @lang('business.database_backup_create')
                </button>
                <button type="button" class="btn btn-default" id="btn_database_backup_refresh">
                    <i class="fa fa-refresh"></i> @lang('business.database_backup_refresh_list')
                </button>
                <span id="database_backup_spinner" class="hide" style="margin-left: 10px;">
                    <i class="fa fa-spinner fa-spin fa-lg"></i> @lang('business.database_backup_please_wait')…
                </span>
            </p>
            <div class="table-responsive">
                <table class="table table-bordered table-condensed" id="database-backup-list">
                    <thead>
                        <tr>
                            <th>@lang('business.database_backup_col_file')</th>
                            <th>@lang('business.database_backup_col_size')</th>
                            <th>@lang('business.database_backup_col_actions')</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="text-muted">—</td></tr>
                    </tbody>
                </table>
            </div>
            <hr>
        </div>
    </div>
    @else
    <div class="row">
        <div class="col-sm-12">
            <p class="text-muted"><i class="fa fa-lock"></i> @lang('business.database_backup_admin_only')</p>
            <hr>
        </div>
    </div>
    @endif

    <div class="row">
        <div class="col-sm-12">
            <h4>Update Artist Names from Product Titles</h4>
            <p class="text-muted">
                Scans products that have no artist but contain <code> - </code> in the title.
                The part <strong>after</strong> the last <code> - </code> is extracted as the artist name.
                <br>
                Example: <code>BACK TO BLACK - AMY WINEHOUSE</code> &rarr; Artist = <strong>AMY WINEHOUSE</strong>
            </p>
            <hr>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-12">
            <button type="button" class="btn btn-info" id="btn_preview_artists">
                <i class="fa fa-search"></i> Preview Extractable Artists
            </button>
            <button type="button" class="btn btn-success" id="btn_update_artists" disabled>
                <i class="fa fa-pencil"></i> Update All Artist Names
            </button>
            <span id="artist_update_spinner" class="hide" style="margin-left: 10px;">
                <i class="fa fa-spinner fa-spin fa-lg"></i> Processing...
            </span>
        </div>
    </div>

    <div class="row" style="margin-top: 15px;">
        <div class="col-sm-12" id="artist-update-results"></div>
    </div>
</div>
