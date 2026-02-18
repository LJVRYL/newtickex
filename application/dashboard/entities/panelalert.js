define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var PanelAlert = function(data) {
        var self = this;

        this.regStatus = ko.observable(1);
        this.rowstatus = ko.observable();

        this.id = ko.observable();
        this.message = ko.observable();
        this.content = ko.observable();
        this.logs = ko.observable();
        this.command = ko.observable();
        this.requestId = ko.observable();
        this.status = ko.observable();
        this.notes = ko.observable();
        this.auditCreation = ko.observable();
        this.panelAlertUser = ko.observable();
        this.panelAlertUserName = ko.observable();

        this.shell = ko.observable(false);

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    PanelAlert.prototype.edit = function() {
        var self = this;
       
        var theData = { "params": {
            "id": ko.utils.unwrapObservable(self.id),
            "status": self.status(),
            "idUser": self.panelAlertUser(),
            "notes": self.notes()
        }};

        ko.utils.clearObservableErrors.bind(self).apply();
        return $.postJSON('/dashboard/panelalert/edit', theData, function(data) {
            if (data.result) {
                if(FerozoDashboard.panelalertsVM() != undefined) {
                    FerozoDashboard.panelalertsVM().init();
                }
                $("#alertinfo").hide();
                $("#alertlist").show();
                $("#edit").modal('hide');
            }
        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
        });
    };
    
    PanelAlert.prototype.remove = function() {
        var self = this;
       
        var theData = { "params": {
            "id": ko.utils.unwrapObservable(self.id),
            "status": self.status(),
            "idUser": self.panelAlertUser(),
            "notes": self.notes()
        }};

        ko.utils.clearObservableErrors.bind(self).apply();
        return $.postJSON('/dashboard/panelalert/remove', theData, function(data) {
            if (data.result) {
//                if(FerozoDashboard.panelalertsVM() != undefined) {
//                    FerozoDashboard.panelalertsVM().init();
//                }
            }
        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
        });
    };
    
//    PanelAlert.prototype.getPackagesVM = function() {
//        return FerozoDashboard && FerozoDashboard.packagesVM();
//    };

//    PanelAlert.prototype.parseViewProperty = function(value, isBool) {
//        if (isBool) {
//            return value ? '<i class="icon-new fa-check"></i>' : '<i class="icon-new fa-close"></i>';
//        }
//        return value == -1 ? '<big>&infin;</big>' : value || 0;
//    };

//    PanelAlert.prototype.save = function() {
//        var self = this;
//        var theData = { "params": self.toJS() };
//        var url = self.id() ? '/dhm/package/edit' : '/dhm/package/create';
//
//        ko.utils.clearObservableErrors.bind(self).apply();
//        self.getPackagesVM().inprocess(1);
//        $.postJSON(url, theData, function(data) {
//            if (data.result) {
//                Notifications.success('Se ha creado correctamente el paquete');
//                self.getPackagesVM().list();
//                $('#modal-create').modal('hide');
//            }
//        }).fail(function(data) {
//        }).always(function(data) {
//            if (data.error && data.error.data) {
//                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
//                data.error.data.userException && Notifications.error(data.error.data.userException.value);
//            }
//            self.getPackagesVM().inprocess(0);
//        });
//    };

//    PanelAlert.prototype.remove = function() {
//        var self = this;
//        var theData = { "params": {
//            "id": self.id()
//        }};
//
//        self.regStatus(4);
//        $.postJSON("/dhm/package/remove", theData, function(data) {
//            if (data.result) {
//                Notifications.success('Se ha eliminado correctamente el paquete');
//                self.getPackagesVM().list();
//                $('#modal-create').modal('hide');
//            }
//
//        }).fail(function(data) {
//        }).always(function(data) {
//
//            if (data.error) {
//                if (data.error.data && data.error.data.userException) {
//                    Notifications.error(data.error.data.userException.value);
//                }
//            }
//
//            data.error && self.regStatus(1);
//            self.getPackagesVM().inprocess(0);
//        });
//    };

    PanelAlert.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return PanelAlert;
});