define(['knockout', 'ko.mapping', 'notifications', 'input', 'package'], function(ko, mapping, Notifications, Input, Package) {
    var Hosting = function(data) {
        var self = this;
        self.regStatus = ko.observable(1);
        self.rowstatus = ko.observable();

        self.account = ko.observable();
        self.allowExchange = ko.observable();
        self.allowImpersonation = ko.observable();
        self.alternativeDomain = ko.observable();
        self.antiSpamConfig = ko.observable();
        self.asignedSpace = ko.observable();
        self.configKeys = ko.observable();
        self.contact = ko.observable();
        self.created = ko.observable();
        self.creationServer = ko.observable();
        self.description = ko.observable();
        self.dotNetVersion = ko.observable();
        self.fwkMode = ko.observable();
        self.hideDefaultDomain = ko.observable();
        self.homedir = ko.observable();
        self.id = ko.observable(); self.idHosting = self.id;
        self.idCustomer = ko.observable();
        self.language = ko.observable();
        self.lockPanelMotive = ko.observable();
        self.mainPanelLogin = ko.observable();
        //self.basePackage = ko.observable(new Package());
        self.basePackage = ko.observable();
        self.selectedEditionPackage = ko.observable(new Package());
        //self.selectedEditionPackage.subscribe(function(basePackage) {
            //related to @hostingsVM.prototype.openModalEditPackage
            //if (basePackage) {
            //    var cloned = ko.mapping.fromJS(ko.toJS(basePackage));
            //    self.selectedEditionPackage(new Package(cloned));
            //}
        //});
        self.ppalDomain = ko.observable(); self.domain = self.ppalDomain;
        self.phpVersion = ko.observable();
        self.regStatus = ko.observable();
        self.registerGlobal = ko.observable();
        self.reseller = ko.observable(); ; self.idReseller = self.reseller;
        self.suspended = ko.observable();
        self.suspensionMotives = ko.observableArray([]);
        self.themes = ko.observable();
        self.usedQuota = ko.observable();
        self.user = ko.observable(); self.username = self.user;
        self.password = ko.observable();
        self.alias = ko.observable();
        self.webFolderConfig = ko.observable();
        self.whiteLabel = ko.observable();
        self.whiteLabelImage = ko.observable();
        self.resellerUsername = ko.observable();
        self.mainDomainName = ko.observable();
        self.saveAsNewPackage = ko.observable();
        self.newPackageName = ko.observable();
        self.servicesToFix = ko.observableArray([]);
        self.newdomain = ko.observable();

        self.sslCert = ko.observable();

        self.creationSuccess = ko.observable(false);
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, self);

    };

    Hosting.prototype.getHostingsVM = function() {
        return window.FerozoDhm && FerozoDhm.hostingsVM();
    };

    Hosting.prototype.updateParamsByPackage = function() {
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

    Hosting.prototype.getImpersonationLink = function() {
        return '/dhm/impersonate?_switch_user=' + this.user();
    };

    Hosting.prototype.impersonate = function() {
        var self = this;

        return !self.suspended() && (window.location.href = self.getImpersonationLink());
    };

    Hosting.prototype.fixServices = function() {
        var self = this;

        if (! self.servicesToFix().length) {
            return;
        }

        var theData = { "params": {
            "id": self.id(),
            "idHosting": self.id(),
            "servicesToFix": self.servicesToFix()
        }};

        self.getHostingsVM().inprocess(1);
        $.postJSON("/dhm/account/hosting/services/fix", theData, function(data) {
            if (data.result) {
                Notifications.success('Se comenzara la correccion de los servicios indicados');
                $('#modal-fix').modal('hide');

            } else if (data.error.data && data.error.data.inputException) {
            }

        }).fail(function(data) {
        }).always(function(data) {
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.getSelfDataFromAPI = function() {
        var self = this;

        var theData = { "params": {
            "id": self.id(),
            "idHosting": self.id(),
            "username": self.user()
        }};

        self.getHostingsVM().inprocess(1);
        return $.postJSON("/dhm/account/hosting/get", theData, function(data) {
            ko.mapping.fromJS(data.result, {}, self);
        }).fail(function(data) {
        }).always(function(data) {
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.save = function() {
        var self = this;
        self.updateParamsByPackage();
        var theData = { "params": self.toJS() };

        ko.utils.clearObservableErrors.bind(self).apply();
        self.getHostingsVM().inprocess(1);
        $.postJSON("/dhm/account/hosting/create", theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                Notifications.success('Se ha creado correctamente la cuenta de hosting');
                var plainPassword = self.password();
                self.getHostingsVM().list().success(function() {
                    //para que se actualize el alt.domain
                    self.getSelfDataFromAPI(function() {
                        self.password(plainPassword);
                    });
                });
                self.creationSuccess(true);
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.sendConfirmationEmail = function() {
        var self = this;
        self.updateParamsByPackage();
        var theData = { "params": self.toJS() };

        ko.utils.clearObservableErrors.bind(self).apply();
        self.getHostingsVM().inprocess(1);
        $.postJSON("/dhm/account/hosting/confirmation-email/send", theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                $('#modal-create').modal('hide');
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.remove = function() {
        var self = this;
        var theData = { "params": {
            "idHosting": self.id()
        }};

        self.regStatus(4);
        $.postJSON("/dhm/account/hosting/remove", theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha eliminado correctamente la cuenta de hosting');
                self.getHostingsVM().list();
                $('#modal-create').modal('hide');
            }

        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.changePassword = function() {
        var self = this;
        var theData = { "params": {
            "idHosting": self.id(),
            "newPassword": self.password()
        }};

        ko.utils.clearObservableErrors.bind(self).apply();
        self.regStatus(3);
        self.getHostingsVM().inprocess(1);
        $.postJSON("/dhm/account/hosting/changepwd", theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha modificado correctamente la contraseña de la cuenta de hosting');
                self.getHostingsVM().list();
                $('#modal-password').modal('hide');
            }
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException, function(obj) {
                    obj.field === 'newPassword' && (obj.field = 'password');
                }).apply();
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.regStatus(1);
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.suspend = function(unsuspend) {
        var self = this;
        var url = unsuspend ? '/dhm/account/hosting/unsuspend' : '/dhm/account/hosting/suspend';
        var theData = { "params": {
            "idHosting": self.id(),
            "motives": self.suspensionMotives(),
            "idReseller": self.idReseller()
        }};

        self.regStatus(3);
        self.getHostingsVM().inprocess(1);
        $.postJSON(url, theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha modificado correctamente la cuenta de hosting');
                self.getHostingsVM().list();
                $('#modal-suspend').modal('hide');
            }

        }).fail(function(data) {
        }).always(function(data) {
            self.regStatus(1);
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.editReseller = function(callback) {
        var self = this;

        if (! FerozoDhm.profileVM().isDhm()) {
            return typeof callback === 'function' && callback();
        }

        var theData = { "params": self.toJS() };
        self.getHostingsVM().inprocess(1);
        $.postJSON("/dhm/account/hosting/owner/change", theData, function(data) {
            typeof callback === 'function' && callback(data);
        }).fail(function(data) {
        }).always(function(data) {
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.editPackage = function() {
        var self = this;
        self.updateParamsByPackage();
        var theData = { "params": self.toJS() };

        ko.utils.clearObservableErrors.bind(self).apply();
        self.getHostingsVM().inprocess(1);
        $.postJSON("/dhm/account/hosting/limits/change", theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                self.editReseller(function() {
                    Notifications.success('Se ha modificado correctamente la cuenta de hosting');
                    self.getHostingsVM().list();
                    $('#modal-package').modal('hide');
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.getHostingsVM().inprocess(0);
        });
    };

    Hosting.prototype.renameMainDomain = function () {
        var self = this;
        var theData = { "params": {
            "idHosting" : self.id(),
            "idMainDomain" : self.ppalDomain(),
            "sNewDomain" : self.newdomain(),
        }};

        $.postJSON("/dhm/domain/renamemaindomain", theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                $('#modal-rename').modal('hide');
            }
        });
    };

    Hosting.prototype.renameReference = function () {
        var self = this;
        var theData = { "params": {
            "idHosting" : self.id(),
            "alias" : self.alias()
        }};

        $.postJSON("/dhm/account/hosting/alias/set", theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                Notifications.success('Se ha modificado correctamente la cuenta de hosting');
                self.getHostingsVM().list();
                $('#modal-alias').modal('hide');
            }
        });
    };

    Hosting.prototype.isAliasUrl = function() {
        var self = this;
        var urlPattern = /^[a-z0-9\-]{0,63}[a-z0-9]{1}(\.[a-z0-9\-]{2,63}){1,3}$/;
        return urlPattern.test(self.alias());
    };


    Hosting.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        //delete obj.basePackage;
        delete obj.selectedEditionPackage;
        return obj;
    };

    return Hosting;
});