define(['knockout', 'domain', 'mssqldb', 'mssqldbuser', 'ko.mapping', 'data'], function(ko, Domain, Mssqldb, Mssqldbuser, mapping, Data) {

    var mssqlVM = function() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;
        self.title = "";

        self.users = ko.observableArray([]);
        self.data = ko.observableArray([]);
        self.userToAssign = ko.observable();
        self.errorUserToAssign = ko.observable();

        self.infoDbAppInstallation = ko.observable({"domain":""});
        self.inprocess = ko.observable(0);
        self.inprocessAssignUser = ko.observable(0);

        self.temp = ko.observable(new Mssqldb());
        self.tempAvailableUsers = ko.computed(function() {
            return self.users().filter(function(e) {
                return e.databaseType && e.databaseType() === self.temp().databaseType();
            });
        });

        self.filters = ko.observableArray([
            {key: 'Todas', value: null},
            {key: 'Microsoft SQL Server 2016', value: 6},
            {key: 'Microsoft SQL Server 2012', value: 5},
            {key: 'Microsoft SQL Server 2008', value: 4},
            {key: 'Microsoft SQL Server 2005', value: 3},
            {key: 'Microsoft SQL Server 2000', value: 2}
        ]);
        self.selectedFilter = ko.observable(self.filters()[0]);
        self.dataByFilter = ko.computed(function() {
            var result = [];
            if (! self.selectedFilter()) {
                return self.data;
            }
            ko.utils.arrayForEach(self.data(), function(obj) {
                if (obj.databaseType() == self.selectedFilter()) {
                    result.push(obj);
                }
            });
            return result;
        });

        self.tempuser = ko.observable(new Mssqldbuser());
        self.sortDirection = ko.observable('asc');

        self.dbTypeDefault = 6;

        self.sortDatabases = function() {
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.name() == right.name() ? 0 : (left.name() < right.name() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.name() == right.name() ? 0 : (left.name() > right.name() ? -1 : 1);
                });
            }
        };

        self.sortUsers = function() {
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

        self.subscribe('listMssqlDbs', function() {
            return self.list();
        });

        self.subscribe('listMssqlUsers', function() {
            return self.listUsers();
        });

        self.subscribe('initMssqlVM', function() {
            return self.init();
        });

        self.init = function() {
            mediator.publish('listMssqlDbs');
            mediator.publish('listMssqlUsers');
        };

        self.assignUser = function() {
            var theData = { "params": {
                "idDatabaseAccount": self.userToAssign().id(),
                "idDatabaseTypeVersion": self.userToAssign().databaseType(),
                "idDatabase": self.temp().id()
            }};

            self.userToAssign().regStatus(2);
            self.errorUserToAssign("");
            self.inprocessAssignUser(1);
            self.temp().databaseUsers.push(self.userToAssign());
            $('.help-block.error').html('');
            $.postJSON('/hosting/database/mssql/users/set', theData, function(response) {
                if (response.error && response.error.data.inputException[0]) {
                    var error = response.error.data.inputException[0];
                    $('input[name^="' + error.field + '"]').parent().find('.help-block.error').html(error.errorDesc);
                    self.errorUserToAssign(error.errorDesc);
                    self.temp().databaseUsers.remove(self.userToAssign());
                }
                mediator.publish('listMssqlDbs');
            }).fail(function() {
                self.temp().databaseUsers.remove(self.userToAssign());
            }).always(function() {
                self.inprocessAssignUser(0);
            });
        };

        self.unasignUser = function(entity, event) {
            'use strict';
            var theData = { "params": {
                "idDatabaseUser": entity.id(),
                "idDatabase": self.temp().id()
            }};

            entity.regStatus(4);
            self.inprocess(1);
            $('.help-block.error').html('');
            $.postJSON('/hosting/database/mssql/users/unset', theData, function(data) {
                mediator.publish('listMssqlDbs');
                if (data.result && data.result.status == 200) {
                    self.temp().databaseUsers.remove(entity);
                }
                self.temp().databaseUsers.remove(entity);
            }).fail(function() {
                entity.regStatus(1);
            }).always(function(data) {
                data.error && entity.regStatus(1);
                self.inprocess(0);
            });
        };
    };

    mssqlVM.prototype.list = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/database/mssql/list", function(data) {
            if (data.result) {
                self.data([]);
                $.each(data.result, function() {
                    self.data.push(new Mssqldb(this));
                });

                //Workaround para actualizar el estado del usuario a asignar/desasignar en el modal
                try {
                    if (self.temp && self.temp() && self.temp().id) {
                        $.each(self.data(), function() {
                            if (this.id() === self.temp().id()) {
                                self.temp(this);
                            }
                        });
                    }
                } catch(error) {
                }
            }
        }).always(function() {
            self.inprocess(0);
        });
    };

    mssqlVM.prototype.listUsers = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/database/mssql/users/list", function(data) {
            if (data.result) {
                self.users([]);
                $.each(data.result, function() {
                    self.users.push(new Mssqldbuser(this));
                });
            }
        }).always(function() {
            self.inprocess(0);
            self.inprocessAssignUser(0);
        });
    };

    mssqlVM.prototype.adminuser = function(entity, event) {
        FerozoHosting.mssqlVM().temp(entity);
    };

    mssqlVM.prototype.openEditPass = function(entity) {
        FerozoHosting.mssqlVM().tempuser(entity);
        $('#edit-mysqldbuser').modal('show');
    };

    mssqlVM.prototype.showUserInfo = function(entity) {
        FerozoHosting.mssqlVM().tempuser(entity);
        $('#info-usermysql').modal('show');
    };

    mssqlVM.prototype.showconect = function(entity, event) {
        FerozoHosting.mssqlVM().temp(entity);
    };

    mssqlVM.prototype.showPackAmigoInfo = function(entity) {
        FerozoHosting.mssqlVM().infoDbAppInstallation(entity.webAppInstallation);
        $('#info-packAmigo-modal').modal('show');
    };

    mssqlVM.prototype.goInstalledApps = function() {
        $('#info-packAmigo-modal').modal('hide');
        window.location.href="#/wordpressandapps";
    };

    mssqlVM.prototype.createModal = function() {
        FerozoHosting.mssqlVM().temp(new Mssqldb());
        $('#nueva-mysqldb').modal('show');
    };

    mssqlVM.prototype.showCreateUserModal = function() {
        FerozoHosting.mssqlVM().tempuser(new Mssqldbuser());
        $('#nuevo-mysqldbuser').modal('show');
    };

    mssqlVM.prototype.newdb = function() {
        $("#nueva-mysqldb").modal();
    };

    mssqlVM.prototype.getPrefixName = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().UserPrefix().split('@')[0]+'_';
        } else {
            return '';
        }
    };
    
    return mssqlVM;
});