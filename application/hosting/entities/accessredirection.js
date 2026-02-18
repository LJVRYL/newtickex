define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var AccessRedirection = function(data) {
        'use strict';
        var self = this;

        mediator.installTo(self);
        ko.mapping = mapping;

        self.id = ko.observable();

        self.path = ko.observable('');
        self.redirection = ko.observable('');
        self.type = ko.observable();
        self.secure = ko.observable();
        self.isFile = ko.observable();

        self.redirectUrlDisplay = function() {
            return (self.secure() ? 'https://' : 'http://') + self.redirection();
        };

        self.pathDisplay = function() {
            var path;
            if (FerozoHosting.profileVM().user().HideDefaultDomain()) {
                var domains = FerozoHosting.profileVM().user().Domains()
                path = (domains[0] ? 'http://' + domains[0].Name() : '');
            } else {
                path = 'http://' + FerozoHosting.profileVM().user().PpalDomain.Name();
            }
            return path + '/' + self.path();;
        };

        self.redirectTypesArray = [{
            "value": "permanent",
            "label": $('#trans-permanent').html() || "Permanente"
        }, {
            "value": "temporal",
            "label": $('#trans-temporal').html() || "Temporal"
        }];

        self.secureModeArray = [{
            "value": false,
            "label": "http://"
        }, {
            "value": true,
            "label": "https://"
        }];

        self.redirectToArray = [{
            "value": false,
            "label": $('#trans-domain').html() || "Dominio / Directorio"
        }, {
            "value": true,
            "label": $('#trans-file').html() || "Archivo"
        }];

        self.regStatus = ko.observable(1);
        self.rowstatus = ko.observable('0');//0=nada;1=delete

        ko.mapping.fromJS(data, {}, this);
    };

    AccessRedirection.prototype.getAccessRedirectionsVM = function() {
        return window.FerozoHosting.accessredirectionsVM();
    };

    AccessRedirection.prototype.remove = function(entity, event) {
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "idAccessRedirection": self.id()
        }};
        self.regStatus(4);
        self.getAccessRedirectionsVM().inprocess(1);
        $.postJSON('/hosting/tools/access/redirection/remove', theData, function(data) {
            if (data.error && data.error.data.inputException) {
            } else if (data.result) {
                //self.getAccessRedirectionsVM().list();
            }
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getAccessRedirectionsVM().inprocess(0);
        });
    };

    AccessRedirection.prototype.save = function() {
        var self = this;
        var theData = { "params": {
            "path": self.path(),
            "redirectUrl": self.redirection(),
            "redirectType": self.type(),
            "secure": self.secure(),
            "isFile": self.isFile() && self.isFile() !== 'false'
        }};

        self.getAccessRedirectionsVM().inprocess(1);
        $.postJSON('/hosting/tools/access/redirection/configure', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $('.help-block.error').html('');
                $.each(data.error.data.inputException, function() {
                    this.field = this.field === 'redirectUrl' ? 'redirection' : this.field;
                    this.field = this.field === 'redirectType' ? 'type' : this.field;
                    $('input[name^="' + this.field + '"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                });
            } else if (data.result) {
                mediator.publish('refreshAccessRedirections');
                $('#modal-create').modal('hide');
            }
        }).always(function() {
            //self.regStatus(1);
            self.getAccessRedirectionsVM().inprocess(0);
        });
    };


    return AccessRedirection;
});