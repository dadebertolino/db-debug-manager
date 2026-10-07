/**
 * DB Debug Manager — Admin JS
 */
(function($) {
    'use strict';

    var autoRefreshTimer = null;

    function setStatus(text) {
        $('#dbdm-log-status').text(text || '');
    }

    function refreshLog() {
        var $viewer = $('#dbdm-log-viewer');
        if (!$viewer.length) return;

        var lines = $('#dbdm-log-lines').val() || 500;
        setStatus(DBDM.i18n.refreshing);

        $.post(DBDM.ajax_url, {
            action: 'dbdm_refresh_log',
            nonce: DBDM.nonce,
            lines: lines
        }).done(function(res) {
            if (!res || !res.success) {
                setStatus(DBDM.i18n.failed);
                return;
            }
            // 2.0.0 (bug 43): il filtro riparte dal contenuto nuovo, non da
            // quello caricato con la pagina.
            $viewer.data('full', res.data.content || '');
            applyFilter();
            $('#dbdm-log-size').text(res.data.exists ? res.data.size : DBDM.i18n.no_log);
            if (res.data.exists) $('#dbdm-log-missing').remove();
            $viewer.scrollTop($viewer[0].scrollHeight);
            setStatus('');
        }).fail(function(xhr) {
            // 2.0.0 (bug 57): nonce scaduto (403, "-1") o errore del server.
            setStatus(xhr && (xhr.status === 403 || xhr.responseText === '-1') ? DBDM.i18n.expired : DBDM.i18n.failed);
        });
    }

    function applyFilter() {
        var term = ($('#dbdm-log-filter').val() || '').toLowerCase();
        var $viewer = $('#dbdm-log-viewer');
        if (!$viewer.length) return;

        var full = $viewer.data('full') || '';
        if (!term) {
            $viewer.text(full || DBDM.i18n.empty_log);
            return;
        }

        var filtered = full
            .split('\n')
            .filter(function(line) {
                return line.toLowerCase().indexOf(term) !== -1;
            })
            .join('\n');

        $viewer.text(filtered || DBDM.i18n.empty_log);
    }

    function applyQueryFilter() {
        var term = ($('#dbdm-q-filter').val() || '').toLowerCase();
        $('#dbdm-q-table tbody tr').each(function() {
            var txt = $(this).text().toLowerCase();
            $(this).toggle(txt.indexOf(term) !== -1);
        });
    }

    $(document).ready(function() {

        $('#dbdm-refresh-btn').on('click', refreshLog);

        $('#dbdm-log-lines').on('change', refreshLog);

        // Filtro log con debounce leggero.
        var filterTimer = null;
        $('#dbdm-log-filter').on('input', function() {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(applyFilter, 150);
        });

        // Testo pieno per filtrare senza richieste.
        var $viewer = $('#dbdm-log-viewer');
        if ($viewer.length) {
            $viewer.data('full', $viewer.text());
            if (!$viewer.text()) $viewer.text(DBDM.i18n.empty_log);
        }

        // Auto-refresh toggle.
        $('#dbdm-auto-refresh').on('change', function() {
            if (this.checked) {
                autoRefreshTimer = setInterval(refreshLog, 5000);
            } else {
                clearInterval(autoRefreshTimer);
                autoRefreshTimer = null;
            }
        });

        // Conferma svuotamento.
        $('#dbdm-clear-form').on('submit', function(e) {
            if (!confirm(DBDM.i18n.confirm_clear)) {
                e.preventDefault();
            }
        });

        // Filtro query.
        var qTimer = null;
        $('#dbdm-q-filter').on('input', function() {
            clearTimeout(qTimer);
            qTimer = setTimeout(applyQueryFilter, 150);
        });

        // Scroll iniziale al fondo del log.
        if ($viewer.length) {
            $viewer.scrollTop($viewer[0].scrollHeight);
        }
    });

})(jQuery);
