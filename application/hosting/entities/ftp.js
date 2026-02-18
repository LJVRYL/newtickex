define(['knockout', 'ko.mapping', 'input', 'domain'], function(ko, mapping, Input, Domain) {
    /* ------------ SUBDOMAIN -----------------*/
    var Ftp = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.usernamePrefix = new Input();
        this.ftppass = new Input();
        this.account = ko.observable('');
        this.domain = ko.observable(new Domain());
        this.path = new Input();
        var mappingRules = {
            'ftppass': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            },
            'usernamePrefix': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            },
            'account': {
                update: function(options) {
                    return  options.data;
                }
            },
            'path': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    Ftp.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        FerozoHosting.ftpVM() && FerozoHosting.ftpVM().inprocess(1);
        self.regStatus(4);
        $.postJSON('/hosting/ftp/removeftpaccount', theData, function(e) {
            mediator.publish('refreshFtp');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.ftpVM() && FerozoHosting.ftpVM().inprocess(0);
        });
    };

    Ftp.prototype.save = function() {
        'use strict';
        var self = this;
        var domainId = self.domain().id;

        var theData = { "params": {
            "usernamePrefix": self.usernamePrefix.content(),
            "ftppass": self.ftppass.content(),
            "path": self.path.content(),
            "idDomain": domainId
        }};

        FerozoHosting.ftpVM() && FerozoHosting.ftpVM().inprocess(1);
        $.postJSON('/hosting/ftp/createftpaccount', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].clearErrors == "function") {self[i].clearErrors()}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshFtp');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.ftpVM() && FerozoHosting.ftpVM().inprocess(0);
        });
    };

    Ftp.prototype.changepass = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "ftppass": self.ftppass.content()
        }};

        FerozoHosting.ftpVM() && FerozoHosting.ftpVM().inprocess(1);
        $.postJSON('/hosting/ftp/changeftppassword', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshFtp');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.ftpVM() && FerozoHosting.ftpVM().inprocess(0);
        });
    };

    return Ftp;
});