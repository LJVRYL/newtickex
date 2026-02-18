define(['knockout'], function(ko) {
    var Account = function(id, idaccounttype, user, password, usernameprefix, usernamesuffix, idaccountcontainer, idregstatus, idcommand, useridn) {
        'use strict';

        this.id = ko.observable(id || '');
        this.idaccounttype = ko.observable(idaccounttype || '');
        this.user = ko.observable(user || '');
        this.password = ko.observable(password || '');
        this.usernameprefix = ko.observable(usernameprefix || '');
        this.usernamesuffix = ko.observable(usernamesuffix || '');
        this.idaccountcontainer = ko.observable(idaccountcontainer || 'null');
        this.idregstatus = ko.observable(idregstatus || 'null');
        this.idcommand = ko.observable(idcommand || 'null');
        this.useridn = ko.observable(useridn || '');
    };

    Account.prototype.eliminar = function() {
        'use strict';
        $.post('index/removedomain/format/json/id/' + this.id(), function() {
            //TODO: Mejorar esto intentando evitar el uso de VM global,
            FerozoHosting.domainVM().init();
        });
    };

    return Account;
});