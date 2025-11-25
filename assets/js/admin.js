/**
 * WP Cron v2 Admin JS
 */
(function($) {
    'use strict';

    // Yritä job uudelleen
    $(document).on('click', '.retry-job', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $row = $button.closest('tr');
        var jobId = $button.data('id');

        $row.addClass('loading');

        $.post(wpCronV2.ajaxUrl, {
            action: 'wp_cron_v2_retry_job',
            nonce: wpCronV2.nonce,
            id: jobId
        }, function(response) {
            if (response.success) {
                // Päivitä status
                $row.find('.status-badge')
                    .removeClass('status-failed')
                    .addClass('status-queued')
                    .text('queued');

                // Poista retry-nappi, lisää cancel
                $button.replaceWith(
                    '<button class="button button-small cancel-job" data-id="' + jobId + '">Peruuta</button>'
                );

                // Nollaa yritykset
                var $attempts = $row.find('td:eq(5)');
                $attempts.text('0/' + $attempts.text().split('/')[1]);
            } else {
                alert('Virhe: ' + (response.data || 'Tuntematon virhe'));
            }
            $row.removeClass('loading');
        }).fail(function() {
            alert('Verkkovirhe');
            $row.removeClass('loading');
        });
    });

    // Peruuta job
    $(document).on('click', '.cancel-job', function(e) {
        e.preventDefault();

        if (!confirm('Haluatko varmasti peruuttaa tämän jobin?')) {
            return;
        }

        var $button = $(this);
        var $row = $button.closest('tr');
        var jobId = $button.data('id');

        $row.addClass('loading');

        $.post(wpCronV2.ajaxUrl, {
            action: 'wp_cron_v2_cancel_job',
            nonce: wpCronV2.nonce,
            id: jobId
        }, function(response) {
            if (response.success) {
                $row.addClass('removed');
                setTimeout(function() {
                    $row.remove();
                }, 300);
            } else {
                alert('Virhe: ' + (response.data || 'Tuntematon virhe'));
                $row.removeClass('loading');
            }
        }).fail(function() {
            alert('Verkkovirhe');
            $row.removeClass('loading');
        });
    });

    // Auto-refresh tilastot 30s välein
    if ($('.wp-cron-v2-stats').length) {
        setInterval(function() {
            $.post(wpCronV2.ajaxUrl, {
                action: 'wp_cron_v2_get_stats',
                nonce: wpCronV2.nonce
            }, function(response) {
                if (response.success) {
                    $('.stat-queued .stat-number').text(response.data.queued);
                    $('.stat-running .stat-number').text(response.data.running);
                    $('.stat-completed .stat-number').text(response.data.completed);
                    $('.stat-failed .stat-number').text(response.data.failed);
                }
            });
        }, 30000);
    }

})(jQuery);
