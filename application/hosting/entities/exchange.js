define(['knockout', 'account', 'mediator', 'input', 'ko.mapping', 'domain', 'user'], function(ko, Account, Mediator, Input, mapping, Domain, User) {
    /* ------------ EMAIL -----------------*/
    var Exchange = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        var self = this;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable('');
        this.domain = new Domain;
        this.account = new Account;

        this.usernameSuffix = new Input({'content': ''});
        this.usernamePrefix = new Input({'content': ''});
        this.password = new Input({'content': ''});

        this.completeAccount = ko.computed(function() {
            return self.account.user();
        });

        var mappingRules = {
            'usernamePrefix': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            }, 'usernameSuffix': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            }, 'password': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    Exchange.prototype.getExchangeVM = function() {
        return FerozoHosting.exchangeVM();
    };

    Exchange.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idExchangeAccount": self.id()
        }};

        self.getExchangeVM().inprocess(1);
        self.regStatus(4);
        $.postJSON('/hosting/email/removeexchangeaccount', theData, function() {
            mediator.publish('refreshExchangeList');
        }).always(function(data) {
            if (data.error) {
                self.regStatus(1);
            }
            self.getExchangeVM().inprocess(0);
        });
    };

    Exchange.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDomain": self.domain.id,
            "usernamePrefix": self.usernamePrefix.content(),
            "password": self.password.content()
        }};

        self.getExchangeVM().inprocess(1);
        $.postJSON('/hosting/email/createexchangeaccount', theData, function(response) {
            $.each(theData.params,function(i, e) {
                if (typeof self[i] !== 'undefined' && typeof self[i].error === "function") {
                    self[i].error("");
                }
            });
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshExchangeList');
                $('.modal').modal('hide');
            }
        }).always(function() {
            self.getExchangeVM().inprocess(0);
        });
    };

    Exchange.prototype.openChangepassword = function() {
        'use strict';
        var self = this;
        self.getExchangeVM().temp(self);
        $('#changepassword').modal('show');
    };

    Exchange.prototype.changepassword = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idExchangeAccount": self.id(),
            "password": self.password.content()
        }};

        self.regStatus(3);
        self.getExchangeVM().inprocess(1);
        $.postJSON('/hosting/email/changeexchangepassword', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshExchangeList');
                $('.modal').modal('hide');
            }
        }).always(function() {
            self.regStatus(1);
            self.getExchangeVM().inprocess(0);
        });
    };


    return Exchange;
});