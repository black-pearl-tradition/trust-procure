(function () {
    var bell = document.getElementById('topbarBell');
    if (!bell) return;
    var btn = document.getElementById('topbarBellBtn');
    var menu = document.getElementById('topbarBellMenu');
    var list = document.getElementById('topbarBellList');
    var markAll = document.getElementById('topbarBellMarkAll');
    var dot = document.getElementById('topbarBellDot');
    var feedUrl = bell.getAttribute('data-feed-url');
    var readUrl = bell.getAttribute('data-read-url');
    var markAllUrl = bell.getAttribute('data-mark-all-url');
    var inboxUrl = bell.getAttribute('data-inbox-url');
    var csrf = bell.getAttribute('data-csrf') || '';
    var loaded = false;
    var loading = false;

    function esc(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function setDot(unread) {
        bell.classList.toggle('has-unread', unread > 0);
        if (unread > 0) {
            if (!dot) {
                dot = document.createElement('span');
                dot.id = 'topbarBellDot';
                dot.className = 'topbar-bell-dot';
                btn.appendChild(dot);
            }
            dot.textContent = unread > 9 ? '9+' : String(unread);
            dot.style.display = '';
        } else if (dot) {
            dot.style.display = 'none';
        }
    }

    function render(items) {
        if (!items.length) {
            list.innerHTML = '<div class="topbar-bell-empty"><i class="bi bi-bell-slash" aria-hidden="true"></i><p>You are all caught up.</p></div>';
            return;
        }
        var html = '';
        items.forEach(function (item) {
            html += '<form method="post" action="' + esc(readUrl) + '" class="topbar-bell-item' + (item.is_read ? '' : ' is-unread') + ' notification-' + esc(item.type) + '">'
                + '<input type="hidden" name="csrf_token" value="' + esc(csrf) + '">'
                + '<input type="hidden" name="id" value="' + esc(item.id) + '">'
                + '<button type="submit">'
                + '<span class="notification-icon"><i class="bi bi-' + esc(item.icon) + '" aria-hidden="true"></i></span>'
                + '<span class="notification-content"><strong>' + esc(item.title) + '</strong>'
                + '<small>' + esc((item.message || '').slice(0, 90)) + ((item.message || '').length > 90 ? '&#8230;' : '') + '</small>'
                + '<time>' + esc(item.created_at) + '</time></span>'
                + '</button></form>';
        });
        list.innerHTML = html;
    }

    function refresh() {
        return fetch(feedUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error('feed failed');
                if (data.csrf) csrf = data.csrf;
                render(data.items || []);
                setDot(data.unread || 0);
            });
    }

    function load() {
        if (loaded || loading) return;
        loading = true;
        refresh().then(function () {
            loaded = true;
            loading = false;
            setInterval(function () {
                refresh().catch(function () { /* keep last state */ });
            }, 60000);
        }).catch(function () {
            loading = false;
            list.innerHTML = '<div class="topbar-bell-empty"><p>Could not load previews. <a href="' + esc(inboxUrl) + '">Open inbox</a></p></div>';
        });
    }

    function toggle(open) {
        var willOpen = open !== undefined ? open : menu.hidden;
        menu.hidden = !willOpen;
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) load();
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        toggle();
    });
    document.addEventListener('click', function (e) {
        if (!menu.hidden && !bell.contains(e.target)) toggle(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) toggle(false);
    });

    if (markAll) {
        markAll.addEventListener('click', function () {
            markAll.disabled = true;
            fetch(markAllUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: 'csrf_token=' + encodeURIComponent(csrf)
            })
                .then(function (resp) { return resp.json(); })
                .then(function (data) {
                    if (!data || !data.success) throw new Error('mark failed');
                    setDot(0);
                    loaded = false;
                    load();
                })
                .catch(function () { /* keep dropdown usable */ })
                .finally(function () { markAll.disabled = false; });
        });
    }
})();
