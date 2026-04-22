$(document).ready(function() {
    customer_set = false;
    window.pos_submit_in_progress = false;
    //Prevent enter key function except texarea
    $('form').on('keyup keypress', function(e) {
        var keyCode = e.keyCode || e.which;
        if (keyCode === 13 && e.target.tagName != 'TEXTAREA') {
            e.preventDefault();
            return false;
        }
    });

    // Initialize default tax on page load
    var tax_rate_id = $('#tax_rate_id').val();
    var default_tax = $('#tax_rate_id').data('default');
    
    // If tax_rate_id is empty/null/0 but default exists, set it
    if ((!tax_rate_id || tax_rate_id === '' || tax_rate_id === null || tax_rate_id === '0' || tax_rate_id === 0) && 
        default_tax && default_tax !== '' && default_tax !== null && default_tax !== '0' && default_tax !== 0) {
        $('#tax_rate_id').val(default_tax);
        // Also set calculation amount from default
        var default_calc_amount = $('#tax_calculation_amount').data('default');
        if (default_calc_amount && default_calc_amount !== '' && default_calc_amount !== null) {
            $('#tax_calculation_amount').val(default_calc_amount);
        }
    }

    //For edit pos form
    if ($('form#edit_pos_sell_form').length > 0) {
        pos_total_row();
        pos_form_obj = $('form#edit_pos_sell_form');
    } else {
        pos_form_obj = $('form#add_pos_sell_form');
        // Recalculate totals on new POS form to ensure default tax is applied
        setTimeout(function() {
            pos_total_row();
            // If Add Bag Fee is prechecked, add the bag fee row
            if ($('#add_plastic_bag').length && $('#add_plastic_bag').is(':checked')) {
                $('#add_plastic_bag').trigger('change');
            }
        }, 100);
    }
    if ($('form#edit_pos_sell_form').length > 0 || $('form#add_pos_sell_form').length > 0) {
        initialize_printer();
    }

    $('select#select_location_id').change(function() {
        var selectedText = $(this).find(':selected').text();
        if ($('#pos_display_location_name').length) {
            $('#pos_display_location_name').text(selectedText || '');
        }
        reset_pos_form();

        var default_price_group = $(this).find(':selected').data('default_price_group')
        if (default_price_group) {
            if($("#price_group option[value='" + default_price_group + "']").length > 0) {
                $("#price_group").val(default_price_group);
                $("#price_group").change();
            }
        }

        //Set default invoice scheme for location
        if ($('#invoice_scheme_id').length) {
            let invoice_scheme_id = $(this).find(':selected').data('default_invoice_scheme_id');
            $("#invoice_scheme_id").val(invoice_scheme_id).change();
        }
        
        //Set default price group
        if ($('#default_price_group').length) {
            var dpg = default_price_group ?
            default_price_group : 0;
            $('#default_price_group').val(dpg);
        }

        set_payment_type_dropdown();

        if ($('#types_of_service_id').length && $('#types_of_service_id').val()) {
            $('#types_of_service_id').change();
        }
    });

    // Reset employee discount when customer changes
    $(document).on('select2:clear', '#customer_id', function() {
        $('#employee_discount_row').hide();
        $('#apply_employee_discount').prop('checked', false);
        $('#customer_id').data('employee_discount_applied', false);
        removeEmployeeDiscount();
    });
    
    //get customer
    // Sarah 2026-04-22: "clicking the customer search doesn't always work".
    // Root cause: select2's minimumInputLength was 1 but the backend
    // (/contacts/customers) requires 2+ chars and returns [] for a 1-char
    // query. So typing the first digit of a phone number fired an AJAX
    // that came back empty, select2 rendered the "Add X as new customer"
    // fallback, and cashiers thought the search was broken — until they
    // typed a second character. Aligning the frontend threshold to 2 and
    // showing a friendly "keep typing" hint for 0-1 chars so the UI
    // stops lying about "no results" at the start of every search.
    $('select#customer_id').select2({
        width: '100%',
        allowClear: true,
        // Sarah 2026-04-22 (v3): telling select2 it has a placeholder is
        // what finally fixes the "clicking shows a useless ✓ Phone #…
        // row" bug. Without this, the Laravel Form::select placeholder
        // (a blank <option value="">) gets treated as a regular result
        // and the dropdown opens showing it with a checkmark — leaving
        // cashiers staring at a dead row and no visible search input.
        // With placeholder set, select2 hides that empty option, so the
        // AJAX search input is the only thing in the dropdown and gets
        // the focus automatically.
        placeholder: 'Phone # (or name / email)…',
        dropdownCssClass: 'pos-customer-select2-dropdown',
        ajax: {
            url: '/contacts/customers',
            dataType: 'json',
            delay: 200,
            data: function(params) {
                return {
                    q: params.term, // search term
                    page: params.page,
                };
            },
            processResults: function(data) {
                return {
                    results: data,
                };
            },
            error: function (xhr) {
                // Surface server failures so a silent 500 stops looking
                // like "nothing matched".
                if (typeof toastr !== 'undefined') {
                    toastr.error('Customer search failed — try again. ' +
                        (xhr && xhr.status ? '(status ' + xhr.status + ')' : ''));
                }
            }
        },
        templateResult: function (data) {
            var template = '';
            if (data.supplier_business_name) {
                template += data.supplier_business_name + "<br>";
            }
            template += data.text + "<br>" + LANG.mobile + ": " + data.mobile;

            if (typeof(data.total_rp) != "undefined") {
                var rp = data.total_rp ? data.total_rp : 0;
                template += "<br><i class='fa fa-gift text-success'></i> " + rp;
            }

            return  template;
        },
        // Sarah 2026-04-22: "customer bar is confusing w the dropdown i want
        // it to be a simple box u type into not a dropdown". When the
        // walk-in customer is selected (the default for most sales), show
        // placeholder-style greyed-out prompt text instead of "Walk-In
        // Customer" — so the field reads as an empty search box cashiers
        // can type into, not as a pre-filled select widget. For real
        // customers, keep the selected name + phone so the snapshot above
        // the receipt stays clear about who's being rung up.
        templateSelection: function (data, container) {
            var defaultId = $('#default_customer_id').val();
            if (data && data.id && defaultId && String(data.id) === String(defaultId)) {
                $(container).addClass('is-walk-in-placeholder');
                return 'Type phone # or name to find a customer…';
            }
            $(container).removeClass('is-walk-in-placeholder');
            return data.text || '';
        },
        minimumInputLength: 2,
        language: {
            inputTooShort: function () {
                return 'Keep typing — 2+ letters or digits (phone, name, or contact ID)…';
            },
            searching: function () { return 'Searching customers…'; },
            noResults: function() {
                var name = $('#customer_id')
                    .data('select2')
                    .dropdown.$search.val();
                return (
                    '<button type="button" data-name="' +
                    name +
                    '" class="btn btn-link add_new_customer"><i class="fa fa-plus-circle fa-lg" aria-hidden="true"></i>&nbsp; ' +
                    __translate('add_name_as_new_customer', { name: name }) +
                    '</button>'
                );
            },
        },
        escapeMarkup: function(markup) {
            return markup;
        },
    });

    // Sarah 2026-04-22: belt-and-suspenders click handler for the customer
    // block. With the `placeholder` config on select2 (above), clicking the
    // field should open the dropdown with only the search input + the
    // "Keep typing…" hint — no dead placeholder row. This handler is the
    // belt: on any click in .pos-customer-block (including the .fa-user
    // addon + the dead zone next to the × clear button), force the
    // dropdown open and put focus in the search input. Excludes buttons
    // that have their own behavior (sign-up, clear-account, info panel).
    $(document).on('click', '.pos-customer-block', function(e) {
        var $tgt = $(e.target);
        if ($tgt.closest('.add_new_customer, #clear_customer_btn, #view_customer_details_btn, #customer_account_info, .select2-selection__clear').length) {
            return;
        }
        var $sel = $('select#customer_id');
        if (!$sel.length) { return; }
        var s2 = $sel.data('select2');
        if (!s2 || (s2.isOpen && !s2.isOpen())) {
            $sel.select2('open');
        }
        // Put focus where they expect it — the search input — so typing
        // immediately filters. select2 normally does this but rarely loses
        // the race against set_default_customer's change trigger.
        setTimeout(function() {
            $('.select2-container--open .select2-search__field').focus();
        }, 10);
    });

    // Clear selected account and immediately allow searching a different one.
    $(document).on('click', '#clear_customer_btn', function() {
        var $customerSelect = $('select#customer_id');
        if (!$customerSelect.length) {
            return;
        }

        $customerSelect.val(null).trigger('change');
        $('#customer_account_info').hide();
        $('#apply_employee_discount').prop('checked', false);
        $('#employee_discount_row').hide();
        $customerSelect.data('employee_discount_applied', false);
        $customerSelect.data('is_employee', false);

        $('#advance_balance').val(0);
        $('#advance_balance_text').text(__currency_trans_from_en(0, true));
        updatePosStoreCreditUI(0);

        setTimeout(function() {
            $customerSelect.select2('open');
        }, 20);
    });
    // Load customer account info when customer is selected
    function loadCustomerAccountInfo(contactId) {
        if (!contactId || contactId === '') {
            $('#customer_account_info').hide();
            return;
        }

        console.log('Loading customer account info for contact ID:', contactId);

        $.ajax({
            url: '/sells/pos/get-customer-account-info',
            type: 'GET',
            data: { contact_id: contactId },
            dataType: 'json',
            success: function(response) {
                console.log('Customer account info response:', response);
                if (response.success && response.data) {
                    var data = response.data;
                    var contact = data.contact;
                    var contactBalance = parseFloat(contact.balance || 0) || 0;

                    // Update account info display - make name clickable
                    var customerNameHtml = '<a href="#" class="customer-name-link" style="color: #495057; text-decoration: none; cursor: pointer; font-weight: bold;" data-contact-id="' + contact.id + '">' + 
                                          (contact.name || 'N/A') + ' <i class="fa fa-info-circle text-info"></i></a>';
                    $('#customer_account_name').html(customerNameHtml);
                    $('#customer_account_balance').text(__currency_trans_from_en(contactBalance, true));
                    $('#customer_gift_card_balance').text(__currency_trans_from_en(data.total_gift_card_balance || 0, true));
                    $('#customer_lifetime_purchases').text(__currency_trans_from_en(contact.lifetime_purchases || 0, true));
                    $('#customer_loyalty_points').text(contact.loyalty_points || 0);

                    // Keep payment modal's advance/store-credit in sync with latest customer balance.
                    $('#advance_balance_text').text(__currency_trans_from_en(contactBalance, true));
                    $('#advance_balance').val(contactBalance);

                    // Update POS store credit helper row
                    updatePosStoreCreditUI(contactBalance);

                    // Show the account info panel
                    $('#customer_account_info').show();
                    console.log('Customer account info displayed successfully');
                } else {
                    console.warn('Customer account info: No data or unsuccessful response', response);
                    $('#customer_account_info').hide();
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading customer account info:', error);
                console.error('Status:', status);
                console.error('Response status:', xhr.status);
                console.error('Response text:', xhr.responseText);
                console.error('Full response:', xhr);
                
                // Show error message to user
                if (xhr.status === 500) {
                    console.error('Server error - check if migrations are run (gift_cards table, loyalty fields)');
                }
                
                $('#customer_account_info').hide();
            }
        });
    }

    /**
     * Update the Store Credit Available row in POS totals.
     */
    function updatePosStoreCreditUI(balance) {
        var $row = $('#pos_store_credit_row');
        if (!$row.length) {
            return;
        }
        var amt = parseFloat(balance || 0) || 0;
        // Inline "Use it" pill sitting next to the Credit amount in the
        // customer snapshot — Sarah 2026-04-22 wanted it here, not buried in
        // the totals card. Show/hide in lock-step with the totals-card row.
        var $inline = $('#inline_use_store_credit_btn');
        if (amt > 0) {
            $('#pos_store_credit_amount').text(__currency_trans_from_en(amt, true));
            $('#btn_use_store_credit').data('credit-amount', amt);
            $row.show();
            if ($inline.length) { $inline.data('credit-amount', amt).show(); }
        } else {
            $('#pos_store_credit_amount').text(__currency_trans_from_en(0, true));
            $('#btn_use_store_credit').data('credit-amount', 0);
            $row.hide();
            if ($inline.length) { $inline.data('credit-amount', 0).hide(); }
        }
    }

    // Function to load and show customer details modal
    function showCustomerDetailsModal(contactId) {
        if (!contactId) {
            toastr.error('Please select a customer first');
            return;
        }

        // Show loading
        $('#customer_account_loading').show();
        $('#customer_account_content').hide();

        // Open modal
        $('#customer_account_modal').modal('show');

        // Load detailed customer info
        $.ajax({
            url: '/sells/pos/get-customer-account-info',
            type: 'GET',
            data: { contact_id: contactId },
            dataType: 'json',
            success: function(response) {
                $('#customer_account_loading').hide();
                
                if (response.success && response.data) {
                    var data = response.data;
                    var contact = data.contact;

                    // Update modal content
                    $('#modal_customer_name').text(contact.name);
                    $('#modal_account_balance').text(__currency_trans_from_en(contact.balance || 0, true));
                    $('#modal_lifetime_purchases').text(__currency_trans_from_en(contact.lifetime_purchases || 0, true));
                    $('#modal_loyalty_points').text(contact.loyalty_points || 0);
                    $('#modal_loyalty_tier').text(contact.loyalty_tier || 'Bronze');
                    $('#modal_last_purchase_date').text(contact.last_purchase_date || 'Never');
                    $('#modal_total_gift_card_balance').text(__currency_trans_from_en(data.total_gift_card_balance || 0, true));
                    $('#modal_store_credit_contact_id').val(contact.id);
                    $('#modal_store_credit_amount').val('');

                    // Update gift cards list
                    var giftCardsHtml = '';
                    if (data.gift_cards && data.gift_cards.length > 0) {
                        data.gift_cards.forEach(function(card) {
                            giftCardsHtml += '<p><strong>Card:</strong> ' + card.card_number + 
                                          ' | <strong>Balance:</strong> ' + __currency_trans_from_en(card.balance, true);
                            if (card.expiry_date) {
                                giftCardsHtml += ' | <strong>Expires:</strong> ' + card.expiry_date;
                            }
                            giftCardsHtml += '</p>';
                        });
                    } else {
                        giftCardsHtml = '<p class="text-muted">No active gift cards</p>';
                    }
                    $('#modal_gift_cards_list').html(giftCardsHtml);

                    // Update preorders
                    var preordersHtml = '';
                    var preorderCount = 0;
                    if (data.preorders && data.preorders.length > 0) {
                        preorderCount = data.preorders.length;
                        data.preorders.forEach(function(preorder) {
                            var productDisplay = preorder.product_name;
                            if (preorder.artist) {
                                productDisplay = preorder.artist + ' - ' + productDisplay;
                            }
                            preordersHtml += '<tr>' +
                                '<td>' + productDisplay + '</td>' +
                                '<td>' + (preorder.sub_sku || 'N/A') + '</td>' +
                                '<td>' + preorder.quantity + '</td>' +
                                '<td>' + preorder.order_date + '</td>' +
                                '<td>' + (preorder.expected_date || 'Not set') + '</td>' +
                                '</tr>';
                        });
                    } else {
                        preordersHtml = '<tr><td colspan="5" class="text-center text-muted">No pending preorders</td></tr>';
                    }
                    $('#modal_preorders_list').html(preordersHtml);
                    $('#modal_preorder_count').text('(' + preorderCount + ' preorder' + (preorderCount !== 1 ? 's' : '') + ')');

                    // Update purchase count
                    var totalPurchases = data.total_purchases_count || 0;
                    $('#modal_purchase_count').text('(' + totalPurchases + ' purchase' + (totalPurchases !== 1 ? 's' : '') + ')');
                    
                    // Update all purchases (full history)
                    var purchasesHtml = '';
                    if (data.all_purchases && data.all_purchases.length > 0) {
                        data.all_purchases.forEach(function(purchase) {
                            var itemsText = purchase.item_count + ' item' + (purchase.item_count !== 1 ? 's' : '');
                            var viewLink = '<a href="' + '/sells/' + purchase.id + '" target="_blank" class="btn btn-xs btn-info"><i class="fa fa-eye"></i> View</a>';
                            
                            purchasesHtml += '<tr>' +
                                '<td><strong>' + purchase.invoice_no + '</strong></td>' +
                                '<td>' + purchase.date + '</td>' +
                                '<td>' + itemsText + '</td>' +
                                '<td>' + __currency_trans_from_en(purchase.total, true) + '</td>' +
                                '<td><span class="label label-' + (purchase.payment_status === 'paid' ? 'success' : purchase.payment_status === 'partial' ? 'warning' : 'danger') + '">' + purchase.payment_status + '</span></td>' +
                                '<td>' + viewLink + '</td>' +
                                '</tr>';
                        });
                    } else {
                        purchasesHtml = '<tr><td colspan="6" class="text-center text-muted">No purchases found</td></tr>';
                    }
                    $('#modal_all_purchases_list').html(purchasesHtml);

                    $('#customer_account_content').show();
                } else {
                    toastr.error('Failed to load customer information');
                }
            },
            error: function() {
                $('#customer_account_loading').hide();
                toastr.error('Error loading customer information');
            }
        });
    }

    // View customer details button click
    $(document).on('click', '#view_customer_details_btn', function() {
        var contactId = $('#customer_id').val();
        showCustomerDetailsModal(contactId);
    });

    // Customer name click handler (delegated event for dynamically added elements)
    $(document).on('click', '.customer-name-link', function(e) {
        e.preventDefault();
        var contactId = $(this).data('contact-id');
        if (contactId) {
            showCustomerDetailsModal(contactId);
        } else {
            // Fallback: get from customer_id select
            contactId = $('#customer_id').val();
            showCustomerDetailsModal(contactId);
        }
    });

    // Add store credit directly from customer details modal
    $(document).on('click', '#modal_add_store_credit_btn', function() {
        var contactId = $('#modal_store_credit_contact_id').val();
        var amount = parseFloat($('#modal_store_credit_amount').val()) || 0;

        if (!contactId) {
            toastr.error('Customer not selected.');
            return;
        }
        if (amount <= 0) {
            toastr.error('Please enter a valid amount.');
            return;
        }

        $.ajax({
            method: 'POST',
            url: '/contacts/' + contactId + '/store-credit',
            dataType: 'json',
            data: {
                amount: amount,
                _token: $('meta[name="csrf-token"]').attr('content')
            },
            success: function(result) {
                if (result.success) {
                    toastr.success(result.msg);
                    $('#modal_account_balance').text(__currency_trans_from_en(result.new_balance || 0, true));
                    $('#modal_store_credit_amount').val('');

                    if ($('#customer_id').val() == contactId) {
                        $('#advance_balance').val(result.new_balance || 0);
                        $('#advance_balance_text').text(__currency_trans_from_en(result.new_balance || 0, true));
                        loadCustomerAccountInfo(contactId);
                    }
                } else {
                    toastr.error(result.msg || 'Unable to add store credit.');
                }
            },
            error: function() {
                toastr.error('Unable to add store credit.');
            }
        });
    });

    $('#customer_id').on('select2:select', function(e) {
        var data = e.params.data;
        if (data.pay_term_number) {
            $('input#pay_term_number').val(data.pay_term_number);
        } else {
            $('input#pay_term_number').val('');
        }

        if (data.pay_term_type) {
            $('#add_sell_form select[name="pay_term_type"]').val(data.pay_term_type);
            $('#edit_sell_form select[name="pay_term_type"]').val(data.pay_term_type);
        } else {
            $('#add_sell_form select[name="pay_term_type"]').val('');
            $('#edit_sell_form select[name="pay_term_type"]').val('');
        }
        
        update_shipping_address(data);
        $('#advance_balance_text').text(__currency_trans_from_en(data.balance), true);
        $('#advance_balance').val(data.balance);

        if (data.price_calculation_type == 'selling_price_group') {
            $('#price_group').val(data.selling_price_group_id);
            $('#price_group').change();
        } else {
            $('#price_group').val('');
            $('#price_group').change();
        }
        if ($('.contact_due_text').length) {
            get_contact_due(data.id);
        }
        
        // Load customer account info
        loadCustomerAccountInfo(data.id);
        
        // Store employee status for later use
        if (data.is_employee == 1) {
            $('#customer_id').data('is_employee', true);
            // Show employee discount checkbox
            $('#employee_discount_row').show();
        } else {
            $('#customer_id').data('is_employee', false);
            // Hide employee discount checkbox
            $('#employee_discount_row').hide();
            $('#apply_employee_discount').prop('checked', false);
            // Remove any applied discounts if customer is not employee
            removeEmployeeDiscount();
        }
    });

    set_default_customer();

    if ($('#search_product').length) {
        //Add Product
        $('#search_product')
            .autocomplete({
                delay: 250,
                source: function(request, response) {
                    var price_group = '';
                    var search_fields = [];
                    $('.search_fields:checked').each(function(i){
                      search_fields[i] = $(this).val();
                    });

                    if ($('#price_group').length > 0) {
                        price_group = $('#price_group').val();
                    }
                    $.getJSON(
                        '/products/list',
                        {
                            price_group: price_group,
                            location_id: $('input#location_id').val(),
                            term: request.term,
                            not_for_selling: 0,
                            search_fields: search_fields
                        },
                        response
                    );
                },
                minLength: 2,
                response: function(event, ui) {
                    if (ui.content.length == 1) {
                        ui.item = ui.content[0];

                        var is_overselling_allowed = false;
                        if($('input#is_overselling_allowed').length) {
                            is_overselling_allowed = true;
                        }
                        var for_so = false;
                        if ($('#sale_type').length && $('#sale_type').val() == 'sales_order') {
                            for_so = true;
                        }

                        if ((ui.item.enable_stock == 1 && ui.item.qty_available > 0) || 
                                (ui.item.enable_stock == 0) || is_overselling_allowed || for_so) {
                            $(this)
                                .data('ui-autocomplete')
                                ._trigger('select', 'autocompleteselect', ui);
                            $(this).autocomplete('close');
                        }
                    } else if (ui.content.length == 0) {
                        toastr.error(LANG.no_products_found);
                        $('input#search_product').select();
                    }
                },
                focus: function(event, ui) {
                    if (ui.item.qty_available <= 0) {
                        return false;
                    }
                },
                select: function(event, ui) {
                    var searched_term = $(this).val();
                    var is_overselling_allowed = false;
                    if($('input#is_overselling_allowed').length) {
                        is_overselling_allowed = true;
                    }
                    var for_so = false;
                    if ($('#sale_type').length && $('#sale_type').val() == 'sales_order') {
                        for_so = true;
                    }

                    if (ui.item.enable_stock != 1 || ui.item.qty_available > 0 || is_overselling_allowed || for_so) {
                        $(this).val(null);

                        //Pre select lot number only if the searched term is same as the lot number
                        var purchase_line_id = ui.item.purchase_line_id && searched_term == ui.item.lot_number ? ui.item.purchase_line_id : null;
                        pos_product_row(ui.item.variation_id, purchase_line_id);
                    } else {
                        alert(LANG.out_of_stock);
                    }
                },
            })
            .autocomplete('instance')._renderItem = function(ul, item) {
                var is_overselling_allowed = false;
                if($('input#is_overselling_allowed').length) {
                    is_overselling_allowed = true;
                }

                var for_so = false;
                if ($('#sale_type').length && $('#sale_type').val() == 'sales_order') {
                    for_so = true;
                }

            // Format: Artist - Title (or just Title if no artist)
            var displayName = item.name;
            if (item.artist && item.artist.trim() !== '') {
                displayName = item.artist + ' - ' + item.name;
            }
            // Append format (LP, CD, Cassette, etc.) if present
            if (item.format && item.format.trim() !== '') {
                displayName += ' [' + item.format + ']';
            }

            if (item.enable_stock == 1 && item.qty_available <= 0 && !is_overselling_allowed && !for_so) {
                var string = '<li class="ui-state-disabled">' + displayName;
                if (item.type == 'variable') {
                    string += ' - ' + item.variation;
                }
                var selling_price = item.selling_price;
                if (item.variation_group_price) {
                    selling_price = item.variation_group_price;
                }
                string +=
                    ' (' +
                    item.sub_sku +
                    ')' +
                    '<br> Price: ' +
                    selling_price +
                    ' (Out of stock) </li>';
                return $(string).appendTo(ul);
            } else {
                var string = '<div>' + displayName;
                if (item.type == 'variable') {
                    string += ' - ' + item.variation;
                }

                var selling_price = item.selling_price;
                if (item.variation_group_price) {
                    selling_price = item.variation_group_price;
                }

                string += ' (' + item.sub_sku + ')' + '<br> Price: ' + selling_price;
                if (item.enable_stock == 1) {
                    var qty_available = __currency_trans_from_en(item.qty_available, false, false, __currency_precision, true);
                    string += ' - ' + qty_available + item.unit;
                }
                string += '</div>';

                return $('<li>')
                    .append(string)
                    .appendTo(ul);
            }
        };
    }

    //Update line total and check for quantity not greater than max quantity
    $('table#pos_table tbody').on('change', 'input.pos_quantity', function() {
        if (sell_form_validator) {
            sell_form_validator.element($(this));
        }
        if (pos_form_validator) {
            pos_form_validator.element($(this));
        }
        // var max_qty = parseFloat($(this).data('rule-max'));
        var entered_qty = __read_number($(this));

        var tr = $(this).parents('tr');

        var unit_price_inc_tax = __read_number(tr.find('input.pos_unit_price_inc_tax'));
        var line_total = entered_qty * unit_price_inc_tax;

        __write_number(tr.find('input.pos_line_total'), line_total, false, 2);
        tr.find('span.pos_line_total_text').text(__currency_trans_from_en(line_total, true));
        
        // Update discount display
        update_discount_display(tr);

        //Change modifier quantity
        tr.find('.modifier_qty_text').each( function(){
            $(this).text(__currency_trans_from_en(entered_qty, false));
        });
        tr.find('.modifiers_quantity').each( function(){
            $(this).val(entered_qty);
        });

        pos_total_row();

        adjustComboQty(tr);
    });

    //If change in unit price update price including tax and line total
    $('table#pos_table tbody').on('change', 'input.pos_unit_price', function() {
        var unit_price = __read_number($(this));
        var tr = $(this).parents('tr');

        //calculate discounted unit price
        var discounted_unit_price = calculate_discounted_unit_price(tr);

        var tax_rate = tr
            .find('select.tax_id')
            .find(':selected')
            .data('rate');
        var quantity = Math.floor(__read_number(tr.find('input.pos_quantity')) || 0);

        var unit_price_inc_tax = __add_percent(discounted_unit_price, tax_rate);
        var line_total = quantity * unit_price_inc_tax;

        __write_number(tr.find('input.pos_unit_price_inc_tax'), unit_price_inc_tax);
        __write_number(tr.find('input.pos_line_total'), line_total);
        tr.find('span.pos_line_total_text').text(__currency_trans_from_en(line_total, true));
        
        // Update discount display
        update_discount_display(tr);
        
        pos_each_row(tr);
        pos_total_row();
        round_row_to_iraqi_dinnar(tr);
    });

    //If change in tax rate then update unit price according to it.
    $('table#pos_table tbody').on('change', 'select.tax_id', function() {
        var tr = $(this).parents('tr');

        var tax_rate = tr
            .find('select.tax_id')
            .find(':selected')
            .data('rate');
        var unit_price_inc_tax = __read_number(tr.find('input.pos_unit_price_inc_tax'));

        var discounted_unit_price = __get_principle(unit_price_inc_tax, tax_rate);
        var unit_price = get_unit_price_from_discounted_unit_price(tr, discounted_unit_price);
        __write_number(tr.find('input.pos_unit_price'), unit_price);
        pos_each_row(tr);
        
        // Update discount display
        update_discount_display(tr);
    });

    //If change in unit price including tax, update unit price
    $('table#pos_table tbody').on('change', 'input.pos_unit_price_inc_tax', function() {
        var unit_price_inc_tax = __read_number($(this));

        if (iraqi_selling_price_adjustment) {
            unit_price_inc_tax = round_to_iraqi_dinnar(unit_price_inc_tax);
            __write_number($(this), unit_price_inc_tax);
        }

        var tr = $(this).parents('tr');

        var tax_rate = tr
            .find('select.tax_id')
            .find(':selected')
            .data('rate');
        var quantity = Math.floor(__read_number(tr.find('input.pos_quantity')) || 0);

        var line_total = quantity * unit_price_inc_tax;
        var discounted_unit_price = __get_principle(unit_price_inc_tax, tax_rate);
        var unit_price = get_unit_price_from_discounted_unit_price(tr, discounted_unit_price);

        __write_number(tr.find('input.pos_unit_price'), unit_price);
        __write_number(tr.find('input.pos_line_total'), line_total, false, 2);
        tr.find('span.pos_line_total_text').text(__currency_trans_from_en(line_total, true));

        pos_each_row(tr);
        pos_total_row();
    });

    //Change max quantity rule if lot number changes
    $('table#pos_table tbody').on('change', 'select.lot_number', function() {
        var qty_element = $(this)
            .closest('tr')
            .find('input.pos_quantity');

        var tr = $(this).closest('tr');
        var multiplier = 1;
        var unit_name = '';
        var sub_unit_length = tr.find('select.sub_unit').length;
        if (sub_unit_length > 0) {
            var select = tr.find('select.sub_unit');
            multiplier = parseFloat(select.find(':selected').data('multiplier'));
            unit_name = select.find(':selected').data('unit_name');
        }
        var allow_overselling = qty_element.data('allow-overselling');
        if ($(this).val() && !allow_overselling) {
            var lot_qty = $('option:selected', $(this)).data('qty_available');
            var max_err_msg = $('option:selected', $(this)).data('msg-max');

            if (sub_unit_length > 0) {
                lot_qty = lot_qty / multiplier;
                var lot_qty_formated = __number_f(lot_qty, false);
                max_err_msg = __translate('lot_max_qty_error', {
                    max_val: lot_qty_formated,
                    unit_name: unit_name,
                });
            }

            qty_element.attr('data-rule-max-value', lot_qty);
            qty_element.attr('data-msg-max-value', max_err_msg);

            qty_element.rules('add', {
                'max-value': lot_qty,
                messages: {
                    'max-value': max_err_msg,
                },
            });
        } else {
            var default_qty = qty_element.data('qty_available');
            var default_err_msg = qty_element.data('msg_max_default');
            if (sub_unit_length > 0) {
                default_qty = default_qty / multiplier;
                var lot_qty_formated = __number_f(default_qty, false);
                default_err_msg = __translate('pos_max_qty_error', {
                    max_val: lot_qty_formated,
                    unit_name: unit_name,
                });
            }

            qty_element.attr('data-rule-max-value', default_qty);
            qty_element.attr('data-msg-max-value', default_err_msg);

            qty_element.rules('add', {
                'max-value': default_qty,
                messages: {
                    'max-value': default_err_msg,
                },
            });
        }
        qty_element.trigger('change');
    });

    //Change in row discount type or discount amount
    $('table#pos_table tbody').on(
        'change',
        'select.row_discount_type, input.row_discount_amount',
        function() {
            var tr = $(this).parents('tr');

            //calculate discounted unit price
            var discounted_unit_price = calculate_discounted_unit_price(tr);

            var tax_rate = tr
                .find('select.tax_id')
                .find(':selected')
                .data('rate');
            var quantity = Math.floor(__read_number(tr.find('input.pos_quantity')) || 0);

            var unit_price_inc_tax = __add_percent(discounted_unit_price, tax_rate);
            var line_total = quantity * unit_price_inc_tax;

            __write_number(tr.find('input.pos_unit_price_inc_tax'), unit_price_inc_tax);
            __write_number(tr.find('input.pos_line_total'), line_total, false, 2);
            tr.find('span.pos_line_total_text').text(__currency_trans_from_en(line_total, true));
            
            // Update discount display
            update_discount_display(tr);
            
            pos_each_row(tr);
            pos_total_row();
            round_row_to_iraqi_dinnar(tr);
        }
    );

    //Remove row on click on remove row.
    // Selector is .pos_remove_row (not i.pos_remove_row) so both styles work:
    //  - Old markup: <i class="fa fa-trash pos_remove_row"> (still used in sell_return)
    //  - New markup: <button class="pos_remove_row"><i class="fa fa-times"></i></button>
    //    (used in sale_pos product_row + manual_product_row)
    // Clicking the inner <i> icon bubbles up to the <button> via jQuery
    // delegated events and matches there, so both the icon and the button
    // trigger the remove.
    $('table#pos_table tbody').on('click', '.pos_remove_row', function() {
        var $row = $(this).parents('tr');
        if ($row.attr('data-plastic-bag') === 'true') {
            $('#add_plastic_bag').prop('checked', false);
        }
        $row.remove();
        pos_total_row();
    });

    //Cancel the invoice
    $('button#pos-cancel').click(function() {
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(confirm => {
            if (confirm) {
                reset_pos_form();
            }
        });
    });

    //Save invoice as draft
    $('button#pos-draft').click(function() {
        //Check if product is present or not.
        if ($('table#pos_table tbody').find('.product_row').length <= 0) {
            toastr.warning(LANG.no_products_added);
            return false;
        }

        var is_valid = isValidPosForm();
        if (is_valid != true) {
            return;
        }

        var data = pos_form_obj.serialize();
        data = data + '&status=draft';
        var url = pos_form_obj.attr('action');

        disable_pos_form_actions();
        $.ajax({
            method: 'POST',
            url: url,
            data: data,
            dataType: 'json',
            success: function(result) {
                enable_pos_form_actions();
                if (result.success == 1) {
                    reset_pos_form();
                    toastr.success(result.msg);
                } else {
                    toastr.error(result.msg);
                }
            },
        });
    });

    //Save invoice as Quotation
    $('button#pos-quotation').click(function() {
        //Check if product is present or not.
        if ($('table#pos_table tbody').find('.product_row').length <= 0) {
            toastr.warning(LANG.no_products_added);
            return false;
        }

        var is_valid = isValidPosForm();
        if (is_valid != true) {
            return;
        }

        var data = pos_form_obj.serialize();
        data = data + '&status=quotation';
        var url = pos_form_obj.attr('action');

        disable_pos_form_actions();
        $.ajax({
            method: 'POST',
            url: url,
            data: data,
            dataType: 'json',
            success: function(result) {
                enable_pos_form_actions();
                if (result.success == 1) {
                    reset_pos_form();
                    toastr.success(result.msg);

                    //Check if enabled or not
                    if (result.receipt.is_enabled) {
                        pos_print(result.receipt);
                    }
                } else {
                    toastr.error(result.msg);
                }
            },
        });
    });

    // Track whether we are prefilling advance payment from the \"Use Store Credit\" button
    window.pos_use_store_credit = false;
    window.pos_use_store_credit_amount = null;

    function set_store_credit_cash_cta(active) {
        var $cashBtn = $('button.pos-express-finalize[data-pay_method="cash"]');
        if (!$cashBtn.length) {
            return;
        }

        var originalText = $cashBtn.data('original-text');
        if (!originalText) {
            $cashBtn.data('original-text', $.trim($cashBtn.text()));
            originalText = $.trim($cashBtn.text());
        }

        if (active) {
            $cashBtn
                .addClass('btn-warning')
                .removeClass('btn-success')
                .attr('title', 'Store credit pending: click CASH to complete');
            $cashBtn.html('<i class="fa fa-hand-pointer-o"></i> Complete with CASH');
        } else {
            $cashBtn
                .removeClass('btn-warning')
                .addClass('btn-success')
                .removeAttr('title')
                .text(originalText);
        }
    }
    // Exposed for global reset_pos_form (defined outside this ready closure).
    window.set_store_credit_cash_cta = set_store_credit_cash_cta;

    // Forward the inline "Use it" button (next to Credit in the customer
    // snapshot) to the main handler so all the application logic lives in
    // one place. Sarah 2026-04-22 relocated the CTA here.
    $(document).on('click', '#inline_use_store_credit_btn', function (e) {
        e.preventDefault();
        $('#btn_use_store_credit').trigger('click');
    });

    // Use store credit: apply on same screen (no popup); deduct from customer when Cash/Finalize is used
    $(document).on('click', '#btn_use_store_credit', function () {
        if ($('table#pos_table tbody').find('.product_row').length <= 0) {
            toastr.warning(LANG.no_products_added);
            return false;
        }

        var advanceBalance = parseFloat($('#advance_balance').val() || 0) || 0;
        if (advanceBalance <= 0) {
            toastr.error(LANG.required_advance_balance_not_available || 'No store credit available.');
            return false;
        }

        var total_payable = __read_number($('input#final_total_input'));
        if (total_payable <= 0) {
            toastr.error(LANG.zero_total_error || 'Nothing to pay for this sale.');
            return false;
        }

        var maxUsable = Math.min(advanceBalance, total_payable);
        var entered = window.prompt(
            'Enter store credit amount to use (max ' + __currency_trans_from_en(maxUsable, true) + '):',
            __number_f(maxUsable, false, false, __currency_precision)
        );
        if (entered === null) {
            return false;
        }
        var requested = parseFloat(entered);
        if (isNaN(requested) || requested <= 0) {
            toastr.error('Please enter a valid amount greater than 0.');
            return false;
        }
        if (requested > maxUsable) {
            toastr.error('Entered amount is greater than available/total payable.');
            return false;
        }
        var useAmount = requested;
        $('#store_credit_used_amount').val(__number_f(useAmount, false, false, __currency_precision));
        set_store_credit_cash_cta(useAmount > 0);
        // Reflect remaining store credit immediately in the POS header row.
        var remainingAdvance = Math.max(0, advanceBalance - useAmount);
        $('#advance_balance').val(remainingAdvance);
        updatePosStoreCreditUI(remainingAdvance);
        try {
            if (window.console && console.log) {
                console.log('[POS store credit applied]', {
                    before_balance: advanceBalance,
                    used_amount: useAmount,
                    remaining_balance: remainingAdvance
                });
            }
        } catch (e) {}

        var $firstRow = $('#payment_rows_div').find('.payment_row').first();
        if ($firstRow.length) {
            var $method = $firstRow.find('.payment_types_dropdown');
            var $amount = $firstRow.find('.payment-amount');
            if ($method.length && $amount.length) {
                $method.val('advance').trigger('change');
                __write_number($amount, useAmount);
                $amount.trigger('change');
                // Recalculate immediately so "Total Payable" updates right away.
                calculate_balance_due();
                // Also update the visible order-total summary lines.
                apply_store_credit_to_order_totals_display();
            }
        }

        toastr.warning(__currency_trans_from_en(useAmount, true) + ' store credit applied. Next step: click the CASH button at bottom to finalize.');
    });

    //Finalize invoice, open payment modal
    $('button#pos-finalize').click(function() {
        // Block empty sale. Bag-fee row is auto-added and has the same
        // .product_row class, so exclude [data-plastic-bag="true"] from the
        // count — a cart that contains only a bag fee is still "empty" from
        // the cashier's point of view and shouldn't be recordable.
        if ($('table#pos_table tbody').find('.product_row:not([data-plastic-bag="true"])').length <= 0) {
            toastr.warning(LANG.no_products_added);
            return false;
        }

        if ($('#reward_point_enabled').length) {
            var validate_rp = isValidatRewardPoint();
            if (!validate_rp['is_valid']) {
                toastr.error(validate_rp['msg']);
                return false;
            }
        }

        // Fallback sync: ensure payment modal receives selected customer's latest balance.
        var selectedCustomer = ($('#customer_id').select2('data') || [])[0];
        if (selectedCustomer && selectedCustomer.balance !== undefined) {
            var selectedBalance = parseFloat(selectedCustomer.balance || 0) || 0;
            $('#advance_balance').val(selectedBalance);
            $('#advance_balance_text').text(__currency_trans_from_en(selectedBalance, true));
        }

        $('#modal_payment').modal('show');
    });

    $('#modal_payment').one('shown.bs.modal', function() {
        $('#modal_payment')
            .find('input')
            .filter(':visible:first')
            .focus()
            .select();
        if ($('form#edit_pos_sell_form').length == 0) {
            $(this).find('#method_0').change();
        }
    });

    // Whenever payment modal is shown, optionally pre-fill advance payment from store credit
    $('#modal_payment').on('shown.bs.modal', function () {
        if (!window.pos_use_store_credit || !window.pos_use_store_credit_amount) {
            return;
        }

        var useAmount = window.pos_use_store_credit_amount;
        var $firstRow = $('#payment_rows_div').find('.payment_row').first();
        if ($firstRow.length) {
            var $method = $firstRow.find('.payment_types_dropdown');
            var $amount = $firstRow.find('.payment-amount');

            if ($method.length && $amount.length) {
                $method.val('advance').trigger('change');
                __write_number($amount, useAmount);
                $amount.trigger('change');
                // Force balance due / total paying to update immediately
                setTimeout(function () {
                    calculate_balance_due();
                    apply_store_credit_to_order_totals_display();
                }, 50);
            }
        }

        // Reset flag so normal checkout is unaffected next time
        window.pos_use_store_credit = false;
        window.pos_use_store_credit_amount = null;
    });

    //Finalize without showing payment options
    $('button.pos-express-finalize').click(function() {
        // Block empty sale — see comment on #pos-finalize handler above; same
        // reason for excluding the bag-fee row from the cart count.
        if ($('table#pos_table tbody').find('.product_row:not([data-plastic-bag="true"])').length <= 0) {
            toastr.warning(LANG.no_products_added);
            return false;
        }

        if ($('#reward_point_enabled').length) {
            var validate_rp = isValidatRewardPoint();
            if (!validate_rp['is_valid']) {
                toastr.error(validate_rp['msg']);
                return false;
            }
        }

        var pay_method = $(this).data('pay_method');
        var store_credit_used_amount = parseFloat($('#store_credit_used_amount').val() || 0) || 0;

        // Guardrail: when store credit is used, push cashier to use CASH finalize path.
        var bypassStoreCreditGuard = $(this).data('store-credit-bypass') === true;
        if (store_credit_used_amount > 0 && pay_method !== 'cash' && pay_method !== 'credit_sale' && !bypassStoreCreditGuard) {
            var $btn = $(this);
            swal({
                title: 'Store credit pending',
                text: 'Store credit was applied. Please click CASH to complete this sale correctly.',
                icon: 'warning',
                buttons: {
                    cancel: 'Go back',
                    confirm: 'Continue anyway'
                },
                dangerMode: true
            }).then(function(continueAnyway) {
                if (continueAnyway) {
                    $btn.data('store-credit-bypass', true);
                    $btn.trigger('click');
                }
            });
            return false;
        }
        if (bypassStoreCreditGuard) {
            $(this).data('store-credit-bypass', false);
        }

        //If pay method is credit sale submit form
        if (pay_method == 'credit_sale') {
            $('#is_credit_sale').val(1);
            pos_form_obj.submit();
            return true;
        } else {
            if ($('#is_credit_sale').length) {
                $('#is_credit_sale').val(0);
            }
        }

        //Check for remaining balance & add it in 1st payment row
        var total_payable = __read_number($('input#final_total_input'));
        var total_paying = __read_number($('input#total_paying_input'));
        if (total_payable > total_paying) {
            var bal_due = total_payable - total_paying;

            var first_row = $('#payment_rows_div')
                .find('.payment-amount')
                .first();
            var first_row_val = __read_number(first_row);
            first_row_val = first_row_val + bal_due;
            __write_number(first_row, first_row_val);
            first_row.trigger('change');
        }

        //Change payment method.
        var payment_method_dropdown = $('#payment_rows_div')
            .find('.payment_types_dropdown')
            .first();
        
            payment_method_dropdown.val(pay_method);
            payment_method_dropdown.change();
        if (pay_method == 'card') {
            $('div#card_details_modal').modal('show');
        } else if (pay_method == 'suspend') {
            $('div#confirmSuspendModal').modal('show');
        } else {
            // Safety: ensure we don't get stuck in a "submit in progress" state.
            window.pos_submit_in_progress = false;
            // Keep explicit trace of store credit used in payment note for sell details.
            var store_credit_used_amount = parseFloat($('#store_credit_used_amount').val() || 0) || 0;
            if (store_credit_used_amount > 0) {
                var $firstNote = $('#payment_rows_div').find('textarea[id^="note_"]').first();
                if ($firstNote.length) {
                    var existing_note = ($firstNote.val() || '').trim();
                    var credit_note_prefix = 'Store credit used: ';
                    var credit_note_text = credit_note_prefix + __currency_trans_from_en(store_credit_used_amount, true);
                    if (existing_note.indexOf(credit_note_prefix) === -1) {
                        $firstNote.val(existing_note ? (existing_note + ' | ' + credit_note_text) : credit_note_text);
                    }
                }
            }
            // Ensure balance/paid inputs are synced right before submit.
            try {
                if (window.console && console.log) {
                    console.log('[POS express finalize]', {
                        pay_method: pay_method,
                        final_total: __read_number($('input#final_total_input')),
                        total_paying: __read_number($('input#total_paying_input')),
                        in_balance_due: $('input#in_balance_due').val(),
                        store_credit_used_amount: $('#store_credit_used_amount').val(),
                        discount_type: $('#discount_type').val(),
                        discount_amount: $('#discount_amount').val()
                    });
                }
            } catch (e) {}
            calculate_balance_due();
            pos_form_obj.submit();
        }
    });

    $('div#card_details_modal').on('shown.bs.modal', function(e) {
        $('input#card_number').focus();
    });

    $('div#confirmSuspendModal').on('shown.bs.modal', function(e) {
        $(this)
            .find('textarea')
            .focus();
    });

    //on save card details
    $('button#pos-save-card').click(function() {
        $('input#card_number_0').val($('#card_number').val());
        $('input#card_holder_name_0').val($('#card_holder_name').val());
        $('input#card_transaction_number_0').val($('#card_transaction_number').val());
        $('select#card_type_0').val($('#card_type').val());
        $('input#card_month_0').val($('#card_month').val());
        $('input#card_year_0').val($('#card_year').val());
        $('input#card_security_0').val($('#card_security').val());

        $('div#card_details_modal').modal('hide');
        pos_form_obj.submit();
    });

    $('button#pos-suspend').click(function() {
        $('input#is_suspend').val(1);
        $('div#confirmSuspendModal').modal('hide');
        pos_form_obj.submit();
        $('input#is_suspend').val(0);
    });

    //fix select2 input issue on modal
    $('#modal_payment')
        .find('.select2')
        .each(function() {
            $(this).select2({
                dropdownParent: $('#modal_payment'),
            });
        });

    $('button#add-payment-row').click(function() {
        var row_index = $('#payment_row_index').val();
        var location_id = $('input#location_id').val();
        $.ajax({
            method: 'POST',
            url: '/sells/pos/get_payment_row',
            data: { row_index: row_index, location_id: location_id },
            dataType: 'html',
            success: function(result) {
                if (result) {
                    var appended = $('#payment_rows_div').append(result);

                    var total_payable = __read_number($('input#final_total_input'));
                    var total_paying = __read_number($('input#total_paying_input'));
                    var b_due = total_payable - total_paying;
                    $(appended)
                        .find('input.payment-amount')
                        .focus();
                    $(appended)
                        .find('input.payment-amount')
                        .last()
                        .val(__currency_trans_from_en(b_due, false))
                        .change()
                        .select();
                    __select2($(appended).find('.select2'));
                    $(appended).find('#method_' + row_index).change();
                    $('#payment_row_index').val(parseInt(row_index) + 1);
                }
            },
        });
    });

    $(document).on('click', '.remove_payment_row', function() {
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(willDelete => {
            if (willDelete) {
                $(this)
                    .closest('.payment_row')
                    .remove();
                calculate_balance_due();
            }
        });
    });

    pos_form_validator = pos_form_obj.validate({
        submitHandler: function(form) {
            if (window.pos_submit_in_progress) {
                return false;
            }
            window.pos_submit_in_progress = true;
            // var total_payble = __read_number($('input#final_total_input'));
            // var total_paying = __read_number($('input#total_paying_input'));
            var cnf = true;

            // Debug: understand why Cash/exress finalize might appear to do nothing.
            try {
                if (window.console && console.log) {
                    console.log('[POS submitHandler]', {
                        in_balance_due: $('input#in_balance_due').val(),
                        final_total: $('input#final_total_input').val(),
                        total_paying_input: $('input#total_paying_input').val(),
                        payment_rows_count: $('#payment_rows_div .payment_row').length,
                        store_credit_used_amount: $('#store_credit_used_amount').val(),
                        discount_type: $('#discount_type').val(),
                        discount_amount: $('#discount_amount').val(),
                        discount_reason: $('#discount_reason').val()
                    });
                }
            } catch (e) {}

            //Ignore if the difference is less than 0.5
            if ($('input#in_balance_due').val() >= 0.5) {
                cnf = confirm(LANG.paid_amount_is_less_than_payable);
                // if( total_payble > total_paying ){
                // 	cnf = confirm( LANG.paid_amount_is_less_than_payable );
                // } else if(total_payble < total_paying) {
                // 	alert( LANG.paid_amount_is_more_than_payable );
                // 	cnf = false;
                // }
            }

            var total_advance_payments = 0;
            $('#payment_rows_div').find('select.payment_types_dropdown').each( function(){
                if ($(this).val() == 'advance') {
                    total_advance_payments++
                };
            });

            if (total_advance_payments > 1) {
                alert(LANG.advance_payment_cannot_be_more_than_once);
                return false;
            }

            var is_msp_valid = true;
            //Validate minimum selling price if hidden
            $('.pos_unit_price_inc_tax').each( function(){
                if (!$(this).is(":visible") && $(this).data('rule-min-value')) {
                    var val = __read_number($(this));
                    var error_msg_td = $(this).closest('tr').find('.pos_line_total_text').closest('td');
                    if (val > $(this).data('rule-min-value')) {
                        is_msp_valid = false;
                        error_msg_td.append( '<label class="error">' + $(this).data('msg-min-value') + '</label>');
                    } else {
                        error_msg_td.find('label.error').remove();
                    }
                }
            });

            if (!is_msp_valid) {
                return false;
            }

            if (cnf) {
                disable_pos_form_actions();

                var selected_customer_id_before_submit = $('#customer_id').val();
                var advance_used_for_sale = 0;
                $('#payment_rows_div .payment_row').each(function() {
                    var method = $(this).find('select.payment_types_dropdown').first().val();
                    if (method === 'advance') {
                        advance_used_for_sale += __read_number($(this).find('input.payment-amount').first());
                    }
                });
                var store_credit_used_amount = parseFloat($('#store_credit_used_amount').val() || 0) || 0;

                var data = $(form).serialize();
                data = data + '&status=final';
                var url = $(form).attr('action');
                $.ajax({
                    method: 'POST',
                    url: url,
                    data: data,
                    dataType: 'json',
                    success: function(result) {
                        if (result.success == 1) {
                            if (result.whatsapp_link) {
                                window.open(result.whatsapp_link);
                            }
                            $('#modal_payment').modal('hide');
                            toastr.success(result.msg);

                            var doResetAndReceipt = function() {
                                reset_pos_form();
                                if (result.receipt.is_enabled) {
                                    pos_print(result.receipt);
                                }
                            };

                            var credit_deducted = advance_used_for_sale > 0 ? advance_used_for_sale : store_credit_used_amount;
                            if (selected_customer_id_before_submit && credit_deducted > 0) {
                                refresh_customer_credit_after_sale(selected_customer_id_before_submit, credit_deducted, doResetAndReceipt);
                            } else {
                                doResetAndReceipt();
                            }
                        } else {
                            toastr.error(result.msg);
                            window.pos_submit_in_progress = false;
                        }

                        enable_pos_form_actions();
                    },
                    error: function() {
                        window.pos_submit_in_progress = false;
                        enable_pos_form_actions();
                    }
                });
            }
            if (!cnf) {
                window.pos_submit_in_progress = false;
            }
            return false;
        },
    });

    $(document).on('change', '.payment-amount', function() {
        calculate_balance_due();
    });

    //Update discount
    $('button#posEditDiscountModalUpdate').click(function() {

        //if discount amount is not valid return false
        if (!$("#discount_amount_modal").valid()) {
            return false;
        }
        //Close modal
        $('div#posEditDiscountModal').modal('hide');

        //Update values
        $('input#discount_type').val($('select#discount_type_modal').val());
        __write_number($('input#discount_amount'), __read_number($('input#discount_amount_modal')));
        
        //Update discount reason
        var discount_reason = $('#discount_reason_modal').val() || '';
        $('#discount_reason').val(discount_reason);

        if ($('#reward_point_enabled').length) {
            var reward_validation = isValidatRewardPoint();
            if (!reward_validation['is_valid']) {
                toastr.error(reward_validation['msg']);
                $('#rp_redeemed_modal').val(0);
                $('#rp_redeemed_modal').change();
            }
            updateRedeemedAmount();
        }

        pos_total_row();
    });
    
    /**
     * Manual discount tier (by Total Payable): under $50 = 0%, $50–$99.99 = 5%, $100–$299.99 = 7%, $300+ = 10%.
     * Returns the max allowed discount percent for the current order total.
     */
    function get_manual_discount_tier_max() {
        var total = __read_number($('#final_total_input')) || 0;
        if (total < 50) return 0;
        if (total < 100) return 5;
        if (total < 300) return 7;
        return 10;
    }

    window.pos_discount_mode = 'manual';
    window.pos_discount_original_max = parseFloat($('#discount_amount_modal').data('max-discount'));
    window.pos_discount_original_msg = $('#discount_amount_modal').data('max-discount-error_msg');

    // Manual discount button - opens discount modal (block if order under $50)
    $(document).on('click', '#pos-manual-discount', function() {
        window.pos_discount_mode = 'manual';
        var tierMax = get_manual_discount_tier_max();
        if (tierMax === 0) {
            toastr.warning('Manual discount is not available for orders under $50.');
            return;
        }
        $('#posEditDiscountModal').modal('show');
    });

    // Preset discount button - opens discount modal without manual tier restrictions
    $(document).on('click', '#pos-preset-discount', function() {
        window.pos_discount_mode = 'preset';
        $('#posEditDiscountModal').modal('show');
        setTimeout(function() {
            if ($('#pos_discount_preset_select').length) {
                $('#pos_discount_preset_select').focus();
            }
        }, 100);
    });

    // Edit icon should keep manual mode behavior.
    $(document).on('click', '#pos-edit-discount', function() {
        window.pos_discount_mode = 'manual';
    });

    // When discount modal is shown, enforce tier max (and cap user max by tier)
    $('#posEditDiscountModal').on('shown.bs.modal', function() {
        if (window.pos_discount_mode === 'preset') {
            var resetMax = isNaN(window.pos_discount_original_max) ? 999 : window.pos_discount_original_max;
            $('#discount_amount_modal').data('max-discount', resetMax);
            $('#discount_amount_modal').data('max-discount-error_msg', window.pos_discount_original_msg || '');
            validate_discount_field();
            return;
        }

        var tierMax = get_manual_discount_tier_max();
        var userMax = parseFloat($('#discount_amount_modal').data('max-discount')) || 999;
        var effectiveMax = (tierMax === 0) ? 0 : Math.min(userMax, tierMax);
        var msg = (tierMax === 0)
            ? 'Manual discount is not available for orders under $50.'
            : ('Maximum allowed discount for this order is ' + effectiveMax + '%.');
        $('#discount_amount_modal').data('max-discount', effectiveMax);
        $('#discount_amount_modal').data('max-discount-error_msg', msg);
        if ($('#discount_type_modal').val() === 'percentage') {
            var current = __read_number($('#discount_amount_modal')) || 0;
            if (current > effectiveMax) {
                __write_number($('#discount_amount_modal'), effectiveMax);
            }
        }
        validate_discount_field();
    });

    $(document).on('change', '#pos_discount_preset_select', function() {
        var opt = $(this).find('option:selected');
        if (!opt.val()) return;
        var type = opt.data('type');
        var amount = opt.data('amount');
        var reason = opt.data('reason') || '';
        if (type) $('#discount_type_modal').val(type).trigger('change');
        if (amount !== undefined && amount !== '') __write_number($('#discount_amount_modal'), amount);
        $('#discount_reason_modal').val(reason);
    });
    
    // Employee discount checkbox handler
    $(document).on('change', '#apply_employee_discount', function() {
        var isEmployee = $('#customer_id').data('is_employee');
        if (!isEmployee) {
            $(this).prop('checked', false);
            toastr.warning('Please select an employee customer first.');
            return;
        }
        
        if ($(this).is(':checked')) {
            // Apply 20% discount to all products in the cart
            var discountApplied = false;
            $('table#pos_table tbody tr.product_row').each(function() {
                var row = $(this);
                var currentDiscount = __read_number(row.find('input.row_discount_amount'));
                // Only apply if no discount is already set
                if (!currentDiscount || currentDiscount == 0) {
                    applyEmployeeDiscount(row);
                    discountApplied = true;
                }
            });
            
            if (discountApplied) {
                toastr.success('Employee discount (20%) applied to all items.');
            } else {
                toastr.info('All items already have discounts applied.');
            }
            
            $('#customer_id').data('employee_discount_applied', true);
            pos_total_row();
        } else {
            // Remove employee discount from all items
            removeEmployeeDiscount();
            toastr.info('Employee discount removed from all items.');
            $('#customer_id').data('employee_discount_applied', false);
            pos_total_row();
        }
    });
    
    // Function to remove employee discount from all items
    function removeEmployeeDiscount() {
        $('table#pos_table tbody tr.product_row').each(function() {
            var row = $(this);
            var discountType = row.find('select.row_discount_type');
            var discountAmount = row.find('input.row_discount_amount');
            
            // Check if this row has the employee discount (20% percentage)
            var currentDiscount = __read_number(discountAmount);
            var currentType = discountType.val();
            
            if (currentType == 'percentage' && currentDiscount == 20) {
                // Remove the discount
                discountType.val('percentage');
                discountAmount.val(0);
                
                // Trigger change to recalculate
                discountType.trigger('change');
                discountAmount.trigger('change');
                
                // Update display
                update_discount_display(row);
            }
        });
    }

    //Shipping
    $('button#posShippingModalUpdate').click(function() {
        //Close modal
        $('div#posShippingModal').modal('hide');

        //update shipping details
        $('input#shipping_details').val($('#shipping_details_modal').val());

        $('input#shipping_address').val($('#shipping_address_modal').val());
        $('input#shipping_status').val($('#shipping_status_modal').val());
        $('input#delivered_to').val($('#delivered_to_modal').val());

        //Update shipping charges
        __write_number(
            $('input#shipping_charges'),
            __read_number($('input#shipping_charges_modal'))
        );

        //$('input#shipping_charges').val(__read_number($('input#shipping_charges_modal')));

        pos_total_row();
    });

    $('#posShippingModal').on('shown.bs.modal', function() {
        $('#posShippingModal')
            .find('#shipping_details_modal')
            .filter(':visible:first')
            .focus()
            .select();
    });

    $(document).on('shown.bs.modal', '.row_edit_product_price_model', function() {
        $('.row_edit_product_price_model')
            .find('input')
            .filter(':visible:first')
            .focus()
            .select();
    });

    //Update Order tax
    $('button#posEditOrderTaxModalUpdate').click(function() {
        //Close modal
        $('div#posEditOrderTaxModal').modal('hide');

        var tax_obj = $('select#order_tax_modal');
        var tax_id = tax_obj.val();
        var tax_rate = tax_obj.find(':selected').data('rate');

        $('input#tax_rate_id').val(tax_id);

        __write_number($('input#tax_calculation_amount'), tax_rate);
        pos_total_row();
    });

    $(document).on('click', '.add_new_customer', function() {
        $('#customer_id').select2('close');
        var name = $(this).data('name');
        $('.contact_modal')
            .find('input#name')
            .val(name);
        $('.contact_modal')
            .find('select#contact_type')
            .val('customer')
            .closest('div.contact_type_div')
            .addClass('hide');
        $('.contact_modal').modal('show');
    });
    $('form#quick_add_contact')
        .submit(function(e) {
            e.preventDefault();
        })
        .validate({
            rules: {
                contact_id: {
                    remote: {
                        url: '/contacts/check-contacts-id',
                        type: 'post',
                        data: {
                            contact_id: function() {
                                return $('#contact_id').val();
                            },
                            hidden_id: function() {
                                if ($('#hidden_id').length) {
                                    return $('#hidden_id').val();
                                } else {
                                    return '';
                                }
                            },
                        },
                    },
                },
            },
            messages: {
                contact_id: {
                    remote: LANG.contact_id_already_exists,
                },
            },
            submitHandler: function(form) {
                $.ajax({
                    method: 'POST',
                    url: base_path + '/check-mobile',
                    dataType: 'json',
                    data: {
                        contact_id: function() {
                            return $('#hidden_id').val();
                        },
                        mobile_number: function() {
                            return $('#mobile').val();
                        },
                    },
                    beforeSend: function(xhr) {
                        __disable_submit_button($(form).find('button[type="submit"]'));
                    },
                    success: function(result) {
                        if (result.is_mobile_exists == true) {
                            swal({
                                title: LANG.sure,
                                text: result.msg,
                                icon: 'warning',
                                buttons: true,
                                dangerMode: true,
                            }).then(willContinue => {
                                if (willContinue) {
                                    submitQuickContactForm(form);
                                } else {
                                    $('#mobile').select();
                                }
                            });
                            
                        } else {
                            submitQuickContactForm(form);
                        }
                    },
                });
            },
        });
    $('.contact_modal').on('hidden.bs.modal', function() {
        $('form#quick_add_contact')
            .find('button[type="submit"]')
            .removeAttr('disabled');
        $('form#quick_add_contact')[0].reset();
    });

    //Updates for add sell
    $('select#discount_type, input#discount_amount, input#shipping_charges, \
        input#rp_redeemed_amount').change(function() {
        pos_total_row();
    });
    $('select#tax_rate_id').change(function() {
        var tax_rate = $(this)
            .find(':selected')
            .data('rate');
        __write_number($('input#tax_calculation_amount'), tax_rate);
        pos_total_row();
    });
    //Datetime picker
    $('#transaction_date').datetimepicker({
        format: moment_date_format + ' ' + moment_time_format,
        ignoreReadonly: true,
    });

    //Direct sell submit
    sell_form = $('form#add_sell_form');
    if ($('form#edit_sell_form').length) {
        sell_form = $('form#edit_sell_form');
        pos_total_row();
    }
    sell_form_validator = sell_form.validate();

    $('button#submit-sell, button#save-and-print').click(function(e) {
        //Check if product is present or not.
        if ($('table#pos_table tbody').find('.product_row').length <= 0) {
            toastr.warning(LANG.no_products_added);
            return false;
        }

        var is_msp_valid = true;
        //Validate minimum selling price if hidden
        $('.pos_unit_price_inc_tax').each( function(){
            if (!$(this).is(":visible") && $(this).data('rule-min-value')) {
                var val = __read_number($(this));
                var error_msg_td = $(this).closest('tr').find('.pos_line_total_text').closest('td');
                if (val > $(this).data('rule-min-value')) {
                    is_msp_valid = false;
                    error_msg_td.append( '<label class="error">' + $(this).data('msg-min-value') + '</label>');
                } else {
                    error_msg_td.find('label.error').remove();
                }
            }
        });

        if (!is_msp_valid) {
            return false;
        }

        if ($(this).attr('id') == 'save-and-print') {
            $('#is_save_and_print').val(1);           
        } else {
            $('#is_save_and_print').val(0);
        }

        if ($('#reward_point_enabled').length) {
            var validate_rp = isValidatRewardPoint();
            if (!validate_rp['is_valid']) {
                toastr.error(validate_rp['msg']);
                return false;
            }
        }

        if ($('.enable_cash_denomination_for_payment_methods').length) {
            var payment_row = $('.enable_cash_denomination_for_payment_methods').closest('.payment_row');
            var is_valid = true;
            var payment_type = payment_row.find('.payment_types_dropdown').val();
            var denomination_for_payment_types = JSON.parse($('.enable_cash_denomination_for_payment_methods').val());
            if (denomination_for_payment_types.includes(payment_type) && payment_row.find('.is_strict').length && payment_row.find('.is_strict').val() === '1' ) {
                var payment_amount = __read_number(payment_row.find('.payment-amount'));
                var total_denomination = payment_row.find('input.denomination_total_amount').val();
                if (payment_amount != total_denomination ) {
                    is_valid = false;
                }
            }

            if (!is_valid) {
                payment_row.find('.cash_denomination_error').removeClass('hide');
                toastr.error(payment_row.find('.cash_denomination_error').text());
                e.preventDefault();
                return false;
            } else {
                payment_row.find('.cash_denomination_error').addClass('hide');
            }
        }

        if (sell_form.valid()) {
            window.onbeforeunload = null;
            $(this).attr('disabled', true);
            sell_form.submit();
        }
    });

    //REPAIR MODULE:check if repair module field is present send data to filter product
    var is_enabled_stock = null;
    if ($("#is_enabled_stock").length) {
        is_enabled_stock = $("#is_enabled_stock").val();
    }

    var device_model_id = null;
    if ($("#repair_model_id").length) {
        device_model_id = $("#repair_model_id").val();
    }

    // Improved category/subcategory search:
    // - allow multi-token queries (e.g. "used rock")
    // - allow "first few letters" prefix matches against words
    // Works by searching across both optgroup label and option text.
    function __tokenize_prefix_words(text) {
        if (text === undefined || text === null) return [];
        return String(text)
            .toLowerCase()
            .trim()
            .split(/[^a-z0-9]+/g)
            .filter(Boolean);
    }

    function __pos_category_matcher(params, data) {
        // Select2 can call matcher with group placeholders etc.
        if (!data || !data.text) return data;

        var term = params && params.term ? String(params.term).trim().toLowerCase() : '';
        if (term === '') return data;

        var optionText = String(data.text || '').toLowerCase();

        // For optgroup-based selects, include the optgroup label in match text.
        var groupLabel = '';
        if (data.element) {
            var $opt = $(data.element);
            groupLabel = $opt.closest('optgroup').attr('label') || '';
        }

        var combined = (groupLabel + ' ' + optionText).toLowerCase();
        var tokens = __tokenize_prefix_words(term);

        if (tokens.length === 0) return null;

        // Prefix-match each token against at least one word in combined text.
        var words = combined.match(/[a-z0-9]+/g) || [];
        var matchedAll = tokens.every(function(tok) {
            if (tok.length === 0) return true;
            return (
                combined.indexOf(tok) !== -1 ||
                words.some(function(w) {
                    return w.indexOf(tok) === 0;
                })
            );
        });

        return matchedAll ? data : null;
    }

    // Re-init select2 only for the POS category select with a custom matcher.
    var $posCategory = $('select#product_category');
    if ($posCategory.length) {
        var currentVal = $posCategory.val();
        try {
            if ($posCategory.data('select2')) {
                $posCategory.select2('destroy');
            }
        } catch (e) {
            // Ignore re-init errors; page can still function.
        }
        $posCategory.select2({
            matcher: __pos_category_matcher,
        });
        if (currentVal !== null && currentVal !== undefined) {
            $posCategory.val(currentVal).trigger('change.select2');
        }
    }

    //Show product list.
    get_product_suggestion_list(
        $('select#product_category').val(),
        $('select#product_brand').val(),
        $('input#location_id').val(),
        null,
        is_enabled_stock,
        device_model_id
    );
    $('select#product_category, select#product_brand, select#select_location_id').on('change', function(e) {
        $('input#suggestion_page').val(1);
        var location_id = $('input#location_id').val();
        if (location_id != '' || location_id != undefined) {
            get_product_suggestion_list(
                $('select#product_category').val(),
                $('select#product_brand').val(),
                $('input#location_id').val(),
                null
            );
        }

        get_featured_products();
    });

    $(document).on('click', 'div.product_box', function() {
        //Check if location is not set then show error message.
        if ($('input#location_id').val() == '') {
            toastr.warning(LANG.select_location);
        } else {
            pos_product_row($(this).data('variation_id'));
        }
    });

    $(document).on('shown.bs.modal', '.row_description_modal', function() {
        $(this)
            .find('textarea')
            .first()
            .focus();
    });

    //Press enter on search product to jump into last quantty and vice-versa
    $('#search_product').keydown(function(e) {
        var key = e.which;
        if (key == 9) {
            // the tab key code
            e.preventDefault();
            if ($('#pos_table tbody tr').length > 0) {
                $('#pos_table tbody tr:last')
                    .find('input.pos_quantity')
                    .focus()
                    .select();
            }
        }
    });
    $('#pos_table').on('keypress', 'input.pos_quantity', function(e) {
        var key = e.which;
        if (key == 13) {
            // the enter key code
            $('#search_product').focus();
        }
    });

    $('#exchange_rate').change(function() {
        var curr_exchange_rate = 1;
        if ($(this).val()) {
            curr_exchange_rate = __read_number($(this));
        }
        var total_payable = __read_number($('input#final_total_input'));
        var shown_total = total_payable * curr_exchange_rate;
        $('span#total_payable').text(__currency_trans_from_en(shown_total, false));
    });

    $('select#price_group').change(function() {
        $('input#hidden_price_group').val($(this).val());
    });

    //Quick add product
    $(document).on('click', 'button.pos_add_quick_product', function() {
        var url = $(this).data('href');
        var container = $(this).data('container');
        $.ajax({
            url: url + '?product_for=pos',
            dataType: 'html',
            success: function(result) {
                $(container)
                    .html(result)
                    .modal('show');
                $('.os_exp_date').datepicker({
                    autoclose: true,
                    format: 'dd-mm-yyyy',
                    clearBtn: true,
                });
            },
        });
    });

    $(document).on('change', 'form#quick_add_product_form input#single_dpp', function() {
        var unit_price = __read_number($(this));
        $('table#quick_product_opening_stock_table tbody tr').each(function() {
            var input = $(this).find('input.unit_price');
            __write_number(input, unit_price);
            input.change();
        });
    });

    $(document).on('quickProductAdded', function(e) {
        //Check if location is not set then show error message.
        if ($('input#location_id').val() == '') {
            toastr.warning(LANG.select_location);
        } else {
            pos_product_row(e.variation.id);
        }
    });

    $('div.view_modal').on('show.bs.modal', function() {
        __currency_convert_recursively($(this));
    });

    $('table#pos_table').on('change', 'select.sub_unit', function() {
        var tr = $(this).closest('tr');
        var base_unit_selling_price = tr.find('input.hidden_base_unit_sell_price').val();

        var selected_option = $(this).find(':selected');

        var multiplier = parseFloat(selected_option.data('multiplier'));

        var allow_decimal = parseInt(selected_option.data('allow_decimal'));

        tr.find('input.base_unit_multiplier').val(multiplier);

        var unit_sp = base_unit_selling_price * multiplier;

        var sp_element = tr.find('input.pos_unit_price');
        __write_number(sp_element, unit_sp);

        sp_element.change();

        var qty_element = tr.find('input.pos_quantity');
        var base_max_avlbl = qty_element.data('qty_available');
        var error_msg_line = 'pos_max_qty_error';

        if (tr.find('select.lot_number').length > 0) {
            var lot_select = tr.find('select.lot_number');
            if (lot_select.val()) {
                base_max_avlbl = lot_select.find(':selected').data('qty_available');
                error_msg_line = 'lot_max_qty_error';
            }
        }

        qty_element.attr('data-decimal', allow_decimal);
        var abs_digit = true;
        if (allow_decimal) {
            abs_digit = false;
        }
        qty_element.rules('add', {
            abs_digit: abs_digit,
        });

        if (base_max_avlbl) {
            var max_avlbl = parseFloat(base_max_avlbl) / multiplier;
            var formated_max_avlbl = __number_f(max_avlbl);
            var unit_name = selected_option.data('unit_name');
            var max_err_msg = __translate(error_msg_line, {
                max_val: formated_max_avlbl,
                unit_name: unit_name,
            });
            qty_element.attr('data-rule-max-value', max_avlbl);
            qty_element.attr('data-msg-max-value', max_err_msg);
            qty_element.rules('add', {
                'max-value': max_avlbl,
                messages: {
                    'max-value': max_err_msg,
                },
            });
            qty_element.trigger('change');
        }
        adjustComboQty(tr);
    });

    //Confirmation before page load.
    window.onbeforeunload = function() {
        if($('form#edit_pos_sell_form').length == 0){
            if($('table#pos_table tbody tr').length > 0) {
                return LANG.sure;
            } else {
                return null;
            }
        }
    }
    $(window).resize(function() {
        // Let the browser handle vertical sizing for POS content on all screens.
        // This avoids fixed heights that can cut off totals/actions on smaller viewports.
        $('div.pos_product_div').css('min-height', 'auto');
        $('div.pos_product_div').css('max-height', 'none');
    }).trigger('resize');

    //Used for weighing scale barcode
    $('#weighing_scale_modal').on('shown.bs.modal', function (e) {

        //Attach the scan event
        onScan.attachTo(document, {
            suffixKeyCodes: [13], // enter-key expected at the end of a scan
            reactToPaste: true, // Compatibility to built-in scanners in paste-mode (as opposed to keyboard-mode)
            onScan: function(sCode, iQty) {
                console.log('Scanned: ' + iQty + 'x ' + sCode); 
                $('input#weighing_scale_barcode').val(sCode);
                $('button#weighing_scale_submit').trigger('click');
            },
            onScanError: function(oDebug) {
                console.log(oDebug); 
            },
            minLength: 2
            // onKeyDetect: function(iKeyCode){ // output all potentially relevant key events - great for debugging!
            //     console.log('Pressed: ' + iKeyCode);
            // }
        });

        $('input#weighing_scale_barcode').focus();
    });

    $('#weighing_scale_modal').on('hide.bs.modal', function (e) {
        //Detach from the document once modal is closed.
        onScan.detachFrom(document);
    });

    $('button#weighing_scale_submit').click(function(){

        var price_group = '';
        if ($('#price_group').length > 0) {
            price_group = $('#price_group').val();
        }

        if($('#weighing_scale_barcode').val().length > 0){
            pos_product_row(null, null, $('#weighing_scale_barcode').val());
            $('#weighing_scale_modal').modal('hide');
            $('input#weighing_scale_barcode').val('');
        } else{
            $('input#weighing_scale_barcode').focus();
        }
    });

    $('#show_featured_products').click( function(){
        if (!$('#featured_products_box').is(':visible')) {
            $('#featured_products_box').fadeIn();
        } else {
            $('#featured_products_box').fadeOut();
        }
    });
    validate_discount_field();
    set_payment_type_dropdown();

    setInterval(function () {
        if ($('span.curr_datetime').length) {
            // POS header always uses 12-hour format for readability
            $('span.curr_datetime').html(moment().format('MM/DD/YYYY hh:mm A'));
        }
    }, 60000);

    // Add manual product
    $(document).on('click', '.pos_add_manual_product', function() {
        console.log("CLICK WORK");
        // Open the modal
        $('#add_manual_product_modal').modal('show');
        
        // Initialize subtotal when modal opens
        setTimeout(function() {
            calculateManualProductSubtotal();
        }, 100);
        
        // Initialize select2 for existing selects (with tokenized matcher for merged category/subcategory search)
        setTimeout(function() {
            applyManualCategoryComboMatcher($('#add_manual_product_modal'));
            initManualProductNameAutocomplete($('#add_manual_product_modal'));
        }, 300);

        // $.ajax({
        //     url: url + '?product_for=pos',
        //     dataType: 'html',
        //     success: function(result) {
        //         $(container)
        //             .html(result)
        //             .modal('show');
        //         $('.os_exp_date').datepicker({
        //             autoclose: true,
        //             format: 'dd-mm-yyyy',
        //             clearBtn: true,
        //         });
        //     },
        // });
    });

    $(document).on('click', '#add_manual_product_button', function() {
        // Sync combo selection to hidden inputs before validation (Select2 may not fire change)
        syncManualProductCategoryFromCombo();
        // Validate form
        var isValid = validateManualProductForm();
        if (!isValid) {
            return;
        }

        // Get all input names and values from the modal
        var inputData = getManualProductModalInputs();

        // Send AJAX request to get manual product rows
        getManualProductRows(inputData);
    });

    // Add another product row
    $(document).on('click', '#add_another_product', function() {
        addManualProductRow();
        initManualProductNameAutocomplete($('#manual_products_container tr:last'));
    });

    // Remove product row
    $(document).on('click', '.remove_product_row', function() {
        $(this).closest('.manual_product_row').remove();
        updateProductRowNumbers();
        calculateManualProductSubtotal();
    });
    
    // Calculate subtotal when price input changes
    $(document).on('input change', '#manual_products_container input[name*="[price]"]', function() {
        calculateManualProductSubtotal();
    });

    // Handle merged category/subcategory combo in manual product modal
    $(document).on('change', '.manual_category_combo', function() {
        var $this = $(this);
        var $row = $this.closest('.manual_product_row');
        var selected = $this.find('option:selected');
        var categoryId = selected.data('category-id') || selected.attr('data-category-id') || '';
        var subCategoryId = selected.data('sub-category-id') || selected.attr('data-sub-category-id') || '';

        $row.find('input.manual_category_id').val(categoryId);
        $row.find('input.manual_sub_category_id').val(subCategoryId);
    });

    // Copy down category/subcategory values to rows below
    $(document).on('click', '#add_manual_product_modal .manual-copy-down', function() {
        var inputClass = $(this).attr('data-class');
        var $currentRow = $(this).closest('.manual_product_row');
        var rowIndex = $('#manual_products_container .manual_product_row').index($currentRow);
        var value = $currentRow.find('.' + inputClass).val();

        $('#manual_products_container .manual_product_row')
            .slice(rowIndex + 1)
            .each(function() {
                $(this).find('.' + inputClass).val(value).trigger('change');
            });
    });

    // Re-apply matcher every time modal is shown (guards against any late re-init)
    $(document).on('shown.bs.modal', '#add_manual_product_modal', function() {
        applyManualCategoryComboMatcher($(this));
        initManualProductNameAutocomplete($(this));
    });

    // If cashier types a keyword and leaves field, try keyword-rule autofill.
    $(document).on('blur', '#manual_products_container input[name*="[name]"]', function() {
        var $input = $(this);
        var $row = $input.closest('.manual_product_row');
        var term = String($input.val() || '').trim();
        if (!term || $row.find('input[name*="[price]"]').val()) {
            return;
        }
        var items = getManualKeywordSuggestions(term);
        if (!items.length) {
            return;
        }
        var best = items[0];
        applyManualRuleToRow($row, best);
        var catId = $row.find('input.manual_category_id').val() || '';
        var subCatId = $row.find('input.manual_sub_category_id').val() || '';
        resolveProductEntryRule(term, catId, subCatId, function(rule) {
            applyResolvedProductEntryRuleToManualRow($row, rule);
        });
    });
});

// Function to get all input names and values from the add_manual_product_modal
function getManualProductModalInputs() {
    var inputData = {};
    $("#add_manual_product_modal").find('input, select, textarea').each(function() {
        var $input = $(this);
        var name = $input.attr('name');
        var value = $input.val();
        var type = $input.attr('type');
        
        if (name) {
            // Handle array inputs like products[0][name]
            if (name.includes('[') && name.includes(']')) {
                var matches = name.match(/^(\w+)\[(\d+)\]\[(\w+)\]$/);
                if (matches) {
                    var arrayName = matches[1];
                    var index = matches[2];
                    var fieldName = matches[3];
                    
                    if (!inputData[arrayName]) {
                        inputData[arrayName] = {};
                    }
                    if (!inputData[arrayName][index]) {
                        inputData[arrayName][index] = {};
                    }
                    
                    // Handle different input types
                    if (type === 'checkbox' || type === 'radio') {
                        inputData[arrayName][index][fieldName] = $input.is(':checked');
                    } else {
                        inputData[arrayName][index][fieldName] = value;
                    }
                }
            } else {
                // Handle different input types
                if (type === 'checkbox' || type === 'radio') {
                    inputData[name] = $input.is(':checked');
                } else {
                    inputData[name] = value;
                }
            }
        }
    });
    
    return inputData;
}

// Manual keyword -> fixed price rules (admin-configured, DB-backed).
var MANUAL_ITEM_PRICE_KEYWORDS = [];
(function initManualItemPriceRulesFromWindow() {
    var source = window.manualItemPriceRules;
    if (!Array.isArray(source)) {
        MANUAL_ITEM_PRICE_KEYWORDS = [];
        return;
    }
    MANUAL_ITEM_PRICE_KEYWORDS = source.map(function(r) {
        var keywordTokens = String(r.keywords || '')
            .split(',')
            .map(function(k) { return String(k).trim().toLowerCase(); })
            .filter(Boolean);
        return {
            label: String(r.label || '').trim(),
            keywords: keywordTokens,
            price: String(r.price || '').trim(),
            category_id: r.category_id ? String(r.category_id).trim() : '',
            sub_category_id: r.sub_category_id ? String(r.sub_category_id).trim() : '',
            artist: r.artist ? String(r.artist).trim() : ''
        };
    }).filter(function(r) {
        return r.label && r.keywords.length > 0 && r.price !== '';
    });
})();

function getManualKeywordSuggestions(term) {
    var q = String(term || '').toLowerCase().trim();
    if (!q) {
        return [];
    }
    var out = [];
    MANUAL_ITEM_PRICE_KEYWORDS.forEach(function(rule) {
        var matched = rule.keywords.some(function(k) {
            return k.indexOf(q) !== -1 || q.indexOf(k) !== -1;
        });
        if (matched) {
            out.push({
                label: rule.label + ' ($' + rule.price + ')',
                value: rule.label,
                price: rule.price,
                category_id: rule.category_id,
                sub_category_id: rule.sub_category_id,
                artist: rule.artist || ''
            });
        }
    });
    return out;
}

function applyManualRuleToRow($row, item) {
    if (!$row || !$row.length || !item) {
        return;
    }

    if (item.price !== null && item.price !== undefined && item.price !== '') {
        $row.find('input[name*="[price]"]').val(item.price).trigger('change');
    }

    if (item.artist !== null && item.artist !== undefined && String(item.artist).trim() !== '') {
        var $artistIn = $row.find('input[name*="[artist]"]');
        if ($artistIn.length) {
            $artistIn.val(String(item.artist).trim()).trigger('change');
        }
    }

    var catId = item.category_id ? String(item.category_id).trim() : '';
    var subCatId = item.sub_category_id ? String(item.sub_category_id).trim() : '';
    if (!catId && !subCatId) {
        return;
    }

    $row.find('input.manual_category_id').val(catId);
    $row.find('input.manual_sub_category_id').val(subCatId);

    var $combo = $row.find('select.manual_category_combo');
    if (!$combo.length) {
        return;
    }

    var comboVal = (catId || '') + '|' + (subCatId || '');
    var $opt = $combo.find('option[value="' + comboVal + '"]');
    if ($opt.length) {
        $combo.val(comboVal).trigger('change');
        return;
    }

    var matchedVal = '';
    $combo.find('option').each(function() {
        var $o = $(this);
        var oCat = String($o.attr('data-category-id') || '').trim();
        var oSub = String($o.attr('data-sub-category-id') || '').trim();
        if (oCat === catId && oSub === subCatId) {
            matchedVal = String($o.val() || '');
            return false;
        }
    });
    if (matchedVal !== '') {
        $combo.val(matchedVal).trigger('change');
    }
}

function applyResolvedProductEntryRuleToManualRow($row, rule) {
    if (!$row || !$row.length || !rule) {
        return;
    }
    if (rule.artist) {
        $row.find('input[name*="[artist]"]').val(rule.artist).trigger('change');
    }
    applyManualRuleToRow($row, {
        price: rule.selling_price || '',
        category_id: rule.category_id || '',
        sub_category_id: rule.sub_category_id || ''
    });
}

function resolveProductEntryRule(title, categoryId, subCategoryId, callback) {
    $.getJSON('/settings/product-entry-rules/resolve', {
        title: title || '',
        category_id: categoryId || '',
        sub_category_id: subCategoryId || ''
    }).done(function(resp) {
        if (resp && resp.success && resp.rule && typeof callback === 'function') {
            callback(resp.rule);
        }
    });
}

function initManualProductNameAutocomplete($scope) {
    var $root = $scope && $scope.length ? $scope : $('#add_manual_product_modal');
    if (!(typeof $.ui !== 'undefined' && $.ui.autocomplete)) {
        return;
    }
    $root.find('#manual_products_container input[name*="[name]"]').each(function() {
        var $input = $(this);
        if ($input.data('manualPriceAutocompleteInit')) {
            return;
        }
        $input.autocomplete({
            minLength: 2,
            delay: 250,
            appendTo: '#add_manual_product_modal .modal-body',
            source: function(request, response) {
                response(getManualKeywordSuggestions(request.term));
            },
            select: function(event, ui) {
                event.preventDefault();
                var $row = $(this).closest('.manual_product_row');
                $(this).val(ui.item.value || ui.item.label || '');
                applyManualRuleToRow($row, ui.item);
                var catId = $row.find('input.manual_category_id').val() || '';
                var subCatId = $row.find('input.manual_sub_category_id').val() || '';
                resolveProductEntryRule($(this).val(), catId, subCatId, function(rule) {
                    applyResolvedProductEntryRuleToManualRow($row, rule);
                });
            }
        }).autocomplete('instance')._renderItem = function(ul, item) {
            var priceText = item.price ? ('<span class="text-success"> $' + item.price + '</span>') : '';
            return $('<li>').append('<div>' + item.label + priceText + '</div>').appendTo(ul);
        };
        $input.autocomplete('widget').css('z-index', 20000);
        $input.data('manualPriceAutocompleteInit', true);
    });
}

// Function to send AJAX request to get manual product rows
function getManualProductRows(inputData) {
    // Add CSRF token to the data
    inputData._token = $('meta[name="csrf-token"]').attr('content');
    
    
    $.ajax({
        method: 'POST',
        url: '/sells/pos/get_manual_product_rows',
        data: inputData,
        dataType: 'json',
        beforeSend: function() {
            // Show loading indicator if needed
            $('#add_manual_product_button').prop('disabled', true).text('Adding Products...');
        },
        success: function(result) {
            if (result.success) {
                let htmlContent = result.html_content;
                // Add the product rows to the POS table
                if (htmlContent) {
                    // If it's an array of HTML content (multiple products)
                    if (Array.isArray(htmlContent)) {
                        htmlContent.forEach(function(rowHtml) {
                            $('#pos_table tbody').append(rowHtml);
                        });
                    } else {
                        // Single product row
                        $('#pos_table tbody').append(htmlContent);
                    }

                    // Initialize the new row calculations for all new rows
                    var newRows = $('#pos_table tbody tr');
                    if (newRows.length) {
                        newRows.each(function() {
                            pos_each_row($(this));
                        });
                    }

                    // Close the modal
                    $('#add_manual_product_modal').modal('hide');

                    // Reset the modal form
                    resetManualProductModal();

                    // Show success message
                    var productCount = Array.isArray(htmlContent) ? htmlContent.length : 1;
                    // toastr.success(result.msg || productCount + ' product(s) added successfully');
                    
                    // Recalculate totals
                    if (typeof pos_total_row == 'function') {
                        pos_total_row();
                    }
                } else {
                    toastr.error('Failed to add product rows');
                }
            } else {
                toastr.error(result.msg || 'Failed to add products');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            toastr.error('An error occurred while adding the product');
        },
        complete: function() {
            // Re-enable the button
            $('#add_manual_product_button').prop('disabled', false).text('Add Products');
        }
    });
}

function set_payment_type_dropdown() {
    var payment_settings = $('#location_id').data('default_payment_accounts');
    payment_settings = payment_settings ? payment_settings : [];
    enabled_payment_types = [];
    for (var key in payment_settings) {
        if (payment_settings[key] && payment_settings[key]['is_enabled']) {
            enabled_payment_types.push(key);
        }
    }
    if (enabled_payment_types.length) {
        $(".payment_types_dropdown > option").each(function() {
            //skip if advance
            if ($(this).val() && $(this).val() != 'advance') {
                if (enabled_payment_types.indexOf($(this).val()) != -1) {
                    $(this).removeClass('hide');
                } else {
                    $(this).addClass('hide');
                }
            }
        });
    }

    // Always keep advance/store-credit payment available in POS payment rows.
    $('.payment_types_dropdown').each(function() {
        var $dropdown = $(this);
        var $advance = $dropdown.find('option[value="advance"]');
        if ($advance.length === 0) {
            $dropdown.append('<option value="advance">' + (LANG.advance || 'Advance') + '</option>');
        } else {
            $advance.removeClass('hide').prop('disabled', false);
        }
        if ($dropdown.hasClass('select2-hidden-accessible')) {
            $dropdown.trigger('change.select2');
        }
    });
}

function get_featured_products() {
    var location_id = $('#location_id').val();
    if (location_id && $('#featured_products_box').length > 0) {
        $.ajax({
            method: 'GET',
            url: '/sells/pos/get-featured-products/' + location_id,
            dataType: 'html',
            success: function(result) {
                if (result) {
                    $('#feature_product_div').removeClass('hide');
                    $('#featured_products_box').html(result);
                } else {
                    $('#feature_product_div').addClass('hide');
                    $('#featured_products_box').html('');
                }
            },
        });
    } else {
        $('#feature_product_div').addClass('hide');
        $('#featured_products_box').html('');
    }
}

function refresh_customer_credit_after_sale(contact_id, advance_used_for_sale, onDone) {
    $.ajax({
        url: '/sells/pos/get-customer-account-info',
        type: 'GET',
        data: { contact_id: contact_id },
        dataType: 'json',
        success: function(response) {
            if (response && response.success && response.data && response.data.contact) {
                var latest_balance = parseFloat(response.data.contact.balance || 0) || 0;

                if ($('#customer_id').val() == contact_id) {
                    $('#advance_balance').val(latest_balance);
                    $('#advance_balance_text').text(__currency_trans_from_en(latest_balance, true));
                    $('#customer_account_balance').text(__currency_trans_from_en(latest_balance, true));
                    updatePosStoreCreditUI(latest_balance);
                    if (typeof get_contact_due === 'function') {
                        get_contact_due(contact_id);
                    }
                }
                // Update Select2 option balance so next time this customer is selected the balance is correct
                var $opt = $('select#customer_id').find('option[value="' + contact_id + '"]');
                if ($opt.length && $opt.data('balance') !== undefined) {
                    $opt.data('balance', latest_balance);
                }
            } else if (advance_used_for_sale > 0 && $('#customer_id').val() == contact_id) {
                var current_balance = parseFloat($('#advance_balance').val() || 0) || 0;
                var adjusted = Math.max(0, current_balance - advance_used_for_sale);
                $('#advance_balance').val(adjusted);
                $('#advance_balance_text').text(__currency_trans_from_en(adjusted, true));
                $('#customer_account_balance').text(__currency_trans_from_en(adjusted, true));
                updatePosStoreCreditUI(adjusted);
                if (typeof get_contact_due === 'function') {
                    get_contact_due(contact_id);
                }
            }
            if (typeof onDone === 'function') {
                onDone();
            }
        },
        error: function() {
            if (typeof onDone === 'function') {
                onDone();
            }
        }
    });
}

function get_product_suggestion_list(category_id, brand_id, location_id, url = null, is_enabled_stock = null, repair_model_id = null) {
    if($('div#product_list_body').length == 0) {
        return false;
    }

    if (url == null) {
        url = '/sells/pos/get-product-suggestion';
    }
    $('#suggestion_page_loader').fadeIn(700);
    var page = $('input#suggestion_page').val();
    if (page == 1) {
        $('div#product_list_body').html('');
    }
    if ($('div#product_list_body').find('input#no_products_found').length > 0) {
        $('#suggestion_page_loader').fadeOut(700);
        return false;
    }
    $.ajax({
        method: 'GET',
        url: url,
        data: {
            category_id: category_id,
            brand_id: brand_id,
            location_id: location_id,
            page: page,
            is_enabled_stock: is_enabled_stock,
            repair_model_id: repair_model_id
        },
        dataType: 'html',
        success: function(result) {
            $('div#product_list_body').append(result);
            $('#suggestion_page_loader').fadeOut(700);
        },
    });
}

//Get recent transactions
function get_recent_transactions(status, element_obj) {
    if (element_obj.length == 0) {
        return false;
    }
    var transaction_sub_type = $("#transaction_sub_type").val();
    $.ajax({
        method: 'GET',
        url: '/sells/pos/get-recent-transactions',
        data: { status: status , transaction_sub_type: transaction_sub_type},
        dataType: 'html',
        success: function(result) {
            element_obj.html(result);
            __currency_convert_recursively(element_obj);
        },
    });
}

//variation_id is null when weighing_scale_barcode is used.
function pos_product_row(variation_id = null, purchase_line_id = null, weighing_scale_barcode = null, quantity = 1) {

    //Get item addition method
    var item_addtn_method = 0;
    var add_via_ajax = true;

    if (variation_id != null && $('#item_addition_method').length) {
        item_addtn_method = $('#item_addition_method').val();
    }

    if (item_addtn_method == 0) {
        add_via_ajax = true;
    } else {
        var is_added = false;

        //Search for variation id in each row of pos table
        $('#pos_table tbody')
            .find('tr')
            .each(function() {
                var row_v_id = $(this)
                    .find('.row_variation_id')
                    .val();
                var enable_sr_no = $(this)
                    .find('.enable_sr_no')
                    .val();
                var modifiers_exist = false;
                if ($(this).find('input.modifiers_exist').length > 0) {
                    modifiers_exist = true;
                }

                if (
                    row_v_id == variation_id &&
                    enable_sr_no !== '1' &&
                    !modifiers_exist &&
                    !is_added
                ) {
                    add_via_ajax = false;
                    is_added = true;

                    //Increment product quantity
                    qty_element = $(this).find('.pos_quantity');
                    var qty = __read_number(qty_element);
                    __write_number(qty_element, qty + 1);
                    qty_element.change();

                    round_row_to_iraqi_dinnar($(this));

                    $('input#search_product')
                        .focus()
                        .select();
                }
        });
    }

    if (add_via_ajax) {
        var product_row = $('input#product_row_count').val();
        var location_id = $('input#location_id').val();
        var customer_id = $('select#customer_id').val();
        var is_direct_sell = false;
        if (
            $('input[name="is_direct_sale"]').length > 0 &&
            $('input[name="is_direct_sale"]').val() == 1
        ) {
            is_direct_sell = true;
        }

        var disable_qty_alert = false;

        if ($('#disable_qty_alert').length) {
            disable_qty_alert = true;
        }

        var is_sales_order = $('#sale_type').length && $('#sale_type').val() == 'sales_order' ? true : false;

        var price_group = '';
        if ($('#price_group').length > 0) {
            price_group = parseInt($('#price_group').val());
        }

        //If default price group present
        if ($('#default_price_group').length > 0 && 
            price_group === '') {
            price_group = $('#default_price_group').val();
        }

        //If types of service selected give more priority
        if ($('#types_of_service_price_group').length > 0 && 
            $('#types_of_service_price_group').val()) {
            price_group = $('#types_of_service_price_group').val();
        }
        
        $.ajax({
            method: 'GET',
            url: '/sells/pos/get_product_row/' + variation_id + '/' + location_id,
            async: false,
            data: {
                product_row: product_row,
                customer_id: customer_id,
                is_direct_sell: is_direct_sell,
                price_group: price_group,
                purchase_line_id: purchase_line_id,
                weighing_scale_barcode: weighing_scale_barcode,
                quantity: quantity,
                is_sales_order: is_sales_order,
                disable_qty_alert: disable_qty_alert
            },
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    $('table#pos_table tbody')
                        .append(result.html_content)
                        .find('input.pos_quantity');
                    //increment row count
                    $('input#product_row_count').val(parseInt(product_row) + 1);
                    var this_row = $('table#pos_table tbody')
                        .find('tr')
                        .last();
                    
                    // Trigger tax dropdown change if it has a value to ensure it's properly selected and calculated
                    var taxSelect = this_row.find('select.tax_id');
                    if (taxSelect.length && taxSelect.val()) {
                        taxSelect.trigger('change');
                    } else {
                        // No tax selected - calculate row without tax
                        pos_each_row(this_row);
                    }
                    
                    // Ensure default tax is set in order tax if not already set
                    var current_tax_rate_id = $('#tax_rate_id').val();
                    if (!current_tax_rate_id || current_tax_rate_id === '' || current_tax_rate_id === null || current_tax_rate_id === '0' || current_tax_rate_id === 0) {
                        var default_tax = $('#tax_rate_id').data('default');
                        if (default_tax && default_tax !== '' && default_tax !== null && default_tax !== '0' && default_tax !== 0) {
                            $('#tax_rate_id').val(default_tax);
                            // Also set calculation amount if available
                            var default_calc_amount = $('#tax_calculation_amount').data('default');
                            if (default_calc_amount && default_calc_amount !== '' && default_calc_amount !== null) {
                                $('#tax_calculation_amount').val(default_calc_amount);
                            }
                        }
                    }
                    
                    // Ensure order tax is recalculated after adding product
                    pos_total_row();

                    //For initial discount if present
                    var line_total = __read_number(this_row.find('input.pos_line_total'));
                    this_row.find('span.pos_line_total_text').text(line_total);
                    
                    // Update discount display
                    update_discount_display(this_row);
                    
                    // Apply employee discount if checkbox is checked and customer is employee
                    if ($('#apply_employee_discount').is(':checked')) {
                        var isEmployee = $('#customer_id').data('is_employee');
                        if (isEmployee) {
                            var currentDiscount = __read_number(this_row.find('input.row_discount_amount'));
                            if (!currentDiscount || currentDiscount == 0) {
                                applyEmployeeDiscount(this_row);
                                update_discount_display(this_row);
                            }
                        }
                    }

                    pos_total_row();

                    //Check if multipler is present then multiply it when a new row is added.
                    if(__getUnitMultiplier(this_row) > 1){
                        this_row.find('select.sub_unit').trigger('change');
                    }

                    if (result.enable_sr_no == '1') {
                        var new_row = $('table#pos_table tbody')
                            .find('tr')
                            .last();
                        new_row.find('.row_edit_product_price_model').modal('show');
                    }

                    round_row_to_iraqi_dinnar(this_row);
                    __currency_convert_recursively(this_row);

                    $('input#search_product')
                        .focus()
                        .select();

                    //Used in restaurant module
                    if (result.html_modifier) {
                        $('table#pos_table tbody')
                            .find('tr')
                            .last()
                            .find('td:first')
                            .append(result.html_modifier);
                    }

                    //scroll bottom of items list
                    $(".pos_product_div").animate({ scrollTop: $('.pos_product_div').prop("scrollHeight")}, 1000);
                } else {
                    toastr.error(result.msg);
                    $('input#search_product')
                        .focus()
                        .select();
                }
            },
        });
    }
}

