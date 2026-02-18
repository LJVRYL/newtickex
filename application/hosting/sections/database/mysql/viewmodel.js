define(['knockout', 'domain', 'mysqldb', 'mysqldbuser', 'ko.mapping', 'data', 'mysqldbhosts'], function(ko, Domain, Mysqldb, Mysqldbuser, mapping, Data, Mysqldbhosts) {

    var mysqlVM = function() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";

        this.users = ko.observableArray([]);
        this.data = ko.observableArray([]);
        this.databases = ko.observableArray([]);
        this.hostsql = ko.observableArray([]);
        this.userToAssign = ko.observable();
        this.errorUserToAssign = ko.observable();
        this.repairResult = new Data();
        this.infoDbAppInstallation = ko.observable({"domain":""});
        this.inprocess = ko.observable(0);
        this.inprocessAssignUser = ko.observable(0);
        this.currentDbs = ko.observable('');
        this.serviceId = ko.observable();
        this.maxDbs = ko.observable('');

        this.temp = ko.observable(new Mysqldb());
        this.tempuser = ko.observable(new Mysqldbuser());
        this.temphost = ko.observable(new Mysqldbhosts());
        this.sortDirection = ko.observable('asc');

        this.setInprocess =  ko.computed({
            read: function () {
                return false;
            },
            write: function(action) {
                if (action == "+") {
                    self.inprocess(self.inprocess()+1);
                } else {
                    self.inprocess(self.inprocess()-1);
                }
            }
        },this);

        this.sortDatabases = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.databases.sort(function(left, right) {
                    return left.name() == right.name() ? 0 : (left.name() < right.name() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.databases.sort(function(left, right) {
                    return left.name() == right.name() ? 0 : (left.name() > right.name() ? -1 : 1);
                });
            }
        };
        this.sortUsers = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.users.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() < right.account.user() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.users.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() > right.account.user() ? -1 : 1);
                });
            }
        };

        this.subscribe('refreshMySqlDb', function() {
            var self = this;
            self.setInprocess('+');
            $.postJSON("/hosting/database/listmysqldatabases", function(data) {
                if (data.result) {
                    var mappingRules = {
                        create: function(options) {
                            return new Mysqldb(options.data);
                        }, key: function(item) {
                            return ko.utils.unwrapObservable(item.id);
                        }
                    };
                    ko.mapping.fromJS(data.result, mappingRules, self.databases);

                    //Workaround para actualizar el estado del usuario a asignar/desasignar en el modal
                    try {
                        if (self.temp && self.temp() && self.temp().id) {
                            $.each(self.databases(), function() {
                                if (this.id() === self.temp().id()) {
                                    self.temp(this);
                                }
                            });
                        }
                    } catch(error) {
                    }
                }
            }).always(function() {
                self.setInprocess('-');
            });
        });

        this.subscribe('refreshMySqlUsers', function() {
            var self = this;
            self.setInprocess('+');
            $.postJSON("/hosting/database/listmysqldatabasesaccounts", function(data) {
                if (data.result) {
                    self.users([]);
                    $.each(data.result, function() {
                        self.users.push(new Mysqldbuser(this));
                    });
                }
                self.inprocessAssignUser(0);
            }).always(function() {
                self.setInprocess('-');
            });
        });

        this.subscribe('refreshMySqlHosts', function() {
            var self = this;
            self.setInprocess('+');
            $.postJSON("/hosting/database/getremotehost", function(data) {
                if (data.result) {
                    self.hostsql([]);
                    $.each(data.result, function() {
                        self.hostsql.push(new Mysqldbhosts(this));
                    });
                }
                self.inprocessAssignUser(0);
            }).always(function() {
                self.setInprocess('-');
            });
        });


        this.subscribe('databaseCreated', function() {
            var self = this;
            self.init();
        });

        this.init = function() {
            'use strict';
            self.inprocess(0);
            self.databases([]);
            mediator.publish('refreshMySqlDb');
            mediator.publish('refreshMySqlUsers');
            mediator.publish('refreshMySqlHosts');
            self.quotaCheck();
        };

        this.quotaCheck = function() {
            self= this;
            self.setInprocess("+")
            $.postJSON("/hosting/account/getinfo", function(data) {
                self.serviceId(data.result.idService);
                self.currentDbs(data.result.Limites.databases.usado);
                self.maxDbs(data.result.Limites.databases.total);
            }).always(function() {
                self.setInprocess("-");
            });
        };

        this.redirectMcSpace = function(entity, event) {
            var self = this;
            window.open('https://micuenta.donweb.com/xx-xx/servicios/sitios/'+FerozoHosting.mysqlVM().serviceId()+'/configurar/cambio-servicio', '_blank');
        };

        this.assignUser = function() {
            'use strict';

            var theData = { "params": {
                "idDbAccount": self.userToAssign().id(),
                "idDatabase": self.temp().id()
            }};

            self.userToAssign().regStatus(2);
            self.inprocessAssignUser(1);
            self.temp().databaseUsers.push(self.userToAssign());
            $('.help-block.error').html('');
            self.errorUserToAssign("");
            $.postJSON('/hosting/database/setmysqldatabaseuser', theData, function(response) {
                if (response.error && response.error.data.inputException[0]) {
                    var error = response.error.data.inputException[0];
                    $('input[name^="' + error.field + '"]').parent().find('.help-block.error').html(error.errorDesc);
                    self.errorUserToAssign(error.errorDesc);
                    self.temp().databaseUsers.remove(self.userToAssign());
                }
                mediator.publish('refreshMySqlDb');
            }).fail(function() {
                self.temp().databaseUsers.remove(self.userToAssign());
            }).always(function() {
                self.inprocessAssignUser(0);
            });
        };

        this.unasignUser = function(entity, event) {
            'use strict';
            var theData = { "params": {
                "idDbUser": entity.id()
            }};

            entity.regStatus(4);
            self.inprocess(1);
            $.postJSON('/hosting/database/unsetmysqldatabaseuser', theData, function(data) {
                mediator.publish('refreshMySqlDb');
                if (data.result && data.result.status == 200) {
                    self.temp().databaseUsers.remove(entity);
                }
            }).fail(function() {
                entity.regStatus(1);
            }).always(function(data) {
                data.error && entity.regStatus(1);
                self.inprocess(0);
            });

        };

    };

    mysqlVM.prototype.adminuser = function(obj, evento) {
        'use strict';
        FerozoHosting.mysqlVM().temp(obj);
    };

    mysqlVM.prototype.openCreateUserModal = function(obj) {
        this.tempuser(new Mysqldbuser());
        $('#nuevo-mysqldbuser').modal('show');
    };

    mysqlVM.prototype.openEditPass = function(obj) {
        'use strict';
        FerozoHosting.mysqlVM().tempuser(obj);
        $('#edit-mysqldbuser').modal('show');
    };

    mysqlVM.prototype.showUserInfo = function(obj) {
        'use strict';
        FerozoHosting.mysqlVM().tempuser(obj);
        $('#info-usermysql').modal('show');
    };

    mysqlVM.prototype.showconect = function(obj, evento) {
        'use strict';
        FerozoHosting.mysqlVM().temp(obj);
    };

    mysqlVM.prototype.showPackAmigoInfo = function(obj) {
        'use strict';
        FerozoHosting.mysqlVM().infoDbAppInstallation(obj.webAppInstallation);
        $('#info-packAmigo-modal').modal('show');
    };

    mysqlVM.prototype.goInstalledApps = function() {
        'use strict';
         $('#info-packAmigo-modal').modal('hide');
         window.location.href="#/wordpressandapps";
    };

    mysqlVM.prototype.nuevamysqldb = function() {
        'use strict';
        FerozoHosting.mysqlVM().temp(new Mysqldb());
        $('#nueva-mysqldb').modal('show');
    };

    mysqlVM.prototype.newdb = function() {
        $("#nueva-mysqldb").modal();
    };

    mysqlVM.prototype.isWin = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
        } else {
            return false;
        }        
    };
    
    mysqlVM.prototype.getPrefixName = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().UserPrefix().split('@')[0]+'_';
        } else {
            return '';
        }
    };

    mysqlVM.prototype.openAppendHostModal = function(obj) {
        this.temphost(new Mysqldbhosts());
        $('#nuevo-remotehosts').modal('show');
    };
    
    return mysqlVM;
});