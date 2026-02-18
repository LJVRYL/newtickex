define(['knockout', 'ko.mapping', 'input'], function(ko, mapping, Input) {
    var configVM = function(data) {
        'use strict';
        var self = this;
        ko.mapping = mapping;
        
        self.inprocess = ko.observable(0);
        self.savedConfig = ko.observable(false);
        self.errors = ko.observable();

        self.hostname = new Input();
        self.serverIp = new Input();
        self.nsPrimary = new Input();
        self.nsPrimaryIp = new Input();
        self.nsSecondary = new Input();
        self.nsSecondaryIp = new Input();
        var mappingRules = {
            'hostname': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'serverIp': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'nsPrimary': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'nsPrimaryIp': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'nsSecondary': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'nsSecondaryIp': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },     
        };
        ko.mapping.fromJS(data, mappingRules, this);
        

    };

    configVM.prototype.saveConfig = function() {
        var self = this;
        self.clearErrors();
        var theData2 = { "params": {
            "hostname":self.hostname.content(),
            "serverIp":self.serverIp.content(),
            "nsPrimary":self.nsPrimary.content(),
            "nsPrimaryIp":self.nsPrimaryIp.content(),
            "nsSecondary":self.nsSecondary.content(),
            "nsSecondaryIp":self.nsSecondaryIp.content()
        }};

        self.inprocess(1);
        $.postJSON("/dhm/setup/editparams", theData2, function(data) { 

            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                    self.inprocess(0);
                });
            } else {
                self.savedConfig(data.result);
            };

        }).always(function(data) {
            self.inprocess(0);
        });
    };

    configVM.prototype.getSetupParams = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/setup/listparams", function(data) { 
            self.hostname.content(data.result.hostname);
            self.serverIp.content(data.result.serverIp);
            self.nsPrimary.content(data.result.nsPrimary);
            self.nsPrimaryIp.content(data.result.nsPrimaryIp); 
            self.nsSecondary.content(data.result.nsSecondary);
            self.nsSecondaryIp.content(data.result.nsSecondaryIp);  
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    configVM.prototype.clearErrors = function() {
        var self = this;

        self.hostname.clearErrors();
        self.serverIp.clearErrors();
        self.nsPrimary.clearErrors();
        self.nsPrimaryIp.clearErrors();
        self.nsSecondary.clearErrors();
        self.nsSecondaryIp.clearErrors();
    }

    configVM.prototype.resetFlag = function() {
        this.savedConfig(false);
    };

    configVM.prototype.init = function() {
        this.getSetupParams();
        document.location.hash = '#/server/config';
        FerozoDhm.activeSection('config');
        return this;
    };

    return configVM;
});