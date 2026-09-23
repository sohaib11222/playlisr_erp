<script>
(function () {
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var toast = document.getElementById('asToast');
    var toastTimer;
    function say(html) {
        if (!toast) { return; }
        toast.innerHTML = html;
        toast.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.style.display = 'none'; }, 6000);
    }
    document.querySelectorAll('.as-section-h').forEach(function (h) {
        h.addEventListener('click', function () { h.parentNode.classList.toggle('collapsed'); });
    });
    document.querySelectorAll('.as-check[data-url]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.as-row');
            if (row.classList.contains('done') || btn.disabled) { return; }
            btn.disabled = true;
            row.classList.add('done');
            var body = new FormData();
            body.append('status', 'complete');
            fetch(btn.dataset.url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: body,
                credentials: 'same-origin'
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
            }).then(function (res) {
                if (res.ok && res.j.success) {
                    say('Nice work. Task completed.');
                    return;
                }
                row.classList.remove('done');
                btn.disabled = false;
                say((res.j.msg ? res.j.msg.replace(/</g, '&lt;') : 'Could not complete that task.') + ' <a href="' + btn.dataset.edit + '">Open task</a>');
            }).catch(function () {
                row.classList.remove('done');
                btn.disabled = false;
                say('Could not reach the server. Try again.');
            });
        });
    });
})();
</script>
