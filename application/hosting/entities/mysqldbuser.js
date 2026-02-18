define(['knockout', 'ko.mapping', 'input'], function(ko, mapping, Input) {
    var Mysqldbuser = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable('');
        this.dbUser = ko.observable();
        this.user = ko.observable('');
        this.account = ko.observable('');
        this.idDbAccount = ko.observable('');
        this.dbPass = new ko.observable();
        this.databaseName = ko.observable('');
        this.idDatabase = ko.observable('');

        ko.mapping.fromJS(data, {}, this);
    };

    Mysqldbuser.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDbbAccount": self.id()
        }};

        self.regStatus(4);
        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $.postJSON('/hosting/database/removemysqldatabaseuser', theData, function(e) {
            mediator.publish('databaseUserDeleted');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });
    };

    Mysqldbuser.prototype.saveEditPass = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idAccount": self.id(),
            "dbPass": self.dbPass()
        }};

        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $('.help-block.error').html('');
        $.postJSON('/hosting/database/changemysqldatabaseuserpass', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    $('input[name^="' + this.field + '"]').parent().find('.help-block.error').html(this.errorDesc);
                });
            } else {
                mediator.publish('refreshMySqlDb');
                mediator.publish('refreshMySqlUsers');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });
    };

    Mysqldbuser.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "dbUser": self.dbUser(),
            "dbPass": self.dbPass(),
            "idDatabase": self.idDatabase()
        }};

        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $('.help-block.error').html('');
        $.postJSON('/hosting/database/createmysqldatabaseuser', theData, function(response) {
            $.each(theData.params,function(i,v) {
                if (typeof self[i] !== 'undefined' && typeof self[i].clearErrors === 'function') {
                    self[i].clearErrors();
                };
            });
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    $('input[name^="' + this.field + '"]').parent().find('.help-block.error').html(this.errorDesc);
                });
            } else {
                mediator.publish('refreshMySqlDb');
                mediator.publish('refreshMySqlUsers');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });;
    };

    Mysqldbuser.prototype.setmysqldatabaseuser = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDbAccount": self.id(),
            "idDatabase": self.idDatabase()
        }};

        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $.postJSON('/hosting/database/setmysqldatabaseuser', theData, function(e) {
            mediator.publish('refreshMySqlUsers');
            $('.modal').modal('hide');
        }).always(function() {
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });;
    };

    return Mysqldbuser;
});