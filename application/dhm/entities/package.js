define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var Package = function(data) {
        var self = this;
        var maxDefault = -1;

        this.regStatus = ko.observable(1);
        this.rowstatus = ko.observable();

        this.id = ko.observable();
        this.description = ko.observable();
        this.active = ko.observable();
        this.reseller = ko.observable();
        this.asignedSpace = ko.observable(maxDefault);
        this.maxFtpAccounts = ko.observable(maxDefault);
        this.maxEmailAccounts = ko.observable(maxDefault);
        this.maxDatabases = ko.observable(maxDefault);
        this.maxDomains = ko.observable(maxDefault);
        this.maxSubdomains = ko.observable(maxDefault);
        this.maxTransfer = ko.observable(maxDefault);
        this.maxHostings = ko.observable(maxDefault);
        this.maxExchangeAccounts = ko.observable(maxDefault);
        this.featuresPack = ko.observable(1);
        this.ssl = ko.observable(false);

        this.shell = ko.observable(false);

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Package.prototype.getPackagesVM = function() {
        return FerozoDhm && FerozoDhm.packagesVM();
    };

    Package.prototype.parseViewProperty = function(value, isBool) {
        if (isBool) {
            return value ? '<i class="icon-new fa-check"></i>' : '<i class="icon-new fa-close"></i>';
        }
        return value == -1 ? '<big>&infin;</big>' : value || 0;
    };

    Package.prototype.save = function() {
        var self = this;
        var theData = { "params": self.toJS() };
        var url = self.id() ? '/dhm/package/edit' : '/dhm/package/create';

        ko.utils.clearObservableErrors.bind(self).apply();
        self.getPackagesVM().inprocess(1);
        $.postJSON(url, theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha creado correctamente el paquete');
                self.getPackagesVM().list();
                $('#modal-create').modal('hide');
            }
        }).fail(function(data) {
        }).always(function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
                data.error.data.userException && Notifications.error(data.error.data.userException.value);
            }
            self.getPackagesVM().inprocess(0);
        });
    };

    Package.prototype.remove = function() {
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        $.postJSON("/dhm/package/remove", theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha eliminado correctamente el paquete');
                self.getPackagesVM().list();
                $('#modal-create').modal('hide');
            }

        }).fail(function(data) {
        }).always(function(data) {

            /*if (data.error) {
                if (data.error.data && data.error.data.userException) {
                    Notifications.error(data.error.data.userException.value);
                }
            }*/

            data.error && self.regStatus(1);
            self.getPackagesVM().inprocess(0);
        });
    };

    Package.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return Package;
});