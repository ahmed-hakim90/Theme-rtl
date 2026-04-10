/* global wookapso, jQuery */
jQuery(function ($) {

    var nonce    = wookapso.nonce;
    var ajaxUrl  = wookapso.ajax_url;

    // ── Helpers ──────────────────────────────────────────────────────────
    function ajax(action, data, done, fail) {
        $.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, data))
            .done(done)
            .fail(fail || function () { console.error('WooKapso AJAX failed:', action); });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function showResult($el, type, msg) {
        $el.removeClass('success error').addClass(type).html(msg).show();
    }

    function spin($btn, text) {
        $btn.prop('disabled', true).data('orig', $btn.text()).text(text || '⏳ جاري...');
    }

    function unspin($btn) {
        $btn.prop('disabled', false).text($btn.data('orig'));
    }

    var i18n = wookapso.i18n || {};
    var pendingTplDelete = null;

    if (i18n.delete_tpl_cancel) {
        $('#wkp-tpl-delete-cancel').text(i18n.delete_tpl_cancel);
        $('#wkp-tpl-delete-confirm-btn').text(i18n.delete_tpl_yes);
    }
    if (i18n.clear_logs_yes) {
        $('#wkp-logs-clear-yes').text(i18n.clear_logs_yes);
        $('#wkp-logs-clear-no').text(i18n.clear_logs_cancel);
        $('#wkp-logs-clear-confirm-title').text(i18n.clear_logs_title);
        $('#wkp-logs-clear-confirm-detail').text(i18n.clear_logs_detail);
    }

    // ═══════════════════════════════════════
    //  SETTINGS TAB
    // ═══════════════════════════════════════

    // Test Connection
    $(document).on('click', '#wkp-test-connection', function () {
        var $btn = $(this), $res = $('#wkp-connection-result');
        spin($btn, '⏳ جاري الاختبار...');
        $res.removeClass('wkp-conn-rich').empty().css('color', '');

        ajax('wookapso_test_conn', {}, function (res) {
            var ok = !!res.success;
            var d = res.data || {};
            var msg = d.message || res.message || '';
            if (!msg) {
                msg = ok ? '✅ الاتصال ناجح!' : '❌ خطأ غير معروف';
            }
            $res.removeClass('wkp-conn-rich');
            if (ok) {
                $res.css('color', '#166534').text('✅ ' + msg);
            } else {
                var html = '❌ ' + escHtml(msg);
                if (d.cause) {
                    html += '<br><span class="wkp-err-label">السبب:</span> ' + escHtml(d.cause);
                }
                if (d.hint) {
                    html += '<br><span class="wkp-err-label">ما الذي تفعله:</span> ' + escHtml(d.hint);
                }
                $res.addClass('wkp-conn-rich').css('color', '#991B1B').html(html);
            }
            unspin($btn);
        }, function () {
            $res.removeClass('wkp-conn-rich').css('color', '#991B1B').text('❌ تعذّر الوصول للخادم — تحقق من الشبكة أو جرّب لاحقاً.');
            unspin($btn);
        });
    });

    // Refresh templates dropdown
    $(document).on('click', '.wkp-refresh-templates', function () {
        var $btn = $(this);
        spin($btn, '⏳');
        // Invalidate and reload page
        ajax('wookapso_fetch_templates', {}, function (res) {
            if (!res.success) {
                unspin($btn);
                return;
            }
            var templates = res.data || [];
            // Update every select
            $('.wkp-tpl-select').each(function () {
                var $sel    = $(this);
                var current = $sel.val();
                // Keep first option (default), remove the rest
                $sel.find('option:not(:first)').remove();
                templates.forEach(function (t) {
                    if (t.status.toUpperCase() !== 'APPROVED') return;
                    var label = '✅ ' + t.name + (t.buttons.length ? ' [أزرار]' : '');
                    $sel.append($('<option>').val(t.name).text(label));
                });
                $sel.val(current);
                unspin($btn);
            });
        });
    });

    // ═══════════════════════════════════════
    //  TEMPLATES TAB
    // ═══════════════════════════════════════

    function applyConfirmationPreset() {
        var p = wookapso.confirmation_preset;
        if (!p || !p.name) return;
        $('#tpl-name').val(p.name);
        $('#tpl-body').val(p.body || '');
        var texts = (p.buttons || []).map(function (b) { return (b && b.text) ? b.text : ''; });
        var $rows = $('#tpl-buttons-list .wkp-btn-row');
        texts.forEach(function (t, i) {
            if ($rows.eq(i).length) {
                $rows.eq(i).find('.tpl-btn-text').val(t);
            }
        });
        if ($('#tpl-preview').is(':visible')) {
            $('#btn-preview-tpl').trigger('click');
        }
    }

    $('#btn-load-confirmation-preset').on('click', function () {
        applyConfirmationPreset();
    });

    // Load templates list on page load if tab is templates
    if (window.location.search.indexOf('tab=templates') !== -1) {
        loadTemplatesList();
    }

    $('#btn-refresh-list').on('click', loadTemplatesList);

    function loadTemplatesList() {
        var $list = $('#tpl-list');
        var $load = $('#tpl-list-loading');
        $list.empty();
        $load.show();

        ajax('wookapso_fetch_templates', {}, function (res) {
            $load.hide();
            if (!res.success) {
                var err = (res.data && res.data.message) ? res.data.message : 'فشل التحميل';
                $list.html('<div class="wkp-empty"><span>❌</span><p>' + escHtml(err) + '</p></div>');
                return;
            }
            var templates = res.data || [];
            if (!templates.length) {
                $list.html('<div class="wkp-empty"><span>📭</span><p>لا توجد تيمبلتات بعد. أنشئ أول تيمبلت!</p></div>');
                return;
            }
            templates.forEach(function (t) {
                $list.append(buildTplCard(t));
            });
        });
    }

    function buildTplCard(t) {
        var tmpl     = $('#tpl-card-tmpl').html();
        var statusCls = { APPROVED: 'sent', PENDING: 'pending', REJECTED: 'failed' }[t.status.toUpperCase()] || 'pending';
        var btnsTag  = t.buttons.length ? '<span class="wkp-badge wkp-badge--blue">أزرار: ' + t.buttons.join(' / ') + '</span>' : '';

        return tmpl
            .replace(/{{ID}}/g,          escHtml(t.id))
            .replace(/{{NAME}}/g,         escHtml(t.name))
            .replace(/{{STATUS}}/g,       escHtml(t.status))
            .replace(/{{STATUS_CLASS}}/g, statusCls)
            .replace(/{{CATEGORY}}/g,     escHtml(t.category))
            .replace(/{{LANGUAGE}}/g,     escHtml(t.language))
            .replace(/{{BUTTONS_TAG}}/g,  btnsTag)
            .replace(/{{BODY}}/g,         escHtml(t.body));
    }

    // Preview
    $('#btn-preview-tpl').on('click', function () {
        var body    = $('#tpl-body').val().trim();
        var $box    = $('#tpl-preview');
        var buttons = getButtons();

        if (!body) return;

        // Replace {{n}} with sample values
        var sample = body
            .replace('{{1}}', '<strong>محمد</strong>')
            .replace('{{2}}', '<strong>55</strong>')
            .replace('{{3}}', '<strong>320 ج.م</strong>');

        $('#preview-body').html(sample);

        var btnsHtml = '';
        buttons.forEach(function (b) {
            if (b.text) btnsHtml += '<div class="wkp-preview-btn">' + escHtml(b.text) + '</div>';
        });
        $('#preview-buttons').html(btnsHtml);
        $box.show();
    });

    // Live preview on type
    $('#tpl-body').on('input', function () {
        if ($('#tpl-preview').is(':visible')) $('#btn-preview-tpl').trigger('click');
    });

    // Add button row
    $('#add-btn-row').on('click', function () {
        var $row = $('<div class="wkp-btn-row">' +
            '<input type="text" placeholder="نص الزرار" class="tpl-btn-text" />' +
            '<button type="button" class="wkp-btn wkp-btn--danger wkp-btn--xs remove-btn-row">✕</button>' +
            '</div>');
        $('#tpl-buttons-list').append($row);
    });

    // Remove button row
    $(document).on('click', '.remove-btn-row', function () {
        $(this).closest('.wkp-btn-row').remove();
        if ($('#tpl-preview').is(':visible')) $('#btn-preview-tpl').trigger('click');
    });

    // Create template
    $('#btn-create-tpl').on('click', function () {
        var $btn = $(this), $res = $('#create-tpl-result');
        var name    = $('#tpl-name').val().trim();
        var body    = $('#tpl-body').val().trim();
        var buttons = getButtons();

        if (!name) { showResult($res, 'error', '❌ اكتب اسم التيمبلت'); $res.show(); return; }
        if (!body)  { showResult($res, 'error', '❌ اكتب نص الرسالة'); $res.show(); return; }

        spin($btn, '⏳ جاري الإرسال لـ Meta...');
        $res.hide();

        ajax('wookapso_create_template', {
            name:     name,
            body:     body,
            category: $('#tpl-category').val(),
            language: $('#tpl-language').val(),
            buttons:  JSON.stringify(buttons),
        }, function (res) {
            unspin($btn);
            if (res.success) {
                showResult($res, 'success',
                    '✅ تم إرسال التيمبلت لـ Meta! انتظر الموافقة (1-3 أيام) ثم ستظهر في القائمة.');
                loadTemplatesList();
                $('#tpl-preview').hide();
                if (wookapso.confirmation_preset && wookapso.confirmation_preset.name) {
                    applyConfirmationPreset();
                } else {
                    $('#tpl-name,#tpl-body').val('');
                }
            } else {
                var msg = res.data?.message || 'فشل الإنشاء';
                var detail = res.data?.detail || '';
                var html = '❌ ' + escHtml(msg);
                if (detail) {
                    html += '<br><small class="wkp-api-detail" style="display:block;margin-top:8px;opacity:.9;word-break:break-word;">' + escHtml(detail) + '</small>';
                }
                showResult($res, 'error', html);
            }
        });
    });

    $('#wkp-tpl-delete-cancel').on('click', function () {
        pendingTplDelete = null;
        $('#wkp-tpl-delete-confirm').hide();
    });

    $('#wkp-tpl-delete-confirm-btn').on('click', function () {
        if (!pendingTplDelete) return;
        var id = pendingTplDelete.id;
        var $btn = pendingTplDelete.$trigger;
        $('#wkp-tpl-delete-confirm').hide();
        pendingTplDelete = null;
        spin($btn, '⏳');
        ajax('wookapso_delete_template', { template_id: id }, function (res) {
            unspin($btn);
            if (!res.success && res.data && res.data.message) {
                var fail = i18n.delete_tpl_fail || 'Error';
                var $flash = $('<div class="wkp-result-box error" role="alert" style="margin-bottom:12px;">❌ ' + escHtml(res.data.message || fail) + '</div>');
                $('#tpl-list').prepend($flash);
                setTimeout(function () {
                    $flash.fadeOut(400, function () {
                        $(this).remove();
                    });
                }, 10000);
                return;
            }
            loadTemplatesList();
        });
    });

    // Delete template — inline confirm (no window.confirm)
    $(document).on('click', '.wkp-delete-tpl', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        var name = $btn.data('name');
        var tmpl = i18n.delete_tpl_confirm || 'Delete template "%s"?';
        pendingTplDelete = { id: id, $trigger: $btn };
        $('#wkp-tpl-delete-msg').text(tmpl.replace('%s', String(name)));
        $('#wkp-tpl-delete-confirm').show();
    });

    // Copy template name
    $(document).on('click', '.wkp-use-tpl', function () {
        var name = $(this).data('name');
        navigator.clipboard.writeText(name).catch(function () {});
        var $b = $(this);
        $b.text('✅ تم النسخ');
        setTimeout(function () { $b.text('📋 نسخ الاسم'); }, 2000);
    });

    // ═══════════════════════════════════════
    //  TEST TAB
    // ═══════════════════════════════════════

    $('#wkp-send-test').on('click', function () {
        var $btn  = $(this), $res = $('#wkp-test-result');
        var phone = $('#wkp-test-phone').val().trim();
        var tpl   = $('#wkp-test-type').val();

        if (!phone) { showResult($res, 'error', '❌ ادخل رقم موبايل'); $res.show(); return; }
        spin($btn, '⏳ جاري الإرسال...');
        $res.hide();

        ajax('wookapso_test_msg', { phone: phone, template: tpl }, function (res) {
            unspin($btn);
            var ok = !!res.success;
            var d = res.data || {};
            var msg = ok ? (d.message || '✅ تم الإرسال بنجاح!') : (d.message || res.message || 'فشل الإرسال');
            var html = (ok ? '' : '❌ ') + escHtml(msg);
            if (!ok) {
                if (d.cause) {
                    html += '<br><span class="wkp-err-label">السبب:</span> ' + escHtml(d.cause);
                }
                if (d.solution) {
                    html += '<br><span class="wkp-err-label">الحل:</span> ' + escHtml(d.solution);
                }
                if (d.technical && d.technical !== msg) {
                    html += '<span class="wkp-err-tech">' + escHtml(d.technical) + '</span>';
                }
            }
            showResult($res, ok ? 'success' : 'error', html);
        });
    });

    // Copy Webhook URL
    $('#wkp-copy-url').on('click', function () {
        var url = $('#wkp-webhook-url').text();
        var $b  = $(this);
        navigator.clipboard.writeText(url).then(function () {
            $b.text('✅ تم النسخ');
            setTimeout(function () { $b.text('📋 نسخ'); }, 2000);
        });
    });

    // ═══════════════════════════════════════
    //  LOGS TAB
    // ═══════════════════════════════════════

    $('#wkp-clear-logs').on('click', function () {
        var $bar = $('#wkp-logs-clear-confirm');
        if ($bar.is(':visible')) {
            $bar.hide();
            return;
        }
        $bar.show();
    });

    $('#wkp-logs-clear-no').on('click', function () {
        $('#wkp-logs-clear-confirm').hide();
    });

    $('#wkp-logs-clear-yes').on('click', function () {
        var $btn = $('#wkp-clear-logs');
        $('#wkp-logs-clear-confirm').hide();
        spin($btn);
        ajax('wookapso_clear_logs', {}, function () {
            location.reload();
        });
    });

    // ═══════════════════════════════════════
    //  Utilities
    // ═══════════════════════════════════════

    function getButtons() {
        var btns = [];
        $('.tpl-btn-text').each(function () {
            var t = $(this).val().trim();
            if (t) btns.push({ text: t });
        });
        return btns;
    }
});
