/* Bourse aux stickers — grille d'inventaire (enregistrement à la volée). */
(function () {
    'use strict';

    var root = document.getElementById('stk-inventory');
    if (!root || typeof stkFront === 'undefined') {
        return;
    }

    var form = root.querySelector('.stk-inventory-form');
    var status = root.querySelector('.stk-inventory-status');
    var submitRow = root.querySelector('.stk-inventory-submit');
    var filters = root.querySelector('.stk-filters');
    var timers = {};

    // Avec JavaScript, l'enregistrement est automatique : le bouton global devient inutile.
    if (submitRow) {
        submitRow.hidden = true;
    }
    if (filters) {
        filters.hidden = false;
    }

    function showStatus(message, isError) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.classList.toggle('is-error', !!isError);
    }

    function stateFor(qty) {
        if (qty <= 0) {
            return 'manquant';
        }
        return qty > 1 ? 'double' : 'possede';
    }

    function setCardState(card, qty) {
        card.classList.remove('stk-state-manquant', 'stk-state-possede', 'stk-state-double');
        card.classList.add('stk-state-' + stateFor(qty));
        // Le filtre n'est pas réappliqué ici : une case ne disparaît pas sous le doigt.
    }

    function updateStats(stats) {
        Object.keys(stats).forEach(function (key) {
            var el = root.querySelector('[data-stk-stat="' + key + '"]');
            if (el) {
                el.textContent = stats[key];
            }
        });
    }

    function save(card, input, previous) {
        var id = card.getAttribute('data-stk-id');
        var body = new FormData();
        body.append('action', 'stk_set_quantity');
        body.append('nonce', stkFront.nonce);
        body.append('sticker_id', id);
        body.append('qty', input.value);

        card.classList.add('is-saving');
        fetch(stkFront.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                card.classList.remove('is-saving');
                if (json && json.success) {
                    input.value = json.data.qty;
                    input.setAttribute('data-stk-saved', json.data.qty);
                    setCardState(card, json.data.qty);
                    updateStats(json.data.stats);
                    showStatus(stkFront.i18n.saved, false);
                } else {
                    var qty = (json && json.data && typeof json.data.qty !== 'undefined') ? json.data.qty : previous;
                    input.value = qty;
                    input.setAttribute('data-stk-saved', qty);
                    setCardState(card, qty);
                    showStatus((json && json.data && json.data.message) ? json.data.message : stkFront.i18n.error, true);
                }
            })
            .catch(function () {
                card.classList.remove('is-saving');
                input.value = previous;
                setCardState(card, previous);
                showStatus(stkFront.i18n.error, true);
            });
    }

    function change(card, delta) {
        var input = card.querySelector('input[type="number"]');
        var min = parseInt(card.getAttribute('data-stk-min'), 10) || 0;
        var saved = input.hasAttribute('data-stk-saved') ? parseInt(input.getAttribute('data-stk-saved'), 10) : parseInt(input.defaultValue, 10) || 0;
        var current = parseInt(input.value, 10);
        if (isNaN(current)) {
            current = saved;
        }
        var next = Math.max(min, Math.min(stkFront.max, current + delta));
        input.value = next;
        setCardState(card, next);
        scheduleSave(card, input, saved);
    }

    function scheduleSave(card, input, previous) {
        var id = card.getAttribute('data-stk-id');
        clearTimeout(timers[id]);
        timers[id] = setTimeout(function () {
            if (String(input.value) !== String(previous)) {
                save(card, input, previous);
            }
        }, 400);
    }

    root.addEventListener('click', function (event) {
        var plus = event.target.closest('.stk-card-plus');
        var minus = event.target.closest('.stk-card-minus');
        if (!plus && !minus) {
            return;
        }
        var card = event.target.closest('.stk-card');
        if (card) {
            change(card, plus ? 1 : -1);
        }
    });

    root.addEventListener('change', function (event) {
        var input = event.target;
        if (!input.matches('.stk-card input[type="number"]')) {
            return;
        }
        var card = input.closest('.stk-card');
        var saved = input.hasAttribute('data-stk-saved') ? parseInt(input.getAttribute('data-stk-saved'), 10) : parseInt(input.defaultValue, 10) || 0;
        var min = parseInt(card.getAttribute('data-stk-min'), 10) || 0;
        var value = parseInt(input.value, 10);
        if (isNaN(value)) {
            value = saved;
        }
        input.value = Math.max(min, Math.min(stkFront.max, value));
        setCardState(card, parseInt(input.value, 10));
        scheduleSave(card, input, saved);
    });

    // La touche Entrée dans un champ ne doit pas soumettre tout le formulaire.
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
        });
    }

    var activeFilter = 'all';

    function applyFilter() {
        root.querySelectorAll('.stk-card').forEach(function (card) {
            card.hidden = activeFilter !== 'all' && !card.classList.contains('stk-state-' + activeFilter);
        });
    }

    if (filters) {
        filters.addEventListener('click', function (event) {
            var button = event.target.closest('[data-stk-filter]');
            if (!button) {
                return;
            }
            activeFilter = button.getAttribute('data-stk-filter');
            filters.querySelectorAll('[data-stk-filter]').forEach(function (b) {
                b.classList.toggle('is-active', b === button);
                b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
            });
            applyFilter();
        });
    }
})();
