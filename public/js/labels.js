$(document).ready(function() {
    $('table#product_table tbody').find('.label-date-picker').each( function(){
        $(this).datepicker({
            autoclose: true
        });
    });
    //Add products
    if ($('#search_product_for_label').length > 0) {
        $('#search_product_for_label')
            .autocomplete({
                source: '/purchases/get_products?check_enable_stock=false',
                minLength: 2,
                response: function(event, ui) {
                    // Zak's ask 2026-04-21: stop popping the picker when
                    // multiple products share a name but print the SAME
                    // price (Studio One / MJ duplicates with matching
                    // \$25.00 tags). When prices differ (\$25 vs \$27)
                    // we still need to ask — printing the wrong price on
                    // a shelf label is worse than one extra click.
                    if (ui.content.length == 0) {
                        swal(LANG.no_products_found);
                        return;
                    }
                    if (ui.content.length == 1) {
                        ui.item = ui.content[0];
                        $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', ui);
                        $(this).autocomplete('close');
                        return;
                    }
                    // Multi-match: extract price tokens from each result's
                    // display text. The endpoint builds text as
                    // "<name> - <sku> - <price> - <category>", so the 3rd
                    // hyphen-separated chunk is reliably the price.
                    var uniquePrices = {};
                    for (var k = 0; k < ui.content.length; k++) {
                        var parts = (ui.content[k].text || '').split(' - ');
                        var priceChunk = parts.length >= 3 ? parts[2].trim() : '';
                        var m = priceChunk.match(/[\d,]+(\.\d+)?/);
                        var p = m ? m[0].replace(/,/g, '') : '';
                        uniquePrices[p] = true;
                    }
                    if (Object.keys(uniquePrices).length <= 1) {
                        // All matches print the same price → auto-pick first.
                        ui.item = ui.content[0];
                        $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', ui);
                        $(this).autocomplete('close');
                    }
                    // Otherwise: prices differ → fall through to the default
                    // jQuery UI picker so the cashier chooses explicitly.
                },
                select: function(event, ui) {
                    $(this).val(null);
                    get_label_product_row(ui.item.product_id, ui.item.variation_id);
                },
            })
            .autocomplete('instance')._renderItem = function(ul, item) {
            return $('<li>')
                .append('<div>' + item.text + '</div>')
                .appendTo(ul);
        };
    }

    $('input#is_show_price').change(function() {
        if ($(this).is(':checked')) {
            $('div#price_type_div').show();
        } else {
            $('div#price_type_div').hide();
        }
    });

    $('button#labels_preview').click(function() {
        if ($('form#preview_setting_form table#product_table tbody tr').length > 0) {
            // POST keeps all print[] / products[] fields (GET URLs can truncate and drop purchase_date, etc.)
            var form = document.getElementById('preview_setting_form');
            if (!form) {
                return;
            }
            var prevAction = form.getAttribute('action') || '';
            var prevTarget = form.getAttribute('target');
            form.setAttribute('action', base_path + '/labels/preview');
            form.setAttribute('method', 'post');
            form.setAttribute('target', '_blank');
            form.submit();
            form.setAttribute('action', prevAction || '#');
            if (prevTarget) {
                form.setAttribute('target', prevTarget);
            } else {
                form.removeAttribute('target');
            }

            // $.ajax({
            //     method: 'get',
            //     url: '/labels/preview',
            //     dataType: 'json',
            //     data: $('form#preview_setting_form').serialize(),
            //     success: function(result) {
            //         if (result.success) {
            //             $('div.display_label_div').removeClass('hide');
            //             $('div#preview_box').html(result.html);
            //             __currency_convert_recursively($('div#preview_box'));
            //         } else {
            //             toastr.error(result.msg);
            //         }
            //     },
            // });
        } else {
            swal(LANG.label_no_product_error).then(value => {
                $('#search_product_for_label').focus();
            });
        }
    });

    $(document).on('click', 'button#print_label', function() {
        window.print();
    });
    
    // Handle Delete Button Click
    $(document).on('click', '.delete-product', function () {
        // Get the row ID from the button's data attribute
        var rowId = $(this).data('row-id');

        // Remove the respective row
        $('#' + rowId).remove();
    });
});

function get_label_product_row(product_id, variation_id) {
    if (product_id) {
        var row_count = $('table#product_table tbody tr').length;
        $.ajax({
            method: 'GET',
            url: '/labels/add-product-row',
            dataType: 'html',
            data: { product_id: product_id, row_count: row_count, variation_id: variation_id },
            success: function(result) {
                $('table#product_table tbody').append(result);

                $('table#product_table tbody').find('.label-date-picker').each( function(){
                    $(this).datepicker({
                        autoclose: true
                    });
                });
            },
        });
    }
}