//Update values for each row
function pos_each_row(row_obj) {
    var unit_price = __read_number(row_obj.find('input.pos_unit_price'));

    var discounted_unit_price = calculate_discounted_unit_price(row_obj);
    var taxSelect = row_obj.find('select.tax_id');
    var tax_rate = 0;
    
    // Only get tax rate if tax is actually selected (not empty/null)
    if (taxSelect.length && taxSelect.val() && taxSelect.val() !== '' && taxSelect.val() !== null) {
        var selectedOption = taxSelect.find(':selected');
        if (selectedOption.length) {
            tax_rate = selectedOption.data('rate') || 0;
        }
    }

    var unit_price_inc_tax =
        discounted_unit_price + __calculate_amount('percentage', tax_rate, discounted_unit_price);
    __write_number(row_obj.find('input.pos_unit_price_inc_tax'), unit_price_inc_tax);

    var discount = __read_number(row_obj.find('input.row_discount_amount'));

    if (discount > 0) {
        var qty = Math.floor(__read_number(row_obj.find('input.pos_quantity')) || 0);
        var line_total = qty * unit_price_inc_tax;
        __write_number(row_obj.find('input.pos_line_total'), line_total);
    }

    //var unit_price_inc_tax = __read_number(row_obj.find('input.pos_unit_price_inc_tax'));

    __write_number(row_obj.find('input.item_tax'), unit_price_inc_tax - discounted_unit_price);
    
    // Update discount display
    update_discount_display(row_obj);
}

