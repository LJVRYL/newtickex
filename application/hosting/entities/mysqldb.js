define(['knockout', 'input', 'ko.mapping'], function(ko, Input, mapping) {
    var Mysqldb = function(data) {
        var self = this;
        mediator.installTo(this);

        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.name = new ko.observable();
        this.databaseUsers = ko.observableArray([]);
        this.withUser = ko.observable(true);
        this.regStatus = ko.observable();
        this.databaseType = ko.observable(1); //igualar a mssqldb
        this.rawName = function() {
            return self.name() && self.name().match('[_](.*)')[1];
        };
        
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Mysqldb.prototype.databaseTypeName = function() {
        return 'MySQL';
    };

    Mysqldb.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $.postJSON('/hosting/database/removemysqldatabase', theData, function(e) {
            mediator.publish('databaseDeleted');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });
    };

    Mysqldb.prototype.repair = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        $('#repair-result-modal').modal('show');
        FerozoHosting.mysqlVM().repairResult.isLoaded(0);
        FerozoHosting.mysqlVM().repairResult.error('');
        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $.postJSON('/hosting/database/checkmysqldatabase', theData, function(data) {
            if (data.result) {
                FerozoHosting.mysqlVM().repairResult.load(data.result.response);
            }
            mediator.publish('databaseRefresh');
        }).always(function(e) {
            FerozoHosting.mysqlVM().repairResult.isLoaded(1);
            if (e.error && e.error.message) {
                FerozoHosting.mysqlVM().repairResult.error(e.error.message);
            }
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });
    };

    Mysqldb.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "name": self.name(),
            "withUser": self.withUser()
        }};

        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $('.help-block.error').html('');
        $.postJSON('/hosting/database/createmysqldatabase', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    $('input[name^="' + this.field + '"]').parent().find('.help-block.error').html(this.errorDesc);
                });
            } else {
                $('.modal').modal('hide');
                mediator.publish('databaseCreated');
            }
        }).always(function() {
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });
    };

    return Mysqldb;
});