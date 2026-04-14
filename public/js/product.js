//This file contains all functions used products tab

$(document).ready(function() {
    // True when editing an existing product (do not auto-overwrite selling price from purchase/margin)
    // Use #type data-action first: it's always present on edit page; form/data may not be in scope when form part is AJAX-loaded
    function is_product_edit() {
        return ($('#type').data('action') === 'edit') ||
            ($('#product_add_form').length && $('#product_add_form').data('productEdit') == 1) ||
            ($('#product_id').length && $('#product_id').val());
    }

    $(document).on('ifChecked', 'input#enable_stock', function() {
        $('div#alert_quantity_div').show();
        $('div#quick_product_opening_stock_div').show();

        //Enable expiry selection
        if ($('#expiry_period_type').length) {
            $('#expiry_period_type').removeAttr('disabled');
        }

        if ($('#opening_stock_button').length) {
            $('#opening_stock_button').removeAttr('disabled');
        }
    });
    $(document).on('ifUnchecked', 'input#enable_stock', function() {
        $('div#alert_quantity_div').hide();
        $('div#quick_product_opening_stock_div').hide();
        $('input#alert_quantity').val(0);

        //Disable expiry selection
        if ($('#expiry_period_type').length) {
            $('#expiry_period_type')
                .val('')
                .change();
            $('#expiry_period_type').attr('disabled', true);
        }
        if ($('#opening_stock_button').length) {
            $('#opening_stock_button').attr('disabled', true);
        }
    });

    //Start For product type single

    //If purchase price exc tax is changed
    $(document).on('change', 'input#single_dpp', function(e) {
        var purchase_exc_tax = __read_number($('input#single_dpp'));
        purchase_exc_tax = purchase_exc_tax == undefined ? 0 : purchase_exc_tax;

        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var purchase_inc_tax = __add_percent(purchase_exc_tax, tax_rate);
        __write_number($('input#single_dpp_inc_tax'), purchase_inc_tax);

        // On edit page: do not overwrite selling price when user blurs purchase price (avoids accidental overwrite)
        if (!is_product_edit()) {
            var profit_percent = __read_number($('#profit_percent'));
            var selling_price = __add_percent(purchase_exc_tax, profit_percent);
            __write_number($('input#single_dsp'), selling_price);

            var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
            __write_number($('input#single_dsp_inc_tax'), selling_price_inc_tax);
        }
    });

    //If tax rate is changed
    $(document).on('change', 'select#tax', function() {
        if ($('select#type').val() == 'single') {
            var purchase_exc_tax = __read_number($('input#single_dpp'));
            purchase_exc_tax = purchase_exc_tax == undefined ? 0 : purchase_exc_tax;

            var tax_rate = $('select#tax')
                .find(':selected')
                .data('rate');
            tax_rate = tax_rate == undefined ? 0 : tax_rate;

            var purchase_inc_tax = __add_percent(purchase_exc_tax, tax_rate);
            __write_number($('input#single_dpp_inc_tax'), purchase_inc_tax);

            var selling_price = __read_number($('input#single_dsp'));
            var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
            __write_number($('input#single_dsp_inc_tax'), selling_price_inc_tax);
        }
    });

    //If purchase price inc tax is changed
    $(document).on('change', 'input#single_dpp_inc_tax', function(e) {
        var purchase_inc_tax = __read_number($('input#single_dpp_inc_tax'));
        purchase_inc_tax = purchase_inc_tax == undefined ? 0 : purchase_inc_tax;

        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var purchase_exc_tax = __get_principle(purchase_inc_tax, tax_rate);
        __write_number($('input#single_dpp'), purchase_exc_tax);
        $('input#single_dpp').change();

        // On edit page: do not overwrite selling price when user blurs purchase price
        if (!is_product_edit()) {
            var profit_percent = __read_number($('#profit_percent'));
            profit_percent = profit_percent == undefined ? 0 : profit_percent;
            var selling_price = __add_percent(purchase_exc_tax, profit_percent);
            __write_number($('input#single_dsp'), selling_price);

            var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
            __write_number($('input#single_dsp_inc_tax'), selling_price_inc_tax);
        }
    });

    $(document).on('change', 'input#profit_percent', function(e) {
        // On edit page: do not overwrite selling price when user changes profit %
        if (is_product_edit()) return;

        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var purchase_inc_tax = __read_number($('input#single_dpp_inc_tax'));
        purchase_inc_tax = purchase_inc_tax == undefined ? 0 : purchase_inc_tax;

        var purchase_exc_tax = __read_number($('input#single_dpp'));
        purchase_exc_tax = purchase_exc_tax == undefined ? 0 : purchase_exc_tax;

        var profit_percent = __read_number($('input#profit_percent'));
        var selling_price = __add_percent(purchase_exc_tax, profit_percent);
        __write_number($('input#single_dsp'), selling_price);

        var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
        __write_number($('input#single_dsp_inc_tax'), selling_price_inc_tax);
    });

    $(document).on('change', 'input#single_dsp', function(e) {
        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var selling_price = __read_number($('input#single_dsp'));
        var purchase_exc_tax = __read_number($('input#single_dpp'));
        var profit_percent = __read_number($('input#profit_percent'));

        //if purchase price not set
        if (purchase_exc_tax == 0) {
            profit_percent = 0;
        } else {
            profit_percent = __get_rate(purchase_exc_tax, selling_price);
        }

        __write_number($('input#profit_percent'), profit_percent);

        var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
        __write_number($('input#single_dsp_inc_tax'), selling_price_inc_tax);
    });

    $(document).on('change', 'input#single_dsp_inc_tax', function(e) {
        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;
        var selling_price_inc_tax = __read_number($('input#single_dsp_inc_tax'));

        var selling_price = __get_principle(selling_price_inc_tax, tax_rate);
        __write_number($('input#single_dsp'), selling_price);
        var purchase_exc_tax = __read_number($('input#single_dpp'));
        var profit_percent = __read_number($('input#profit_percent'));

        //if purchase price not set
        if (purchase_exc_tax == 0) {
            profit_percent = 0;
        } else {
            profit_percent = __get_rate(purchase_exc_tax, selling_price);
        }

        __write_number($('input#profit_percent'), profit_percent);
    });

    if ($('#product_add_form').length) {
        $('form#product_add_form').validate({
            rules: {
                sku: {
                    remote: {
                        url: '/products/check_product_sku',
                        type: 'post',
                        data: {
                            sku: function() {
                                return $('#sku').val();
                            },
                            product_id: function() {
                                if ($('#product_id').length > 0) {
                                    return $('#product_id').val();
                                } else {
                                    return '';
                                }
                            },
                        },
                    },
                },
                category_combo: {
                    required: true,
                },
                expiry_period: {
                    required: {
                        depends: function(element) {
                            return (
                                $('#expiry_period_type')
                                    .val()
                                    .trim() != ''
                            );
                        },
                    },
                },
            },
            messages: {
                sku: {
                    remote: LANG.sku_already_exists,
                },
                category_combo: {
                    required: LANG.required,
                },
            },
        });
    }

    // (Set current stock quick modal removed; now handled by dedicated page)

    // Unified Category + Subcategory dropdown (Add/Edit product)
    function __tokenizeCategorySearch(text) {
        if (text === undefined || text === null) return [];
        return String(text)
            .toLowerCase()
            .trim()
            .split(/[^a-z0-9]+/g)
            .filter(Boolean);
    }

    function __categoryComboMatcher(params, data) {
        if (!data || !data.text) return data;

        var term = params && params.term ? String(params.term).trim().toLowerCase() : '';
        if (term === '') return data;

        var labelText = String(data.text || '').toLowerCase();
        var tokens = __tokenizeCategorySearch(term);
        if (!tokens.length) return data;

        var words = labelText.match(/[a-z0-9]+/g) || [];
        var matchedAll = tokens.every(function(tok) {
            return labelText.indexOf(tok) !== -1 || words.some(function(w) { return w.indexOf(tok) === 0; });
        });

        return matchedAll ? data : null;
    }

    function applyCategoryComboMatcher($el) {
        if (!$el || !$el.length) return;
        var selected = $el.val();
        try {
            if ($el.data('select2')) {
                $el.select2('destroy');
            }
        } catch (e) {}

        $el.select2({
            matcher: __categoryComboMatcher
        });

        if (selected !== null && selected !== undefined && selected !== '') {
            $el.val(selected).trigger('change.select2');
        }
    }

    function syncCategoryComboSelection($combo) {
        var $selected = $combo.find('option:selected');
        var categoryId = $selected.data('category-id') || '';
        var subCategoryId = $selected.data('sub-category-id') || '';

        $('#category_id').val(categoryId);
        $('#sub_category_id').val(subCategoryId);
    }

    if ($('#category_combo').length) {
        applyCategoryComboMatcher($('#category_combo'));
        // Initial sync on page load
        syncCategoryComboSelection($('#category_combo'));
    }

    $(document).on('change', '#category_combo', function() {
        syncCategoryComboSelection($(this));
        // Trigger validation if present
        if ($(this).closest('form').length && $(this).valid) {
            $(this).valid();
        }
    });

    $(document).on('click', '.submit_product_form', function(e) {
        e.preventDefault();

        var is_valid_product_form = true;

        var variation_skus = [];

        $('#product_form_part').find('.input_sub_sku').each( function(){
            var element = $(this);
            var row_variation_id = '';
            if ($(this).closest('tr').find('.row_variation_id')) {
                row_variation_id = $(this).closest('tr').find('.row_variation_id').val();
            }

            variation_skus.push({sku: element.val(), variation_id: row_variation_id});
            
        });

        if (variation_skus.length > 0) {
            $.ajax({
                method: 'post',
                url: '/products/validate_variation_skus',
                data: { skus: variation_skus},
                success: function(result) {
                    if (result.success == true) {
                        var submit_type = $(this).attr('value');
                        $('#submit_type').val(submit_type);
                        if ($('form#product_add_form').valid()) {
                            $('form#product_add_form').submit();
                        }
                    } else {
                        toastr.error(__translate('skus_already_exists', {sku: result.sku}));
                        return false;
                    }
                },
            });
        } else {
            var submit_type = $(this).attr('value');
            $('#submit_type').val(submit_type);
            if ($('form#product_add_form').valid()) {
                $('form#product_add_form').submit();
            }
        }
        
    });
    //End for product type single

    //Start for product type Variable
    //If purchase price exc tax is changed
    $(document).on('change', 'input.variable_dpp', function(e) {
        var tr_obj = $(this).closest('tr');

        var purchase_exc_tax = __read_number($(this));
        purchase_exc_tax = purchase_exc_tax == undefined ? 0 : purchase_exc_tax;

        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var purchase_inc_tax = __add_percent(purchase_exc_tax, tax_rate);
        __write_number(tr_obj.find('input.variable_dpp_inc_tax'), purchase_inc_tax);

        // On edit page: do not overwrite selling price when user blurs purchase price
        if (!is_product_edit()) {
            var profit_percent = __read_number(tr_obj.find('input.variable_profit_percent'));
            var selling_price = __add_percent(purchase_exc_tax, profit_percent);
            __write_number(tr_obj.find('input.variable_dsp'), selling_price);

            var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
            __write_number(tr_obj.find('input.variable_dsp_inc_tax'), selling_price_inc_tax);
        }
    });

    //If purchase price inc tax is changed
    $(document).on('change', 'input.variable_dpp_inc_tax', function(e) {
        var tr_obj = $(this).closest('tr');

        var purchase_inc_tax = __read_number($(this));
        purchase_inc_tax = purchase_inc_tax == undefined ? 0 : purchase_inc_tax;

        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var purchase_exc_tax = __get_principle(purchase_inc_tax, tax_rate);
        __write_number(tr_obj.find('input.variable_dpp'), purchase_exc_tax);

        // On edit page: do not overwrite selling price when user blurs purchase price
        if (!is_product_edit()) {
            var profit_percent = __read_number(tr_obj.find('input.variable_profit_percent'));
            var selling_price = __add_percent(purchase_exc_tax, profit_percent);
            __write_number(tr_obj.find('input.variable_dsp'), selling_price);

            var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
            __write_number(tr_obj.find('input.variable_dsp_inc_tax'), selling_price_inc_tax);
        }
    });

    $(document).on('change', 'input.variable_profit_percent', function(e) {
        // On edit page: do not overwrite selling price when user changes profit %
        if (is_product_edit()) return;

        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var tr_obj = $(this).closest('tr');
        var profit_percent = __read_number($(this));

        var purchase_exc_tax = __read_number(tr_obj.find('input.variable_dpp'));
        purchase_exc_tax = purchase_exc_tax == undefined ? 0 : purchase_exc_tax;

        var selling_price = __add_percent(purchase_exc_tax, profit_percent);
        __write_number(tr_obj.find('input.variable_dsp'), selling_price);

        var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
        __write_number(tr_obj.find('input.variable_dsp_inc_tax'), selling_price_inc_tax);
    });

    $(document).on('change', 'input.variable_dsp', function(e) {
        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var tr_obj = $(this).closest('tr');
        var selling_price = __read_number($(this));
        var purchase_exc_tax = __read_number(tr_obj.find('input.variable_dpp'));

        var profit_percent = __read_number(tr_obj.find('input.variable_profit_percent'));

        //if purchase price not set
        if (purchase_exc_tax == 0) {
            profit_percent = 0;
        } else {
            profit_percent = __get_rate(purchase_exc_tax, selling_price);
        }

        __write_number(tr_obj.find('input.variable_profit_percent'), profit_percent);

        var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
        __write_number(tr_obj.find('input.variable_dsp_inc_tax'), selling_price_inc_tax);
    });
    $(document).on('change', 'input.variable_dsp_inc_tax', function(e) {
        var tr_obj = $(this).closest('tr');
        var selling_price_inc_tax = __read_number($(this));

        var tax_rate = $('select#tax')
            .find(':selected')
            .data('rate');
        tax_rate = tax_rate == undefined ? 0 : tax_rate;

        var selling_price = __get_principle(selling_price_inc_tax, tax_rate);
        __write_number(tr_obj.find('input.variable_dsp'), selling_price);

        var purchase_exc_tax = __read_number(tr_obj.find('input.variable_dpp'));
        var profit_percent = __read_number(tr_obj.find('input.variable_profit_percent'));
        //if purchase price not set
        if (purchase_exc_tax == 0) {
            profit_percent = 0;
        } else {
            profit_percent = __get_rate(purchase_exc_tax, selling_price);
        }

        __write_number(tr_obj.find('input.variable_profit_percent'), profit_percent);
    });

    $(document).on('click', '.add_variation_value_row', function() {
        var variation_row_index = $(this)
            .closest('.variation_row')
            .find('.row_index')
            .val();
        var variation_value_row_index = $(this)
            .closest('table')
            .find('tr:last .variation_row_index')
            .val();

        if (
            $(this)
                .closest('.variation_row')
                .find('.row_edit').length >= 1
        ) {
            var row_type = 'edit';
        } else {
            var row_type = 'add';
        }

        var table = $(this).closest('table');

        $.ajax({
            method: 'GET',
            url: '/products/get_variation_value_row',
            data: {
                variation_row_index: variation_row_index,
                value_index: variation_value_row_index,
                row_type: row_type,
            },
            dataType: 'html',
            success: function(result) {
                if (result) {
                    table.append(result);
                    toggle_dsp_input();
                }
            },
        });
    });

    $(document).on('change', '.variation_template', function() {
        tr_obj = $(this).closest('tr');

        if ($(this).val() !== '') {
            tr_obj.find('input.variation_name').val(
                $(this)
                    .find('option:selected')
                    .text()
            );

            var template_id = $(this).val();
            var row_index = $(this)
                .closest('tr')
                .find('.row_index')
                .val();
            $.ajax({
                method: 'POST',
                url: '/products/get_variation_template',
                dataType: 'html',
                data: { template_id: template_id, row_index: row_index },
                success: function(result) {
                    if (result) {
                        tr_obj
                            .find('table.variation_value_table')
                            .find('tbody')
                            .html(result);

                        toggle_dsp_input();
                    }
                },
            });
        }
    });

    $(document).on('click', '.remove_variation_value_row', function() {
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(willDelete => {
            if (willDelete) {
                var count = $(this)
                    .closest('table')
                    .find('.remove_variation_value_row').length;
                if (count === 1) {
                    $(this)
                        .closest('.variation_row')
                        .remove();
                } else {
                    $(this)
                        .closest('tr')
                        .remove();
                }
            }
        });
    });

    //If tax rate is changed
    $(document).on('change', 'select#tax', function() {
        if ($('select#type').val() == 'variable') {
            var tax_rate = $('select#tax')
                .find(':selected')
                .data('rate');
            tax_rate = tax_rate == undefined ? 0 : tax_rate;

            $('table.variation_value_table > tbody').each(function() {
                $(this)
                    .find('tr')
                    .each(function() {
                        var purchase_exc_tax = __read_number($(this).find('input.variable_dpp'));
                        purchase_exc_tax = purchase_exc_tax == undefined ? 0 : purchase_exc_tax;

                        var purchase_inc_tax = __add_percent(purchase_exc_tax, tax_rate);
                        __write_number(
                            $(this).find('input.variable_dpp_inc_tax'),
                            purchase_inc_tax
                        );

                        var selling_price = __read_number($(this).find('input.variable_dsp'));
                        var selling_price_inc_tax = __add_percent(selling_price, tax_rate);
                        __write_number(
                            $(this).find('input.variable_dsp_inc_tax'),
                            selling_price_inc_tax
                        );
                    });
            });
        }
    });
    //End for product type Variable
    $(document).on('change', '#tax_type', function(e) {
        toggle_dsp_input();
    });
    toggle_dsp_input();

    $(document).on('change', '#expiry_period_type', function(e) {
        if ($(this).val()) {
            $('input#expiry_period').prop('disabled', false);
        } else {
            $('input#expiry_period').val('');
            $('input#expiry_period').prop('disabled', true);
        }
    });

    $(document).on('click', 'a.view-product', function(e) {
        e.preventDefault();
        $.ajax({
            url: $(this).attr('href'),
            dataType: 'html',
            success: function(result) {
                $('#view_product_modal')
                    .html(result)
                    .modal('show');
                __currency_convert_recursively($('#view_product_modal'));
            },
        });
    });
    var img_fileinput_setting = {
        showUpload: false,
        showPreview: true,
        browseLabel: LANG.file_browse_label,
        removeLabel: LANG.remove,
        previewSettings: {
            image: { width: 'auto', height: 'auto', 'max-width': '100%', 'max-height': '100%' },
        },
    };
    $('#upload_image').fileinput(img_fileinput_setting);

    if ($('textarea#product_description').length > 0) {
        tinymce.init({
            selector: 'textarea#product_description',
            height:250
        });
    }
});

