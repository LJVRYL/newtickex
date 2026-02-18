define(['knockout', 'ko.mapping', 'domain'], function(ko, mapping, Domain) {

    var AvailableApp = function(data) {
        var self = this;
        ko.mapping = mapping;

        this.adminUrl = ko.observable();
        this.allowAutoLogin = ko.observable();
        this.allowSyncPass = ko.observable();
        this.dbRequired = ko.observable();
        this.description = ko.observable();
        this.enabled = ko.observable();
        this.id = ko.observable();
        this.domain = ko.observable(new Domain);
        this.imagePath = ko.observable();
        this.installations = ko.observableArray([]);
        this.isDotNet = ko.observable();
        this.isWellWithCurrentPHP = ko.observable();
        this.name = ko.observable();
        this.nameKey = ko.observable();
        this.opSystem = ko.observable();
        this.searchField = ko.observable();
        this.version = ko.observable();
        this.folder = ko.observable('');
        this.webappemail = ko.observable();
        this.sslType = ko.observable('');
        this.flagSsl = ko.observable(false);

        this.installed = ko.computed(function() {
            return self.installations().length;
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

    AvailableApp.prototype.getwordpressVM = function() {
        return FerozoHosting.wordpressVM() && FerozoHosting.wordpressVM();
    };

    AvailableApp.prototype.install = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "iddomain": self.domain().id,
            "folder": self.folder() || '',
            "sslType": self.domain().domain.content().indexOf('.ferozo.com') > 0 ? 'ssl' : self.sslType(),
            "webappemail": self.webappemail()
        }};
        self.getwordpressVM().installFlag(true);
        // self.getwordpreswVM().inprocess(1);
        $('.help-block.error').fadeOut(500).html('');
        $.postJSON('/hosting/webapp/installwebapp', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    this.field === 'name' && (this.field = 'folder');
                    $('input[name^="' + this.field + '"]').parent().find('.help-block.error').fadeIn(300).html(this.errorDesc);
                });
            } else {
                self.getwordpressVM().list();
                self.getwordpressVM().switchInstalledTab();
                $('.modal').modal('hide');
            }
        }).fail(function() {
        }).always(function(data) {
            self.getwordpressVM().installFlag(false);
            if (data.error && data.error.data.userException) {
                self.folder('');
                $('.modal').modal('hide');
            }
            // self.getwordpress().inprocess(0); 
        });
    };

    return AvailableApp;
});