function pos_total_row() {
    var total_quantity = 0;
    var price_total = get_subtotal();
    // Bag Fee isn't a product — Sarah asked to exclude it from the ITEMS
    // count on the totals block. The bag row carries data-plastic-bag="true"
    // and auto-adds itself when the Bag Fee checkbox is on; counting it made
    // a 1-item sale look like 2. Price total still includes the bag fee
    // (that one stays in get_subtotal).
    $('table#pos_table tbody tr:not([data-plastic-bag="true"])').each(function() {
        total_quantity = total_quantity + Math.floor(__read_number($(this).find('input.pos_quantity')) || 0);
    });

    //updating shipping charges
    $('span#shipping_charges_amount').text(
        __currency_trans_from_en(__read_number($('input#shipping_charges_modal')), false)
    );

    $('span.total_quantity').each(function() {
        $(this).html(__number_f(total_quantity, false, false, 0));
    });

    //$('span.unit_price_total').html(unit_price_total);
    $('span.price_total').html(__currency_trans_from_en(price_total, false));
    calculate_billing_details(price_total);
}

function get_subtotal() {
    var price_total = 0;

    $('table#pos_table tbody tr').each(function() {
        price_total = price_total + __read_number($(this).find('input.pos_line_total'));
    });

    //Go through the modifier prices.
    $('input.modifiers_price').each(function() {
        var modifier_price = __read_number($(this));
        var modifier_quantity = $(this).closest('.product_modifier').find('.modifiers_quantity').val();
        var modifier_subtotal = modifier_price * modifier_quantity;
        price_total = price_total + modifier_subtotal;
    });

    return price_total;
}