function toggle_dsp_input() {
    var tax_type = $('#tax_type').val();
    if (tax_type == 'inclusive') {
        $('.dsp_label').each(function() {
            $(this).text(LANG.inc_tax);
        });
        $('#single_dsp').addClass('hide');
        $('#single_dsp_inc_tax').removeClass('hide');

        $('.add-product-price-table')
            .find('.variable_dsp_inc_tax')
            .each(function() {
                $(this).removeClass('hide');
            });
        $('.add-product-price-table')
            .find('.variable_dsp')
            .each(function() {
                $(this).addClass('hide');
            });
    } else if (tax_type == 'exclusive') {
        $('.dsp_label').each(function() {
            $(this).text(LANG.exc_tax);
        });
        $('#single_dsp').removeClass('hide');
        $('#single_dsp_inc_tax').addClass('hide');

        $('.add-product-price-table')
            .find('.variable_dsp_inc_tax')
            .each(function() {
                $(this).addClass('hide');
            });
        $('.add-product-price-table')
            .find('.variable_dsp')
            .each(function() {
                $(this).removeClass('hide');
            });
    }
}

function get_product_details(rowData) {
    var div = $('<div/>')
        .addClass('loading')
        .text('Loading...');

    $.ajax({
        url: '/products/' + rowData.id,
        dataType: 'html',
        success: function(data) {
            div.html(data).removeClass('loading');
        },
    });

    return div;
}

