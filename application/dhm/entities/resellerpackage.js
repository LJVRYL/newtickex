define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var ResellerPackage = function(data) {
        var self = this;
        var maxDefault = -1;

        this.regStatus = ko.observable(1);
        this.rowstatus = ko.observable();

        this.id = ko.observable();
        this.description = ko.observable();
        this.active = ko.observable();
        this.reseller = ko.observable();
        this.maxEmailAccounts = ko.observable(maxDefault);
        this.maxTransfer = ko.observable(maxDefault);
        this.maxHostings = ko.observable(maxDefault);

        this.shell = ko.observable(false);

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    ResellerPackage.prototype.getPackagesVM = function() {
        return FerozoDhm && FerozoDhm.resellerpackagesVM();
    };

    ResellerPackage.prototype.parseViewProperty = function(value, isBool) {
        if (isBool) {
            return value ? '<i class="icon-new fa-check"></i>' : '<i class="icon-new fa-close"></i>';
        }
        return value == -1 ? '<big>&infin;</big>' : value || 0;
    };

    ResellerPackage.prototype.save = function() {
        var self = this;
        var theData = { "params": self.toJS() };
        var url = self.id() ? '/dhm/package/reseller/edit' : '/dhm/package/reseller/create';

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
            }
            self.getPackagesVM().inprocess(0);
        });
    };

    ResellerPackage.prototype.remove = function() {
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        $.postJSON("/dhm/package/reseller/remove", theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha eliminado correctamente el paquete');
                self.getPackagesVM().list();
                $('#modal-create').modal('hide');
            }
        }).fail(function(data) {
        }).always(function(data) {
            if (data.error) {
                if (data.error.data && data.error.data.userException) {
                    Notifications.error(data.error.data.userException.value);
                }
            }
            data.error && self.regStatus(1);
            self.getPackagesVM().inprocess(0);
        });
    };

    ResellerPackage.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return ResellerPackage;
});