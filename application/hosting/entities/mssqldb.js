define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var Mssqldb = function(data) {
        var self = this;
        mediator.installTo(this);

        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable();
        this.name = ko.observable('');
        this.databaseType = ko.observable(6);

        this.databaseUsers = ko.observableArray([]);
        this.withUser = ko.observable(true);
        this.regStatus = ko.observable();
        this.rawName = function() {
            return self.name() && self.name().match('[_](.*)')[1];
        };

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Mssqldb.prototype.databaseTypeNames = [
        {"value": 6, "label": "Microsoft SQL Server 2016"},
        {"value": 5, "label": "Microsoft SQL Server 2012"},
        {"value": 4, "label": "Microsoft SQL Server 2008"},
        {"value": 3, "label": "Microsoft SQL Server 2005"},
        {"value": 2, "label": "Microsoft SQL Server 2000"}
    ];

    Mssqldb.prototype.newDatabaseTypeNames = [
        {"value": 6, "label": "Microsoft SQL Server 2016"},
        {"value": 5, "label": "Microsoft SQL Server 2012"}
    ];
    
    Mssqldb.prototype.databaseTypeName = function() {
        for (var type in this.databaseTypeNames) {
            if (this.databaseTypeNames[type].value == this.databaseType()) {
                return this.databaseTypeNames[type].label;
            }
        }
    };

    Mssqldb.prototype.remove = function() {
        'use strict';
        var self = this;
        var request = { "params": {
            "id": self.id(),
            "idDatabase": self.id()
        }};

        self.regStatus(4);
        $.postJSON('hosting/database/mssql/remove', request, function(data) {
            mediator.publish('listMssqlDbs');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(0);
        });
    };

    Mssqldb.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "databaseName": self.name(),
            "withUser": self.withUser(),
            "idDatabaseType": self.databaseType()
        }};

        $('.help-block.error').html('');
        FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(1);
        FerozoHosting.mssqlVM().selectedFilter(null)
        $.postJSON('/hosting/database/mssql/create', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    this.field = this.field === 'databaseName' ? 'name' : this.field;
                    this.field = this.field === 'idDatabaseType' ? 'databaseType' : this.field;
                    $('input[name^="' + this.field + '"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                });
            } else {
                mediator.publish('listMssqlDbs');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.mssqlVM() && FerozoHosting.mssqlVM().inprocess(0);
        });
    };

    return Mssqldb;
});