//Quick add unit
$(document).on('submit', 'form#quick_add_unit_form', function(e) {
    e.preventDefault();
    var form = $(this);
    var data = form.serialize();

    $.ajax({
        method: 'POST',
        url: $(this).attr('action'),
        dataType: 'json',
        data: data,
        beforeSend: function(xhr) {
            __disable_submit_button(form.find('button[type="submit"]'));
        },
        success: function(result) {
            if (result.success == true) {
                var newOption = new Option(result.data.short_name, result.data.id, true, true);
                // Append it to the select
                $('#unit_id')
                    .append(newOption)
                    .trigger('change');
                $('div.view_modal').modal('hide');
                toastr.success(result.msg);
            } else {
                toastr.error(result.msg);
            }
        },
    });
});

//Quick add brand
$(document).on('submit', 'form#quick_add_brand_form', function(e) {
    e.preventDefault();
    var form = $(this);
    var data = form.serialize();

    $.ajax({
        method: 'POST',
        url: $(this).attr('action'),
        dataType: 'json',
        data: data,
        beforeSend: function(xhr) {
            __disable_submit_button(form.find('button[type="submit"]'));
        },
        success: function(result) {
            if (result.success == true) {
                var newOption = new Option(result.data.name, result.data.id, true, true);
                // Append it to the select
                $('#brand_id')
                    .append(newOption)
                    .trigger('change');
                $('div.view_modal').modal('hide');
                toastr.success(result.msg);
            } else {
                toastr.error(result.msg);
            }
        },
    });
});

