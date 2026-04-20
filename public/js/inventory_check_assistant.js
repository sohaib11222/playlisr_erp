(function ($) {
    'use strict';

    var rows = [];
    var currentFilter = 'all';
    var verified = {};
    var selected = {};
    var currentPreset = '';

    function rowKey(r) {
        return r.variation_id + '_' + r.location_id;
    }

    function getFilters() {
        var preset = $('#ica_preset').val() || '';
        var loc = $('#ica_location_id').val();
        var cat = $('#ica_category_id').val();
        var sup = $('#ica_supplier_id').val();
        return {
            preset: preset || undefined,
            location_id: loc || undefined,
            category_id: cat || undefined,
            supplier_id: sup || undefined,
            sale_start: $('#ica_sale_start').val() || undefined,
            sale_end: $('#ica_sale_end').val() || undefined
        };
    }

    function applyPresetMeta() {
        var key = $('#ica_preset').val();
        currentPreset = key || '';
        if (!key || !window.ICA_PRESET_META || !window.ICA_PRESET_META[key]) {
            return;
        }
        var m = window.ICA_PRESET_META[key];
        if (m.location_id) {
            $('#ica_location_id').val(String(m.location_id)).trigger('change');
        }
        if (m.sale_start) {
            $('#ica_sale_start').val(m.sale_start);
        }
        if (m.sale_end) {
            $('#ica_sale_end').val(m.sale_end);
        }
        if (m.supplier_id) {
            $('#ica_supplier_id').val(String(m.supplier_id)).trigger('change');
        }
    }

    function rowMatchesFilter(r) {
        if (currentFilter === 'all') {
            return true;
        }
        return (r.tags || []).indexOf(currentFilter) !== -1;
    }

    function renderTable() {
        var $tb = $('#ica_tbody');
        $tb.empty();
        var shown = 0;
        rows.forEach(function (r) {
            if (!rowMatchesFilter(r)) {
                return;
            }
            shown++;
            var k = rowKey(r);
            var tags = (r.tags || []).map(function (t) {
                return '<span class="label label-info">' + $('<div>').text(t).html() + '</span>';
            }).join(' ');
            var avg = r.avg_sell_days !== null && r.avg_sell_days !== undefined ? r.avg_sell_days : '—';
            var chk = verified[k] ? 'checked' : '';
            var sel = selected[k] ? 'checked' : '';
            var tr =
                '<tr data-key="' +
                k +
                '">' +
                '<td class="no-print"><input type="checkbox" class="ica-row-select" data-key="' +
                k +
                '" ' +
                sel +
                '></td>' +
                '<td class="no-print"><input type="checkbox" class="ica-row-verify" data-key="' +
                k +
                '" ' +
                chk +
                '></td>' +
                '<td>' +
                $('<div>').text(r.sku || '').html() +
                '</td>' +
                '<td>' +
                $('<div>').text(r.product || '').html() +
                '</td>' +
                '<td>' +
                $('<div>').text(r.artist || '').html() +
                '</td>' +
                '<td>' +
                $('<div>').text(r.format || '').html() +
                '</td>' +
                '<td>' +
                $('<div>').text(r.location_name || '').html() +
                '</td>' +
                '<td>' +
                $('<div>').text(String(r.stock)).html() +
                '</td>' +
                '<td>' +
                $('<div>').text(String(r.sold_qty_window)).html() +
                '</td>' +
                '<td>' +
                $('<div>').text(String(avg)).html() +
                '</td>' +
                '<td>' +
                tags +
                '</td>' +
                '<td>' +
                $('<div>').text(String(r.suggested_qty)).html() +
                '</td>' +
                '</tr>';
            $tb.append(tr);
        });
        if (!shown) {
            $tb.append(
                '<tr><td colspan="12" class="text-center text-muted">No rows for this filter.</td></tr>'
            );
        }
    }

    function loadCandidates() {
        var params = getFilters();
        $('#ica_apply').prop('disabled', true);
        $.ajax({
            url: window.ICA_DATA_URL,
            data: params,
            dataType: 'json',
            success: function (res) {
                rows = res.candidates || [];
                var meta = res.meta || {};
                $('#ica_meta_line').text(
                    'Window: ' +
                        (meta.sale_start || '') +
                        ' — ' +
                        (meta.sale_end || '') +
                        ' · Rows: ' +
                        rows.length
                );
                renderTable();
            },
            error: function (xhr) {
                alert('Could not load data: ' + (xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : xhr.statusText));
            },
            complete: function () {
                $('#ica_apply').prop('disabled', false);
            }
        });
    }

    function buildExportUrl() {
        var params = $.param(getFilters());
        return window.ICA_EXPORT_URL + (params ? '?' + params : '');
    }

    function copyForCart() {
        var fmt = window.ICA_COPY_FORMAT || '{qty} x {sku} — {product}';
        var lines = [];
        rows.forEach(function (r) {
            var k = rowKey(r);
            if (!selected[k]) {
                return;
            }
            var line = fmt
                .replace('{qty}', String(r.suggested_qty))
                .replace('{sku}', r.sku || '')
                .replace('{product}', r.product || '');
            lines.push(line);
        });
        if (!lines.length) {
            alert('Select at least one row with the checkbox in the first column.');
            return;
        }
        var text = lines.join('\n');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                alert('Copied ' + lines.length + ' line(s) to clipboard.');
            });
        } else {
            prompt('Copy:', text);
        }
    }

    function collectStateJson() {
        return JSON.stringify({
            verified: verified,
            selected: selected,
            preset: currentPreset,
            filters: getFilters()
        });
    }

    function applyStateJson(str) {
        try {
            var o = JSON.parse(str || '{}');
            verified = o.verified || {};
            selected = o.selected || {};
            if (o.filters) {
                if (o.filters.location_id) {
                    $('#ica_location_id').val(String(o.filters.location_id)).trigger('change');
                }
                if (o.filters.category_id) {
                    $('#ica_category_id').val(String(o.filters.category_id)).trigger('change');
                }
                if (o.filters.supplier_id) {
                    $('#ica_supplier_id').val(String(o.filters.supplier_id)).trigger('change');
                }
                if (o.filters.sale_start) {
                    $('#ica_sale_start').val(o.filters.sale_start);
                }
                if (o.filters.sale_end) {
                    $('#ica_sale_end').val(o.filters.sale_end);
                }
                if (o.filters.preset) {
                    $('#ica_preset').val(o.filters.preset).trigger('change');
                }
            }
            renderTable();
        } catch (e) {
            console.warn(e);
        }
    }

    function refreshNotes() {
        var loc = $('#ica_location_id').val();
        $.get(
            window.ICA_NOTES_URL,
            { location_id: loc, note_type: 'street_pulse' },
            function (res) {
                var $el = $('#ica_notes_street');
                $el.empty();
                (res.data || []).forEach(function (n) {
                    $el.append(
                        '<div class="well well-sm">' +
                            $('<div>').text(n.body).html() +
                            ' <button type="button" class="btn btn-xs btn-link ica-del-note" data-id="' +
                            n.id +
                            '">Delete</button></div>'
                    );
                });
            }
        );
        $.get(
            window.ICA_NOTES_URL,
            { location_id: loc, note_type: 'customer_request' },
            function (res) {
                var $el = $('#ica_notes_customer');
                $el.empty();
                (res.data || []).forEach(function (n) {
                    $el.append(
                        '<div class="well well-sm">' +
                            $('<div>').text(n.body).html() +
                            ' <button type="button" class="btn btn-xs btn-link ica-del-note" data-id="' +
                            n.id +
                            '">Delete</button></div>'
                    );
                });
            }
        );
    }

    function refreshSessions() {
        $.get(window.ICA_SESSIONS_URL, function (res) {
            var $s = $('#ica_session_select');
            $s.empty().append('<option value="">—</option>');
            (res.data || []).forEach(function (s) {
                $s.append(
                    $('<option></option>').attr('value', s.id).text(s.name + ' (#' + s.id + ')')
                );
            });
        });
    }

    $(document).ready(function () {
        if ($('#ica_table').length === 0) {
            return;
        }

        $('#ica_preset').on('change', applyPresetMeta);
        $('#ica_apply').on('click', loadCandidates);

        $('#ica_tab_filters button').on('click', function () {
            $('#ica_tab_filters button').removeClass('active');
            $(this).addClass('active');
            currentFilter = $(this).data('filter');
            renderTable();
        });

        $(document).on('change', '.ica-row-verify', function () {
            var k = $(this).data('key');
            verified[k] = $(this).is(':checked');
        });
        $(document).on('change', '.ica-row-select', function () {
            var k = $(this).data('key');
            selected[k] = $(this).is(':checked');
        });
        $('#ica_select_all').on('change', function () {
            var on = $(this).is(':checked');
            $('.ica-row-select:visible').prop('checked', on).trigger('change');
        });

        $('#ica_export_csv').on('click', function () {
            window.location.href = buildExportUrl();
        });
        $('#ica_copy_cart').on('click', copyForCart);
        $('#ica_print').on('click', function () {
            window.print();
        });

        $('#ica_sp_save').on('click', function () {
            var body = $('#ica_sp_body').val();
            if (!body.trim()) {
                return;
            }
            $.ajax({
                url: window.ICA_NOTES_STORE,
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': window.ICA_CSRF },
                data: {
                    _token: window.ICA_CSRF,
                    note_type: 'street_pulse',
                    body: body,
                    location_id: $('#ica_location_id').val(),
                    reference_date: $('#ica_sp_ref').val()
                },
                success: function () {
                    $('#ica_sp_body').val('');
                    refreshNotes();
                }
            });
        });

        $('#ica_cr_save').on('click', function () {
            var body = $('#ica_cr_body').val();
            if (!body.trim()) {
                return;
            }
            $.ajax({
                url: window.ICA_NOTES_STORE,
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': window.ICA_CSRF },
                data: {
                    _token: window.ICA_CSRF,
                    note_type: 'customer_request',
                    body: body,
                    location_id: $('#ica_location_id').val()
                },
                success: function () {
                    $('#ica_cr_body').val('');
                    refreshNotes();
                }
            });
        });

        $(document).on('click', '.ica-del-note', function () {
            var id = $(this).data('id');
            if (!confirm('Delete this note?')) {
                return;
            }
            $.ajax({
                url: '/reports/inventory-check-assistant/notes/' + id,
                method: 'POST',
                data: { _token: window.ICA_CSRF, _method: 'DELETE' },
                success: function () {
                    refreshNotes();
                }
            });
        });

        $('#ica_session_save').on('click', function () {
            var name = $('#ica_session_name').val();
            if (!name.trim()) {
                alert('Enter a session name.');
                return;
            }
            var f = getFilters();
            var sid = $('#ica_session_select').val();
            var payload = {
                _token: window.ICA_CSRF,
                name: name,
                location_id: f.location_id,
                category_id: f.category_id,
                supplier_id: f.supplier_id,
                sale_start: f.sale_start,
                sale_end: f.sale_end,
                preset_key: f.preset || null,
                state_json: collectStateJson()
            };
            var req = {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': window.ICA_CSRF },
                data: payload,
                success: function () {
                    refreshSessions();
                    alert('Session saved.');
                }
            };
            if (sid) {
                req.url = '/reports/inventory-check-assistant/sessions/' + sid;
                req.data._method = 'PUT';
            } else {
                req.url = window.ICA_SESSIONS_STORE;
            }
            $.ajax(req);
        });

        $('#ica_session_load').on('click', function () {
            var id = $('#ica_session_select').val();
            if (!id) {
                return;
            }
            $.get(window.ICA_SESSIONS_URL, function (res) {
                var s = (res.data || []).filter(function (x) {
                    return String(x.id) === String(id);
                })[0];
                if (!s) {
                    return;
                }
                $('#ica_session_name').val(s.name);
                if (s.location_id) {
                    $('#ica_location_id').val(String(s.location_id)).trigger('change');
                }
                if (s.category_id) {
                    $('#ica_category_id').val(String(s.category_id)).trigger('change');
                }
                if (s.supplier_id) {
                    $('#ica_supplier_id').val(String(s.supplier_id)).trigger('change');
                }
                if (s.sale_start) {
                    $('#ica_sale_start').val(s.sale_start);
                }
                if (s.sale_end) {
                    $('#ica_sale_end').val(s.sale_end);
                }
                if (s.preset_key) {
                    $('#ica_preset').val(s.preset_key).trigger('change');
                }
                applyStateJson(s.state_json);
                loadCandidates();
            });
        });

        $('#ica_session_delete').on('click', function () {
            var id = $('#ica_session_select').val();
            if (!id || !confirm('Delete this session?')) {
                return;
            }
            $.ajax({
                url: '/reports/inventory-check-assistant/sessions/' + id,
                method: 'POST',
                data: { _token: window.ICA_CSRF, _method: 'DELETE' },
                success: function () {
                    refreshSessions();
                }
            });
        });

        $('#ica_location_id').on('change', function () {
            refreshNotes();
        });

        refreshNotes();
        refreshSessions();
    });
})(jQuery);
