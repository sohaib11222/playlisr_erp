// Autosave for the label print queue (Sarah 2026-07-08).
// Zella lost 40+ scanned barcodes when Playlist went down mid-print: the
// queue lived only in the browser DOM, so a crash / refresh / ctrl+shift+r
// wiped it. We now mirror the queued rows to localStorage on every change
// and restore them on load. Purely client-side, no backend involved.
var LABEL_QUEUE_KEY = 'label_print_queue_v1';

// Copy each input/select's live value into its DOM attribute so that
// $.html() captures what the user actually typed/selected (typed values
// live on the .value property, not the value attribute).
function sync_label_row_values() {
    $('table#product_table tbody tr').each(function() {
        $(this).find('input').each(function() {
            this.setAttribute('value', this.value);
        });
        $(this).find('select').each(function() {
            var val = this.value;
            $(this).find('option').removeAttr('selected');
            $(this).find('option').filter(function() {
                return this.value === val;
            }).attr('selected', 'selected');
        });
    });
}

function save_label_queue() {
    try {
        if ($('table#product_table tbody tr').length === 0) {
            localStorage.removeItem(LABEL_QUEUE_KEY);
            return;
        }
        sync_label_row_values();
        localStorage.setItem(LABEL_QUEUE_KEY, $('table#product_table tbody').html());
    } catch (e) {
        // localStorage unavailable / full — fail silently, never block printing.
    }
}

function clear_label_queue() {
    try {
        localStorage.removeItem(LABEL_QUEUE_KEY);
    } catch (e) {}
}

function restore_label_queue() {
    try {
        // Only restore into an empty table so a fresh server render wins.
        if ($('table#product_table tbody tr').length > 0) {
            return;
        }
        var saved = localStorage.getItem(LABEL_QUEUE_KEY);
        if (!saved) {
            return;
        }
        $('table#product_table tbody').html(saved);
        $('table#product_table tbody').find('.label-date-picker').each(function() {
            $(this).datepicker({ autoclose: true });
        });
        var count = $('table#product_table tbody tr').length;
        if (count > 0 && typeof toastr !== 'undefined') {
            toastr.info('Restored ' + count + ' label' + (count === 1 ? '' : 's') +
                ' from your last session. Print or delete them to clear.');
        }
    } catch (e) {}
}

$(document).ready(function() {
    $('table#product_table tbody').find('.label-date-picker').each( function(){
        $(this).datepicker({
            autoclose: true
        });
    });

    // Bring back any queue left over from a crash/refresh, then keep it saved.
    restore_label_queue();
    // Persist as the user edits quantities, dates, and price groups.
    $(document).on('change keyup', 'table#product_table tbody input, table#product_table tbody select', function() {
        save_label_queue();
    });
    // Last-ditch save if the tab is closed or the page navigates away.
    $(window).on('beforeunload', save_label_queue);
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
                    // When a UPC is scanned, the same barcode lives on both
                    // the Sealed and the Used copy. Print the SEALED label —
                    // drop the Used matches so we don't tag a sealed item as
                    // used. Scoped to UPC (all-digit) input so name searches
                    // still surface both. Categories: "Sealed Vinyl",
                    // "Sealed CD", "Cassettes - Sealed", etc.
                    var typed = ($(this).val() || '').trim();
                    if (/^\d{8,}$/.test(typed) && ui.content.length > 1) {
                        var sealed = ui.content.filter(function (it) {
                            var cat = it.catname;
                            if (!cat) {
                                // Fallback: catname is the last " - " chunk of the label text.
                                var parts = (it.text || '').split(' - ');
                                cat = parts.length ? parts[parts.length - 1] : '';
                            }
                            return cat.toLowerCase().indexOf('sealed') !== -1;
                        });
                        if (sealed.length > 0) {
                            ui.content = sealed;
                        }
                    }
                    if (ui.content.length == 1) {
                        ui.item = ui.content[0];
                        $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', ui);
                        $(this).autocomplete('close');
                        return;
                    }
                    // The same-price auto-pick below is only meant for TRUE
                    // duplicates: multiple rows of the SAME title (e.g. a
                    // record listed twice). It must NOT collapse genuinely
                    // different titles that happen to share a price — e.g.
                    // "Jaco Pastorius" vs "Jaco Pastorius Invitation" both
                    // tagged $25 would always silently pick the first, so the
                    // other title could never be printed (Sarah 2026-07-06).
                    // If the matches span more than one distinct title, show
                    // the picker and let the user choose. The title is the 1st
                    // " - " chunk of the display text; strip any trailing
                    // " (variation)" so variations of one product still collapse.
                    var titleOf = function (it) {
                        var raw = ((it.text || '').split(' - ')[0] || '').trim();
                        return raw.replace(/\s*\([^()]*\)\s*$/, '').trim().toLowerCase();
                    };
                    var uniqueTitles = {};
                    for (var n = 0; n < ui.content.length; n++) {
                        uniqueTitles[titleOf(ui.content[n])] = true;
                    }
                    if (Object.keys(uniqueTitles).length > 1) {
                        // Distinct titles matched → let the menu render normally.
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
                        return;
                    }
                    // Prices differ: pick the most recently updated entry
                    // (Sarah 2026-04-21: the newest price is the current
                    // shelf price). Falls back to the first item if the
                    // server didn't surface updated_at.
                    var sorted = ui.content.slice().sort(function (a, b) {
                        var ta = Date.parse(a.variation_updated_at || 0) || 0;
                        var tb = Date.parse(b.variation_updated_at || 0) || 0;
                        return tb - ta;
                    });
                    ui.item = sorted[0];
                    $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', ui);
                    $(this).autocomplete('close');
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

        // Keep the saved queue in sync (clears storage once the last row goes).
        save_label_queue();
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

                // Autosave the queue every time a scanned product is added.
                save_label_queue();
            },
        });
    }
}
