define(['knockout', 'input', 'hosting/entities/record', 'ko.mapping'], function(ko, Input, Record, mapping) {
    function SslCert(data) {
        'use strict';

        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.entitiname = 'sslcert';
        this.regStatus = ko.observable(2);
        this.command = {};
        this.id = '';
        this.parkstatus = ko.observable('n/c');
        this.domainselected = ko.observable();
        this.id = ko.observable();
        this.description = new Input();
        this.issuer = new Input();
        this.expirationDate = new Input();
        this.altDomain = new Input();
        this.crt = ko.observable(new Input({
            'content': ''
        }));
        this.key = ko.observable(new Input({
            'content': ''
        }));
        this.isdomain = ko.observable();
        this.forcedHttps = ko.observable(false);

        var mappingRules = {
            'description': {
                create: function(options) {
                    return new Input({
                        "content": options.data
                    });
                }
            },
            'issuer': {
                create: function(options) {
                    return new Input({
                        "content": options.data
                    });
                }
            },
            'expirationDate': {
                create: function(options) {
                    return new Input({
                        "content": options.data
                    });
                }
            },
            'crt': {
                checkCrt: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            },
            'key': {
                checkCrt: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, self);
    };

    SslCert.prototype = new Record({});
    SslCert.prototype.constructor = SslCert;

    SslCert.prototype.genCrt = function() {
        'use strict';
        var self = this;
        var theData = 
        { "params": {
            "idDomain": self.domainselected().id
        }};
        FerozoHosting.sslVM() && FerozoHosting.sslVM().inprocess(1);
        $.postJSON('/hosting/domain/genssl', theData, function(data) { 
        }).fail(function(data) {
        }).always(function(data) {
            FerozoHosting.sslVM().init();
            FerozoHosting.sslVM() && FerozoHosting.sslVM().inprocess(0);
            $('.modal').modal('hide');
        });
    }

    SslCert.prototype.checkCrt = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            'crt': self.crt().content(),
            'key': self.key().content()
        }};

        FerozoHosting.sslVM() && FerozoHosting.sslVM().inprocess(1);
        $.postJSON('/hosting/domain/getsslcertinfo', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i]().clearErrors == "function") {self[i]().clearErrors()}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
            } else {
                $('.modal').modal('hide');
                self.description = new Input({
                    "content": response.result.domain
                });
                self.issuer = new Input({
                    "content": response.result.issuer
                });
                self.expirationDate = new Input({
                    "content": response.result.validTo.date
                });
                self.altDomain = new Input({
                    "content": response.result.altDomain
                });                
                self.isdomain = response.result.isdomain;
                FerozoHosting.sslVM().temp(self);
                $('#install-new').modal('show');
            }
        }).always(function() {
            FerozoHosting.sslVM() && FerozoHosting.sslVM().inprocess(0);
        });
    };    

    SslCert.prototype.install = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            'crt': self.crt().content(),
            'key': self.key().content(),
            'domain': self.description.content(),
            'domainAlt': self.altDomain.content(),
            'forcedHttps': self.forcedHttps()
        }};

        FerozoHosting.sslVM() && FerozoHosting.sslVM().inprocess(1);
        $.postJSON('/hosting/domain/installsslcrtkey', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i]().clearErrors == "function") {self[i]().clearErrors()}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshSslCertList');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.sslVM() && FerozoHosting.sslVM().inprocess(0);
        });
    };
    
    SslCert.prototype.remove = function() {
        var self = this;
        var theData = { "params": {
            "id": ko.utils.unwrapObservable(self.id)
        }};
        $("#confirm-delete").modal('hide');
        this.regStatus(4);
        $.postJSON('/hosting/domain/uninstallsslcert', theData, function(data) {
        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
        });
    };

    return SslCert;
});
