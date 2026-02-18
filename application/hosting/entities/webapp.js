define(['knockout', 'ko.mapping', 'domain', 'availableapp'], function(ko, mapping, Domain, AvailableApp) {

    var WebApp = function(data) {
        var self = this;
        ko.mapping = mapping;

        /* installed */
        this.command = ko.observable();
        this.database = ko.observable();
        this.domain = ko.observable(new Domain);
        this.folder = ko.observable();
        this.hosting = ko.observable();
        this.id = ko.observable();
        this.regStatus = ko.observable();
        this.rowstatus = ko.observable();
        this.webApp = ko.observable(new AvailableApp);
        this.visible = ko.observable(true);
        this.password = ko.observable();
        this.loginUser = ko.observable('');
        this.sslType = ko.observable('');
        this.flagSsl = ko.observable(false);
        
        this.folderPath = ko.computed(function() {
            return '/public_html/' + (self.folder() ? self.folder() : '');
        });

        ko.mapping.fromJS(data, {}, this);

        this.domain.subscribe(function(newValue) {
            if (newValue) {
                if (newValue.domain.content().indexOf('.ferozo.com') > 0) {
                    self.flagSsl(false)
                } else {
                    self.flagSsl(true);
                } 
            }       
        });
    };

    WebApp.prototype.sslTypes = [
        {"value": "", "label": "#trans-ssl-no"},
        {"value": "ssl", "label": "#trans-ssl-yes"}
        //{"value": "sslwww", "label": "#trans-ssl-www"}
    ];    
    
    WebApp.prototype.getwordpressVM = function() {
        return FerozoHosting.wordpressVM() && FerozoHosting.wordpressVM();
    };

    WebApp.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        $.postJSON('/hosting/webapp/uninstallwebapp', theData, function(data) {
        }).fail(function() {
        }).always(function(data) {
            self.getwordpressVM().inprocess(0);
            data.error && self.regStatus(1);
        });
    };

    WebApp.prototype.editDomain = function() {
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "idDomain": self.domain().id,
            "sslType": self.sslType()
        }};
        self.regStatus(3);
        self.getwordpressVM().inprocess(1);
        $.postJSON('/hosting/webapp/changedomain', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                });
            } else {
                $('.modal').modal('hide');
            }
        }).fail(function() {
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getwordpressVM().inprocess(0);
            if (data.error && data.error.data.userException) {
                $('.modal').modal('hide');
            }
        });
    };

    WebApp.prototype.resetPass = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "password": self.password()
        }};

        self.regStatus(3);
        self.getwordpressVM().inprocess(1);
        $.postJSON('/hosting/webapp/syncpassword', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            } else {
                $('.modal').modal('hide');
            }
        }).fail(function() {
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getwordpressVM().inprocess(0);
        });
    };

    return WebApp;
});