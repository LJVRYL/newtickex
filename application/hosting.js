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
    'ferozohostingapp': 'libs/ferozohostingapp',
    'sort': 'libs/sort',
    'translate': 'libs/translate',
    'whilecallback': 'libs/whilecallback',

    'hotlink': 'hosting/entities/hotlink',
    'accessrecord': 'hosting/entities/accessrecord',
    'accessredirection': 'hosting/entities/accessredirection',
    'backup': 'hosting/entities/backup',
    'record': 'hosting/entities/record',
    'entities/record': 'hosting/entities/record',
    'spampattern': 'hosting/entities/spampattern',
    'antispamconfig': 'hosting/entities/antispamconfig',
    'user': 'hosting/entities/user',
    'availableapp': 'hosting/entities/availableapp',
    'wpstage': 'hosting/entities/wpstage',
    'webapp': 'hosting/entities/webapp',
    'input': 'hosting/entities/input',
    'data': 'hosting/entities/data',
    'domain': 'hosting/entities/domain',
    'subdomain': 'hosting/entities/subdomain',
    'dnsapp': 'hosting/entities/dnsapp',
    'dnsmx': 'hosting/entities/dnsmx',
    'sslcert': 'hosting/entities/sslcert',
    'email': 'hosting/entities/email',
    'exchange': 'hosting/entities/exchange',
    'emailforwarding': 'hosting/entities/emailforwarding',
    'emailalias': 'hosting/entities/emailalias',
    'emailautoreply': 'hosting/entities/emailautoreply',
    'ftp': 'hosting/entities/ftp',
    'git': 'hosting/entities/git',
    'task': 'hosting/entities/task',
    'errorpage': 'hosting/entities/errorpage',
    'mysqldb': 'hosting/entities/mysqldb',
    'mysqldbuser': 'hosting/entities/mysqldbuser',
    'mysqldbhosts': 'hosting/entities/mysqldbhosts',
    'mssqldb': 'hosting/entities/mssqldb',
    'mssqldbuser': 'hosting/entities/mssqldbuser',
    'dnsrecord': 'hosting/entities/dnsrecord',
    'ipsblock': 'hosting/entities/ipsblock',
    'apachehandlers': 'hosting/entities/apachehandlers',
    'scheduledtasks': 'hosting/entities/scheduledtasks',
    'cgi': 'hosting/entities/cgi',
    'account': 'hosting/entities/account',
    'folder': 'hosting/entities/folder'
}});

require(['knockout', 'ko.fzextend', 'ferozohostingapp'], function(ko, fzExtend, FerozoHostingApp) {

    FerozoHosting = new FerozoHostingApp();
    FerozoHosting.init();

    ko.applyBindings(FerozoHosting, window.document.querySelector('html'));
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