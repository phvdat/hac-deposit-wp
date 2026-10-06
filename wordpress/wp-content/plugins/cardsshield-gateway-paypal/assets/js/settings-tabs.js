jQuery(function ($) {
    var $wrap = $('.cs-pp-tabs');
    if (!$wrap.length) return;

    $wrap.on('click', '.cs-pp-tab-link', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $wrap.find('.cs-pp-tab-link').removeClass('active');
        $(this).addClass('active');
        $wrap.find('.cs-pp-tab-panel').hide();
        $wrap.find('.cs-pp-tab-panel[data-tab="' + tab + '"]').show();
        $('#cs_pp_active_tab').val(tab);

        if (history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('cs_pp_tab', tab);
            history.replaceState(null, '', url.toString());
        }
    });
});
