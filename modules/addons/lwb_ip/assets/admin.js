(function () {
    var root = document.querySelector('.lwbip');
    if (!root) { return; }
    // Filtre instantané des tableaux.
    root.querySelectorAll('[data-filter]').forEach(function (input) {
        var table = root.querySelector(input.getAttribute('data-filter'));
        if (!table) { return; }
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    });
    // Tout sélectionner.
    root.querySelectorAll('[data-all]').forEach(function (box) {
        box.addEventListener('change', function () {
            root.querySelectorAll('input[name="' + box.getAttribute('data-all') + '"]').forEach(function (c) {
                if (c.closest('tr').style.display !== 'none') { c.checked = box.checked; }
            });
        });
    });
    // Copier.
    root.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            var done = function () { btn.textContent = 'Copié ✓'; setTimeout(function () { btn.textContent = 'Copier'; }, 1800); };
            if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done); } else { var i = btn.previousElementSibling; i.select(); document.execCommand('copy'); done(); }
        });
    });
    // Un seul menu « … » ouvert à la fois.
    root.addEventListener('click', function (e) {
        root.querySelectorAll('details.lwbip-more[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } });
    });
})();
