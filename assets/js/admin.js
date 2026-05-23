(function($) {
    'use strict';

    $(document).ready(function() {
        $('.notice.is-dismissible').on('click', '.notice-dismiss', function() {
            $(this).closest('.notice').fadeOut(150);
        });

        $('.twork-bulk-actions select').on('change', function() {
            if ($(this).val()) {
                $(this).closest('form').find('input[type="submit"]').prop('disabled', false);
            }
        });
    });
})(jQuery);
