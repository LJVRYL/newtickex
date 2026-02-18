define(['knockout', 'ko.mapping', 'securityconfig', 'masslogin', 'notifications'], function(ko, mapping, SecurityConfig, MassLogin, Notifications) {
    var securityVM = function() {
        'use strict';

        ko.mapping = mapping;
        this.securityConfig = ko.observable(new SecurityConfig());
        this.massLogin = ko.observable(new MassLogin());

        this.inprocess = ko.observable(0);
        this.checkedDhm = ko.observable();
        this.checkedReseller = ko.observable();
        this.savedConfig = ko.observable(false);
        this.errors = ko.observable();
        this.blockedIps = ko.observableArray([]);
        this.whiteList = ko.observableArray([]);
        this.blackList = ko.observableArray([]);
        this.flagWhite = ko.observable(false);
        this.flagBlack = ko.observable(false);
        this.ip = ko.observable();
        this.captchaEnabled = ko.observable(false);
        this.turnstileProvider = ko.observable();
        this.secretKey = ko.observable();
        this.siteKey = ko.observable();
    };

    securityVM.prototype.getUserData = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/account/getinfo", function(data) { 
            self.checkedDhm(data.result.RequirePpalDomain);
            self.checkedReseller(data.result.ResellerRequirePpalDomain);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    securityVM.prototype.getSecurityConfig = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/ipcontrol/get", function(data) { 
            self.securityConfig(new SecurityConfig(data.result.ip_login_max_attemps));
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    securityVM.prototype.getBlockedList = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/ipblock/list", function(data) { 
            self.blockedIps(data.result);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    securityVM.prototype.getMassLogin = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/ipmasslogin/get", function(data) { 
            self.massLogin(new MassLogin(data.result.ip_mass_login_protection));
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    securityVM.prototype.getWhiteBlackList = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/ipwhiteblacklist/get", function(data) { 
            self.whiteList(data.result.ip_protection_lists.whitelist);
            self.blackList(data.result.ip_protection_lists.blacklist);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    securityVM.prototype.getCaptchaConfig = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/captcha/get", function(data) { 
            if (data.result.recaptcha_provider != 'none') {
                self.captchaEnabled(true);
                self.turnstileProvider(data.result.recaptcha_provider);
                self.secretKey(data.result.recaptcha_secret_key);
                self.siteKey(data.result.recaptcha_site_key);
            } else {
                self.captchaEnabled(false);
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    securityVM.prototype.resetFlag = function() {
        var self = this;
        self.savedConfig(false);
    };

    securityVM.prototype.init = function() {
        this.getUserData();
        this.getSecurityConfig();
        this.getWhiteBlackList();
        this.getMassLogin();
        this.getBlockedList();
        this.getCaptchaConfig();
        return this;
    };

    securityVM.prototype.addIpsList = function(list) {
        var self = this;
        FerozoDhm.securityVM().ip('');
        FerozoDhm.securityVM().flagBlack(false);
        FerozoDhm.securityVM().flagWhite(false);
        if(list == 'black')
            FerozoDhm.securityVM().flagBlack(true);
        else
            FerozoDhm.securityVM().flagWhite(true);
        $('#modal-add-ip').modal('show');
    };

    securityVM.prototype.unlockIp = function(obj) {
        var self = this;
        var theData = { "params": {
            "id": obj.id,
            "ip": obj.ipAddress,
            "isBlocked": false,
            "liberationDate": obj.liberationDate
        }};
        FerozoDhm.securityVM().inprocess(1);
        $.postJSON("/dhm/serverconfig/ipblock/update", theData, function(data) { 
            if (data.result) {
                Notifications.success(data.result.successMsg);
                FerozoDhm.securityVM().getBlockedList();
            }
        }).always(function(data) {
            FerozoDhm.securityVM().inprocess(0);
        });
    };

    securityVM.prototype.listsRm = function(obj) {
        var self = this;
        var theData = { "params": {
            "ip": obj
        }};
        FerozoDhm.securityVM().inprocess(1);
        $.postJSON("/dhm/serverconfig/ipwhiteblacklist/remove", theData, function(data) { 
            if (data.result) {
                Notifications.success(data.result.successMsg);
                FerozoDhm.securityVM().getWhiteBlackList();
            }
        }).always(function(data) {
            FerozoDhm.securityVM().inprocess(0);
        });
    };

    securityVM.prototype.saveCaptchaConfig = function() {
        var self = this;
        var theData;
        if (self.captchaEnabled()) {
            theData = { "params": {
                "recaptcha_provider": self.turnstileProvider(), 
                "recaptcha_site_key": self.turnstileProvider() == 'default' ? '' : self.siteKey(),
                "recaptcha_secret_key": self.turnstileProvider() == 'default' ? '' :  self.secretKey()
            }};
        } else {
            theData = { "params": {
                "recaptcha_provider": 'none', 
                "recaptcha_site_key": '',
                "recaptcha_secret_key": ''
            }};
        }
        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/captcha/set", theData, function(data) { 
            if (data.result) {
                Notifications.success(data.result.successMsg);
                self.getCaptchaConfig();
                self.siteKey.errors(null);
                self.secretKey.errors(null);
            }
            if (data.error && data.error.data) {
                $.each(data.error.data.inputException, function() {
                    switch(this.field) {
                        case "recaptcha_site_key": {
                            self.siteKey.errors(this.errorDesc);
                        }
                        break;
                        case "recaptcha_secret_key": {
                            self.secretKey.errors(this.errorDesc);
                        }
                        break;
                    }
                });
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    securityVM.prototype.addIp = function() {
        var self = this;
        var theData = { "params": {
            "ip": self.ip()
        }};
        self.inprocess(1);
        if (FerozoDhm.securityVM().flagWhite()) {
            $.postJSON("/dhm/serverconfig/ipwhiteblacklist/setwhitelist", theData, function(data) { 
                if (data.result) {
                    FerozoDhm.securityVM().getWhiteBlackList();
                    self.ip.errors(null);
                    $('#modal-add-ip').modal('hide');
                }
                if (data.error && data.error.data) {
                    $.each(data.error.data.inputException, function() {
                        self.ip.errors(this.errorDesc);
                    });
                }
            }).always(function(data) {
                self.inprocess(0);
            });
        } else {
            $.postJSON("/dhm/serverconfig/ipwhiteblacklist/setblacklist", theData, function(data) { 
                if (data.result) {
                    FerozoDhm.securityVM().getWhiteBlackList();
                    self.ip.errors(null);
                    $('#modal-add-ip').modal('hide');
                }
                if (data.error && data.error.data) {
                    $.each(data.error.data.inputException, function() {
                        self.ip.errors(this.errorDesc);
                    });
                }
            }).always(function(data) {
                self.inprocess(0);
            });
        }
    };



    return securityVM;
});