function get_taxable_subtotal() {
    // Get subtotal for ORDER tax calculation
    // Include ALL products EXCEPT:
    // 1. Bag fee (data-plastic-bag="true")
    // 2. Products explicitly marked as tax-exempt (data-tax-exempt="true")
    var taxable_total = 0;

    $('table#pos_table tbody tr').each(function() {
        // Skip bag fee rows (always tax-exempt)
        if ($(this).attr('data-plastic-bag') === 'true') {
            return true; // continue to next iteration
        }
        
        // Skip tax-exempt products (marked with data-tax-exempt="true")
        if ($(this).data('tax-exempt') === true || $(this).data('tax-exempt') === 'true' || $(this).attr('data-tax-exempt') === 'true') {
            return true; // continue to next iteration
        }
        
        // Include ALL other products in taxable total
        // This includes products with or without tax_id - ORDER tax applies to all non-exempt products
        var rowTotal = __read_number($(this).find('input.pos_line_total'));
        taxable_total = taxable_total + rowTotal;
    });

    //Go through the modifier prices.
    $('input.modifiers_price').each(function() {
        var modifier_price = __read_number($(this));
        var modifier_quantity = $(this).closest('.product_modifier').find('.modifiers_quantity').val();
        var modifier_subtotal = modifier_price * modifier_quantity;
        taxable_total = taxable_total + modifier_subtotal;
    });

    return taxable_total;
}

