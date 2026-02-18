require.config({"baseUrl":"/application",'paths': {
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
    'ferozodhmapp': 'libs/ferozodhmapp',
    'sort': 'libs/sort',
    'translate': 'libs/translate',
    'whilecallback': 'libs/whilecallback',
    'securityconfig': 'dhm/entities/securityconfig',
    'user': 'dhm/entities/user',
    'task': 'dhm/entities/task',   
    'hosting': 'dhm/entities/hosting',
    'reseller': 'dhm/entities/reseller',
    'package': 'dhm/entities/package',
    'resellerpackage': 'dhm/entities/resellerpackage',
    'service': 'dhm/entities/service',
    'process': 'dhm/entities/process',
    'input': 'dhm/entities/input',
    'ip': 'dhm/entities/ip',
    'backupconfig': 'dhm/entities/backupconfig',
    'securityconfig': 'dhm/entities/securityconfig',
    'masslogin': 'dhm/entities/masslogin',
    'domain': 'dhm/entities/domain',
    'dnsrecord': 'dhm/entities/dnsrecord',
    'features': 'dhm/entities/features',
    'featuresitems': 'dhm/entities/featuresitems'
}});

require(['knockout', 'ko.fzextend', 'ferozodhmapp'], function(ko, fzExtend, FerozoDhmApp) {

    FerozoDhm = new FerozoDhmApp();
    FerozoDhm.init();

    ko.applyBindings(FerozoDhm, window.document.querySelector('html'));
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