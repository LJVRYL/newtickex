define(['knockout', 'account', 'mediator', 'input', 'email', 'ko.mapping'], function(ko, Account, Mediator, Input, Email, mapping) {
    /* ------------ EMAIL -----------------*/
    var EmailAutoReply = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable('');
        this.idEmailAccount = ko.observable('');
        this.subject = ko.observable(new Input({
            'content': ''
        }));
        this.keepSubject = ko.observable(true);
        this.name = ko.observable(new Input({
            'content': ''
        }));

        this.body = ko.observable(new Input({
            'content': ''
        }));

        this.expiration = ko.observable(new Input({
            'content': ''
        }));
        
        this.expired = ko.observable();
        
        var mappingRules = {
            'subject': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                },
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            },
            'name': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                },
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            },
            'body': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                },
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            },
            'expiration': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                },
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    EmailAutoReply.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        $.postJSON('/hosting/email/removeemailautoreply', theData, function(e) {
            mediator.publish('refreshEmailAutoReply');
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
        });
    };

    EmailAutoReply.prototype.save = function() {
        'use strict';

        var _uriNew= "/hosting/email/createemailautoreply";
        var _uriModify= "/hosting/email/changeemailautoreply";
        var _uri= "/hosting/email/changeemailautoreply";
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "idEmailAccount": self.idEmailAccount(),
            "name": self.name().content(),
            "subject": self.subject().content(),
            "keepSubject": self.keepSubject(),
            "body": self.body().content(),
            "expiration": self.expiration().content()
        }};

        FerozoHosting.emailautoreplyVM() && FerozoHosting.emailautoreplyVM().inprocess(1);

        if (self.id() > 0) {
            _uri=_uriModify;
        } else {
            _uri=_uriNew;
        }

        $.postJSON(_uri, theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i]().clearErrors == "function") {self[i]().clearErrors()}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshEmailAutoReply');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.emailautoreplyVM() && FerozoHosting.emailautoreplyVM().inprocess(0);
        });
    };

    return EmailAutoReply;
});