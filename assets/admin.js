/* global TBotAjax */
(function ($) {
    'use strict';

    /* ── TOAST ──────────────────────────────────────────────────────────── */
    function toast(message, type = 'success', duration = 3500) {
        let $t = $('#tbot-toast');
        if (!$t.length) {
            $t = $('<div id="tbot-toast"></div>').appendTo('body');
        }
        $t.removeClass('tbot-toast-success tbot-toast-error tbot-toast-warning')
          .addClass('tbot-toast-' + type)
          .html('<span>' + message + '</span>')
          .css('display', 'flex');

        clearTimeout($t.data('timer'));
        $t.data('timer', setTimeout(() => $t.fadeOut(300), duration));
    }

    /* ── BUTTON LOADING STATE ────────────────────────────────────────────── */
    function setLoading($btn, loading) {
        if (loading) {
            $btn.data('orig-html', $btn.html())
                .html('<span class="tbot-spinner"></span> Cargando...')
                .prop('disabled', true);
        } else {
            $btn.html($btn.data('orig-html') || $btn.html())
                .prop('disabled', false);
        }
    }

    /* ── AJAX HELPER ─────────────────────────────────────────────────────── */
    function tbotAjax(action, extraData, $btn, onSuccess, onError) {
        if ($btn) setLoading($btn, true);
        $.post(TBotAjax.ajax_url, {
            action:    'tbot_' + action,
            nonce:     TBotAjax.nonce,
            ...extraData
        })
        .done(function (res) {
            if ($btn) setLoading($btn, false);
            if (res.success) {
                onSuccess(res.data);
            } else {
                const msg = res.data?.message || 'Error inesperado.';
                toast('❌ ' + msg, 'error');
                if (onError) onError(res.data);
            }
        })
        .fail(function () {
            if ($btn) setLoading($btn, false);
            toast('❌ Error de red. Verifica tu conexión.', 'error');
            if (onError) onError();
        });
    }

    /* ── DIAGNOSTICS: CHECK TELEGRAM ─────────────────────────────────────── */
    $(document).on('submit', '#tbot-form-check-telegram', function (e) {
        e.preventDefault();
        const $btn     = $(this).find('button[type=submit]');
        const $result  = $('#tbot-result-telegram');

        tbotAjax('check_telegram', {}, $btn, function (data) {
            const hasError = !!data.last_error_message;
            const html = `
                <div class="tbot-diag-result tbot-diag-${hasError ? 'error' : 'success'}">
                    <div class="tbot-diag-result-row"><span>URL Webhook:</span><code>${escHtml(data.url || 'No configurada')}</code></div>
                    <div class="tbot-diag-result-row"><span>Pendientes:</span><strong>${parseInt(data.pending_update_count || 0)}</strong></div>
                    <div class="tbot-diag-result-row"><span>Último error:</span><span>${escHtml(data.last_error_message || '✅ Ninguno')}</span></div>
                </div>`;
            $result.html(html);
            toast(hasError ? '⚠️ Telegram reporta errores' : '✅ Conexión OK con Telegram', hasError ? 'warning' : 'success');
        });
    });

    /* ── DIAGNOSTICS: TEST AI ────────────────────────────────────────────── */
    $(document).on('submit', '#tbot-form-test-ai', function (e) {
        e.preventDefault();
        const $btn    = $(this).find('button[type=submit]');
        const $result = $('#tbot-result-ai');

        tbotAjax('test_ai', {}, $btn, function (data) {
            $result.html(`<div class="tbot-diag-result tbot-diag-success">✅ ${escHtml(data.message || 'IA OK')}<br><small class="tbot-muted">Tokens usados: ${data.tokens || 0}</small></div>`);
            toast('✅ IA responde correctamente', 'success');
        }, function () {
            $result.html('<div class="tbot-diag-result tbot-diag-error">❌ No se pudo contactar la IA. Revisa tus API Keys en Ajustes.</div>');
            toast('❌ Fallo al contactar la IA', 'error');
        });
    });

    /* ── DIAGNOSTICS: SET WEBHOOK ─────────────────────────────────────────── */
    $(document).on('submit', '#tbot-form-set-webhook', function (e) {
        e.preventDefault();
        const $btn    = $(this).find('button[type=submit]');
        const $result = $('#tbot-result-webhook');

        tbotAjax('set_webhook', {}, $btn, function (data) {
            $result.html(`<div class="tbot-diag-result tbot-diag-success">✅ Webhook configurado en Telegram.<br><small class="tbot-muted">${escHtml(data.webhook_url || '')}</small></div>`);
            toast('✅ Webhook de Telegram activado', 'success');
        }, function (data) {
            $result.html(`<div class="tbot-diag-result tbot-diag-error">❌ Telegram rechazó el webhook. Revisa tu Token Maestro.<br><small>${escHtml(data?.description || '')}</small></div>`);
            toast('❌ Error configurando webhook', 'error');
        });
    });

    $(document).on('submit', '#tbot-form-flush-webhook', function (e) {
        e.preventDefault();
        const $btn    = $(this).find('button[type=submit]');
        const $result = $('#tbot-result-webhook');

        tbotAjax('flush_webhook', {}, $btn, function (data) {
            $result.html(`<div class="tbot-diag-result tbot-diag-success">✅ Tubería limpiada. Webhook reseteado.<br><small class="tbot-muted">${escHtml(data.message || '')}</small></div>`);
            toast('✅ Webhook de Telegram limpiado', 'success');
        }, function (data) {
            $result.html(`<div class="tbot-diag-result tbot-diag-error">❌ Error limpiando webhook. Revisa tu Token Maestro.<br><small>${escHtml(data?.description || '')}</small></div>`);
            toast('❌ Error limpiando webhook', 'error');
        });
    });

    /* ── DIAGNOSTICS: CLEAR LOGS ─────────────────────────────────────────── */
    $(document).on('submit', '#tbot-form-clear-logs', function (e) {
        e.preventDefault();
        if (!confirm('¿Estás seguro de que quieres borrar todos los logs?')) return;

        const $btn = $(this).find('button[type=submit]');
        tbotAjax('clear_logs', {}, $btn, function () {
            $('#tbot-logs-tbody').html('<tr><td colspan="4" class="tbot-center tbot-muted" style="padding:20px;">Sin logs registrados.</td></tr>');
            toast('🗑 Logs borrados correctamente', 'success');
        });
    });

    /* ── DIAGNOSTICS: LIVE LOG POLLING ───────────────────────────────────── */
    if ($('#tbot-logs-tbody').length) {
        let logCountdown = 30;
        const $indicator = $('<span class="tbot-muted" style="font-size:11px; margin-left:10px;">Actualización en <b id="tbot-log-countdown">30</b>s</span>');
        $('#tbot-logs-toolbar').append($indicator);

        const logTimer = setInterval(function () {
            logCountdown--;
            $('#tbot-log-countdown').text(logCountdown);
            if (logCountdown <= 0) {
                logCountdown = 30;
                refreshLogs();
            }
        }, 1000);

        function refreshLogs() {
            tbotAjax('get_logs', { limit: 50 }, null, function (data) {
                if (!data.html) return;
                $('#tbot-logs-tbody').html(data.html);
            });
        }
    }

    /* ── USERS: INLINE STATUS TOGGLE ────────────────────────────────────── */
    $(document).on('change', '.tbot-user-status-select', function () {
        const $sel    = $(this);
        const user_id = $sel.data('user-id');
        const status  = $sel.val();

        tbotAjax('update_user_field', { user_id, field: 'tbot_status', value: status }, null, function () {
            toast('✅ Estado actualizado', 'success');
            const $badge = $sel.closest('tr').find('.tbot-status-badge');
            $badge.attr('class', 'tbot-badge tbot-badge-' + (status === 'blacklisted' ? 'error' : 'success'))
                  .text(status === 'blacklisted' ? '🚫 Bloqueado' : '✅ Activo');
        });
    });

    $(document).on('change', '.tbot-user-sub-select', function () {
        const $sel    = $(this);
        const user_id = $sel.data('user-id');
        const value   = $sel.val();

        tbotAjax('update_user_field', { user_id, field: 'tbot_subscription', value }, null, function () {
            toast('✅ Suscripción actualizada', 'success');
            const $badge = $sel.closest('tr').find('.tbot-sub-badge');
            const labels = { free:'🆓 Free', standard:'⭐ Standard', premium:'💎 Premium', admin:'🔑 Admin' };
            const classes = { free:'free', standard:'standard', premium:'premium', admin:'admin' };
            $badge.attr('class', 'tbot-badge tbot-badge-' + (classes[value] || 'free'))
                  .text(labels[value] || value);
        });
    });

    /* ── STAT COUNTER ANIMATION ──────────────────────────────────────────── */
    $('.tbot-stat-value').each(function () {
        const $el   = $(this);
        const target = parseInt($el.text().replace(/[^0-9]/g, ''), 10);
        if (isNaN(target) || target === 0) return;
        let current = 0;
        const step = Math.max(1, Math.ceil(target / 28));
        const timer = setInterval(function () {
            current = Math.min(current + step, target);
            $el.text(current.toLocaleString());
            if (current >= target) clearInterval(timer);
        }, 28);
    });

    /* ── PERSONA ICON PREVIEW ─────────────────────────────────────────────── */
    $(document).on('input', '.persona-icon-input', function () {
        $(this).closest('.tbot-persona-card').find('.tbot-persona-icon-preview').text($(this).val());
    });

    /* ── PAYMENT METHOD TOGGLE ────────────────────────────────────────────── */
    $(document).on('change', '.tbot-method-toggle input', function () {
        $(this).closest('.tbot-method-toggle').toggleClass('active', this.checked);
    });

    /* ── UTILITY ─────────────────────────────────────────────────────────── */
    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})(jQuery);
