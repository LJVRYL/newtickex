define(['knockout', 'input', 'hosting/entities/record', 'ko.mapping'], function(ko, Input, Record, mapping) {
    function Domain(data) {
        'use strict';

        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.entitiname = 'domain';
        this.regStatus = ko.observable(2);
        this.command = {};
        this.id = '';
        this.parkstatus = ko.observable('n/c');
        this.domain = new Input();
        this.sslCert = ko.observable();
        this.ui_stat = ko.observable('');
        this.clearDomain = ko.computed(function() {
            return self.domain.content();
        });
        this.forcedHttps = ko.observable(false);
        this.flaghttps = ko.observable(0);
        this.hasexternalmx = ko.observable(0);

        var mappingRules = {
            'domain': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, self);
    };

    Domain.prototype = new Record({});
    Domain.prototype.constructor = Domain;

    Domain.prototype.rename = function() {
        var self = this;
        var theData = { "params": {
            "id": ko.utils.unwrapObservable(self.id),
            "newdomain": self.domain.content()
        }};

        self.regStatus(3);
        ko.utils.clearObservableErrors.bind(self).apply();
        return $.postJSON('/hosting/domain/renamedomain', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException, function(obj) {
                    obj.field === 'newdomain' && (obj.field = 'domain.content');
                }).apply();
            }
            if (data.result) {
                FerozoHosting.domainsVM().init();
                $("#rename").modal('hide');
            }
        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
        });
    };

    Domain.prototype.addLets = function(entity, event) {
        var self = this;
        var theData = 
            { "params": {
                "idDomain": ko.utils.unwrapObservable(self.id)
            }};
        this.regStatus(3);
        $.postJSON('/hosting/domain/genssl', theData, function(data) { 
        }).fail(function(data) {
        }).always(function(data) {
            FerozoHosting.domainsVM().listPaginated();
            self.regStatus(1);
        });
    };

    Domain.prototype.remove = function() {
        var self = this;
        var theData = { "params": {
            "id": ko.utils.unwrapObservable(self.id)
        }};
        $("#confirm-delete").modal('hide');
        this.regStatus(4);
        $.postJSON('/hosting/domain/removedomain', theData, function(data) {
        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
        });
    };

    Domain.prototype.changeForce = function() {
        var self = this;
        var theData = { "params": {
            "domain": self.domain.content(),
            "forced" : self.forcedHttps()
        }};
        self.regStatus(3);
        $.postJSON('/hosting/domain/forcehttps', theData, function(data) {
            if (data.result) {
                FerozoHosting.domainsVM().init();
                //$("#forzarhttps").modal('hide');
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.regStatus(1);
        });
    };

    Domain.prototype.isFzCom = function() {
        var self = this;
        if ((self.domain.content().indexOf('.ferozo.com') > 0) || (self.domain.content().indexOf('.ferozo.net') > 0)){
            return true;
        } else {
            return false;
        }        
    };


    return Domain;
});