$(document).on('click', 'button.apply-all', function(){
    var val = $(this).closest('.input-group').find('input').val();
    var target_class = $(this).data('target-class');
    $(this).closest('tbody').find('tr').each( function(){
        element =  $(this).find(target_class);
        element.val(val);
        element.change();
    });
});

// Artist/title text autocomplete (DB first, API fallback for artists).
(function() {
    /**
     * jQuery UI Menu binds delegated mouseenter on .ui-menu-item → menu.focus → autocomplete menufocus.
     * That fills the input on hover. Remove that binding; click + keyboard still work.
     * jQuery 3 needs .off(events, '.ui-menu-item'); namespace-only .off() often misses delegates.
     */
    function detachArtistTitleMenuMouseenter(inst) {
        if (!inst || !inst.menu || !inst.menu.element) {
            return;
        }
        var el = inst.menu.element;
        var m = inst.menu;
        var ns = m.eventNamespace || '';
        if (typeof m._off === 'function') {
            try {
                m._off(el, 'mouseenter');
            } catch (e) {}
        }
        if (ns) {
            el.off('mouseenter' + ns, '.ui-menu-item');
            el.off('mouseenter' + ns);
        }
        el.off('mouseenter', '.ui-menu-item');
        el.off('mouseenter');
    }

    function patchArtistTitleAutocompleteResponse(inst) {
        if (!inst || inst._artistTitleNoHoverPatched || typeof inst.__response !== 'function') {
            return;
        }
        inst._artistTitleNoHoverPatched = true;
        var orig = inst.__response;
        inst.__response = function(content) {
            orig.apply(this, arguments);
            detachArtistTitleMenuMouseenter(this);
        };
    }

    function initArtistTitleAutocomplete($input) {
        if (!$input || !$input.length || $input.data('artistTitleAutocompleteInit')) {
            return;
        }
        if (!(typeof $.ui !== 'undefined' && $.ui.autocomplete)) {
            return;
        }

        var type = $input.hasClass('artist-autocomplete-input') ? 'artist' : 'title';

        $input.autocomplete({
            minLength: 1,
            delay: 180,
            source: function(request, response) {
                $input.data('acSuggestionBaseTerm', request.term);
                $.getJSON('/products/autocomplete-suggestions', {
                    type: type,
                    q: request.term,
                    limit: 20
                }).done(function(data) {
                    response(Array.isArray(data) ? data : []);
                }).fail(function() {
                    response([]);
                });
            },
            open: function() {
                detachArtistTitleMenuMouseenter($input.data('ui-autocomplete'));
            },
            response: function() {
                detachArtistTitleMenuMouseenter($input.data('ui-autocomplete'));
            },
            focus: function(event, ui) {
                event.preventDefault();
                var oe = event.originalEvent;
                var fromKeyboard = oe && /^key/i.test(oe.type);
                if (fromKeyboard) {
                    return;
                }
                var base = $input.data('acSuggestionBaseTerm');
                if (base == null) {
                    return;
                }
                var ac = $input.data('ui-autocomplete');
                if (ac) {
                    ac._value(String(base));
                }
            },
            select: function(event, ui) {
                event.preventDefault();
                $(this).val(ui.item.value || ui.item.label || '');
                $(this).trigger('change');
            }
        });

        patchArtistTitleAutocompleteResponse($input.data('ui-autocomplete'));

        $input.data('artistTitleAutocompleteInit', true);
    }

    function initArtistTitleAutocompleteInScope($root) {
        var $ctx = $root && $root.length ? $root : $(document);
        $ctx.find('input.artist-autocomplete-input, input.title-autocomplete-input').each(function() {
            initArtistTitleAutocomplete($(this));
        });
    }

    $(document).on('focusin', 'input.artist-autocomplete-input, input.title-autocomplete-input', function() {
        initArtistTitleAutocomplete($(this));
    });

    $(function() {
        initArtistTitleAutocompleteInScope($(document));
    });

    window.initArtistTitleAutocompleteFields = initArtistTitleAutocompleteInScope;
})();

