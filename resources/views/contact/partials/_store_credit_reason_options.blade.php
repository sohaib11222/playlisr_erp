{{-- Locked store-credit reason options. Single source of truth is
     config/constants.php store_credit_reasons (also validated server-side in
     ContactController@updateStoreCredit). Rendered into every "Add Store
     Credit" picker so the list can never drift between screens. --}}
<option value="">— Select a reason —</option>
@foreach(config('constants.store_credit_reasons', []) as $code => $label)
    <option value="{{ $code }}">{{ $label }}</option>
@endforeach
