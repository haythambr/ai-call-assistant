/*
 * Call desk — AI Call Assistant (jQuery)
 *
 * Every call is drawn as a "voice fingerprint": one bar group per transcript
 * turn, cobalt for the AI receptionist and saffron for the caller. The bars are
 * generated deterministically from the words, so a call always looks the same.
 */
$(function () {
    'use strict';

    var $body = $('body');
    var urls = {
        index: $body.data('index-url'),
        simulate: $body.data('simulate-url'),
        callsBase: $body.data('calls-base-url'),
        contacts: $body.data('settings-url'),
        importContacts: $body.data('import-url'),
        callNumber: $body.data('call-number-url'),
        callMany: $body.data('call-many-url'),
        contactsBase: $body.data('contacts-base-url'),
        contactPage: $body.data('contacts-base-url'),
        campaignPage: $body.data('campaigns-base-url')
    };

    var $list = $('#call-list');
    var $detail = $('#call-detail');
    var $today = $('#today-line');
    var $sync = $('#sync-state');
    var $simulate = $('#simulate-call');
    var $toasts = $('#toasts');

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var state = {
        calls: [],
        stats: null,
        selectedId: $body.data('selected-call') || null,
        renderedDetailKey: null,
        knownStatus: {},      // id -> last status we saw, to spot transitions
        ringingId: null,      // the call created with "Simulate a call"
        justFinished: {},     // id -> true until its finish animation has played
        firstLoad: true,
        pollTimer: null
    };

    var LABELS = {
        dialing: 'Dialing',
        received: 'Ringing',
        processing: 'AI is reading',
        completed: 'Done',
        failed: 'Failed',
        booked: 'Booked',
        conflict: 'Time taken',
        book_appointment: 'Wants to book',
        reschedule: 'Reschedule',
        question: 'Question',
        complaint: 'Complaint',
        opt_out: 'Asked not to call',
        other: 'Other',
        positive: 'Happy caller',
        neutral: 'Neutral',
        negative: 'Upset caller'
    };

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            'Accept': 'application/json'
        }
    });

    /* ------------------------------------------------------------------
     | Data
     | ------------------------------------------------------------------ */

    function loadCalls() {
        clearTimeout(state.pollTimer);

        return $.getJSON(urls.index)
            .done(function (data) {
                var transitions = detectTransitions(data.calls);

                state.calls = data.calls;
                renderToday(data.stats);
                renderList();
                renderDetail();

                $.each(transitions, function (_, call) { announce(call); });
                state.firstLoad = false;

                if (!$('#call-sheet').prop('hidden')) {
                    loadContacts();
                }
            })
            .fail(function () {
                $sync.removeClass('is-busy').text('Offline, retrying');
            })
            .always(scheduleNextPoll);
    }

    function scheduleNextPoll() {
        var busy = state.calls.some(function (call) { return !call.is_finished; });

        $sync.toggleClass('is-busy', busy).text(busy ? 'Analysing calls' : 'Up to date');
        state.pollTimer = setTimeout(loadCalls, busy ? 1500 : 15000);
    }

    function detectTransitions(calls) {
        var finished = [];

        $.each(calls, function (_, call) {
            var before = state.knownStatus[call.id];

            if (before && before !== call.status && call.is_finished) {
                finished.push(call);
                state.justFinished[call.id] = true;
                if (state.ringingId === call.id) {
                    state.ringingId = null;
                }
            }
            state.knownStatus[call.id] = call.status;
        });

        return finished;
    }

    function findCall(id) {
        return $.grep(state.calls, function (call) { return call.id === id; })[0] || null;
    }

    /* ------------------------------------------------------------------
     | Voice fingerprint
     | ------------------------------------------------------------------ */

    function seededRandom(text) {
        var seed = 2166136261;
        for (var i = 0; i < text.length; i++) {
            seed = Math.imul(seed ^ text.charCodeAt(i), 16777619);
        }
        return function () {
            seed = Math.imul(seed ^ (seed >>> 15), seed | 1);
            seed ^= seed + Math.imul(seed ^ (seed >>> 7), seed | 61);
            return ((seed ^ (seed >>> 14)) >>> 0) / 4294967296;
        };
    }

    /**
     * Turn a transcript into bars: [{ role, turn, h }], with h between 0.12 and 1.
     * targetBars spreads a fixed number of bars across the call (list view);
     * without it, longer sentences simply get more bars (detail view).
     */
    function fingerprint(transcript, targetBars) {
        var total = 0;
        $.each(transcript, function (_, turn) { total += turn.text.length; });

        var bars = [];
        $.each(transcript, function (index, turn) {
            var random = seededRandom(turn.role + turn.text);
            var count = targetBars
                ? Math.max(1, Math.round(turn.text.length / Math.max(total, 1) * targetBars))
                : Math.max(4, Math.round(turn.text.length / 6));

            for (var i = 0; i < count; i++) {
                var envelope = 0.45 + 0.55 * Math.sin(Math.PI * (i + 0.5) / count);
                bars.push({
                    role: turn.role,
                    turn: index,
                    h: Math.max(0.12, Math.min(1, envelope * (0.35 + 0.65 * random())))
                });
            }
        });

        return bars;
    }

    /** While an outbound call is still dialing there are no words yet: draw a placeholder line. */
    function waveSource(call) {
        if (call.transcript.length) {
            return call.transcript;
        }
        return [{ role: 'agent', text: new Array(15).join((call.caller_phone || 'dialing') + ' ') }];
    }

    function miniWave(call, rowIndex) {
        var $wave = $('<div>', { 'class': 'mini-wave' + (call.transcript.length ? '' : ' is-pending'), 'aria-hidden': 'true' });

        $.each(fingerprint(waveSource(call), 46), function (i, bar) {
            $('<i>', { 'class': 'v-' + bar.role })
                .css({ height: Math.round(bar.h * 100) + '%', '--i': i, '--row': rowIndex })
                .appendTo($wave);
        });

        return $wave;
    }

    /* ------------------------------------------------------------------
     | Today's sentence
     | ------------------------------------------------------------------ */

    function renderToday(stats) {
        var previous = state.stats;
        state.stats = stats;

        // Calls still being analysed are shown live in the list, so the sentence
        // only counts settled outcomes and keeps its length while calls come in.
        var parts = [
            numberHtml(stats.total, 'total', '') + plural(stats.total, ' call', ' calls') + ' handled',
            numberHtml(stats.booked, 'booked', 'num-booked') + ' booked into the calendar'
        ];
        parts.push(stats.needs_attention
            ? numberHtml(stats.needs_attention, 'needs_attention', 'num-alert') + plural(stats.needs_attention, ' needs', ' need') + ' a person'
            : 'nothing needs a person');

        $today.html(joinSentence(parts) + '.');

        $today.find('.num').each(function () {
            var $num = $(this);
            var key = $num.data('key');
            var to = stats[key];
            // A clause that just appeared shows its number at once.
            var from = previous && previous[key] ? previous[key] : (previous ? to : 0);

            $num.toggleClass('is-zero', to === 0);
            countTo($num, from, to);
        });
    }

    function numberHtml(value, key, extraClass) {
        return '<span class="num ' + extraClass + '" data-key="' + key + '">' + value + '</span>';
    }

    function plural(count, one, many) {
        return count === 1 ? one : many;
    }

    function joinSentence(parts) {
        if (parts.length < 2) {
            return parts.join('');
        }
        var first = parts[0].charAt(0).toUpperCase() + parts[0].slice(1);
        return [first].concat(parts.slice(1, -1)).join(', ') + ', and ' + parts[parts.length - 1];
    }

    function countTo($el, from, to) {
        if (reduceMotion || from === to) {
            $el.text(to);
            return;
        }
        $({ value: from }).animate({ value: to }, {
            duration: 700,
            easing: 'swing',
            step: function (now) { $el.text(Math.round(now)); },
            complete: function () { $el.text(to); }
        });
    }

    /* ------------------------------------------------------------------
     | Call list
     | ------------------------------------------------------------------ */

    function renderList() {
        var previousIds = $list.children('.call-row').map(function () { return $(this).data('id'); }).get();

        $list.empty().toggleClass('is-drawing', state.firstLoad && !reduceMotion);

        if (!state.calls.length) {
            $list.append(
                $('<li>', { 'class': 'empty-list' })
                    .append($('<p>', { text: 'No calls yet.' }))
                    .append($('<p>', { text: 'Press “Simulate a call” to hear how the AI handles one.' }))
            );
            return;
        }

        $.each(state.calls, function (index, call) {
            var live = !call.is_finished;
            var $state = $('<div>', { 'class': 'call-state' }).append(chip(call.status));

            if (call.appointment) {
                $state.append(chip(call.appointment.status));
            } else if (call.intent && call.is_finished) {
                $state.append(chip(call.intent, 'chip-plain'));
            }

            $('<li>', {
                'class': 'call-row'
                    + (call.id === state.selectedId ? ' is-selected' : '')
                    + (live ? ' is-live' : '')
                    + ((call.id === state.ringingId && call.status === 'received') || call.status === 'dialing' ? ' is-ringing' : '')
                    + (!state.firstLoad && $.inArray(call.id, previousIds) === -1 ? ' is-entering' : ''),
                tabindex: 0,
                role: 'button',
                'aria-pressed': call.id === state.selectedId ? 'true' : 'false',
                'aria-label': (call.direction === 'outbound' ? 'Call to ' : 'Call from ') + call.caller + ', ' + (LABELS[call.status] || call.status),
                'data-id': call.id
            })
                .append(
                    $('<div>', { 'class': 'call-who' })
                        .append($('<div>', { 'class': 'call-name', text: call.caller }))
                        .append(
                            $('<div>', { 'class': 'call-when' })
                                .append(direction(call))
                                .append(document.createTextNode(', ' + whenText(call)))
                        )
                )
                .append($state)
                .append(miniWave(call, index))
                .appendTo($list);
        });
    }

    function chip(value, extraClass) {
        return $('<span>', {
            'class': 'chip ' + (extraClass || 'chip-' + value),
            text: LABELS[value] || value
        });
    }

    var ARROWS = {
        inbound: 'M19 6.4 17.6 5 7 15.6V9H5v10h10v-2H8.4L19 6.4Z',
        outbound: 'M5 17.6 6.4 19 17 8.4V15h2V5H9v2h6.6L5 17.6Z'
    };

    function direction(call) {
        var dir = call.direction === 'outbound' ? 'outbound' : 'inbound';
        return $('<span>', { 'class': 'dir dir-' + dir })
            .append('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + ARROWS[dir] + '"/></svg>')
            .append(document.createTextNode(dir === 'outbound' ? 'Outgoing' : 'Incoming'));
    }

    function whenText(call) {
        return call.status === 'dialing'
            ? 'calling now'
            : relativeTime(call.started_at) + ', ' + formatDuration(call.duration_seconds);
    }

    /* ------------------------------------------------------------------
     | Call detail
     | ------------------------------------------------------------------ */

    function renderDetail() {
        var call = findCall(state.selectedId);

        if (!call) {
            return;
        }

        // Re-render only when something about this call changed.
        var key = call.id + ':' + call.status + ':' + (call.appointment ? call.appointment.status : '-');
        if (key === state.renderedDetailKey) {
            return;
        }
        var isSameCall = state.renderedDetailKey && state.renderedDetailKey.split(':')[0] === String(call.id);
        state.renderedDetailKey = key;

        var finishing = !!state.justFinished[call.id];
        delete state.justFinished[call.id];

        var $inner = $('<div>', { 'class': isSameCall ? '' : 'detail-inner' })
            .append(detailHead(call))
            .append(callLine(call, finishing))
            .append(detailBody(call, finishing));

        $detail.empty().append($inner);
    }

    function detailHead(call) {
        var $contact = $('<div>', { 'class': 'contact' });

        if (call.caller_phone) {
            $contact.append($('<a>', { href: 'tel:' + call.caller_phone.replace(/\s+/g, ''), text: call.caller_phone_display || call.caller_phone }));
        }
        if (call.caller_email) {
            $contact.append($('<a>', { href: 'mailto:' + call.caller_email, text: call.caller_email }));
        }
        $contact.append(direction(call));
        $contact.append($('<span>', { text: longTime(call.started_at) }));

        var $chips = $('<div>', { 'class': 'detail-chips' }).append(chip(call.status));
        if (call.intent) {
            $chips.append(chip(call.intent, call.intent === 'opt_out' ? 'chip-opt_out' : 'chip-plain'));
        }
        if (call.sentiment) {
            $chips.append(chip(call.sentiment));
        }

        var $name = call.contact_id && urls.contactPage
            ? $('<a>', { 'class': 'detail-name-link', href: urls.contactPage + '/' + call.contact_id, text: call.caller })
            : document.createTextNode(call.caller);
        var $who = $('<div>').append($('<h2>').append($name)).append($contact);
        if (call.purpose) {
            var $purpose = $('<p>', { 'class': 'purpose-line' })
                .append(document.createTextNode('Called about: '))
                .append($('<strong>', { text: call.purpose }));
            if (call.campaign && urls.campaignPage) {
                $purpose
                    .append(document.createTextNode(' · Campaign '))
                    .append($('<a>', { href: urls.campaignPage + '/' + call.campaign.id, text: call.campaign.name }));
            }
            $who.append($purpose);
        }

        return $('<div>', { 'class': 'detail-head' }).append($who).append($chips);
    }

    function callLine(call, finishing) {
        var analysing = !call.is_finished;
        var pending = !call.transcript.length;
        var bars = fingerprint(waveSource(call));
        var $wave = $('<div>', {
            'class': 'wave' + (pending ? ' is-pending' : ''),
            role: 'img',
            'aria-label': pending ? 'Waiting for the call to connect' : 'Voice fingerprint of the conversation, ' + call.transcript.length + ' turns'
        });

        $.each(bars, function (i, bar) {
            $('<span>', { 'class': 'bar v-' + bar.role, 'data-turn': bar.turn })
                .css({ '--h': bar.h.toFixed(3), '--i': i })
                .appendTo($wave);
        });

        var turns = call.transcript.length;
        var callerTurns = $.grep(call.transcript, function (turn) { return turn.role === 'caller'; }).length;

        var stateClass = pending ? (call.status === 'dialing' ? ' is-dialing' : '') : (analysing ? ' is-analysing' : '');

        return $('<div>', {
            'class': 'callline' + stateClass + (finishing && !reduceMotion ? ' is-settling' : '')
        })
            .append(
                $('<div>', { 'class': 'callline-top' })
                    .append(
                        $('<div>', { 'class': 'legend' })
                            .append($('<span>', { 'class': 'lg-agent', text: 'AI receptionist' }))
                            .append($('<span>', { 'class': 'lg-caller', text: otherParty(call) }))
                    )
                    .append($('<span>', { 'class': 'callline-length', text: formatDuration(call.duration_seconds) }))
            )
            .append($wave)
            .append($('<div>', { 'class': 'playhead', 'aria-hidden': 'true' }))
            .append(
                $('<div>', { 'class': 'ruler' })
                    .append($('<span>', { text: '0:00' }))
                    .append($('<span>', { text: turns + ' turns, ' + otherParty(call).toLowerCase() + ' spoke ' + callerTurns + plural(callerTurns, ' time', ' times') }))
                    .append($('<span>', { text: formatDuration(call.duration_seconds) }))
            )
            .append($('<span>', { 'class': 'reading', text: call.status === 'dialing' ? 'Calling ' + (call.caller_phone || '') + '…' : 'AI is reading the call…' }));
    }

    function otherParty(call) {
        return call.direction === 'outbound' ? 'Customer' : 'Caller';
    }

    function detailBody(call, finishing) {
        var $body = $('<div>', { 'class': 'detail-body' });
        var summaryTitle = call.direction === 'outbound' ? 'How the call went' : 'What the caller wanted';

        if (call.status === 'failed') {
            var connected = call.transcript.length > 0;
            var $problem = $('<div>', { 'class': 'problem' }).append(
                $('<div>')
                    .append($('<strong>', { text: connected ? 'The AI couldn’t finish this call.' : 'This call wasn’t placed.' }))
                    .append($('<span>', { text: call.error || 'Processing failed.' }))
            );
            if (connected) {
                $problem.append($('<button>', { type: 'button', 'class': 'btn-retry js-retry', text: 'Try again', 'data-id': call.id }));
            }
            $body.append($problem);
        }

        if (call.status === 'dialing') {
            $body.append(block('Calling now', $('<p>', {
                'class': 'analysing-note',
                text: 'The AI is calling ' + (call.caller_phone || 'this number') + '. The summary and the full conversation appear here when the call ends.'
            })));
        } else if (!call.is_finished) {
            $body.append(block(summaryTitle, $('<p>', { 'class': 'analysing-note', text: 'The summary appears here as soon as the AI has read the call.' })));
        } else if (call.summary) {
            var $summary = $('<p>', { 'class': 'summary-text' });
            $body.append(block(summaryTitle, $summary));
            typeInto($summary, call.summary, finishing);
        }

        var $columns = $('<div>', { 'class': 'two-col' });

        if (call.appointment) {
            $columns.append(block('Appointment', ticket(call.appointment, finishing)));
        }
        if (call.action_items.length) {
            var $items = $('<ul>', { 'class': 'follow-ups' });
            $.each(call.action_items, function (_, item) {
                $items.append($('<li>', { text: item }));
            });
            $columns.append(block('Follow-ups for the team', $items));
        }
        if ($columns.children().length) {
            $body.append($columns);
        }

        if (call.transcript.length) {
            var $transcript = $('<div>', { 'class': 'transcript' });
            $.each(call.transcript, function (index, turn) {
                $('<div>', { 'class': 'turn turn-' + turn.role, 'data-turn': index })
                    .append($('<span>', { 'class': 'turn-who', text: turn.role === 'agent' ? 'AI receptionist' : (call.caller_name || otherParty(call)) }))
                    .append(document.createTextNode(turn.text))
                    .appendTo($transcript);
            });
            $body.append(block('Conversation', $transcript));
        }

        return $body;
    }

    function block(title, $content) {
        return $('<div>', { 'class': 'block' }).append($('<h3>', { text: title }), $content);
    }

    function ticket(appointment, finishing) {
        var start = new Date(appointment.starts_at);
        var end = new Date(appointment.ends_at);
        var zone = { timeZone: appointment.timezone };
        var time = { hour: 'numeric', minute: '2-digit', timeZone: appointment.timezone };

        var stampText = { booked: 'Booked', conflict: 'Time taken', failed: 'Not booked' }[appointment.status] || appointment.status;

        var $body = $('<div>', { 'class': 'ticket-body' })
            .append($('<div>', { 'class': 'ticket-title', text: appointment.title }))
            .append($('<div>', {
                'class': 'ticket-time',
                text: start.toLocaleTimeString([], time) + ' to ' + end.toLocaleTimeString([], time) + ' (' + appointment.timezone.replace(/_/g, ' ') + ')'
            }));

        if (appointment.link) {
            $body.append($('<a>', { 'class': 'ticket-link', href: appointment.link, target: '_blank', rel: 'noopener', text: 'Open in Google Calendar' }));
        } else if (appointment.provider === 'demo' && appointment.status === 'booked') {
            $body.append($('<p>', { 'class': 'ticket-note', text: 'Demo mode: saved here, not sent to Google Calendar.' }));
        }
        if (appointment.error) {
            $body.append($('<p>', { 'class': 'ticket-note is-alert', text: appointment.error }));
        }
        // Sits in the corner of the ticket on wide screens, under the text on phones.
        $body.append($('<span>', { 'class': 'stamp is-' + appointment.status, text: stampText }));

        return $('<div>', { 'class': 'ticket' + (finishing ? ' is-new' : '') })
            .append(
                $('<div>', { 'class': 'ticket-date' })
                    .append($('<span>', { 'class': 'ticket-month', text: start.toLocaleDateString([], $.extend({ month: 'short' }, zone)) }))
                    .append($('<span>', { 'class': 'ticket-day', text: start.toLocaleDateString([], $.extend({ day: 'numeric' }, zone)) }))
                    .append($('<span>', { 'class': 'ticket-weekday', text: start.toLocaleDateString([], $.extend({ weekday: 'short' }, zone)) }))
            )
            .append($body);
    }

    /** Types the summary out when a call has just been analysed; otherwise shows it at once. */
    function typeInto($el, text, animate) {
        if (!animate || reduceMotion) {
            $el.text(text);
            return;
        }

        var $text = $('<span>');
        var $caret = $('<span>', { 'class': 'caret', 'aria-hidden': 'true' });
        $el.attr('aria-label', text).append($text, $caret);

        var i = 0;
        var step = Math.max(1, Math.round(text.length / 90));
        (function tick() {
            i = Math.min(text.length, i + step);
            $text.text(text.slice(0, i));
            if (i < text.length) {
                setTimeout(tick, 16);
            } else {
                setTimeout(function () { $caret.remove(); $el.removeAttr('aria-label'); }, 900);
            }
        }());
    }

    /* ------------------------------------------------------------------
     | Toasts
     | ------------------------------------------------------------------ */

    function announce(call) {
        var outbound = call.direction === 'outbound';
        var label = (outbound ? 'Call to ' : 'Call from ') + call.caller;
        var message;
        var alert = false;

        if (call.status === 'failed') {
            message = outbound && !call.transcript.length
                ? 'The call to ' + call.caller + ' wasn’t placed.'
                : 'Couldn’t analyse the ' + (outbound ? 'call to ' : 'call from ') + call.caller + '.';
            alert = true;
        } else if (call.intent === 'opt_out') {
            message = call.caller + ' asked not to be called again. Their number won’t be called.';
            alert = true;
        } else if (call.appointment && call.appointment.status === 'booked') {
            message = call.caller + ' is booked for ' + new Date(call.appointment.starts_at).toLocaleString([], {
                weekday: 'short', hour: 'numeric', minute: '2-digit', timeZone: call.appointment.timezone
            }) + '.';
        } else if (call.appointment) {
            message = label + ' analysed, but the time is taken.';
            alert = true;
        } else {
            message = label + ' analysed.';
        }

        toast(message, alert);
    }

    function toast(message, alert) {
        var $toast = $('<div>', { 'class': 'toast' + (alert ? ' is-alert' : ''), text: message }).appendTo($toasts);
        setTimeout(function () {
            $toast.addClass('is-leaving');
            setTimeout(function () { $toast.remove(); }, 300);
        }, 4800);
    }

    /* ------------------------------------------------------------------
     | Formatting
     | ------------------------------------------------------------------ */

    function relativeTime(iso) {
        if (!iso) {
            return 'time unknown';
        }
        var seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
        if (seconds < 60) { return 'just now'; }
        if (seconds < 3600) { return Math.floor(seconds / 60) + ' min ago'; }
        if (seconds < 86400) { return Math.floor(seconds / 3600) + ' h ago'; }
        return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    function longTime(iso) {
        if (!iso) {
            return '';
        }
        return new Date(iso).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function formatDuration(seconds) {
        if (seconds === null || seconds === undefined) {
            return '0:00';
        }
        var minutes = Math.floor(seconds / 60);
        var rest = seconds % 60;
        return minutes + ':' + (rest < 10 ? '0' : '') + rest;
    }

    /* ------------------------------------------------------------------
     | Interaction
     | ------------------------------------------------------------------ */

    function selectCall(id, focusDetail) {
        state.selectedId = id;
        state.renderedDetailKey = null;

        $list.find('.call-row').each(function () {
            var selected = $(this).data('id') === id;
            $(this).toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false');
        });
        renderDetail();

        if (focusDetail && window.matchMedia('(max-width: 960px)').matches) {
            $detail[0].scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        }
    }

    $list.on('click', '.call-row', function () {
        selectCall($(this).data('id'), true);
    });

    $list.on('keydown', '.call-row', function (event) {
        var $row = $(this);

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selectCall($row.data('id'), true);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            var $next = event.key === 'ArrowDown' ? $row.next('.call-row') : $row.prev('.call-row');
            if ($next.length) {
                $next.trigger('focus');
                selectCall($next.data('id'), false);
            }
        }
    });

    // Point at the waveform to find that moment in the conversation.
    $detail.on('mouseenter', '.wave .bar', function () {
        var turn = $(this).data('turn');
        var $wave = $(this).closest('.wave').addClass('is-pointing');

        $wave.find('.bar').each(function () {
            $(this).toggleClass('is-lit', $(this).data('turn') === turn);
        });
        $detail.find('.turn').removeClass('is-lit').filter('[data-turn="' + turn + '"]').addClass('is-lit');
    });

    $detail.on('mouseleave', '.wave', function () {
        $(this).removeClass('is-pointing').find('.bar').removeClass('is-lit');
        $detail.find('.turn').removeClass('is-lit');
    });

    $detail.on('click', '.wave .bar', function () {
        var $turn = $detail.find('.turn[data-turn="' + $(this).data('turn') + '"]');
        if ($turn.length) {
            $turn[0].scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
        }
    });

    $simulate.on('click', function () {
        $simulate.addClass('is-dialing').find('.btn-label').text('Calling…');

        $.post(urls.simulate)
            .done(function (call) {
                state.ringingId = call.id;
                state.knownStatus[call.id] = call.status;
                if (call.is_finished) {
                    state.justFinished[call.id] = true;
                }
                state.selectedId = call.id;
                state.renderedDetailKey = null;
                loadCalls();
            })
            .fail(function () {
                toast('The call didn’t go through. Check that the app is running.', true);
            })
            .always(function () {
                setTimeout(function () {
                    $simulate.removeClass('is-dialing').find('.btn-label').text('Simulate a call');
                }, 600);
            });
    });

    $detail.on('click', '.js-retry', function () {
        var $button = $(this).prop('disabled', true).text('Trying again…');
        var id = $button.data('id');

        $.post(urls.callsBase + '/' + id + '/retry')
            .done(function (call) { state.knownStatus[id] = call.status; })
            .always(loadCalls);
    });

    // Keep "3 min ago" honest without re-fetching.
    setInterval(function () {
        $list.find('.call-row').each(function () {
            var call = findCall($(this).data('id'));
            if (call) {
                $(this).find('.call-when').empty().append(direction(call)).append(document.createTextNode(', ' + whenText(call)));
            }
        });
    }, 30000);

    /* ------------------------------------------------------------------
     | Make calls: one number, a CSV of contacts, or a selection
     | ------------------------------------------------------------------ */

    var $sheet = $('#call-sheet');
    var $contactList = $('#contact-list');
    var $selectAll = $('#select-all');
    var $callSelected = $('#call-selected');
    var $batchResult = $('.batch-result');
    var $dropzone = $('#dropzone');
    var dropzoneTitle = $dropzone.find('.dropzone-title').text();
    var CUSTOM_PURPOSE = '__custom__';
    var outbound = { contacts: [], purposesLoaded: false, lastFocus: null };

    function openSheet() {
        outbound.lastFocus = document.activeElement;
        $sheet.prop('hidden', false).removeClass('is-closing');
        $body.addClass('has-sheet');
        loadContacts().always(function () { $('#cn-name').trigger('focus'); });
    }

    function closeSheet() {
        if ($sheet.prop('hidden')) {
            return;
        }
        $sheet.addClass('is-closing');
        setTimeout(function () {
            $sheet.prop('hidden', true).removeClass('is-closing');
            $body.removeClass('has-sheet');
            if (outbound.lastFocus) {
                outbound.lastFocus.focus();
            }
        }, reduceMotion ? 0 : 230);
    }

    $('#open-calls-sheet').on('click', openSheet);
    $sheet.on('click', '[data-close-sheet]', closeSheet);

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && !$sheet.prop('hidden')) {
            closeSheet();
        }
    });

    // Keep keyboard focus inside the open panel.
    $sheet.on('keydown', function (event) {
        if (event.key !== 'Tab') {
            return;
        }
        var $focusable = $sheet.find('button, input, select, a[href]').filter(':visible:not(:disabled)');
        var first = $focusable[0];
        var last = $focusable[$focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    function loadContacts() {
        return $.getJSON(urls.contacts).done(function (data) {
            outbound.contacts = data.contacts || [];
            if (!outbound.purposesLoaded) {
                fillPurposes(data.purposes);
                outbound.purposesLoaded = true;
            }
            renderHours(data.calling_hours);
            $('#outbound-demo').prop('hidden', !data.demo_mode);
            renderContacts();
        });
    }

    function fillPurposes(purposes) {
        $sheet.find('.js-purpose').each(function () {
            var $select = $(this).empty();
            $.each(purposes, function (_, purpose) {
                $select.append($('<option>', { value: purpose, text: purpose }));
            });
            $select.append($('<option>', { value: CUSTOM_PURPOSE, text: 'Something else…' }));
        });
    }

    function purposeScope($el) {
        return $el.closest('form, .sheet-foot');
    }

    $sheet.on('change', '.js-purpose', function () {
        var custom = $(this).val() === CUSTOM_PURPOSE;
        var $field = purposeScope($(this)).find('.js-custom-purpose').prop('hidden', !custom);
        if (custom) {
            $field.find('input').trigger('focus');
        }
    });

    function purposeFrom($select) {
        return $select.val() === CUSTOM_PURPOSE
            ? $.trim(purposeScope($select).find('.js-custom-purpose input').val())
            : $select.val();
    }

    function renderHours(hours) {
        $('#calling-hours')
            .text('Calls go out between ' + clock(hours.start) + ' and ' + clock(hours.end) + ', ' +
                hours.timezone.replace(/_/g, ' ') + ' time. ' + (hours.open ? 'You can call now.' : 'Calling is closed right now.'))
            .toggleClass('is-open', hours.open)
            .toggleClass('is-closed', !hours.open);
    }

    function clock(hhmm) {
        var parts = String(hhmm).split(':');
        var hour = +parts[0];
        return (hour % 12 || 12) + ':' + (parts[1] || '00') + (hour < 12 ? ' AM' : ' PM');
    }

    function contactStatus(contact) {
        if (contact.opted_out) {
            return { text: 'Asked not to be called', blocked: true };
        }
        if (!contact.has_consent) {
            return { text: 'No consent on record', blocked: true };
        }
        if (contact.last_called_at) {
            return { text: 'Called ' + relativeTime(contact.last_called_at), blocked: false };
        }
        return { text: 'Not called yet', blocked: false };
    }

    function renderContacts() {
        var selected = selectedIds();
        $contactList.empty();

        if (!outbound.contacts.length) {
            $contactList.append($('<li>', { 'class': 'empty', text: 'No contacts yet. Upload a CSV or call a number above, and they’ll be listed here.' }));
        }

        $.each(outbound.contacts, function (_, contact) {
            var status = contactStatus(contact);
            var inputId = 'contact-' + contact.id;
            var label = contact.name || contact.phone;

            $('<li>', { 'class': 'contact' + (status.blocked ? ' is-blocked' : '') })
                .append($('<input>', {
                    type: 'checkbox',
                    id: inputId,
                    value: contact.id,
                    disabled: status.blocked,
                    checked: !status.blocked && $.inArray(contact.id, selected) !== -1
                }))
                .append(
                    $('<label>', { 'class': 'contact-who', 'for': inputId })
                        .append($('<div>', { 'class': 'contact-name', text: label }))
                        .append(
                            $('<div>', { 'class': 'contact-meta' })
                                .append($('<span>', { text: contact.phone }))
                                .append($('<span>', { 'class': status.blocked ? 'is-blocked' : '', text: status.text }))
                        )
                )
                .append($('<button>', {
                    type: 'button',
                    'class': 'btn-mini js-call-contact',
                    'data-id': contact.id,
                    text: 'Call',
                    disabled: status.blocked,
                    'aria-label': 'Call ' + label
                }))
                .appendTo($contactList);
        });

        updateBatchBar();
    }

    function selectedIds() {
        return $contactList.find('input:checked').map(function () { return +this.value; }).get();
    }

    function updateBatchBar() {
        var count = selectedIds().length;
        var callable = $contactList.find('input:not(:disabled)').length;

        $callSelected.prop('disabled', count === 0).text(count ? 'Call ' + count + ' selected' : 'Call selected');
        $selectAll
            .prop('checked', count > 0 && count === callable)
            .prop('indeterminate', count > 0 && count < callable)
            .prop('disabled', callable === 0);
    }

    $contactList.on('change', 'input[type="checkbox"]', updateBatchBar);

    $selectAll.on('change', function () {
        $contactList.find('input:not(:disabled)').prop('checked', this.checked);
        updateBatchBar();
    });

    function errorText(xhr, fallback) {
        var json = xhr && xhr.responseJSON;
        if (json && json.errors) {
            return $.map(json.errors, function (messages) { return messages[0]; }).join(' ');
        }
        return (json && json.message) || fallback;
    }

    function callStarted(call) {
        state.knownStatus[call.id] = call.status;
        state.selectedId = call.id;
        state.renderedDetailKey = null;
        toast(call.status === 'failed' ? (call.error || 'The call wasn’t placed.') : 'Calling ' + call.caller + '…', call.status === 'failed');
        closeSheet();
        loadCalls();
    }

    // Call one number -----------------------------------------------------------

    $('#call-number-form').on('submit', function (event) {
        event.preventDefault();

        var $form = $(this);
        var $error = $form.find('.form-error').text('');
        var $phone = $('#cn-phone').removeClass('is-invalid');
        var purpose = purposeFrom($('#cn-purpose'));

        if (!$.trim($phone.val())) {
            $phone.addClass('is-invalid').trigger('focus');
            $error.text('Enter the phone number to call.');
            return;
        }
        if (!purpose) {
            $error.text('Write what the AI should call about.');
            return;
        }
        if (!$('#cn-consent').is(':checked')) {
            $error.text('Confirm that this person agreed to be called.');
            return;
        }

        var $button = $form.find('button[type="submit"]').prop('disabled', true);
        $button.find('span').text('Calling…');

        $.post(urls.callNumber, { name: $('#cn-name').val(), phone: $phone.val(), purpose: purpose, consent: 1 })
            .done(function (call) {
                $form[0].reset();
                $form.find('.js-custom-purpose').prop('hidden', true);
                callStarted(call);
            })
            .fail(function (xhr) {
                $error.text(errorText(xhr, 'The call didn’t go out. Check the number and try again.'));
                if (xhr.responseJSON && xhr.responseJSON.errors && xhr.responseJSON.errors.phone) {
                    $phone.addClass('is-invalid');
                }
            })
            .always(function () {
                $button.prop('disabled', false).find('span').text('Call now');
            });
    });

    // Call saved contacts -------------------------------------------------------

    $contactList.on('click', '.js-call-contact', function () {
        var $button = $(this);
        var purpose = purposeFrom($('#batch-purpose'));

        if (!purpose) {
            $batchResult.text('Write what the AI should call about.');
            return;
        }

        $button.prop('disabled', true).text('Calling…');
        $.post(urls.contactsBase + '/' + $button.data('id') + '/call', { purpose: purpose })
            .done(callStarted)
            .fail(function (xhr) {
                $batchResult.text(errorText(xhr, 'The call didn’t go out. Try again.'));
                $button.prop('disabled', false).text('Call');
            });
    });

    $callSelected.on('click', function () {
        var purpose = purposeFrom($('#batch-purpose'));

        if (!purpose) {
            $batchResult.text('Write what the AI should call about.');
            return;
        }

        $callSelected.prop('disabled', true).text('Queuing calls…');

        $.post(urls.callMany, { contact_ids: selectedIds(), purpose: purpose })
            .done(function (report) {
                $batchResult.empty();

                if (report.queued) {
                    $batchResult
                        .append($('<strong>', { text: report.queued + plural(report.queued, ' call', ' calls') + ' queued' }))
                        .append(document.createTextNode(report.queued > 1
                            ? ', a few seconds apart. Each one shows up in the call list as it starts.'
                            : '. It shows up in the call list as it starts.'));
                } else {
                    $batchResult.append($('<strong>', { text: 'No calls were queued.' }));
                }

                if (report.skipped.length) {
                    var $skipped = $('<ul>');
                    $.each(report.skipped, function (_, skip) {
                        $skipped.append($('<li>', { text: skip.name + ': ' + skip.reason }));
                    });
                    $batchResult.append($skipped);
                }

                $contactList.find('input:checked').prop('checked', false);
                loadContacts();
                loadCalls();
            })
            .fail(function (xhr) {
                $batchResult.text(errorText(xhr, 'The calls didn’t go out. Try again.'));
            })
            .always(updateBatchBar);
    });

    // Upload a CSV ---------------------------------------------------------------

    $('#import-file').on('change', function () {
        var file = this.files[0];
        $dropzone.toggleClass('has-file', !!file).find('.dropzone-title').text(file ? file.name : dropzoneTitle);
    });

    $dropzone
        .on('dragenter dragover', function () { $dropzone.addClass('is-over'); })
        .on('dragleave drop', function () { $dropzone.removeClass('is-over'); });

    $('#import-form').on('submit', function (event) {
        event.preventDefault();

        var $form = $(this);
        var $error = $form.find('.form-error').text('');
        var $result = $form.find('.form-result').empty();
        var file = $('#import-file')[0].files[0];

        if (!file) {
            $error.text('Choose a CSV file first.');
            return;
        }
        if (!$('#import-consent').is(':checked')) {
            $error.text('Confirm that everyone on the list agreed to be called.');
            return;
        }

        var data = new FormData();
        data.append('file', file);
        data.append('consent', '1');

        var $button = $form.find('button[type="submit"]').prop('disabled', true).text('Uploading…');

        $.ajax({ url: urls.importContacts, method: 'POST', data: data, processData: false, contentType: false, dataType: 'json' })
            .done(function (report) {
                $result
                    .append($('<strong>', { text: report.imported + plural(report.imported, ' contact', ' contacts') + ' added' }))
                    .append(document.createTextNode(report.updated ? ', and ' + report.updated + ' already on the list updated.' : '.'));

                if (report.skipped.length) {
                    var $skipped = $('<ul>');
                    $.each(report.skipped.slice(0, 8), function (_, skip) {
                        $skipped.append($('<li>', { text: 'Row ' + skip.row + ': ' + skip.reason }));
                    });
                    if (report.skipped.length > 8) {
                        $skipped.append($('<li>', { text: 'And ' + (report.skipped.length - 8) + ' more rows.' }));
                    }
                    $result.append(document.createTextNode(' Skipped ' + report.skipped.length + plural(report.skipped.length, ' row:', ' rows:'))).append($skipped);
                }

                $form[0].reset();
                $dropzone.removeClass('has-file').find('.dropzone-title').text(dropzoneTitle);
                loadContacts();
            })
            .fail(function (xhr) {
                $error.text(errorText(xhr, 'The file couldn’t be uploaded. Try again.'));
            })
            .always(function () {
                $button.prop('disabled', false).text('Upload contacts');
            });
    });

    if (new URLSearchParams(window.location.search).get('new') === '1') {
        openSheet();
    }

    loadCalls().done(function () {
        if (state.calls.length && state.selectedId === null && window.matchMedia('(min-width: 961px)').matches) {
            selectCall(state.calls[0].id, false);
        }
    });
});
