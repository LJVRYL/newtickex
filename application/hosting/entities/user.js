define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var User = function(data) {

        this.idReseller = ko.observable();
        this.idDhm = ko.observable();
        this.UserName = ko.observable();
        this.UserPrefix = ko.observable();
        this.Password = ko.observable();
        this.NewPassword = ko.observable();
        this.Contact = ko.observable();
        this.Hostings = ko.observable();
        this.Server = {
            OpSystem: ko.observable(),
            IpWan: ko.observable(),
            Name: ko.observable(),
            ShortName: ko.observable(),
            Type: ko.observable()
        };
        this.Language = {
            Name: ko.observable(),
            Id:  ko.observable(),
            AvailableLanguages: ko.observableArray([])
        };
        this.AsignedSpace = ko.observable('');
        this.AsignedSpaceHome = ko.observable('');
        this.AsignedSpaceEmail = ko.observable('');
        
        this.UsedSpaceHome = ko.observable('');
        this.UsedSpaceEmail = ko.observable('');
        
        this.UserPrefix = ko.observable('');
        this.PpalDomain = {
            "Name": ko.observable()
        };
        this.Domains = ko.observableArray([]);
        this.Emails = ko.observableArray([]);
        this.EmailsCount = ko.observable();
        this.FtpAccounts = ko.observableArray([]);
        this.WebApps = ko.observableArray([]);
        this.EmailClient = ko.observable();
        this.EmailClients = ko.observableArray([]);

        this.AllowExchange = ko.observable();
        this.HideDefaultDomain = ko.observable();
        
        this.Suspended = {
            Value: ko.observable(),
            Motives: ko.observable()
        };
        this.WhiteLabel = ko.observable();
        this.FreeDomainTlds = ko.observableArray([]);

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    User.prototype.getProfileVM = function() {
        return FerozoHosting.profileVM && FerozoHosting.profileVM();
    };

    User.prototype.save = function() {
        var self = this;
        var theData = { "params": {
            "idLanguage": self.Language.Id(),
            "contactEmail": self.Contact(),
            "newPassword": self.NewPassword(),
            "emailClient": self.EmailClient()
        }};

        self.getProfileVM().inprocess(1);
        self.getProfileVM().inprocessSettings(1);
        $.postJSON('/hosting/account/hostingsettings', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException, function(obj) {
                    obj.field === 'contactEmail' && (obj.field = 'Contact');
                    obj.field === 'newPassword' && (obj.field = 'NewPassword');
                    obj.field === 'emailClient' && (obj.field = 'EmailClient');
                }).apply();
                self.getProfileVM().inprocessSettings(0);
                return;
            }

            if (self.Language.Id() !== self.getProfileVM().user().Language.Id()) {
                window.location.reload();
                return;
            }
            self.getProfileVM().init().success(function() {
                $('#modal-edit').modal('hide');
                self.getProfileVM().inprocessSettings(0);
            });
        }).always(function() {
            self.getProfileVM().inprocess(0);
        });
    };

    /**
     * Metodos para actualizar el listado del index
     */
    User.prototype.updateEmails = function(Emails) {
        var self = this;
        self.Emails.removeAll();
        ko.utils.arrayForEach(Emails, function(data) {
            data && data.id && self.Emails.push({
                Id: data.id,
                Name: data.account.user,
                Quota: data.quota
            });
        });
    };

    User.prototype.updateDomains = function(Domains) {
        var self = this;
        self.Domains.removeAll();
        ko.utils.arrayForEach(Domains, function(data) {
            data && data.id && self.Domains.push({
                Id: data.id,
                Name: data.domain
            });
        });
    };

    User.prototype.updateFtps = function(Ftps) {
        var self = this;
        self.FtpAccounts.removeAll();
        ko.utils.arrayForEach(Ftps, function(data) {
            data && data.id && self.FtpAccounts.push({
                Id: ko.observable(data.id),
                Name: ko.observable(data.account.user)
            });
        });
    };

    User.prototype.updateWebapps = function(WebApps) {
        var self = this;
        self.WebApps.removeAll();
        ko.utils.arrayForEach(WebApps, function(data) {
            data && data.id && self.WebApps.push({
                Id: ko.observable(data.id),
                Name: ko.observable(data.webApp.name),
                Domain: ko.observable(data.domain.domain)
            });
        });
    };

    return User;
});

//
//define(['knockout', 'ko.mapping'], function(ko, mapping) {
//    var User = function(data) {
//        'use strict';
//        ko.mapping = mapping;
//        this.Language = {
//            "Id": ko.observable(),
//            "Name": ko.observable()
//        };
//
//        this.PanelLogin = {
//            "Username": ko.observable('')
//        };
//
//        this.UserName = ko.observable('');
//        this.Password = ko.observable('');
//
//        this.Server = {
//            "OpSystem": ko.observable(''),
//            "Type": ko.observable(''),
//            "IpWan": ko.observable('')
//        };
//
//        this.AsignedSpace = ko.observable('');
//        this.UserPrefix = ko.observable('');
//        this.Contact = ko.observable('');
//        this.PpalDomain = {
//            "Name": ko.observable()
//        };
//
//        this.Domains = ko.observableArray();
//        this.Emails = ko.observableArray();
//        this.FtpAccounts = ko.observableArray();
//        this.WebApps = ko.observableArray();
//        this.EmailClient = ko.observable('');
//
//        this.AllowExchange = ko.observable();
//        this.HideDefaultDomain = ko.observable();
//        ko.mapping.fromJS(data, {}, this);
//    };
//
//    User.prototype.clearErrors = function() {
//        this.error('');
//    };
//    User.prototype.updateEmails = function(Emails) {
//        this.Emails.removeAll();
//        parent=this;$.each(Emails,function(i,v) {parent.Emails.push({"Id":v.id,"Name":v.account.user,"Quota":v.quota});});
//    };
//    User.prototype.updateDomains = function(Domains) {
//        this.Domains.removeAll();
//        parent=this;$.each(Domains,function(i,v) {parent.Domains.push({"Id":v.id,"Name":v.domain});});
//    };
//    User.prototype.updateFtps = function(FtpAccounts) {
//        this.FtpAccounts.removeAll();
//        parent=this;$.each(FtpAccounts,function(i,v) {parent.FtpAccounts.push({"Id":ko.observable(v.id),"Name":ko.observable(v.account.user)});});
//    };
//    User.prototype.updateWebapps = function(Webapps) {
//        this.WebApps.removeAll();
//        parent=this;$.each(Webapps,function(i,v) {parent.WebApps.push({"Id":ko.observable(v.id),"Name":ko.observable(v.webApp.name),"Domain":ko.observable(v.domain.domain)});});
//    };
//
//    return User;
//});