define(['knockout', 'service', 'sort'], function(ko, Service, Sort) {
    var servicesVM = function() {
        'use strict';

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);

        this.sortByName = new Sort(this.data, 'name');
        this.sortByStatus = new Sort(this.data, 'status');
    };

    servicesVM.prototype.init = function() {
        this.list();
        return this;
    };

    servicesVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/services/status", theData, function(data) {
            self.data([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.data.push(new Service(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    return servicesVM;
});