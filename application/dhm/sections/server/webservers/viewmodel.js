define(['knockout', 'service'], function(ko, Service) {
    var webserversVM = function() {
        'use strict';
        this.inprocess = ko.observable(1);
        this.changeflag = ko.observable();
        this.serverType = ko.observable();
        this.serverTypeToSave = ko.observable();
    };

    webserversVM.prototype.init = function() {
        this.inprocess(1);
        this.getUserData();
        return this;
    };

    webserversVM.prototype.getUserData = function() {
        var self = this;
        $.postJSON("/dhm/account/getinfo", function(data) { 
            self.serverType(data.result.Server.WebServer);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    webserversVM.prototype.openModal = function() {
        $('#modal-change').modal('show');
    };

    webserversVM.prototype.save = function() {
        var self = this;
        if(self.serverType() == 'ols') {
            self.serverTypeToSave('apache');
            self.saveWSConfig();
        } else {
            self.serverTypeToSave('ols');
            self.saveWSConfig();
        }
    };

    webserversVM.prototype.saveWSConfig = function() {
        var self = this;
        var theData = { "params": {
            "webServer":this.serverTypeToSave(),
        }};
        $.postJSON("/dhm/serverconfig/webserver/set", theData, function(data) { 
            $('#modal-change').modal('hide');
        }).always(function(data) {
            self.getUserData();
        });
    };

    return webserversVM;
});