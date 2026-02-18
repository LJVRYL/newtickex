define(['knockout', 'ko.mapping', 'notifications', 'resellerpackage'], function(ko, mapping, Notifications, ResellerPackage) {
    var Reseller = function(data) {
        var self = this;
        self.regStatus = ko.observable(1);
        self.rowstatus = ko.observable();

        self.id = ko.observable(); self.idReseller = self.id;
        self.account = ko.observable();
        self.user = ko.observable(); self.username = self.description = self.user;
        self.password = ko.observable();

        self.basePackage = ko.observable();

        self.saveAsNewPackage = ko.observable();
        self.newPackageName = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, self);
    };

    Reseller.prototype.getResellersVM = function() {
        return window.FerozoDhm && FerozoDhm.resellersVM();
    };

    Reseller.prototype.updateParamsByPackage = function() {
        //Copia desde la entidad de package los params para enviarlos por post
        if (! this.basePackage()) {
            Notifications.error('No se pudo identificar el paquete');
            return;
        }

        var pkgs = this.basePackage();
        for (var i in pkgs) {
            if (typeof i === 'string' && i.match('^(max|shell|asignedSpace)')) {
                this[i] = pkgs[i];
            }
        }
    };

    Reseller.prototype.save = function() {
        var self = this;
        var url = self.id() ? '/dhm/account/reseller/edit' : '/dhm/account/reseller/create';
        self.updateParamsByPackage();
        var theData = { "params": self.toJS() };

        ko.utils.clearObservableErrors.bind(self).apply();
        self.getResellersVM().inprocess(1);
        $.postJSON(url, theData, function(data) {
            if (data.result) {
                self.id() ?
                    Notifications.success('Se ha editado correctamente la cuenta de reseller') :
                    Notifications.success('Se ha creado correctamente la cuenta de reseller');
                self.getResellersVM().list();
                $('#modal-create').modal('hide');
                $('#modal-edit').modal('hide');

            }
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.getResellersVM().inprocess(0);
        });
    };

    Reseller.prototype.remove = function() {
        var self = this;
        var theData = { "params": {
            "idReseller": self.id(),
            "id": self.id()
        }};

        self.regStatus(4);
        $.postJSON("/dhm/account/reseller/remove", theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha eliminado correctamente la cuenta de reseller');
                self.getResellersVM().list();
            }

        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getResellersVM().inprocess(0);
        });
    };

    Reseller.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    Reseller.prototype.genPass = function() {
        this.password($.passGen({'length' : 10, 'numeric' : true, 'lowercase' : true, 'uppercase' : true, 'special' : true}) );
    };

    return Reseller;
});