<div class="modal-dialog" role="document">
  <div class="modal-content">

    {!! Form::open(['url' => action('TaxonomyController@store'), 'method' => 'post', 'id' => 'category_add_form' ]) !!}
    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      <h4 class="modal-title">Add Category</h4>
    </div>

    <div class="modal-body">
      <input type="hidden" name="category_type" value="{{$category_type}}">

      @php
        $name_label = !empty($module_category_data['taxonomy_label']) ? $module_category_data['taxonomy_label'] : 'Category Name';
        $cat_code_enabled = isset($module_category_data['enable_taxonomy_code']) && !$module_category_data['enable_taxonomy_code'] ? false : true;
        $cat_code_label = !empty($module_category_data['taxonomy_code_label']) ? $module_category_data['taxonomy_code_label'] : 'Code';
        $enable_sub_category = isset($module_category_data['enable_sub_taxonomy']) && !$module_category_data['enable_sub_taxonomy'] ? false : true;
      @endphp

      <!-- Category Name -->
      <div class="form-group" id="single_category_name">
        {!! Form::label('name', $name_label . ':*') !!}
        {!! Form::text('name', null, ['class' => 'form-control', 'required', 'placeholder' => $name_label]) !!}
      </div>

      <!-- Subcategory Toggle -->
      @if($enable_sub_category)
      <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('add_as_sub_cat', 1, false, ['class' => 'toggler', 'data-toggle_id' => 'subcategory_section']) !!} Add as Subcategory
          </label>
        </div>
      </div>

      <!-- Multiple Subcategories Section -->
      <div id="subcategory_section" class="form-group hide">
        <label for="subcategories">Subcategories:</label>
        <div id="subcategory_fields">
          <div class="subcategory_group">
            {!! Form::text('subcategories[]', null, ['class' => 'form-control', 'placeholder' => 'Subcategory Name']) !!}
          </div>
        </div>
        <button type="button" id="add_subcategory" class="btn btn-success">Add Subcategory</button>
      </div>
      @endif

      <!-- Code Field (if enabled) -->
      @if($cat_code_enabled)
      <div class="form-group">
        {!! Form::label('short_code', $cat_code_label . ':') !!}
        {!! Form::text('short_code', null, ['class' => 'form-control', 'placeholder' => $cat_code_label]) !!}
      </div>
      @endif

      <!-- Description -->
      <div class="form-group">
        {!! Form::label('description', 'Description:') !!}
        {!! Form::textarea('description', null, ['class' => 'form-control', 'placeholder' => 'Description', 'rows' => 3]) !!}
      </div>

      @if(!empty($parent_categories) && $enable_sub_category)
      <!-- Parent Category Selector -->
      <div class="form-group hide" id="parent_cat_div">
        {!! Form::label('parent_id', 'Select Parent Category:') !!}
        {!! Form::select('parent_id', $parent_categories, null, ['class' => 'form-control']); !!}
      </div>
      @endif
    </div>

    <div class="modal-footer">
      <button type="submit" class="btn btn-primary">Save</button>
      <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
    </div>

    {!! Form::close() !!}

  </div><!-- /.modal-content -->
</div><!-- /.modal-dialog -->

<script>
  $(document).ready(function() {
    // Show/hide subcategory section based on checkbox
    $('.toggler').change(function() {
      var toggle_id = $(this).data('toggle_id');
      var isChecked = $(this).is(':checked');
      
      $('#' + toggle_id).toggleClass('hide', !isChecked);

      // Show/hide single category name field based on checkbox
      $('#single_category_name').toggleClass('hide', isChecked);
      $('#parent_cat_div').toggleClass('hide', !isChecked); // Show parent category selector when subcategory is selected

      // Set required attribute for single category field based on visibility
      if (isChecked) {
        $('#name').removeAttr('required');
      } else {
        $('#name').attr('required', true);
      }
    });

    // Add new subcategory input field
    $('#add_subcategory').click(function() {
      var newField = `<div class="subcategory_group">
                        {!! Form::text('subcategories[]', null, ['class' => 'form-control', 'placeholder' => 'Subcategory Name']) !!}
                        <button type="button" class="remove_subcategory btn btn-danger">Remove</button>
                      </div>`;
      $('#subcategory_fields').append(newField);
    });

    // Remove a subcategory input field
    $(document).on('click', '.remove_subcategory', function() {
      $(this).closest('.subcategory_group').remove();
    });
  });
</script>

