/**
 * DB Debug Manager — Admin JS
 */
(function($) {
    'use strict';

    var autoRefreshTimer = null;

    function refreshLog() {
        var $viewer = $('#dbdm-log-viewer');
        if (!$viewer.length) return;

        var lines = $('#dbdm-log-lines').val() || 500;

        $.post(DBDM.ajax_url, {
            action: 'dbdm_refresh_log',
            nonce: DBDM.nonce,
            lines: lines
        }).done(function(res) {
            if (res && res.success) {
                var content = res.data.content || DBDM.i18n.empty_log;
                $viewer.text(content);
                applyFilter();
                // Scroll a fondo.
                $viewer.scrollTop($viewer[0].scrollHeight);
            }
        });
    }

    function applyFilter() {
        var term = ($('#dbdm-log-filter').val() || '').toLowerCase();
        var $viewer = $('#dbdm-log-viewer');
        if (!$viewer.length) return;

        // Recupera il testo pieno dalla prima volta.
        if (!$viewer.data('full')) {
            $viewer.data('full', $viewer.text());
        }

        if (!term) {
            $viewer.text($viewer.data('full'));
            return;
        }

        var filtered = $viewer.data('full')
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

        // Al cambio del selettore righe aggiorna e memorizza testo pieno nuovo.
        $('#dbdm-log-lines').on('change', function() {
            var $v = $('#dbdm-log-viewer');
            $v.removeData('full');
            refreshLog();
        });

        // Filtro log con debounce leggero.
        var filterTimer = null;
        $('#dbdm-log-filter').on('input', function() {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(applyFilter, 150);
        });

        // Inizializza il valore "full" per permettere filtri senza fetch.
        var $viewer = $('#dbdm-log-viewer');
        if ($viewer.length) {
            $viewer.data('full', $viewer.text());
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