function calculate_billing_details(price_total) {
    var discount = pos_discount(price_total);
    if ($('#reward_point_enabled').length) {
        total_customer_reward = $('#rp_redeemed_amount').val();
        discount = parseFloat(discount) + parseFloat(total_customer_reward);

        if ($('input[name="is_direct_sale"]').length <= 0) {
            $('span#total_discount').text(__currency_trans_from_en(discount, false));
        }
    }

    var order_tax = pos_order_tax(price_total, discount);

    //Add shipping charges.
    var shipping_charges = __read_number($('input#shipping_charges'));

    var additional_expense = 0;
    //calculate additional expenses
    if ($('input#additional_expense_value_1').length > 0) {
        additional_expense += __read_number($('input#additional_expense_value_1'));
    }
    if ($('input#additional_expense_value_2').length > 0) {
        additional_expense += __read_number($('input#additional_expense_value_2'))
    }
    if ($('input#additional_expense_value_3').length > 0) {
        additional_expense += __read_number($('input#additional_expense_value_3'))
    }
    if ($('input#additional_expense_value_4').length > 0) {
        additional_expense += __read_number($('input#additional_expense_value_4'))
    }

    //Add packaging charge
    var packing_charge = 0;
    if ($('#types_of_service_id').length > 0 && 
            $('#types_of_service_id').val()) {
        packing_charge = __calculate_amount($('#packing_charge_type').val(), 
            __read_number($('input#packing_charge')), price_total);

        $('#packing_charge_text').text(__currency_trans_from_en(packing_charge, false));
    }

    // Get rounding multiple for calculations
    var rounding_multiple = $('#amount_rounding_method').val() ? parseFloat($('#amount_rounding_method').val()) : 0;

    // Calculate pre-tax amount (what goes into Clover device)
    // Pre-tax = subtotal - discount + shipping + packing + additional expenses (before order tax)
    var pre_tax_amount = price_total - discount + shipping_charges + packing_charge + additional_expense;
    
    // Apply rounding to pre-tax amount if rounding is enabled
    var pre_tax_rounded = pre_tax_amount;
    if (rounding_multiple > 0) {
        var pre_tax_round_data = __round(pre_tax_amount, rounding_multiple);
        pre_tax_rounded = pre_tax_round_data.number;
    }
    
    // Display pre-tax amount (what cashiers enter into Clover)
    var curr_exchange_rate = 1;
    if ($('#exchange_rate').length > 0 && $('#exchange_rate').val()) {
        curr_exchange_rate = __read_number($('#exchange_rate'));
    }
    var shown_pre_tax = pre_tax_rounded * curr_exchange_rate;
    $('span#pre_tax_amount').text(__currency_trans_from_en(shown_pre_tax, false));

    var total_payable = price_total + order_tax - discount + shipping_charges + packing_charge + additional_expense;
    var round_off_data = __round(total_payable, rounding_multiple);
    var total_payable_rounded = round_off_data.number;

    var round_off_amount = round_off_data.diff;
    if (round_off_amount != 0) {
        $('span#round_off_text').text(__currency_trans_from_en(round_off_amount, false));
    } else {
        $('span#round_off_text').text(0);
    }
    $('input#round_off_amount').val(round_off_amount);

    __write_number($('input#final_total_input'), total_payable_rounded);
    // curr_exchange_rate already calculated above for pre_tax_amount
    var shown_total = total_payable_rounded * curr_exchange_rate;
    $('span#total_payable').text(__currency_trans_from_en(shown_total, false));
    // Update total with tax in totals section
    $('span#total_with_tax').text(__currency_trans_from_en(shown_total, false));

    $('span.total_payable_span').text(__currency_trans_from_en(total_payable_rounded, true));

    //Check if edit form then don't update price.
    if ($('form#edit_pos_sell_form').length == 0 && $('form#edit_sell_form').length == 0) {
        __write_number($('.payment-amount').first(), total_payable_rounded);
    }

    $(document).trigger('invoice_total_calculated');

    calculate_balance_due();
    // If store credit is set, update the visible order summary spans to match remaining due.
    apply_store_credit_to_order_totals_display();
}

