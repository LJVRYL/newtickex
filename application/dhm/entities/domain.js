define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var Domain = function(data) {
        var self = this;
        this.regStatus = ko.observable(1);
        this.rowstatus = ko.observable(0);

        this.id = ko.observable(); this.idDomain = this.id;
        this.domain = ko.observable();
        this.username = ko.observable();
        this.hosting = ko.observable(); this.idHosting = this.hosting;

        this.isDefault = ko.observable();
        this.resolveDomain = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Domain.prototype.getDomainsVM = function() {
        return FerozoDhm && FerozoDhm.domainsVM();
    };

    Domain.prototype.addLets = function(entity, event) {
        var self = this;
        var theData = 
            { "params": {
                "idHosting": self.idHosting(),
                "idDomain": self.idDomain()
            }};
        self.regStatus(3);
        $.postJSON('/dhm/domain/genssl', theData, function(data) { 
        }).fail(function(data) {
        }).always(function(data) {
            FerozoDhm.domainsVM().listPaginated();
            self.regStatus(1);
        });
    };

    Domain.prototype.remove = function() {
        var self = this;
        var theData = { "params": self.toJS() };

        ko.utils.clearObservableErrors.bind(self).apply();

        self.regStatus(4);
        self.getDomainsVM().inprocess(1);
        $.postJSON('/dhm/domain/remove', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                //self.getDomainsVM().list();
            }
        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getDomainsVM().inprocess(0);
        });
    };

    Domain.prototype.save = function() {
        var self = this;
        var theData = { "params": self.toJS() };

        if (! self.hosting()) {
            return;
        }

        ko.utils.clearObservableErrors.bind(self).apply();

        self.regStatus(2);
        self.getDomainsVM().inprocess(1);
        $.postJSON('/dhm/domain/park', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                self.getDomainsVM().data.unshift(self);
                $('#modal-create').modal('hide');
            }
        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getDomainsVM().inprocess(0);
        });
    };

    Domain.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return Domain;
});