// Product Entry Rules resolver (title/category -> artist/prices/category)
(function() {
    function readFirst($scope, selectors) {
        for (var i = 0; i < selectors.length; i++) {
            var $el = $scope.find(selectors[i]).first();
            if ($el.length) {
                return $el;
            }
        }
        return $();
    }

    function resolveProductEntryRule(title, category_id, sub_category_id, callback) {
        $.getJSON('/settings/product-entry-rules/resolve', {
            title: title || '',
            category_id: category_id || '',
            sub_category_id: sub_category_id || ''
        }).done(function(resp) {
            if (resp && resp.success && resp.rule && typeof callback === 'function') {
                callback(resp.rule);
            }
        });
    }

    function applyRuleToScope($scope, rule) {
        if (!$scope || !$scope.length || !rule) {
            return;
        }
        if (rule.artist) {
            var $artist = readFirst($scope, ['input.artist-autocomplete-input', 'input[name="artist"]', 'input[name*="[artist]"]']);
            if ($artist.length && !$artist.val()) {
                $artist.val(rule.artist).trigger('change');
            }
        }
        if (rule.purchase_price !== null && rule.purchase_price !== undefined && rule.purchase_price !== '') {
            var $pp = readFirst($scope, ['input[name="single_dpp_inc_tax"]', 'input[name*="[single_dpp_inc_tax]"]']);
            if ($pp.length && !$pp.val()) {
                $pp.val(rule.purchase_price).trigger('change');
            }
        }
        if (rule.selling_price !== null && rule.selling_price !== undefined && rule.selling_price !== '') {
            var $sp = readFirst($scope, ['input[name="single_dsp_inc_tax"]', 'input[name*="[single_dsp_inc_tax]"]']);
            if ($sp.length && !$sp.val()) {
                $sp.val(rule.selling_price).trigger('change');
            }
        }
    }

    $(document).on('blur', 'input[name="name"], .product-name-autocomplete, input[name*="[name]"]', function() {
        var $name = $(this);
        var title = String($name.val() || '').trim();
        if (!title) {
            return;
        }
        var $scope = $name.closest('form, .product-row, .manual_product_row');
        if (!$scope.length) {
            $scope = $(document);
        }

        var $cat = readFirst($scope, ['input[name="category_id"]', 'input[name*="[category_id]"]']);
        var $sub = readFirst($scope, ['input[name="sub_category_id"]', 'input[name*="[sub_category_id]"]']);
        var category_id = $cat.length ? $cat.val() : '';
        var sub_category_id = $sub.length ? $sub.val() : '';

        resolveProductEntryRule(title, category_id, sub_category_id, function(rule) {
            applyRuleToScope($scope, rule);
        });
    });
})();