function pos_discount(total_amount) {
    var calculation_type = $('#discount_type').val();
    var calculation_amount = __read_number($('#discount_amount'));

    var discount = __calculate_amount(calculation_type, calculation_amount, total_amount);

    $('span#total_discount').text(__currency_trans_from_en(discount, false));

    return discount;
}

function pos_order_tax(price_total, discount) {
    // Use taxable subtotal (excluding bag fee and tax-exempt products) for tax calculation
    var taxable_total = get_taxable_subtotal();
    var total_amount = taxable_total - discount;
    
    // Get tax_rate_id - check value first, then data-default
    var tax_rate_id = $('#tax_rate_id').val();
    var calculation_type = 'percentage';
    var calculation_amount = __read_number($('#tax_calculation_amount'));
    
    // If tax_rate_id is empty/null/0, get from data-default
    if (!tax_rate_id || tax_rate_id === '' || tax_rate_id === null || tax_rate_id === '0' || tax_rate_id === 0) {
        tax_rate_id = $('#tax_rate_id').data('default');
        if (tax_rate_id && tax_rate_id !== '' && tax_rate_id !== null && tax_rate_id !== '0' && tax_rate_id !== 0) {
            $('#tax_rate_id').val(tax_rate_id);
        }
    }
    
    // If calculation_amount is 0 or empty, get from data-default
    if (!calculation_amount || calculation_amount === 0) {
        var default_calc_amount = $('#tax_calculation_amount').data('default');
        if (default_calc_amount && default_calc_amount !== '' && default_calc_amount !== null) {
            calculation_amount = parseFloat(default_calc_amount);
            if (calculation_amount && calculation_amount > 0) {
                $('#tax_calculation_amount').val(calculation_amount);
            }
        }
    }
    
    // If still no tax_rate_id but we have taxable items, try to get from first taxable product
    if ((!tax_rate_id || tax_rate_id === '' || tax_rate_id === null || tax_rate_id === '0' || tax_rate_id === 0) && taxable_total > 0) {
        var firstTaxableRow = $('table#pos_table tbody tr').not('[data-plastic-bag="true"]').filter(function() {
            var taxSelect = $(this).find('select.tax_id');
            if (taxSelect.length) {
                var taxValue = taxSelect.val();
                return taxValue && taxValue !== '' && taxValue !== null && taxValue !== '0' && taxValue !== 0;
            }
            return false;
        }).first();
        
        if (firstTaxableRow.length) {
            var productTaxId = firstTaxableRow.find('select.tax_id').val();
            if (productTaxId && productTaxId !== '' && productTaxId !== null && productTaxId !== '0' && productTaxId !== 0) {
                tax_rate_id = productTaxId;
                $('#tax_rate_id').val(tax_rate_id);
                var productTaxRate = firstTaxableRow.find('select.tax_id').find(':selected').data('rate') || 0;
                if (productTaxRate > 0) {
                    calculation_amount = productTaxRate;
                    $('#tax_calculation_amount').val(calculation_amount);
                }
            }
        }
    }

    // Calculate order tax if we have valid tax_rate_id, taxable_total, and calculation_amount
    var order_tax = 0;
    if (tax_rate_id && tax_rate_id !== '' && tax_rate_id !== null && tax_rate_id !== '0' && tax_rate_id !== 0) {
        if (taxable_total > 0) {
            if (calculation_amount && calculation_amount > 0) {
                order_tax = __calculate_amount(calculation_type, calculation_amount, total_amount);
            } else {
                // If calculation_amount is missing, try to get it from the tax dropdown
                var taxSelect = $('select.tax_id').first();
                if (taxSelect.length && taxSelect.val() === tax_rate_id) {
                    var taxRate = taxSelect.find(':selected').data('rate');
                    if (taxRate && taxRate > 0) {
                        calculation_amount = taxRate;
                        $('#tax_calculation_amount').val(calculation_amount);
                        order_tax = __calculate_amount(calculation_type, calculation_amount, total_amount);
                    }
                }
            }
        }
    }

    $('span#order_tax').text(__currency_trans_from_en(order_tax, false));
    // Also update tax display in totals section
    $('span#order_tax_display').text(__currency_trans_from_en(order_tax, false));

    return order_tax;
}

function calculate_balance_due() {
    var total_payable = __read_number($('#final_total_input'));
    var total_paying = 0;
    $('#payment_rows_div')
        .find('.payment-amount')
        .each(function() {
            if (parseFloat($(this).val())) {
                total_paying += __read_number($(this));
            }
        });
    var bal_due = total_payable - total_paying;
    var change_return = 0;

    //change_return
    if (bal_due < 0 || Math.abs(bal_due) < 0.05) {
        __write_number($('input#change_return'), bal_due * -1);
        $('span.change_return_span').text(__currency_trans_from_en(bal_due * -1, true));
        change_return = bal_due * -1;
        bal_due = 0;
    } else {
        __write_number($('input#change_return'), 0);
        $('span.change_return_span').text(__currency_trans_from_en(0, true));
        change_return = 0;
        
    }

    if (change_return !== 0) {
        $('#change_return_payment_data').removeClass('hide');
    } else {
        $('#change_return_payment_data').addClass('hide');
    }

    __write_number($('input#total_paying_input'), total_paying);
    $('span.total_paying').text(__currency_trans_from_en(total_paying, true));

    __write_number($('input#in_balance_due'), bal_due);
    $('span.balance_due').text(__currency_trans_from_en(bal_due, true));

    // Bottom "Total Payable" should match visible top totals.
    // When store credit is applied, show invoice total minus store credit used.
    if ($('span#total_payable').length) {
        var invoice_total = __read_number($('input#final_total_input'));
        var store_credit_used_amount = parseFloat($('#store_credit_used_amount').val() || 0) || 0;
        var payable_display = invoice_total;
        if (store_credit_used_amount > 0) {
            payable_display = invoice_total - store_credit_used_amount;
            if (payable_display < 0) {
                payable_display = 0;
            }
        }
        $('span#total_payable').text(__currency_trans_from_en(payable_display, false));
    }

    __highlight(bal_due * -1, $('span.balance_due'));
    __highlight(change_return * -1, $('span.change_return_span'));
}

/**
 * Store credit in POS is treated like a payment (advance) and only affects remaining due.
 * However, the visible order summary lines ("Without Tax / Tax / Total (with Tax)")
 * can be expected to reflect remaining due too. This updates only those spans.
 */
function apply_store_credit_to_order_totals_display() {
    var store_credit_used_amount = parseFloat($('#store_credit_used_amount').val() || 0) || 0;
    if (store_credit_used_amount <= 0) {
        return;
    }

    var $totalWithTax = $('span#total_with_tax');
    var $preTax = $('span#pre_tax_amount');
    var $orderTaxDisplay = $('span#order_tax_display');
    var $orderTax = $('span#order_tax');

    if (!$totalWithTax.length || !$preTax.length) {
        return;
    }

    var totalWithTaxDisp = 0;
    try {
        totalWithTaxDisp = __number_uf($totalWithTax.text(), false);
    } catch (e) {}

    if (!totalWithTaxDisp || totalWithTaxDisp <= 0) {
        return;
    }

    var exchangeRate = 1;
    if ($('#exchange_rate').length > 0 && $('#exchange_rate').val()) {
        exchangeRate = __read_number($('#exchange_rate'));
    }

    var creditDisp = store_credit_used_amount * exchangeRate;
    var remainingTotalWithTax = totalWithTaxDisp - creditDisp;
    if (remainingTotalWithTax < 0) {
        remainingTotalWithTax = 0;
    }

    var orderTaxDisp = 0;
    if ($orderTaxDisplay.length) {
        try {
            orderTaxDisp = __number_uf($orderTaxDisplay.text(), false);
        } catch (e) {
            orderTaxDisp = 0;
        }
    }

    // Reduce tax proportionally so: pre-tax + tax = total-with-tax.
    var taxRatio = totalWithTaxDisp > 0 ? orderTaxDisp / totalWithTaxDisp : 0;
    var remainingOrderTax = remainingTotalWithTax * taxRatio;
    var remainingPreTax = remainingTotalWithTax - remainingOrderTax;

    $preTax.text(__currency_trans_from_en(remainingPreTax, false));
    if ($orderTaxDisplay.length) {
        $orderTaxDisplay.text(__currency_trans_from_en(remainingOrderTax, false));
    }
    if ($orderTax.length) {
        $orderTax.text(__currency_trans_from_en(remainingOrderTax, false));
    }
    $totalWithTax.text(__currency_trans_from_en(remainingTotalWithTax, false));
}

function isValidPosForm() {
    flag = true;
    $('span.error').remove();

    if ($('select#customer_id').val() == null) {
        flag = false;
        error = '<span class="error">' + LANG.required + '</span>';
        $(error).insertAfter($('select#customer_id').parent('div'));
    }

    if ($('tr.product_row').length == 0) {
        flag = false;
        error = '<span class="error">' + LANG.no_products + '</span>';
        $(error).insertAfter($('input#search_product').parent('div'));
    }

    return flag;
}

function reset_pos_form(){
    window.pos_submit_in_progress = false;

	//If on edit page then redirect to Add POS page
	if($('form#edit_pos_sell_form').length > 0){
		setTimeout(function() {
			window.location = $("input#pos_redirect_url").val();
		}, 4000);
		return true;
	}
	
    //reset all repair defects tags
    if ($("#repair_defects").length > 0) {
        tagify_repair_defects.removeAllTags();
    }

	if(pos_form_obj[0]){
		pos_form_obj[0].reset();
	}
	if(sell_form[0]){
		sell_form[0].reset();
	}
	set_default_customer();
	set_location();

	$('tr.product_row').remove();
	$('span.total_quantity, span.price_total, span#total_discount, span#order_tax, span#order_tax_display, span#total_payable, span#total_with_tax, span#shipping_charges_amount').text(0);
	$('span.total_payable_span', 'span.total_paying', 'span.balance_due').text(0);

	$('#modal_payment').find('.remove_payment_row').each( function(){
		$(this).closest('.payment_row').remove();
	});

    if ($('#is_credit_sale').length) {
        $('#is_credit_sale').val(0);
    }
    if ($('#store_credit_used_amount').length) {
        $('#store_credit_used_amount').val(0);
    }
    if (typeof window.set_store_credit_cash_cta === 'function') {
        window.set_store_credit_cash_cta(false);
    }

	//Reset discount
	__write_number($('input#discount_amount'), $('input#discount_amount').data('default'));
	$('input#discount_type').val($('input#discount_type').data('default'));

	//Reset tax rate
	$('input#tax_rate_id').val($('input#tax_rate_id').data('default'));
	__write_number($('input#tax_calculation_amount'), $('input#tax_calculation_amount').data('default'));

	$('select.payment_types_dropdown').val('cash').trigger('change');
	$('#price_group').trigger('change');

	//Reset shipping
	__write_number($('input#shipping_charges'), $('input#shipping_charges').data('default'));
	$('input#shipping_details').val($('input#shipping_details').data('default'));
    $('input#shipping_address, input#shipping_status, input#delivered_to').val('');
	if($('input#is_recurring').length > 0){
		$('input#is_recurring').iCheck('update');
	};
    if($('#invoice_layout_id').length > 0){
        $('#invoice_layout_id').trigger('change');
    };
    $('span#round_off_text').text(0);

    //repair module extra  fields reset
    if ($('#repair_device_id').length > 0) {
        $('#repair_device_id').val('').trigger('change');
    }

    //Status is hidden in sales order
    if ($('#status').length > 0 && $('#status').is(":visible")) {
        $('#status').val('').trigger('change');
    }
    if ($('#transaction_date').length > 0) {
        $('#transaction_date').data("DateTimePicker").date(moment());
    }
    if ($('.paid_on').length > 0) {
        $('.paid_on').data("DateTimePicker").date(moment());
    }
    if ($('#commission_agent').length > 0) {
        $('#commission_agent').val('').trigger('change');
    } 

    //reset contact due
    $('.contact_due_text').find('span').text('');
    $('.contact_due_text').addClass('hide');

    $(document).trigger('sell_form_reset');
}

function set_default_customer() {
    var default_customer_id = $('#default_customer_id').val();
    var default_customer_name = $('#default_customer_name').val();
    var default_customer_display_name = $('#default_customer_display_name').length ? $('#default_customer_display_name').val() : default_customer_name;
    var default_customer_balance = $('#default_customer_balance').val();
    var default_customer_address = $('#default_customer_address').val();
    var exists = default_customer_id ? $('select#customer_id option[value=' + default_customer_id + ']').length : 0;
    if (exists == 0 && default_customer_id) {
        $('select#customer_id').append(
            $('<option>', { value: default_customer_id, text: default_customer_display_name || default_customer_name })
        );
    }
    $('#advance_balance_text').text(__currency_trans_from_en(default_customer_balance), true);
    $('#advance_balance').val(default_customer_balance);
    $('#shipping_address_modal').val(default_customer_address);
    if (default_customer_address) {
        $('#shipping_address').val(default_customer_address);
    }
    $('select#customer_id')
        .val(default_customer_id)
        .trigger('change');

    if ($('#default_selling_price_group').length) {
        $('#price_group').val($('#default_selling_price_group').val());
        $('#price_group').change();
    }

    //initialize tags input (tagify)
    if ($("textarea#repair_defects").length > 0 && !customer_set) {
        let suggestions = [];
        if ($("input#pos_repair_defects_suggestion").length > 0 && $("input#pos_repair_defects_suggestion").val().length > 2) {
            suggestions = JSON.parse($("input#pos_repair_defects_suggestion").val());    
        }
        let repair_defects = document.querySelector('textarea#repair_defects');
        tagify_repair_defects = new Tagify(repair_defects, {
                  whitelist: suggestions,
                  maxTags: 100,
                  dropdown: {
                    maxItems: 100,           // <- mixumum allowed rendered suggestions
                    classname: "tags-look", // <- custom classname for this dropdown, so it could be targeted
                    enabled: 0,             // <- show suggestions on focus
                    closeOnSelect: false    // <- do not hide the suggestions dropdown once an item has been selected
                  }
                });
    }

    customer_set = true;
}

//Set the location and initialize printer
function set_location() {
    if ($('select#select_location_id').length == 1) {
        $('input#location_id').val($('select#select_location_id').val());
        $('input#location_id').data(
            'receipt_printer_type',
            $('select#select_location_id')
                .find(':selected')
                .data('receipt_printer_type')
        );
        $('input#location_id').data(
            'default_payment_accounts',
            $('select#select_location_id')
                .find(':selected')
                .data('default_payment_accounts')
        );

        $('input#location_id').attr(
            'data-default_price_group',
            $('select#select_location_id')
                .find(':selected')
                .data('default_price_group')
        );
    }

    if ($('input#location_id').val()) {
        $('input#search_product')
            .prop('disabled', false)
            .focus();
    } else {
        $('input#search_product').prop('disabled', true);
    }

    initialize_printer();
}

function initialize_printer() {
    if ($('input#location_id').data('receipt_printer_type') == 'printer') {
        initializeSocket();
    }
}

$('body').on('click', 'label', function(e) {
    var field_id = $(this).attr('for');
    if (field_id) {
        if ($('#' + field_id).hasClass('select2')) {
            $('#' + field_id).select2('open');
            return false;
        }
    }
});

$('body').on('focus', 'select', function(e) {
    var field_id = $(this).attr('id');
    if (field_id) {
        if ($('#' + field_id).hasClass('select2')) {
            $('#' + field_id).select2('open');
            return false;
        }
    }
});

function round_row_to_iraqi_dinnar(row) {
    if (iraqi_selling_price_adjustment) {
        var element = row.find('input.pos_unit_price_inc_tax');
        var unit_price = round_to_iraqi_dinnar(__read_number(element));
        __write_number(element, unit_price);
        element.change();
    }
}

function pos_print(receipt) {
    //If printer type then connect with websocket
    if (receipt.print_type == 'printer') {
        var content = receipt;
        content.type = 'print-receipt';

        //Check if ready or not, then print.
        if (socket != null && socket.readyState == 1) {
            socket.send(JSON.stringify(content));
        } else {
            initializeSocket();
            setTimeout(function() {
                socket.send(JSON.stringify(content));
            }, 700);
        }

    } else if (receipt.html_content != '') {
        var title = document.title;
        if (typeof receipt.print_title != 'undefined') {
            document.title = receipt.print_title;
        }

        //If printer type browser then print content
        $('#receipt_section').html(receipt.html_content);
        __currency_convert_recursively($('#receipt_section'));
        __print_receipt('receipt_section');

        setTimeout(function() {
            document.title = title;
        }, 1200);
    }
}

