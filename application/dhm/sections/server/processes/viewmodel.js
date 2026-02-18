define(['knockout', 'process', 'sort'], function(ko, Process, Sort) {
    var processesVM = function() {
        'use strict';

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);

        this.sortByPid = new Sort(this.data, 'pid', true);
        this.sortByCommand = new Sort(this.data, 'command');
        this.sortByUser = new Sort(this.data, 'user');
    };

    processesVM.prototype.init = function() {
        this.list();
    };

    processesVM.prototype.refresh = function() {
        this.data([]);
        this.list();
    };

    processesVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/processes/status", theData, function(data) {
            self.data([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.data.push(new Process(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    return processesVM;
});