define(['knockout', 'account', 'mediator', 'input', 'email', 'ko.mapping'], function(ko, Account, Mediator, Input, Email, mapping) {
    var EmailForwarding = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable('');
        this.idEmailAccount = ko.observable('');
        this.keepMailCopy = ko.observable(true);
        this.disableKeepMailCopy = ko.observable("0");

        this.emailForward = ko.observable(new Input({
            'content': ''
        }));

        var mappingRules = {
            'emailForward': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            }, 'emailAccount': {
                create: function(options) {
                    return ko.observable(new Email(options.data));
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    EmailForwarding.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        // FerozoHosting.emailforwardingVM() && FerozoHosting.emailforwardingVM().inprocess(1);
        $.postJSON('/hosting/email/removeemailforward', theData, function(e) {
            mediator.publish('emailForwardDeleted');
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            // FerozoHosting.emailforwardingVM() && FerozoHosting.emailforwardingVM().inprocess(0);
        });
    };

    EmailForwarding.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmailAccount": self.idEmailAccount(),
            "emailForward": self.emailForward().content(),
            "keepMailCopy": self.keepMailCopy()
        }};

        FerozoHosting.emailforwardingVM() && FerozoHosting.emailforwardingVM().inprocess(1);
        $.postJSON('/hosting/email/createemailforward', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
                FerozoHosting.emailforwardingVM() && FerozoHosting.emailforwardingVM().inprocess(0);
            } else {
                mediator.publish('refreshEmailForwarding');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.emailforwardingVM() && FerozoHosting.emailforwardingVM().inprocess(0);
        });
    };
    return EmailForwarding;
});