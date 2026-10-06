jQuery(document).ready( function($)
{
    $(document).on('click', '#sync-tracking-info-btn', function () {
        var syncCount = $('#sync-count').val();
        if (parseInt(syncCount) === 0) {
            alert("Don't have unsynced orders");
            return;
        }
        $('#sync-tracking-info-btn').addClass('activeLoading');

        var data = {
            'action': 'mecom_gateway_paypal_action',
            'command': 'syncTrackingInfo',
        };
        jQuery.ajaxSetup({timeout: 300000});

        function doSyncBatch(onDone) {
            jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                var responseJson = JSON.parse(response);
                if (responseJson.success) {
                    if (responseJson.count > 0) {
                        doSyncBatch(onDone);
                    } else {
                        onDone(true);
                    }
                } else {
                    onDone(false, responseJson.error);
                }
            }).fail(function () {
                onDone(false, 'Error when sync tracking info. Please try again after![2]');
            });
        }

        doSyncBatch(function (isSuccess, errorMessage) {
            $('#sync-tracking-info-btn').removeClass('activeLoading');
            if (isSuccess) {
                alert('Sync tracking info successfully!');
                location.reload();
            } else {
                alert(errorMessage);
            }
        });
    });
});