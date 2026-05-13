{{-- Sarah 2026-05-13: HTML-only modal markup. Behaviour JS is loaded
     separately via clover_mismatch_modal_script.blade.php inside
     @section('javascript') — keeping HTML out of the script
     pipeline avoids the layout breakage we hit when the script was
     inline in @section('content'). --}}
<div class="modal fade" id="clover_mismatch_modal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:#FFF3D6; border-bottom:1px solid #E8C77A;">
        <button type="button" class="close" aria-label="Close" id="clover_mismatch_dismiss"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" style="color:#6B4F12;">⚠ Clover vs ERP mismatch</h4>
      </div>
      <div class="modal-body">
        <p id="clover_mismatch_prompt" style="margin: 0 0 12px; font-size: 15px; line-height: 1.4;"></p>
        <div style="background:#fafafa; border:1px solid #eee; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px;">
          <div id="clover_mismatch_detail_erp" style="margin-bottom:4px;"></div>
          <div id="clover_mismatch_detail_clover"></div>
        </div>
        <label for="clover_mismatch_reason" style="font-weight:600; margin-bottom:4px;">Why?</label>
        <textarea id="clover_mismatch_reason" class="form-control" rows="3" placeholder="e.g. discount given to customer, typo when keying Clover, voided on Clover but rang in ERP…" maxlength="2000"></textarea>
        <div id="clover_mismatch_error" style="color:#B0451A; margin-top:6px; display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" id="clover_mismatch_later">Later</button>
        <button type="button" class="btn btn-primary" id="clover_mismatch_save">Save explanation</button>
      </div>
    </div>
  </div>
</div>
