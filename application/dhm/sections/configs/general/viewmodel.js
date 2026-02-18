define(['knockout', 'ko.mapping', 'securityconfig', 'notifications'], function(ko, mapping, SecurityConfig, Notifications) {
    var generalVM = function() {
        'use strict';

        ko.mapping = mapping;
        this.securityConfig = ko.observable(new SecurityConfig());

        this.inprocess = ko.observable(0);
        this.checkedDhm = ko.observable();
        this.checkedReseller = ko.observable();
        this.savedConfig = ko.observable(false);
        this.errors = ko.observable();
        this.blockedIps = ko.observableArray([]);

    };

    generalVM.prototype.getUserData = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/account/getinfo", function(data) { 
            self.checkedDhm(data.result.RequirePpalDomain);
            self.checkedReseller(data.result.ResellerRequirePpalDomain);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    generalVM.prototype.getSecurityConfig = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/ipcontrol/get", function(data) { 
            self.securityConfig(new SecurityConfig(data.result.ip_login_max_attemps));
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    generalVM.prototype.getBlockedList = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/ipblock/list", function(data) { 
            self.blockedIps(data.result);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    generalVM.prototype.saveConfig = function() {
        var self = this;
        var theData = { "params": {
            "requirePpalDomain":this.checkedDhm(),
            "resellerRequirePpalDomain":this.checkedReseller()
        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/requireppaldomain/set", theData, function(data) { 
            self.savedConfig(data.result);
        }).always(function(data) {
            self.getUserData();
            self.inprocess(0);
        });

    };

    generalVM.prototype.resetFlag = function() {
        var self = this;
        self.savedConfig(false);
    };

    generalVM.prototype.init = function() {
        this.getUserData();
        this.getSecurityConfig();
        return this;
    };

    generalVM.prototype.blockedIpsList = function() {
        this.getBlockedList();
        $('#blocked-ips').modal('show');
    };

    generalVM.prototype.unlockIp = function(obj) {
        var self = this;
        var theData = { "params": {
            "id": obj.id,
            "ip": obj.ipAddress,
            "isBlocked": false,
            "liberationDate": obj.liberationDate
        }};
        FerozoDhm.generalVM().inprocess(1);
        $.postJSON("/dhm/serverconfig/ipblock/update", theData, function(data) { 
            if (data.result) {
                Notifications.success(data.result.successMsg);
                FerozoDhm.generalVM().getBlockedList();
            }
        }).always(function(data) {
            FerozoDhm.generalVM().inprocess(0);
        });
    };

    return generalVM;
});