define(['knockout', 'ko.mapping', 'input'], function(ko, mapping, Input) {
    var Mysqldbhosts = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');
        this.id = ko.observable('');
        this.idHosting = ko.observable();
        this.remoteHost = ko.observable('');
        this.regStatus = ko.observable();

        this.optionhost = ko.observable('all');

        ko.mapping.fromJS(data, {}, this);
    };

    Mysqldbhosts.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idremotehost": self.id()
        }};

        self.regStatus(4);
        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $.postJSON('/hosting/database/removeremotehost', theData, function(e) {
            mediator.publish('refreshMySqlHosts');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            //data.error && self.regStatus(1);
            self.regStatus(1);
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });
    };

    Mysqldbhosts.prototype.save = function() {
        var newhost;

        if(this.optionhost() == "all") {
            newhost="%";
        }else{
            newhost=this.remoteHost();
        }
        
        var theData = { "params": {
            "optionhost": this.optionhost(),
            "remotehost": newhost
        }};

        FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(1);
        $.postJSON('/hosting/database/addremotehost', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    $('input[name^="' + this.field + '"]').parent().find('.help-block.error').html(this.errorDesc);
                });
            } else {
                mediator.publish('refreshMySqlHosts');
                $('.modal').modal('hide');
            }

        }).always(function() {
            FerozoHosting.mysqlVM() && FerozoHosting.mysqlVM().inprocess(0);
        });


    };

    return Mysqldbhosts;
});