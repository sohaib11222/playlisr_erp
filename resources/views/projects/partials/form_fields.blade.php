<div class="form-group">
    <label>Title <span class="text-danger">*</span></label>
    <input type="text" class="form-control" name="title" value="{{ old('title', $project->title ?? '') }}" required maxlength="200">
</div>

<div class="form-group">
    <label>Description / why</label>
    <textarea name="description" class="form-control" rows="4" placeholder="What is this project and why does it matter?">{{ old('description', $project->description ?? '') }}</textarea>
</div>

<div class="form-group">
    <label>Store</label>
    <select name="store" class="form-control" style="width:auto;">
        <option value="" @if(empty($project->store ?? '')) selected @endif>Both stores</option>
        @foreach($storeLabels as $key => $label)
            <option value="{{ $key }}" @if(($project->store ?? '')===$key) selected @endif>{{ $label }}</option>
        @endforeach
    </select>
</div>
