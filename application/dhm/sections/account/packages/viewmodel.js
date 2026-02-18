define(['knockout', 'package', 'features','sort'], function(ko, Package, Features, Sort) {
    var packagesVM = function() {
        'use strict';

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new Package());
        this.packlist = ko.observableArray([]);

        this.sortByDescription = new Sort(this.data, 'description');
    };

    packagesVM.prototype.init = function() {
        this.list();
        this.listpacks();
    };

    packagesVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/package/list", theData, function(data) {
            self.data([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.data.push(new Package(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    packagesVM.prototype.listpacks = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/server/packlist", theData, function(data) {
            self.packlist([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.packlist.push(new Features(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });

    };

    packagesVM.prototype.openModal = function() {
        this.temp(new Package());
        $('#modal-create').modal('show');
    };

    packagesVM.prototype.openModalEdit = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(cloned);
        $('#modal-create').modal('show');
    };

    return packagesVM;
});