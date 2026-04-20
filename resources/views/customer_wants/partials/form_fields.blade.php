<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label>Artist</label>
            <input type="text" class="form-control" name="artist" value="{{ old('artist', $want->artist ?? '') }}" placeholder="e.g. Kali Uchis">
        </div>
    </div>
    <div class="col-md-5">
        <div class="form-group">
            <label>Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title" value="{{ old('title', $want->title ?? '') }}" required placeholder="e.g. Red Moon In Venus">
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label>Format</label>
            <input type="text" class="form-control" name="format" value="{{ old('format', $want->format ?? '') }}" placeholder="LP, CD, Cassette, …">
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label>Customer (existing account)</label>
            <select name="contact_id" class="form-control select2_customer" style="width:100%;">
                <option value="">— choose if they have an account —</option>
                @if(!empty($want) && $want->contact)
                    <option value="{{ $want->contact->id }}" selected>{{ trim($want->contact->first_name . ' ' . $want->contact->last_name) }}</option>
                @endif
            </select>
            <small class="text-muted">If they don't have an account yet, leave blank and enter a phone below.</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label>Phone</label>
            <input type="text" class="form-control" name="phone" value="{{ old('phone', $want->phone ?? '') }}">
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label>Store</label>
            <select name="location_id" class="form-control">
                <option value="">—</option>
                @foreach($business_locations as $id => $name)
                    <option value="{{ $id }}" @if((string)($want->location_id ?? '')===(string)$id) selected @endif>{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-md-2">
        <div class="form-group">
            <label>Priority</label>
            <select name="priority" class="form-control">
                <option value="normal" @if(($want->priority ?? 'normal')==='normal') selected @endif>Normal</option>
                <option value="high" @if(($want->priority ?? '')==='high') selected @endif>High</option>
                <option value="low" @if(($want->priority ?? '')==='low') selected @endif>Low</option>
            </select>
        </div>
    </div>
</div>

<div class="form-group">
    <label>Notes</label>
    <textarea name="notes" class="form-control" rows="3" placeholder="Specific pressing, color vinyl, condition requirements, etc.">{{ old('notes', $want->notes ?? '') }}</textarea>
</div>

@push('scripts')
<script>
$(function() {
    $('.select2_customer').select2({
        ajax: {
            url: '/contacts/customers',
            dataType: 'json',
            delay: 250,
            data: function(params) { return { q: params.term, type: 'customer' }; },
            processResults: function(data) {
                return {
                    results: $.map(data, function(c) {
                        return { id: c.id, text: c.name };
                    })
                };
            }
        },
        minimumInputLength: 1,
        placeholder: '— choose if they have an account —'
    });
});
</script>
@endpush
