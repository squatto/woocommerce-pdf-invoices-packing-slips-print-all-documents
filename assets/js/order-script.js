jQuery(document).ready(function ($) {
    $('#doaction, #doaction2').click(function (event) {
        var actionselected = $(this).attr('id').substr(2);
        var action = $('select[name="' + actionselected + '"]').val();

        if (action == wpo_wcpdf_all_ajax.bulk_action) {
            event.preventDefault();

            var checked = [];

            $('tbody th.check-column input[type="checkbox"]:checked').each(function () {
                checked.push($(this).val());
            });

            if (!checked.length) {
                alert('You have to select order(s) first!');
                return;
            }

            var order_ids = checked.join('x');
            var url = wpo_wcpdf_all_ajax.ajaxurl +
                (wpo_wcpdf_all_ajax.ajaxurl.indexOf('?') != -1 ? '&' : '?') +
                'action=generate_wpo_wcpdf_all' +
                '&document_type=all' +
                '&order_ids=' + order_ids +
                '&_wpnonce=' + wpo_wcpdf_all_ajax.nonce;

            window.open(url, '_blank');
        }
    });
});

