define(['knockout', 'ko.mapping', 'email'], function(ko, mapping, Email) {
    var SpamPattern = function(data) {
        'use strict';
        var self = this;

        mediator.installTo(self);
        ko.mapping = mapping;

        self.rowstatus = ko.observable('0');//0=nada;1=delete
        self.id = '';
        self.type = ko.observable('white');
        self.patternspam = ko.observable();
        self.pattern = ko.computed(function() {
            //alias para fix del backend que aveces es patternspam y otras pattern
            return self.patternspam();
        });
        self.regStatus = ko.observable(1);
        self.email = ko.observable(new Email());

        self.validationMsg = ko.observable(''); //para spammultiple
        self.types = [{
           "value": "white",
           "label": "Lista blanca"
        }, {
           "value": "black",
           "label": "Lista negra"
        }];

        self.isTemplate = false;
        ko.mapping.fromJS(data, {}, this);

        self.changeType = function(entity, event,a) {
            var value = $(event.target).val();
            self.type(value);
        };

        self.getTypeByValue = function(value) {
            for (var i in self.types) {
                if (self.types[i].value === value) {
                    return self.types[i].label;
                }
            }
            return '';
        };
    };

    SpamPattern.prototype.remove = function(entity, event) {
        var self = this;
        var emailID = null;
        try {
            emailID = self.email().id();
        } catch (e) {}
        var theData = { "params": {
            "idEmail": emailID,
            "patternspam": self.patternspam(),
            "id": self.id
        }};
        self.regStatus(4);

        var url = self.isTemplate ?
            "/hosting/email/antispamtemplate/remove"+self.type()+"list" :
            "/hosting/email/deleteantispam"+self.type()+"list";
        $.postJSON(url, theData, function(data) {
            if (data.result) {
                $.each(data.result, function() {
                });
                try {
                    FerozoHosting.antispamtemplateVM() ? FerozoHosting.antispamtemplateVM().list() : null;
                    FerozoHosting.antispamVM() ? FerozoHosting.antispamVM().nextStep() : null;
                } catch(e) {}
            }
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            FerozoHosting.antispamVM() && FerozoHosting.antispamVM().inprocess(0);
            FerozoHosting.antispamtemplateVM() && FerozoHosting.antispamtemplateVM().inprocess(0);
            data.error && self.regStatus(1);
        });
    };

    SpamPattern.prototype.save = function(entity, event) {
        var modal = $('#modal-create');
        var emailID = null;
        try {
            emailID = this.email().id();
        } catch (e) {}
        var theData = { "params": {
            "idEmail": emailID,
            "patternspam": this.patternspam(),
            "pattern": this.patternspam()
        }};

        var url = this.isTemplate ?
            "/hosting/email/antispamtemplate/add"+this.type()+"list" :
            "/hosting/email/addantispam"+this.type()+"list";
        $.postJSON(url, theData, function(data) {
            if (data.result) {
                $.each(data.result, function() {
                });
                try {
                    FerozoHosting.antispamtemplateVM() ? FerozoHosting.antispamtemplateVM().list() : null;
                    FerozoHosting.antispamVM() ? FerozoHosting.antispamVM().nextStep() : null;
                } catch(e) {}
                modal.modal('hide');
            } else if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    this.field = this.field === 'pattern' ? 'patternspam' : this.field;
                    modal.find('input[data-bind="value: '+this.field+'"]').parent().find('.help-block.error').html(this.errorDesc);
                });
                return;
            }
        }).always(function() {
            FerozoHosting.antispamVM() && FerozoHosting.antispamVM().inprocess(0);
            FerozoHosting.antispamtemplateVM() && FerozoHosting.antispamtemplateVM().inprocess(0);
        });
    };

    return SpamPattern;
});