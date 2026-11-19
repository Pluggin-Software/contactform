/*
 * ContactForm (1.0.1): the public form's draft and double-send guard, the form
 * builder's rows, delete confirmations and the ⋯ menu. Plain DOM, delegated
 * from document, so it works on any page the module renders and needs nothing
 * from jQuery. Everything here is an enhancement: every form posts and every
 * screen works without it, except adding a field in the builder.
 */
(function () {
    'use strict';

    var CHOICE_TYPES = ['select', 'radio', 'checkboxes'];

    /* ---------------------------------------------------------- the public form */

    /*
     * A draft of what is typed, kept for this tab only (sessionStorage). A
     * message is otherwise lost whenever the page comes back without it: a
     * refused CSRF token at 'enforce', a session that expired while someone
     * wrote, a slip of the back button. Restored only into a form the server
     * sent back EMPTY, so it never overrides what the server kept; cleared when
     * the thank-you appears.
     */
    function draftKey(id) { return 'cf_draft_' + id; }

    function draftFields(form) {
        return Array.prototype.filter.call(form.elements, function (el) {
            return el.name && el.name.indexOf('f[') === 0 && !el.hasAttribute('data-cf-nodraft');
        });
    }

    function saveDraft(form) {
        var data = {};
        draftFields(form).forEach(function (el) {
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) { (data[el.name] = data[el.name] || []).push(el.value); }
            } else if (el.value !== '') {
                data[el.name] = el.value;
            }
        });
        try { sessionStorage.setItem(draftKey(form.getAttribute('data-cf-draft')), JSON.stringify(data)); } catch (e) { /* storage off: nothing to keep */ }
    }

    function restoreDraft(form) {
        var fields = draftFields(form);
        var empty = fields.every(function (el) {
            return (el.type === 'checkbox' || el.type === 'radio') ? !el.checked : el.value === '';
        });
        if (!empty) { return; }

        var data;
        try { data = JSON.parse(sessionStorage.getItem(draftKey(form.getAttribute('data-cf-draft'))) || 'null'); } catch (e) { data = null; }
        if (!data) { return; }

        fields.forEach(function (el) {
            var v = data[el.name];
            if (v === undefined) { return; }
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = [].concat(v).indexOf(el.value) !== -1;
            } else {
                el.value = v;
            }
        });
    }

    /* ---------------------------------------------------------- the builder */

    function rowName(row) {
        var label = row.querySelector('[data-cf-name="label"]');
        var text = label ? label.value.trim() : '';
        return text || 'new field';
    }

    // Rows post as fields[n][...] in the order they are shown; each row's
    // hidden legend and button names follow its label, so a screen reader
    // hears "Move Email address up", not the same words on every row.
    function renumber(list) {
        Array.prototype.forEach.call(list.querySelectorAll('[data-cf-row]'), function (row, n) {
            Array.prototype.forEach.call(row.querySelectorAll('[data-cf-name]'), function (input) {
                input.name = 'fields[' + n + '][' + input.getAttribute('data-cf-name') + ']';
            });
            nameRow(row, n);
        });
    }

    function nameRow(row, n) {
        var name = rowName(row);
        var legend = row.querySelector('[data-cf-legend]');
        if (legend) { legend.textContent = 'Field ' + (n + 1) + ': ' + name; }
        var up = row.querySelector('[data-cf-up]'), down = row.querySelector('[data-cf-down]'), remove = row.querySelector('[data-cf-remove]');
        if (up) { up.setAttribute('aria-label', 'Move ' + name + ' up'); }
        if (down) { down.setAttribute('aria-label', 'Move ' + name + ' down'); }
        if (remove) { remove.setAttribute('aria-label', 'Remove ' + name); }
    }

    function announce(builder, text) {
        var live = builder.querySelector('[data-cf-announce]');
        if (!live) { return; }
        // cleared first, so the same words said twice are still announced
        live.textContent = '';
        text = text.charAt(0).toUpperCase() + text.slice(1);
        window.setTimeout(function () { live.textContent = text; }, 50);
    }

    function position(list, row) {
        return Array.prototype.indexOf.call(list.querySelectorAll('[data-cf-row]'), row) + 1;
    }

    function toggleOptions(row) {
        var type = row.querySelector('[data-cf-type]');
        var options = row.querySelector('[data-cf-options]');
        if (type && options) {
            options.hidden = CHOICE_TYPES.indexOf(type.value) === -1;
        }
    }

    /* ---------------------------------------------------------- events */

    document.addEventListener('click', function (e) {
        var t = e.target;
        var builder = t.closest && t.closest('.cf-builder');

        if (builder) {
            var list = builder.querySelector('[data-cf-rows]');
            var row = t.closest('[data-cf-row]');
            var total;

            if (t.closest('[data-cf-add]')) {
                var tpl = builder.querySelector('[data-cf-template]');
                var fresh = tpl.content.firstElementChild.cloneNode(true);
                list.appendChild(fresh);
                renumber(list);
                fresh.querySelector('[data-cf-name="label"]').focus();
                announce(builder, 'Field ' + position(list, fresh) + ' added. Give it a label.');
                return;
            }
            if (row && t.closest('[data-cf-remove]')) {
                var name = rowName(row);
                var next = row.nextElementSibling || row.previousElementSibling;
                row.parentNode.removeChild(row);
                renumber(list);
                if (next) { next.querySelector('[data-cf-name="label"]').focus(); }
                else { builder.querySelector('[data-cf-add]').focus(); }
                announce(builder, name + ' removed. It is gone when you save.');
                return;
            }
            total = list.querySelectorAll('[data-cf-row]').length;
            if (row && t.closest('[data-cf-up]') && row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
                renumber(list);
                t.closest('button').focus();
                announce(builder, rowName(row) + ' moved up, to field ' + position(list, row) + ' of ' + total + '.');
                return;
            }
            if (row && t.closest('[data-cf-down]') && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
                renumber(list);
                t.closest('button').focus();
                announce(builder, rowName(row) + ' moved down, to field ' + position(list, row) + ' of ' + total + '.');
                return;
            }
        }

        // a ⋯ menu closes on a click anywhere outside it
        Array.prototype.forEach.call(document.querySelectorAll('details.cf-menu[open]'), function (menu) {
            if (!menu.contains(t)) { menu.open = false; }
        });
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('.cf-builder [data-cf-type]')) {
            toggleOptions(e.target.closest('[data-cf-row]'));
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.closest) { return; }
        var draft = el.closest('form[data-cf-draft]');
        if (draft) { saveDraft(draft); return; }
        if (el.matches('.cf-builder [data-cf-name="label"]')) {
            var row = el.closest('[data-cf-row]');
            var list = el.closest('[data-cf-rows]');
            nameRow(row, position(list, row) - 1);
        }
    });
    // checkboxes and radios report change, not input, in some browsers
    document.addEventListener('change', function (e) {
        var draft = e.target.closest && e.target.closest('form[data-cf-draft]');
        if (draft) { saveDraft(draft); }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var question = form.getAttribute && form.getAttribute('data-cf-confirm');
        if (question && !window.confirm(question)) {
            e.preventDefault();
            return;
        }
        if (form.classList && form.classList.contains('cf-builder')) {
            renumber(form.querySelector('[data-cf-rows]'));
        }
        // one message, one post: a second press while the first is on its way
        // does nothing. The server also answers a repeat within two minutes as
        // the same message (ContactForm::DUPLICATE_SECONDS).
        if (form.hasAttribute && form.hasAttribute('data-cf-draft')) {
            if (form.getAttribute('data-cf-sending') === '1') {
                e.preventDefault();
                return;
            }
            form.setAttribute('data-cf-sending', '1');
            saveDraft(form);
            var button = form.querySelector('.cf-send');
            if (button) {
                button.setAttribute('aria-disabled', 'true');
                button.setAttribute('data-cf-label', button.textContent);
                button.textContent = button.getAttribute('data-cf-sending') || 'Sending…';
            }
        }
    });

    // back to this page from the browser's cache: the form can be sent again
    window.addEventListener('pageshow', function () {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-cf-sending="1"]'), function (form) {
            form.removeAttribute('data-cf-sending');
            var button = form.querySelector('.cf-send');
            if (button && button.hasAttribute('data-cf-label')) {
                button.textContent = button.getAttribute('data-cf-label');
                button.removeAttribute('aria-disabled');
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-cf-sent]'), function (box) {
            try { sessionStorage.removeItem(draftKey(box.getAttribute('data-cf-sent'))); } catch (e) { /* nothing kept */ }
        });
        Array.prototype.forEach.call(document.querySelectorAll('form[data-cf-draft]'), restoreDraft);

        // a refused message or save brings the reader to what went wrong
        var box = document.querySelector('.cf-errors, .cf-sent');
        if (box) { box.focus(); }
    });
})();
