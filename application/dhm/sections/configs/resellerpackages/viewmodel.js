define(['knockout', 'resellerpackage', 'sort'], function(ko, ResellerPackage, Sort) {
    var resellerpackagesVM = function() {
        'use strict';

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new ResellerPackage());

        this.sortByDescription = new Sort(this.data, 'description');
    };

    resellerpackagesVM.prototype.init = function() {
        this.list();
    };

    resellerpackagesVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/package/reseller/list", theData, function(data) {
            self.data([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.data.push(new ResellerPackage(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    resellerpackagesVM.prototype.openModal = function() {
        this.temp(new ResellerPackage());
        $('#modal-create').modal('show');
    };

    resellerpackagesVM.prototype.openModalEdit = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(cloned);
        $('#modal-create').modal('show');
    };
    return resellerpackagesVM;
});