function calculate_discounted_unit_price(row) {
    var this_unit_price = __read_number(row.find('input.pos_unit_price'));
    var row_discounted_unit_price = this_unit_price;
    var row_discount_type = row.find('select.row_discount_type').val();
    var row_discount_amount = __read_number(row.find('input.row_discount_amount'));
    if (row_discount_amount) {
        if (row_discount_type == 'fixed') {
            row_discounted_unit_price = this_unit_price - row_discount_amount;
        } else {
            row_discounted_unit_price = __substract_percent(this_unit_price, row_discount_amount);
        }
    }

    return row_discounted_unit_price;
}

// Apply employee discount to a product row
function applyEmployeeDiscount(row) {
    var discountType = row.find('select.row_discount_type');
    var discountAmount = row.find('input.row_discount_amount');
    
    // Check if discount fields exist (they might be hidden but still in DOM)
    if (discountType.length && discountAmount.length) {
        // Only apply if no discount is already set
        var currentDiscount = __read_number(discountAmount);
        if (!currentDiscount || currentDiscount == 0) {
            // Set the values even if fields are hidden
            discountType.val('percentage');
            discountAmount.val(20);
            
            // Make sure fields are visible temporarily to trigger change (if needed)
            var wasHidden = discountType.closest('td').hasClass('hide') || discountType.closest('td').is(':hidden');
            if (wasHidden) {
                discountType.closest('td').removeClass('hide').show();
            }
            
            // Trigger change to recalculate
            discountType.trigger('change');
            discountAmount.trigger('change');
            
            // Hide again if it was hidden
            if (wasHidden) {
                setTimeout(function() {
                    discountType.closest('td').addClass('hide').hide();
                }, 100);
            }
        }
    } else {
        // If discount fields don't exist at all, create hidden inputs
        var rowIndex = row.data('row_index') || row.find('input[name*="[product_id]"]').attr('name').match(/\[(\d+)\]/)[1];
        
        // Check if hidden inputs already exist
        if (row.find('input[name="products[' + rowIndex + '][line_discount_type]"]').length == 0) {
            row.append('<input type="hidden" name="products[' + rowIndex + '][line_discount_type]" value="percentage" class="row_discount_type">');
            row.append('<input type="hidden" name="products[' + rowIndex + '][line_discount_amount]" value="20" class="row_discount_amount">');
        } else {
            row.find('input[name="products[' + rowIndex + '][line_discount_type]"]').val('percentage');
            row.find('input[name="products[' + rowIndex + '][line_discount_amount]"]').val(20);
        }
        
        // Manually recalculate the row
        pos_each_row(row);
        update_discount_display(row);
        pos_total_row();
    }
}

// Update discount display in product row
function update_discount_display(row_obj) {
    var discount_display = row_obj.find('.row_discount_display');
    var discount_amount = __read_number(row_obj.find('input.row_discount_amount'));
    var discount_type = row_obj.find('select.row_discount_type').val();
    var unit_price = __read_number(row_obj.find('input.pos_unit_price'));
    var quantity = Math.floor(__read_number(row_obj.find('input.pos_quantity')) || 0);
    var tax_rate = row_obj.find('select.tax_id').find(':selected').data('rate') || 0;
    
    if (discount_amount > 0) {
        // Calculate original price (before discount) with tax
        var original_unit_price_inc_tax = __add_percent(unit_price, tax_rate);
        var original_line_total = quantity * original_unit_price_inc_tax;
        
        // Calculate discount amount
        var discount_value = 0;
        if (discount_type == 'fixed') {
            discount_value = discount_amount * quantity;
        } else {
            discount_value = (discount_amount / 100) * original_line_total;
        }
        
        // Calculate final price (after discount) with tax
        var discounted_unit_price = calculate_discounted_unit_price(row_obj);
        var final_unit_price_inc_tax = __add_percent(discounted_unit_price, tax_rate);
        var final_line_total = quantity * final_unit_price_inc_tax;
        
        // Update display
        discount_display.find('.original_price_text').html(
            'Original: <span class="display_currency" data-currency_symbol="true">' + original_line_total + '</span>'
        );
        
        if (discount_type == 'fixed') {
            discount_display.find('.discount_applied_text').html(
                '- Discount: <span class="display_currency" data-currency_symbol="true">' + discount_value + '</span>'
            );
        } else {
            discount_display.find('.discount_applied_text').html(
                '- Discount: <span class="display_currency" data-currency_symbol="true">' + discount_value + '</span> (' + discount_amount + '%)'
            );
        }
        
        discount_display.find('.final_price_text').html(
            'Final: <span class="display_currency" data-currency_symbol="true">' + final_line_total + '</span>'
        );
        
        discount_display.show();
    } else {
        discount_display.hide();
    }
}

function get_unit_price_from_discounted_unit_price(row, discounted_unit_price) {
    var this_unit_price = discounted_unit_price;
    var row_discount_type = row.find('select.row_discount_type').val();
    var row_discount_amount = __read_number(row.find('input.row_discount_amount'));
    if (row_discount_amount) {
        if (row_discount_type == 'fixed') {
            this_unit_price = discounted_unit_price + row_discount_amount;
        } else {
            this_unit_price = __get_principle(discounted_unit_price, row_discount_amount, true);
        }
    }

    return this_unit_price;
}

//Update quantity if line subtotal changes
$('table#pos_table tbody').on('change', 'input.pos_line_total', function() {
    var subtotal = __read_number($(this));
    var tr = $(this).parents('tr');
    var quantity_element = tr.find('input.pos_quantity');
    var unit_price_inc_tax = __read_number(tr.find('input.pos_unit_price_inc_tax'));
    var quantity = Math.floor(subtotal / unit_price_inc_tax) || 0;
    __write_number(quantity_element, quantity);

    if (sell_form_validator) {
        sell_form_validator.element(quantity_element);
    }
    if (pos_form_validator) {
        pos_form_validator.element(quantity_element);
    }
    tr.find('span.pos_line_total_text').text(__currency_trans_from_en(subtotal, true));

    pos_total_row();
});

$('div#product_list_body').on('scroll', function() {
    if ($(this).scrollTop() + $(this).innerHeight() >= $(this)[0].scrollHeight) {
        var page = parseInt($('#suggestion_page').val());
        page += 1;
        $('#suggestion_page').val(page);
        var location_id = $('input#location_id').val();
        var category_id = $('select#product_category').val();
        var brand_id = $('select#product_brand').val();

        var is_enabled_stock = null;
        if ($("#is_enabled_stock").length) {
            is_enabled_stock = $("#is_enabled_stock").val();
        }

        var device_model_id = null;
        if ($("#repair_model_id").length) {
            device_model_id = $("#repair_model_id").val();
        }

        get_product_suggestion_list(category_id, brand_id, location_id, null, is_enabled_stock, device_model_id);
    }
});

$(document).on('ifChecked', '#is_recurring', function() {
    $('#recurringInvoiceModal').modal('show');
});

$(document).on('shown.bs.modal', '#recurringInvoiceModal', function() {
    $('input#recur_interval').focus();
});

$(document).on('click', '#select_all_service_staff', function() {
    var val = $('#res_waiter_id').val();
    $('#pos_table tbody')
        .find('select.order_line_service_staff')
        .each(function() {
            $(this)
                .val(val)
                .change();
        });
});

$(document).on('click', '.print-invoice-link', function(e) {
    e.preventDefault();
    $.ajax({
        url: $(this).attr('href') + "?check_location=true",
        dataType: 'json',
        success: function(result) {
            if (result.success == 1) {
                //Check if enabled or not
                if (result.receipt.is_enabled) {
                    pos_print(result.receipt);
                }
            } else {
                toastr.error(result.msg);
            }

        },
    });
});

function getCustomerRewardPoints() {
    if ($('#reward_point_enabled').length <= 0) {
        return false;
    }
    var is_edit = $('form#edit_sell_form').length || 
    $('form#edit_pos_sell_form').length ? true : false;
    if (is_edit && !customer_set) {
        return false;
    }

    var customer_id = $('#customer_id').val();

    $.ajax({
        method: 'POST',
        url: '/sells/pos/get-reward-details',
        data: { 
            customer_id: customer_id
        },
        dataType: 'json',
        success: function(result) {
            $('#available_rp').text(result.points);
            $('#rp_redeemed_modal').data('max_points', result.points);
            updateRedeemedAmount();
            $('#rp_redeemed_amount').change()
        },
    });
}

function updateRedeemedAmount(argument) {
    var points = $('#rp_redeemed_modal').val().trim();
    points = points == '' ? 0 : parseInt(points);
    var amount_per_unit_point = parseFloat($('#rp_redeemed_modal').data('amount_per_unit_point'));
    var redeemed_amount = points * amount_per_unit_point;
    $('#rp_redeemed_amount_text').text(__currency_trans_from_en(redeemed_amount, true));
    $('#rp_redeemed').val(points);
    $('#rp_redeemed_amount').val(redeemed_amount);
}

$(document).on('change', 'select#customer_id', function(){
    var default_customer_id = $('#default_customer_id').val();
    if ($(this).val() == default_customer_id) {
        //Disable reward points for walkin customers
        if ($('#rp_redeemed_modal').length) {
            $('#rp_redeemed_modal').val('');
            $('#rp_redeemed_modal').change();
            $('#rp_redeemed_modal').attr('disabled', true);
            $('#available_rp').text('');
            updateRedeemedAmount();
            pos_total_row();
        }
    } else {
        if ($('#rp_redeemed_modal').length) {
            $('#rp_redeemed_modal').removeAttr('disabled');
        }
        getCustomerRewardPoints();
    }

    get_sales_orders();
});

$(document).on('change', '#rp_redeemed_modal', function(){
    var points = $(this).val().trim();
    points = points == '' ? 0 : parseInt(points);
    var amount_per_unit_point = parseFloat($(this).data('amount_per_unit_point'));
    var redeemed_amount = points * amount_per_unit_point;
    $('#rp_redeemed_amount_text').text(__currency_trans_from_en(redeemed_amount, true));
    var reward_validation = isValidatRewardPoint();
    if (!reward_validation['is_valid']) {
        toastr.error(reward_validation['msg']);
        $('#rp_redeemed_modal').select();
    }
});

$(document).on('change', '.direct_sell_rp_input', function(){
    updateRedeemedAmount();
    pos_total_row();
});

function isValidatRewardPoint() {
    var element = $('#rp_redeemed_modal');
    var points = element.val().trim();
    points = points == '' ? 0 : parseInt(points);

    var max_points = parseInt(element.data('max_points'));
    var is_valid = true;
    var msg = '';

    if (points == 0) {
        return {
            is_valid: is_valid,
            msg: msg
        }
    }

    var rp_name = $('input#rp_name').val();
    if (points > max_points) {
        is_valid = false;
        msg = __translate('max_rp_reached_error', {max_points: max_points, rp_name: rp_name});
    }

    var min_order_total_required = parseFloat(element.data('min_order_total'));

    var order_total = __read_number($('#final_total_input'));

    if (order_total < min_order_total_required) {
        is_valid = false;
        msg = __translate('min_order_total_error', {min_order: __currency_trans_from_en(min_order_total_required, true), rp_name: rp_name});
    }

    var output = {
        is_valid: is_valid,
        msg: msg,
    }

    return output;
}

function adjustComboQty(tr){
    if(tr.find('input.product_type').val() == 'combo'){
        var qty = Math.floor(__read_number(tr.find('input.pos_quantity')) || 0);
        var multiplier = __getUnitMultiplier(tr);

        tr.find('input.combo_product_qty').each(function(){
            $(this).val($(this).data('unit_quantity') * qty * multiplier);
        });
    }
}

$(document).on('change', '#types_of_service_id', function(){
    var types_of_service_id = $(this).val();
    var location_id = $('#location_id').val();

    if(types_of_service_id) {
        $.ajax({
            method: 'POST',
            url: '/sells/pos/get-types-of-service-details',
            data: { 
                types_of_service_id: types_of_service_id,
                location_id: location_id
            },
            dataType: 'json',
            success: function(result) {
                //reset form if price group is changed
                var prev_price_group = $('#types_of_service_price_group').val();
                if(result.price_group_id) {
                    $('#types_of_service_price_group').val(result.price_group_id);
                    $('#price_group_text').removeClass('hide');
                    $('#price_group_text span').text(result.price_group_name);
                } else {
                    $('#types_of_service_price_group').val('');
                    $('#price_group_text').addClass('hide');
                    $('#price_group_text span').text('');
                }
                $('#types_of_service_id').val(types_of_service_id);
                $('.types_of_service_modal').html(result.modal_html);
                
                if (prev_price_group != result.price_group_id) {
                    if ($('form#edit_pos_sell_form').length > 0) {
                        $('table#pos_table tbody').html('');
                        pos_total_row();
                    } else {
                        reset_pos_form();
                    }
                } else {
                    pos_total_row();
                }

                $('.types_of_service_modal').modal('show');
            },
        });
    } else {
        $('.types_of_service_modal').html('');
        $('#types_of_service_price_group').val('');
        $('#price_group_text').addClass('hide');
        $('#price_group_text span').text('');
        $('#packing_charge_text').text('');
        if ($('form#edit_pos_sell_form').length > 0) {
            $('table#pos_table tbody').html('');
            pos_total_row();
        } else {
            reset_pos_form();
        }
    }
});

$(document).on('change', 'input#packing_charge, #additional_expense_value_1, #additional_expense_value_2, \
        #additional_expense_value_3, #additional_expense_value_4', function() {
    pos_total_row();
});

$(document).on('click', '.service_modal_btn', function(e) {
    if ($('#types_of_service_id').val()) {
        $('.types_of_service_modal').modal('show');
    }
});

$(document).on('change', '.payment_types_dropdown', function(e) {
    var default_accounts = $('select#select_location_id').length ? 
                $('select#select_location_id')
                .find(':selected')
                .data('default_payment_accounts') : $('#location_id').data('default_payment_accounts');
    var payment_type = $(this).val();
    var payment_row = $(this).closest('.payment_row');
    if (payment_type && payment_type != 'advance') {
        var default_account = default_accounts && default_accounts[payment_type]['account'] ? 
            default_accounts[payment_type]['account'] : '';
        var row_index = payment_row.find('.payment_row_index').val();

        var account_dropdown = payment_row.find('select#account_' + row_index);
        if (account_dropdown.length && default_accounts) {
            account_dropdown.val(default_account);
            account_dropdown.change();
        }
    }

    //Validate max amount and disable account if advance 
    amount_element = payment_row.find('.payment-amount');
    account_dropdown = payment_row.find('.account-dropdown');
    if (payment_type == 'advance') {
        max_value = $('#advance_balance').val();
        msg = $('#advance_balance').data('error-msg');
        amount_element.rules('add', {
            'max-value': max_value,
            messages: {
                'max-value': msg,
            },
        });
        if (account_dropdown) {
            account_dropdown.prop('disabled', true);
            account_dropdown.closest('.form-group').addClass('hide');
        }
    } else {
        amount_element.rules("remove", "max-value");
        if (account_dropdown) {
            account_dropdown.prop('disabled', false); 
            account_dropdown.closest('.form-group').removeClass('hide');
        }    
    }
    
    // Auto-populate amount for Clover payment
    if (payment_type == 'clover') {
        var total_payable = __read_number($('input#final_total_input'));
        var total_paying = __read_number($('input#total_paying_input'));
        var balance_due = total_payable - total_paying;
        
        // Set the amount in the payment row
        if (balance_due > 0) {
            __write_number(amount_element, balance_due);
            amount_element.trigger('change');
        }
        
        // Automatically send payment to Clover device
        // Note: This will be sent when transaction is finalized, but we can prepare it here
        toastr.info('Clover payment selected. Amount will be sent to device when transaction is finalized.');
    }
});

$(document).on('show.bs.modal', '#recent_transactions_modal', function () {
    get_recent_transactions('final', $('div#tab_final'));
});
$(document).on('shown.bs.tab', 'a[href="#tab_quotation"]', function () {
    get_recent_transactions('quotation', $('div#tab_quotation'));
});
$(document).on('shown.bs.tab', 'a[href="#tab_draft"]', function () {
    get_recent_transactions('draft', $('div#tab_draft'));
});

function disable_pos_form_actions(){
    if (!window.navigator.onLine) {
        return false;
    }

    $('div.pos-processing').show();
    $('#pos-save').attr('disabled', 'true');
    $('div.pos-form-actions').find('button').attr('disabled', 'true');
}

function enable_pos_form_actions(){
    $('div.pos-processing').hide();
    $('#pos-save').removeAttr('disabled');
    $('div.pos-form-actions').find('button').removeAttr('disabled');
}

$(document).on('change', '#recur_interval_type', function() {
    if ($(this).val() == 'months') {
        $('.subscription_repeat_on_div').removeClass('hide');
    } else {
        $('.subscription_repeat_on_div').addClass('hide');
    }
});

function validate_discount_field() {
    discount_element = $('#discount_amount_modal');
    discount_type_element = $('#discount_type_modal');

    if ($('#add_sell_form').length || $('#edit_sell_form').length) {
        discount_element = $('#discount_amount');
        discount_type_element = $('#discount_type');
    }
    var max_value = parseFloat(discount_element.data('max-discount'));
    if (discount_element.val() != '' && !isNaN(max_value)) {
        if (discount_type_element.val() == 'fixed') {
            var subtotal = get_subtotal();
            //get max discount amount
            max_value = __calculate_amount('percentage', max_value, subtotal)
        }

        discount_element.rules('add', {
            'max-value': max_value,
            messages: {
                'max-value': discount_element.data('max-discount-error_msg'),
            },
        });
    } else {
        discount_element.rules("remove", "max-value");      
    }
    discount_element.trigger('change');
}

$(document).on('change', '#discount_type_modal, #discount_type', function() {
    validate_discount_field();
});

function update_shipping_address(data) {
    if ($('#shipping_address_div').length) {
        var shipping_address = '';
        if (data.supplier_business_name) {
            shipping_address += data.supplier_business_name;
        }
        if (data.name) {
            shipping_address += ',<br>' + data.name;
        }
        if (data.text) {
            shipping_address += ',<br>' + data.text;
        }
        shipping_address += ',<br>' + data.shipping_address ;
        $('#shipping_address_div').html(shipping_address);
    }
    if ($('#billing_address_div').length) {
        var address = [];
        if (data.supplier_business_name) {
            address.push(data.supplier_business_name);
        }
        if (data.name) {
            address.push('<br>' + data.name);
        }
        if (data.text) {
            address.push('<br>' + data.text);
        }
        if (data.address_line_1) {
            address.push('<br>' + data.address_line_1);
        }
        if (data.address_line_2) {
            address.push('<br>' + data.address_line_2);
        }
        if (data.city) {
            address.push('<br>' + data.city);
        }
        if (data.state) {
            address.push(data.state);
        }
        if (data.country) {
            address.push(data.country);
        }
        if (data.zip_code) {
            address.push('<br>' + data.zip_code);
        }
        var billing_address = address.join(', ');
        $('#billing_address_div').html(billing_address);
    }

    if ($('#shipping_custom_field_1').length) {
        let shipping_custom_field_1 = data.shipping_custom_field_details != null ? data.shipping_custom_field_details.shipping_custom_field_1 : '';
        $('#shipping_custom_field_1').val(shipping_custom_field_1);
    }

    if ($('#shipping_custom_field_2').length) {
        let shipping_custom_field_2 = data.shipping_custom_field_details != null ? data.shipping_custom_field_details.shipping_custom_field_2 : '';
        $('#shipping_custom_field_2').val(shipping_custom_field_2);
    }

    if ($('#shipping_custom_field_3').length) {
        let shipping_custom_field_3 = data.shipping_custom_field_details != null ? data.shipping_custom_field_details.shipping_custom_field_3 : '';
        $('#shipping_custom_field_3').val(shipping_custom_field_3);
    }

    if ($('#shipping_custom_field_4').length) {
        let shipping_custom_field_4 = data.shipping_custom_field_details != null ? data.shipping_custom_field_details.shipping_custom_field_4 : '';
        $('#shipping_custom_field_4').val(shipping_custom_field_4);
    }

    if ($('#shipping_custom_field_5').length) {
        let shipping_custom_field_5 = data.shipping_custom_field_details != null ? data.shipping_custom_field_details.shipping_custom_field_5 : '';
        $('#shipping_custom_field_5').val(shipping_custom_field_5);
    }
    
    //update export fields
    if (data.is_export) {
        $('#is_export').prop('checked', true);
        $('div.export_div').show();
        if ($('#export_custom_field_1').length) {
            $('#export_custom_field_1').val(data.export_custom_field_1);
        }
        if ($('#export_custom_field_2').length) {
            $('#export_custom_field_2').val(data.export_custom_field_2);
        }
        if ($('#export_custom_field_3').length) {
            $('#export_custom_field_3').val(data.export_custom_field_3);
        }
        if ($('#export_custom_field_4').length) {
            $('#export_custom_field_4').val(data.export_custom_field_4);
        }
        if ($('#export_custom_field_5').length) {
            $('#export_custom_field_5').val(data.export_custom_field_5);
        }
        if ($('#export_custom_field_6').length) {
            $('#export_custom_field_6').val(data.export_custom_field_6);
        }
    } else {
        $('#export_custom_field_1, #export_custom_field_2, #export_custom_field_3, #export_custom_field_4, #export_custom_field_5, #export_custom_field_6').val('');
        $('#is_export').prop('checked', false);
        $('div.export_div').hide();
    }
    
    $('#shipping_address_modal').val(data.shipping_address);
    $('#shipping_address').val(data.shipping_address);
}

