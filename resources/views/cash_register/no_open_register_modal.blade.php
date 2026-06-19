{{-- Shown when "Close Register" is clicked by a user who has no OPEN register
     of their own (typically an owner/admin). The full close modal parses the
     register's open_time with Carbon, which throws "Data missing" on a null,
     500-ing the AJAX so the button appears dead. This friendly stand-in keeps
     the close flow from ever silently failing. --}}
<div class="modal-dialog" role="document">
	<div class="modal-content" style="border-radius: 10px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,.3);">
		<div class="modal-header">
			<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			<h3 class="modal-title">Close Register</h3>
		</div>
		<div class="modal-body" style="padding: 22px;">
			<p style="font-size: 15px; margin-bottom: 8px;">
				<strong>You don't have an open register to close.</strong>
			</p>
			<p style="font-size: 13px; color: #6b6256; margin-bottom: 0;">
				The Close Register button closes the register tied to your own login,
				and there isn't an open one right now. If you're trying to close out a
				cashier's drawer, use Force Close Registers under Admin to pick the
				specific register.
			</p>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-default" data-dismiss="modal">OK</button>
		</div>
	</div>
</div>
