<div class="pos-tab-content">
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
