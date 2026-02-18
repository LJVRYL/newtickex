define(['knockout', 'ko.mapping', 'mssqldb'], function(ko, mapping, Mssqldb) {
    var Mssqldbuser = function(data) {
        'use strict';

        mediator.installTo(this);

        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable();
        this.dbUser = ko.observable('');
        this.user = ko.observable('');
        this.account = ko.observable('');
        this.idDbAccount = ko.observable('');
        this.dbPass = ko.observable('');
        this.databaseName = ko.observable('');
        this.idDatabase = ko.observable('');
        this.databaseType = ko.observable(6);

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Mssqldbuser.prototype.databaseTypeNames = Mssqldb.prototype.databaseTypeNames;
    Mssqldbuser.prototype.databaseTypeName = Mssqldb.prototype.databaseTypeName;
    Mssqldbuser.prototype.newDatabaseTypeNames = Mssqldb.prototype.newDatabaseTypeNames;

    Mssqldbuser.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDbbAccount": self.id(),
            "idDatabaseAccount": self.id()
        }};

        self.regStatus(4);
        FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(1);
        $.postJSON('hosting/database/mssql/users/remove', theData, function(data) {
            mediator.publish('databaseUserDeleted');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(0);
        });
    };

    Mssqldbuser.prototype.saveEditPass = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idAccount": self.id(),
            "dbPass": self.dbPass()
        }};

        FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(1);
        $.postJSON('/hosting/database/changemysqldatabaseuserpass', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    if (self[this.field] && self[this.field].error) {
                        //Esto anda si se usa la propiedad de la entity como Input()
                        self[this.field].error(this.errorDesc);
                    } else {
                        throw new Error(this.errorDesc);
                    }
                });
                FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(0);
            } else {
                mediator.publish('listMssqlDbs');
                mediator.publish('listMssqlUsers');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(0);
        });
    };

    Mssqldbuser.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "dbUser": self.dbUser(),
            "dbPass": self.dbPass(),
            "idSqlVersion": self.databaseType(),
            "idDatabase": self.idDatabase()
        }};

        FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(1);
        $.postJSON('/hosting/database/mssql/users/create', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    //self[this.field].error(this.errorDesc);
                    $('input[name^="' + this.field + '"]').parent().find('.help-block.error').html(this.errorDesc);
                });
            } else {
                mediator.publish('listMssqlDbs');
                mediator.publish('listMssqlUsers');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(0);
        });
    };

    Mssqldbuser.prototype.setmysqldatabaseuser = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDbAccount": self.id(),
            "idDatabase": self.idDatabase()
        }};

        FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(1);
        $.postJSON('/hosting/database/mssql/users/set', theData, function(e) {
            mediator.publish('listMssqlUsers');
            $('.modal').modal('hide');
        }).always(function() {
            FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(0);
        });
    };

    return Mssqldbuser;
});