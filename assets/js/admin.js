/* Bourse aux stickers — administration (choix d'image via la médiathèque WordPress). */
(function ($) {
    'use strict';

    var frame = null;

    $(document).on('click', '.stk-image-choose', function (event) {
        event.preventDefault();
        var $field = $(this).closest('.stk-image-field');

        if (typeof wp === 'undefined' || !wp.media) {
            return;
        }
        if (!frame) {
            frame = wp.media({
                title: stkAdmin.mediaTitle,
                button: { text: stkAdmin.mediaButton },
                library: { type: 'image' },
                multiple: false
            });
        }

        frame.off('select').on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
            $field.find('.stk-image-id').val(attachment.id);
            $field.find('.stk-image-preview').empty().append($('<img>', { src: url, alt: '' }));
            $field.find('.stk-image-remove').prop('hidden', false);
        });

        frame.open();
    });

    $(document).on('click', '.stk-image-remove', function (event) {
        event.preventDefault();
        var $field = $(this).closest('.stk-image-field');
        $field.find('.stk-image-id').val('0');
        $field.find('.stk-image-preview').empty();
        $(this).prop('hidden', true);
    });

    // Arbitrage d'un litige : confirmation avant d'appliquer la décision.
    $(document).on('submit', '.stk-resolve-form', function (event) {
        if (!window.confirm($(this).data('confirm'))) {
            event.preventDefault();
        }
    });

    $(document).on('submit', '.stk-delete-form', function (event) {
        if (!window.confirm(stkAdmin.confirmDelete)) {
            event.preventDefault();
        }
    });
})(jQuery);
