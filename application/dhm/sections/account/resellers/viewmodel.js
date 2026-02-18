define(['knockout', 'resellerpackage', 'reseller', 'sort'], function(ko, ResellerPackage, Reseller, Sort) {
    var resellersVM = function() {

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.packages = ko.observableArray([]);
        this.editionPackages = ko.observableArray([]);
        this.tempCreation = ko.observable(new Reseller());
        this.temp = ko.observable(new Reseller());

        this.sortByUsername = new Sort(this.data, 'user');
    };

    resellersVM.prototype.init = function() {
        this.list();
        this.listPackages();
    };

    resellersVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/account/reseller/list", theData, function(data) {
            self.data([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.data.push(new Reseller(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    resellersVM.prototype.listPackages = function() {
        var self = this;
        var theData = { "params": {

        }};

        //self.inprocess(1);
        $.postJSON("/dhm/package/reseller/list", theData, function(data) {
            self.packages([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.packages.push(new ResellerPackage(obj));
                    self.editionPackages()[obj.id]=new ResellerPackage(obj);
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            //self.inprocess(0);
        });
    };

    resellersVM.prototype.openModal = function() {
        this.tempCreation(new Reseller());
        $('#modal-create').modal('show');
    };

    resellersVM.prototype.openModalEdit = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(new Reseller(cloned));
        this.temp().basePackage(new ResellerPackage(cloned));
        $('#modal-edit').modal('show');
    };

    return resellersVM;
});