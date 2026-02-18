define(['knockout', 'account', 'mediator', 'input', 'email', 'ko.mapping'], function(ko, Account, Mediator, Input, Email, mapping) {
    /* ------------ EMAIL -----------------*/
    var EmailAlias = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable('');
        this.idDomain = ko.observable('');
        this.idEmailAccount = ko.observable('');
        this.subdomainDomain = ko.observable();
        this.emailAlias = ko.observable(new Input({
            'content': ''
        }));
        var mappingRules = {
            'emailAlias': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    EmailAlias.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        FerozoHosting.emailaliasVM() && FerozoHosting.emailaliasVM().inprocess(1);
        $.postJSON('/hosting/email/removeemailalias', theData, function(e) {
            mediator.publish('refreshEmailAlias');
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            FerozoHosting.emailaliasVM() && FerozoHosting.emailaliasVM().inprocess(0);
            data.error && self.regStatus(1);
        });;
    };

    EmailAlias.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmailAccount": self.idEmailAccount(),
            "emailAlias": self.emailAlias().content(),
            "idDomain": self.subdomainDomain().id,
            "type": self.subdomainDomain().type
        }};

        FerozoHosting.emailaliasVM() && FerozoHosting.emailaliasVM().inprocess(1);
        $.postJSON('/hosting/email/createemailalias', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshEmailAlias');
                $('.modal').modal('hide');
            }
        }).always(function(data) {
            FerozoHosting.emailaliasVM() && FerozoHosting.emailaliasVM().inprocess(0);
        });
    };

    return EmailAlias;
});