require.config({"baseUrl":"/application",'paths': {
//require.config({'paths': {
    'knockout': 'libs/knockout',
    'ko.mapping': 'libs/knockout.mapping',
    'ko.fzextend': 'libs/knockout.fzextend',
    'mediator': 'libs/mediator',
    'fzPaginator': 'libs/knockout.fzpaginator',
    'fzPaginatorAjax': 'libs/knockout.fzpaginatorAjax',
    'caller': 'libs/caller',
    'treeNavigation': 'libs/tree.navigation',
    'notifications': 'libs/notifications',
    'cookies': 'libs/cookies',
    'ferozodashboardapp': 'libs/ferozodashboardapp',
    'sort': 'libs/sort',
    'translate': 'libs/translate',
    'whilecallback': 'libs/whilecallback',
    'panelalert': 'dashboard/entities/panelalert',
}});

require(['knockout', 'ko.fzextend', 'ferozodashboardapp'], function(ko, fzExtend, FerozoDashboardApp) {

    FerozoDashboard = new FerozoDashboardApp();
    FerozoDashboard.init();

    ko.applyBindings(FerozoDashboard, window.document.querySelector('html'));
    fzExtend.extend(ko);

    $(function() {
        $(window.document).on('shown', '.modal', function() {
            $(window.document).scrollTop() > 200 && $(window.document).scrollTop(85);
            var self = this;
            var timer = setTimeout(function() {
                $('[autofocus]', self).focus();
                timer && clearTimeout(timer);
            }, 100);
        });
    });
});