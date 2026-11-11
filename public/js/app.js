/*
 * AI Call Assistant: behaviour shared by every page (jQuery).
 * The call desk has its own script, dashboard.js.
 */
$(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    function plural(count, one, many) {
        return count + ' ' + (count === 1 ? one : many);
    }

    /* Toasts from the server (flash messages) ------------------------------ */

    $('[data-flash]').each(function (index) {
        var $toast = $(this);
        setTimeout(function () {
            $toast.addClass('is-leaving');
            setTimeout(function () { $toast.remove(); }, 320);
        }, 5200 + index * 400);
    });

    /* Mobile menu ----------------------------------------------------------- */

    $('[data-open-rail]').on('click', function () { $('body').addClass('has-rail'); });
    $('[data-close-rail]').on('click', function () { $('body').removeClass('has-rail'); });
    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') { $('body').removeClass('has-rail'); }
    });

    /* Confirm before destructive actions ------------------------------------ */

    $(document).on('click', '[data-confirm]', function (event) {
        if (!window.confirm($(this).data('confirm'))) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    });

    /* Filters that apply as you type --------------------------------------- */

    var searchTimer = null;
    $('input[data-autosubmit]').on('input', function () {
        var form = this.form;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { form.requestSubmit ? form.requestSubmit() : form.submit(); }, 450);
    });
    $('select[data-autosubmit]').on('change', function () { this.form.submit(); });

    // Keep the cursor at the end of the search box after the page reloads.
    var $search = $('input[data-autosubmit]');
    if ($search.length && $search.val()) {
        var value = $search.val();
        $search.trigger('focus').val('').val(value);
    }

    /* Contacts: select rows for bulk actions -------------------------------- */

    var $bulkForm = $('#bulk-form');
    if ($bulkForm.length) {
        var $bar = $bulkForm.find('.bulkbar');

        var updateBulk = function () {
            var $checked = $bulkForm.find('.js-check:checked');
            var all = $bulkForm.find('.js-check').length;

            $bulkForm.find('.js-selected-count').text($checked.length);
            $bar.prop('hidden', $checked.length === 0);
            $bulkForm.find('.js-check-all')
                .prop('checked', $checked.length > 0 && $checked.length === all)
                .prop('indeterminate', $checked.length > 0 && $checked.length < all);
            $bulkForm.find('tbody tr').each(function () {
                $(this).toggleClass('is-selected', $(this).find('.js-check').is(':checked'));
            });
        };

        $bulkForm.on('change', '.js-check', updateBulk);
        $bulkForm.on('change', '.js-check-all', function () {
            $bulkForm.find('.js-check').prop('checked', this.checked);
            updateBulk();
        });
        $bulkForm.on('click', '.js-bulk-clear', function () {
            $bulkForm.find('.js-check, .js-check-all').prop('checked', false);
            updateBulk();
        });
        $bulkForm.on('click', '.js-bulk-campaign', function () {
            var ids = $bulkForm.find('.js-check:checked').map(function () { return 'contacts[]=' + this.value; }).get();
            window.location = $bulkForm.data('campaign-url') + '?' + ids.join('&');
        });
    }

    /* File drop zones ------------------------------------------------------- */

    $('[data-dropzone]').each(function () {
        var $zone = $(this);
        var $title = $zone.find('.dropzone-title');

        $zone.find('input[type="file"]').on('change', function () {
            var file = this.files[0];
            $zone.toggleClass('has-file', !!file);
            $title.text(file ? file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB' : $title.data('default'));
        });
        $zone
            .on('dragenter dragover', function () { $zone.addClass('is-over'); })
            .on('dragleave drop', function () { $zone.removeClass('is-over'); });
    });

    /* Tags: one-click suggestions ------------------------------------------ */

    $(document).on('click', '[data-add-tag]', function () {
        var $input = $('[data-tag-input] input');
        var tags = $.map(String($input.val()).split(','), $.trim).filter(Boolean);
        var tag = String($(this).data('add-tag'));

        if ($.inArray(tag, tags) === -1) {
            tags.push(tag);
        }
        $input.val(tags.join(', ')).trigger('focus');
        $(this).addClass('is-added');
    });

    /* "What should the AI call about?": presets or own words ---------------- */

    $('[data-purpose-form]').each(function () {
        var $form = $(this);
        var $value = $form.find('[data-purpose-value]');
        var $custom = $form.find('[data-purpose-custom]');
        var $customInput = $custom.find('input');

        var sync = function () {
            var $radio = $form.find('[data-purpose-radio]:checked');
            var choice = $radio.length ? $radio.val() : $form.find('[data-purpose-select]').val();
            var isCustom = choice === '';

            $custom.prop('hidden', !isCustom);
            $value.val(isCustom ? $.trim($customInput.val()) : choice).trigger('change');
        };

        $form.on('change', '[data-purpose-radio], [data-purpose-select]', function () {
            sync();
            if (!$custom.prop('hidden')) { $customInput.trigger('focus'); }
        });
        $customInput.on('input', sync);
        sync();
    });

    /* Campaign builder ------------------------------------------------------ */

    var $builder = $('#campaign-builder');
    if ($builder.length) {
        var gap = +$builder.data('gap') || 20;
        var callable = +$builder.data('callable') || 0;
        var $picker = $builder.find('[data-picker]');

        var audienceCount = function () {
            var audience = $builder.find('[data-audience]:checked').val();
            if (audience === 'all') { return callable; }
            if (audience === 'tag') { return +$builder.find('input[name="tag"]:checked').data('count') || 0; }
            return $builder.find('input[name="contact_ids[]"]:checked').length;
        };

        var duration = function (count) {
            if (!count) { return '—'; }
            var seconds = Math.max(0, count - 1) * gap + 60;
            if (seconds < 120) { return 'about a minute'; }
            if (seconds < 3600) { return 'about ' + Math.round(seconds / 60) + ' minutes'; }
            return 'about ' + (Math.round(seconds / 360) / 10) + ' hours';
        };

        var updateSummary = function () {
            var count = audienceCount();
            var name = $.trim($builder.find('[data-summary-name]').val());
            var purpose = $.trim($builder.find('[data-purpose-value]').val());
            var later = $builder.find('[data-when]:checked').val() === 'later';
            var when = 'starting right away';

            if (later) {
                var raw = $builder.find('input[name="scheduled_at"]').val();
                var date = raw ? new Date(raw) : null;
                when = date && !isNaN(date) ? 'starting ' + date.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'at the time you pick';
            }

            $builder.find('[data-summary-title]').text(name || 'Untitled campaign');
            $builder.find('[data-summary-count]').text(plural(count, 'person', 'people'));
            $builder.find('[data-summary-purpose]').text(purpose ? purpose.charAt(0).toLowerCase() + purpose.slice(1) : '…');
            $builder.find('[data-summary-when]').text(when);
            $builder.find('[data-summary-duration]').text(duration(count));
            $builder.find('[data-submit-label]').text(later ? 'Schedule campaign' : 'Launch campaign');
            $builder.find('[data-summary-submit]').prop('disabled', count === 0);
            $picker.find('[data-picker-all]').text(
                $builder.find('input[name="contact_ids[]"]:checked').length ? plural($builder.find('input[name="contact_ids[]"]:checked').length, 'person', 'people') + ' picked' : 'Select all who can be called'
            );
        };

        $builder.on('change', '[data-audience]', function () {
            var audience = $(this).val();
            $builder.find('.audience-panel').each(function () {
                $(this).prop('hidden', $(this).data('panel') !== audience);
            });
            updateSummary();
        });
        $builder.on('change', '[data-when]', function () {
            $builder.find('[data-schedule]').prop('hidden', $(this).val() !== 'later');
            updateSummary();
        });
        $builder.on('input change', 'input, select', updateSummary);

        $picker.on('input', '[data-picker-search]', function () {
            var term = $.trim(this.value).toLowerCase();
            var shown = 0;
            $picker.find('.picker-list li').each(function () {
                var match = !term || String($(this).data('search')).indexOf(term) !== -1;
                $(this).prop('hidden', !match);
                shown += match ? 1 : 0;
            });
            $picker.find('.picker-empty').prop('hidden', shown > 0);
        });
        $picker.on('click', '[data-picker-all]', function () {
            $picker.find('.picker-list li:not([hidden]) input:not(:disabled)').prop('checked', true);
            updateSummary();
        });
        $picker.on('click', '[data-picker-none]', function () {
            $picker.find('.picker-list input').prop('checked', false);
            updateSummary();
        });

        updateSummary();
    }

    /* Campaign page: keep the live part fresh ------------------------------- */

    var liveTimer = null;
    function refreshCampaign() {
        var $live = $('#campaign-live');
        if (!$live.length || $live.data('live') !== 1) { return; }

        liveTimer = setTimeout(function () {
            $.get($live.data('refresh-url')).done(function (html) {
                var $next = $($.parseHTML($.trim(html))).filter('#campaign-live');
                if ($next.length && $next.data('live') !== 1) {
                    // Finished: reload once so the buttons and sidebar catch up too.
                    window.location.reload();
                    return;
                }
                if ($next.length) {
                    $live.replaceWith($next);
                }
                refreshCampaign();
            }).fail(function () {
                refreshCampaign();
            });
        }, 3500);
    }
    refreshCampaign();

    /* Copy to clipboard ----------------------------------------------------- */

    $(document).on('click', '[data-copy]', function () {
        var $button = $(this);
        var $field = $($button.data('copy'));
        var done = function () {
            var original = $button.html();
            $button.text('Copied');
            setTimeout(function () { $button.html(original); }, 1600);
        };

        if (navigator.clipboard) {
            navigator.clipboard.writeText($field.val()).then(done);
        } else {
            $field.trigger('select');
            document.execCommand('copy');
            done();
        }
    });

    /* Sign in: demo account ------------------------------------------------- */

    $('[data-demo-email]').on('click', function () {
        var $form = $('form.form-stack');
        $form.find('input[name="email"]').val($(this).data('demo-email'));
        $form.find('input[name="password"]').val($(this).data('demo-password'));
        $(this).addClass('is-loading');
        $form.trigger('submit');
    });

    /* Waves draw in once on load ------------------------------------------- */

    if (!reduceMotion) {
        $('body').addClass('is-ready');
    }
});