function get_sales_orders() {
    if ($('#sales_order_ids').length) {
        if ($('#sales_order_ids').hasClass('not_loaded')) {
            $('#sales_order_ids').removeClass('not_loaded');
            return false;
        }
        var customer_id = $('select#customer_id').val();
        var location_id = $('input#location_id').val();
        $.ajax({
            url: '/get-sales-orders/' + customer_id + '?location_id=' + location_id,
            dataType: 'json',
            success: function(data) {
                $('#sales_order_ids').select2('destroy').empty().select2({data: data});
                $('table#pos_table tbody').find('tr').each( function(){
                    if (typeof($(this).data('so_id')) !== 'undefined') {
                        $(this).remove();
                    }
                });
                pos_total_row();
            },
        });
    }
}

$("#sales_order_ids").on("select2:select", function (e) {
    var sales_order_id = e.params.data.id;
    var product_row = $('input#product_row_count').val();
    var location_id = $('input#location_id').val();
    $.ajax({
        method: 'GET',
        url: '/get-sales-order-lines',
        async: false,
        data: {
            product_row: product_row,
            sales_order_id: sales_order_id
        },
        dataType: 'json',
        success: function(result) {
            if (result.html) {
                var html = result.html;
                $(html).find('tr').each(function(){
                    $('table#pos_table tbody')
                    .append($(this))
                    .find('input.pos_quantity');
                    
                    var this_row = $('table#pos_table tbody')
                        .find('tr')
                        .last();
                    pos_each_row(this_row);

                    product_row = parseInt(product_row) + 1;

                    //For initial discount if present
                    var line_total = __read_number(this_row.find('input.pos_line_total'));
                    this_row.find('span.pos_line_total_text').text(line_total);

                    //Check if multipler is present then multiply it when a new row is added.
                    if(__getUnitMultiplier(this_row) > 1){
                        this_row.find('select.sub_unit').trigger('change');
                    }

                    round_row_to_iraqi_dinnar(this_row);
                    __currency_convert_recursively(this_row);
                });

                set_so_values(result.sales_order);

                //increment row count
                $('input#product_row_count').val(product_row);
                
                pos_total_row();
            
            } else {
                toastr.error(result.msg);
                $('input#search_product')
                    .focus()
                    .select();
            }
        },
    });
});

function set_so_values(so) {
    $('textarea[name="sale_note"]').val(so.additional_notes);
    if ($('#shipping_details').is(':visible')) {
        $('#shipping_details').val(so.shipping_details);
    }
    $('#shipping_address').val(so.shipping_address);
    $('#delivered_to').val(so.delivered_to);
    $('#shipping_charges').val( __number_f(so.shipping_charges));
    $('#shipping_status').val(so.shipping_status);
    if ($('#shipping_custom_field_1').length) {
        $('#shipping_custom_field_1').val(so.shipping_custom_field_1);
    }
    if ($('#shipping_custom_field_2').length) {
        $('#shipping_custom_field_2').val(so.shipping_custom_field_2);
    }
    if ($('#shipping_custom_field_3').length) {
        $('#shipping_custom_field_3').val(so.shipping_custom_field_3);
    }
    if ($('#shipping_custom_field_4').length) {
        $('#shipping_custom_field_4').val(so.shipping_custom_field_4);
    }
    if ($('#shipping_custom_field_5').length) {
        $('#shipping_custom_field_5').val(so.shipping_custom_field_5);
    }
}

$("#sales_order_ids").on("select2:unselect", function (e) {
    var sales_order_id = e.params.data.id;
    $('table#pos_table tbody').find('tr').each( function(){
        if (typeof($(this).data('so_id')) !== 'undefined' 
            && $(this).data('so_id') == sales_order_id) {
            $(this).remove();
        pos_total_row();
        }
    });
});

$(document).on('click', '#add_expense', function(){
    $.ajax({
        url: '/expenses/create',
        data: { 
            location_id: $('#select_location_id').val()
        },
        dataType: 'html',
        success: function(result) {
            $('#expense_modal').html(result);
            $('#expense_modal').modal('show');
        },
    });
});

$(document).on('shown.bs.modal', '#expense_modal', function(){
    $('#expense_transaction_date').datetimepicker({
        format: moment_date_format + ' ' + moment_time_format,
        ignoreReadonly: true,
    });
    $('#expense_modal .paid_on').datetimepicker({
        format: moment_date_format + ' ' + moment_time_format,
        ignoreReadonly: true,
    });
    $(this).find('.select2').select2();
    $('#add_expense_modal_form').validate();
});

$(document).on('hidden.bs.modal', '#expense_modal', function(){
    $(this).html('');
});

$(document).on('submit', 'form#add_expense_modal_form', function(e) {
    e.preventDefault();
    var data = $(this).serialize();

    $.ajax({
        method: 'POST',
        url: $(this).attr('action'),
        dataType: 'json',
        data: data,
        success: function(result) {
            if (result.success == true) {
                $('#expense_modal').modal('hide');
                toastr.success(result.msg);
            } else {
                toastr.error(result.msg);
            }
        },
    });
});

function get_contact_due(id) {
    $.ajax({
        method: 'get',
        url: /get-contact-due/ + id,
        dataType: 'text',
        success: function(result) {
            if (result != '') {
                $('.contact_due_text').find('span').text(result);
                $('.contact_due_text').removeClass('hide');
            } else {
                $('.contact_due_text').find('span').text('');
                $('.contact_due_text').addClass('hide');
            }
        },
    });
}

function submitQuickContactForm(form) {
    var data = $(form).serialize();
    $.ajax({
        method: 'POST',
        url: $(form).attr('action'),
        dataType: 'json',
        data: data,
        beforeSend: function(xhr) {
            __disable_submit_button($(form).find('button[type="submit"]'));
        },
        success: function(result) {
            if (result.success == true) {
                var name = result.data.name;

                if (result.data.supplier_business_name) {
                    name += result.data.supplier_business_name;
                }
                
                $('select#customer_id').append(
                    $('<option>', { value: result.data.id, text: name })
                );
                $('select#customer_id')
                    .val(result.data.id)
                    .trigger('change');
                $('div.contact_modal').modal('hide');
                update_shipping_address(result.data)
                toastr.success(result.msg);
                
                // Fix column alignment after customer creation
                setTimeout(function() {
                    $('#customer_account_info').css('display', 'none').show();
                    // Force layout recalculation
                    if (typeof $('#customer_account_info')[0] !== 'undefined') {
                        $('#customer_account_info')[0].offsetHeight;
                    }
                }, 100);
            } else {
                toastr.error(result.msg);
            }
        },
    });
}

$(document).on('click', '#send_for_sell_return', function(e) {
    var invoice_no = $('#send_for_sell_return_invoice_no').val();

    if (invoice_no) {
        $.ajax({
            method: 'get',
            url: /validate-invoice-to-return/ + encodeURI(invoice_no),
            dataType: 'json',
            success: function(result) {
                if (result.success == true) {
                    window.location = result.redirect_url ;
                } else {
                    toastr.error(result.msg);
                }
            },
        });

        
    }
});

// Helper function to add a new manual product row
function addManualProductRow() {
    var currentRowCount = $('#manual_products_container .manual_product_row').length;
    var newRowIndex = currentRowCount;
    var newRowNumber = currentRowCount + 1;
    
    // Create new row HTML from scratch
    var newRowHtml = `
        <tr class="manual_product_row" data-row="${newRowIndex}">
            <td>${newRowNumber}</td>
            <td>
                <input type="text" 
                       name="products[${newRowIndex}][name]" 
                       class="form-control manual-product-name-input" 
                       required 
                       placeholder="Product Name">
            </td>
            <td>
                <input type="text" 
                       name="products[${newRowIndex}][artist]" 
                       class="form-control artist-autocomplete-input" 
                       placeholder="Artist">
            </td>
            <td>
                <div style="display:flex; gap:4px; align-items:center;">
                    <select name="products[${newRowIndex}][category_combo]" 
                            class="form-control select2 manual_category_combo" 
                            data-row="${newRowIndex}"
                            required>
                        <option value="">Please select</option>
                        ${getCategoryComboOptions()}
                    </select>
                    <button type="button" class="btn btn-primary btn-xs manual-copy-down" data-class="manual_category_combo" data-row-index="${newRowIndex}" title="Copy Down">
                        <i class="fa fa-arrow-down"></i>
                    </button>
                </div>
                <input type="hidden" name="products[${newRowIndex}][category_id]" class="manual_category_id" data-row="${newRowIndex}">
                <input type="hidden" name="products[${newRowIndex}][sub_category_id]" class="manual_sub_category_id" data-row="${newRowIndex}">
            </td>
            <td>
                <input type="text" 
                       name="products[${newRowIndex}][price]" 
                       class="form-control input_number" 
                       required 
                       placeholder="0.00">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-danger btn-sm remove_product_row">
                    <i class="fa fa-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    // Add the new row to the container
    $('#manual_products_container').append(newRowHtml);
    
    // Initialize select2 for the new selects
    applyManualCategoryComboMatcher($('#manual_products_container tr:last'));
    
    // Calculate subtotal after adding row
    calculateManualProductSubtotal();
}

function manualCategoryTokenize(text) {
    if (text === undefined || text === null) return [];
    return String(text)
        .toLowerCase()
        .trim()
        .split(/[^a-z0-9]+/g)
        .filter(Boolean);
}

function manualCategoryComboMatcher(params, data) {
    if (!data || !data.text) return data;
    var term = params && params.term ? String(params.term).trim().toLowerCase() : '';
    if (!term) return data;

    var label = String(data.text || '').toLowerCase();
    var tokens = manualCategoryTokenize(term);
    if (!tokens.length) return data;

    var words = label.match(/[a-z0-9]+/g) || [];
    var matchesAll = tokens.every(function(tok) {
        return label.indexOf(tok) !== -1 || words.some(function(w) { return w.indexOf(tok) === 0; });
    });

    return matchesAll ? data : null;
}

function applyManualCategoryComboMatcher($scope) {
    var $root = $scope && $scope.length ? $scope : $('#add_manual_product_modal');

    // Keep default Select2 behavior for non-category fields
    $root.find('.select2').not('.manual_category_combo').each(function() {
        var $el = $(this);
        if ($el.hasClass('select2-hidden-accessible')) {
            return;
        }
        $el.select2({
            placeholder: "Please select",
            allowClear: true
        });
    });

    // Apply enhanced matcher specifically for merged category/subcategory combo
    $root.find('select.manual_category_combo').each(function() {
        var $el = $(this);
        var currentVal = $el.val();

        try {
            if ($el.hasClass('select2-hidden-accessible') || $el.data('select2')) {
                $el.select2('destroy');
            }
        } catch (e) {}

        // Remove stale select2 wrappers/attrs if any previous init left duplicates.
        $el.removeClass('select2-hidden-accessible')
            .removeAttr('data-select2-id')
            .removeAttr('aria-hidden')
            .removeAttr('tabindex');
        $el.siblings('span.select2').remove();

        $el.select2({
            placeholder: "Please select",
            allowClear: true,
            dropdownParent: $('#add_manual_product_modal'),
            matcher: manualCategoryComboMatcher
        });

        if (currentVal !== undefined && currentVal !== null && currentVal !== '') {
            $el.val(currentVal).trigger('change.select2');
        }
    });
}

// Helper function to get category+subcategory combo options HTML
function getCategoryComboOptions() {
    var options = '';
    $('#manual_products_container tr:first select[name*="[category_combo]"] option').each(function() {
        var value = $(this).attr('value');
        var text = $(this).text();
        var categoryId = $(this).data('category-id');
        var subCategoryId = $(this).data('sub-category-id');

        if (value !== '') {
            options += `<option value="${value}" data-category-id="${categoryId || ''}" data-sub-category-id="${subCategoryId || ''}">${text}</option>`;
        }
    });
    return options;
}

// Helper function to update product row numbers
function updateProductRowNumbers() {
    $('#manual_products_container .manual_product_row').each(function(index) {
        var $row = $(this);
        var rowNumber = index + 1;
        
        // Update the row number in the first column
        $row.find('td:first').text(rowNumber);
        
        // Update data-row attribute
        $row.attr('data-row', index);
        
        // Update all input names, IDs, and data attributes
        $row.find('input, select').each(function() {
            var $this = $(this);
            var name = $this.attr('name');
            var id = $this.attr('id');
            
            if (name && name.includes('[')) {
                var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                $this.attr('name', newName);
            }
            
            if (id && id.includes('[')) {
                var newId = id.replace(/\[\d+\]/, '[' + index + ']');
                $this.attr('id', newId);
            }
            
            // Update data-row attributes
            if ($this.hasClass('manual_category_combo') || $this.hasClass('manual_category_id') || $this.hasClass('manual_sub_category_id')) {
                $this.attr('data-row', index);
            }
        });

        $row.find('.manual-copy-down').attr('data-row-index', index);
        
        // Hide remove button for first row
        if (index === 0) {
            $row.find('.remove_product_row').hide();
        } else {
            $row.find('.remove_product_row').show();
        }
    });
}

// Helper function to reset the manual product modal
function resetManualProductModal() {
    // Remove all rows except the first one
    $('#manual_products_container .manual_product_row:not(:first)').remove();
    
    // Reset the first row - clear all input values
    $('#manual_products_container .manual_product_row:first').find('input, select').each(function() {
        var $this = $(this);
        if ($this.attr('type') !== 'hidden') {
            $this.val('');
        }
    });
    
    // Reinitialize select2 for the first row selects
    applyManualCategoryComboMatcher($('#manual_products_container .manual_product_row:first'));
    
    // Update row numbers (should just be row 1 now)
    updateProductRowNumbers();
    
    // Hide remove button for first row
    $('#manual_products_container .manual_product_row:first .remove_product_row').hide();
    
    // Calculate subtotal after removing row
    calculateManualProductSubtotal();
}

// Sync category_combo selection to hidden category_id/sub_category_id in each row (call before validate/submit)
function syncManualProductCategoryFromCombo() {
    $('#manual_products_container .manual_product_row').each(function() {
        var $row = $(this);
        var $combo = $row.find('select.manual_category_combo');
        if (!$combo.length) return;
        var val = $combo.val();
        // Select2 can leave native select .val() empty; try selected option's value
        if (!val && $combo.find('option:selected').length) {
            val = $combo.find('option:selected').attr('value') || $combo.find('option:selected').val();
        }
        if (!val) {
            var existingCat = $row.find('input.manual_category_id').val();
            if (!existingCat || String(existingCat).trim() === '') {
                $row.find('input.manual_category_id').val('');
                $row.find('input.manual_sub_category_id').val('');
            }
            return;
        }
        var categoryId = '';
        var subCategoryId = '';
        if (String(val).indexOf('_') !== -1) {
            var parts = String(val).split('_');
            categoryId = parts[0] || '';
            subCategoryId = parts[1] !== undefined ? String(parts[1]) : '';
        } else {
            var $selected = $combo.find('option:selected');
            categoryId = $selected.length ? ($selected.attr('data-category-id') || '') : '';
            subCategoryId = $selected.length ? ($selected.attr('data-sub-category-id') || '') : '';
        }
        $row.find('input.manual_category_id').val(String(categoryId).trim());
        $row.find('input.manual_sub_category_id').val(String(subCategoryId).trim());
    });
}

// Helper function to validate manual product form
function validateManualProductForm() {
    var isValid = true;
    var hasValidProduct = false;
    
    $('#manual_products_container .manual_product_row').each(function() {
        var $row = $(this);
        var productName = $row.find('input[name*="[name]"]').val();
        var price = $row.find('input[name*="[price]"]').val();
        var categoryId = $row.find('input[name*="[category_id]"]').val();
        var subCategoryId = $row.find('input[name*="[sub_category_id]"]').val();
        // Fallback: read from combo when hidden inputs empty (Select2 can leave them unsynced)
        if ((!categoryId || String(categoryId).trim() === '') && $row.find('select.manual_category_combo').length) {
            var comboVal = $row.find('select.manual_category_combo').val() || $row.find('select.manual_category_combo option:selected').attr('value');
            if (comboVal && String(comboVal).indexOf('_') !== -1) {
                var p = String(comboVal).split('_');
                categoryId = p[0] || '';
                subCategoryId = p[1] !== undefined ? p[1] : '';
                $row.find('input.manual_category_id').val(categoryId);
                $row.find('input.manual_sub_category_id').val(subCategoryId);
            }
        }
        
        if (productName || price) {
            hasValidProduct = true;

            var trimmedName = (productName || '').trim();
            if (trimmedName === '') {
                toastr.error('Product name is required for all products');
                isValid = false;
                return false;
            }

            if (trimmedName.length < 3) {
                toastr.error('Please describe the item in a few more words (e.g. "Airheads candy")');
                isValid = false;
                return false;
            }

            // Nudge cashiers toward a real description rather than a placeholder.
            var genericNames = [
                'manual', 'manual item', 'manual items', 'item', 'items',
                'misc', 'miscellaneous', 'misc item', 'n/a', 'na', 'none',
                'test', 'thing', 'stuff', 'product', 'unknown', '-', '--', '...'
            ];
            if (genericNames.indexOf(trimmedName.toLowerCase()) !== -1) {
                toastr.error('Please describe what was sold — e.g. "Airheads candy" instead of "' + trimmedName + '"');
                isValid = false;
                return false;
            }

            // Reject pure digits / punctuation — the name needs actual words.
            if (/^[0-9\W_]+$/.test(trimmedName)) {
                toastr.error('Please add a short description with words (e.g. "Soda can")');
                isValid = false;
                return false;
            }

            if (!categoryId || String(categoryId).trim() === '') {
                toastr.error('Category is required for all products');
                isValid = false;
                return false;
            }
            
            if (subCategoryId === undefined || subCategoryId === null || String(subCategoryId).trim() === '') {
                toastr.error('Sub Category is required for all products');
                isValid = false;
                return false;
            }
            
            if (!price || price.trim() === '' || parseFloat(price) <= 0) {
                toastr.error('Valid price is required for all products');
                isValid = false;
                return false;
            }
        }
    });
    
    if (!hasValidProduct) {
        toastr.error('Please enter at least one product');
        isValid = false;
    }
    
    return isValid;
}

// Function to calculate and display manual product subtotal
function calculateManualProductSubtotal() {
    var subtotal = 0;
    
    $('#manual_products_container .manual_product_row').each(function() {
        var $priceInput = $(this).find('input[name*="[price]"]');
        if ($priceInput.length) {
            var price = __read_number($priceInput);
            if (!isNaN(price) && price > 0) {
                subtotal += price;
            }
        }
    });
    
    // Update subtotal display
    $('#manual_products_subtotal').text(__currency_trans_from_en(subtotal, false));
}

// Handle bag fee charge toggle
$(document).on('change', '#add_plastic_bag', function() {
    var isChecked = $(this).is(':checked');
    var bagFeeRow = $('#pos_table tbody tr[data-plastic-bag="true"]');
    
    if (isChecked) {
        // Add bag fee row if not already present
        if (bagFeeRow.length === 0) {
            var location_id = $('#location_id').val();
            var currentRowCount = $('#pos_table tbody tr.product_row').length;
            
            $.ajax({
                url: '/sells/pos/get_plastic_bag_row',
                method: 'POST',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    product_row: currentRowCount,
                    location_id: location_id
                },
                dataType: 'json',
                success: function(result) {
                    if (result.success && result.html_content) {
                        // Add the bag fee row
                        $('#pos_table tbody').append(result.html_content);
                        
                        // Mark it as bag fee row
                        var newRow = $('#pos_table tbody tr').last();
                        newRow.attr('data-plastic-bag', 'true');
                        
                        // Set tax to "No Tax" for bag fee (tax-exempt)
                        var taxSelect = newRow.find('select.tax_id');
                        if (taxSelect.length) {
                            taxSelect.val('').trigger('change');
                        }
                        
                        // Initialize the row - this will populate tax and calculate prices
                        pos_each_row(newRow);
                        
                        // Recalculate totals
                        pos_total_row();
                    }
                },
                error: function() {
                    toastr.error('Failed to add bag fee charge');
                    $('#add_plastic_bag').prop('checked', false);
                }
            });
        }
    } else {
        // Remove bag fee row
        if (bagFeeRow.length > 0) {
            bagFeeRow.remove();
            pos_total_row();
        }
    }
});
