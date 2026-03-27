(function($) {
    'use strict';
    if (typeof ScandTicket === 'undefined') return;

    function apiCall(endpoint, method, data) {
        return $.ajax({
            url: ScandTicket.api + endpoint,
            method: method || 'GET',
            data: data ? JSON.stringify(data) : undefined,
            contentType: 'application/json',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ScandTicket.nonce); }
        });
    }

    window.ScandTicketAdmin = { apiCall: apiCall };
})(jQuery);