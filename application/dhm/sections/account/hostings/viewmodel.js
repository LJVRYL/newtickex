define(['knockout', 'hosting', 'sort', 'package', 'reseller', 'fzPaginatorAjax', 'whilecallback', 'translate'], function(ko, Hosting, Sort, Package, Reseller, fzPaginatorAjax, WhileCallback, Translate) {
    var hostingsVM = function() {
        'use strict';
        
        var self = this;
        this.inprocess = ko.observable(0); 
        this.data = ko.observableArray([]);
        this.packages = ko.observableArray([]);
        this.editionPackages = ko.observableArray([]);
        this.resellers = ko.observableArray([]);
        this.resellersFilter = ko.observableArray([]);
        this.temp = ko.observable(new Hosting());
        this.tempCreation = ko.observable(new Hosting());
        this.packlist = ko.observableArray([]);
        this.checkedDhm = ko.observable();
        this.checkedReseller = ko.observable();
        this.password = ko.observable();
        this.sortByCreated = new Sort(this.data, 'created');
        this.sortByUser = new Sort(this.data, 'user');
        this.sortByReseller = new Sort(this.data, 'resellerUsername');
        this.isQuotaActive = ko.observable();
        

        this.listPaginated = function() {
            this.inprocess(0);
            var theData = { filter: {
                username: self.pagination.query(),
                status: self.pagination.selectedSuspendedStatus(),
                idReseller: self.pagination.selectedReseller()
            }};
            return self.pagination.ajaxViewModelListing(this, Hosting, "/dhm/account/hosting/list", theData, this.paginationPushCallback.bind(this));
        };
        this.pagination = new fzPaginatorAjax(self.listPaginated.bind(this));
        this.pagination.selectedReseller = ko.observable(0);
        this.pagination.selectedSuspendedStatus = ko.observable(0);
    };

    hostingsVM.prototype.paginationPushCallback = function(obj) {
        var self = this;
        var hosting = new Hosting(obj);
        //hosting.basePackage(new Package(obj));
        self.data.push(hosting);
    };

    hostingsVM.prototype.labelStrings = {
        customResellerName: Translate('#trans-custom'),
        customResellerId: null,
        all: Translate('#trans-all-resellers'),
        none: Translate('#trans-none')
    };

    hostingsVM.prototype.suspensionFilterOptions = [
        {label: Translate('#trans-all-states'), value: 0},
        {label: Translate('#trans-active'),     value: 'active'},
        {label: Translate('#trans-suspended'),  value: 'suspended'}
    ];

    hostingsVM.prototype.suspensionMotivesList = [
        {label: Translate('#trans-administrative'),         value: 1},
        {label: 'Spam',                                     value: 2},
        {label: Translate('#trans-resource-consumption'),   value: 3},
        {label: Translate('#trans-illegals'),               value: 4},
        {label: Translate('#trans-cleaning'),               value: 5},
        {label: Translate('#trans-mass-mailing'),           value: 6},
        {label: Translate('#trans-vulnerable-form'),        value: 7}
    ];

    hostingsVM.prototype.servicesToFixList = [
        {label: 'FTP',                              value: 'ftp'},
        {label: 'Virtual Hosts',                    value: 'vhost'},
        {label: Translate('#trans-permissions'),    value: 'perms'}
    ];

    hostingsVM.prototype.init = function() {
        var self = this;

        self.listPaginated();
        self.listPackages();
        self.listpacks();
        self.getUserData();
        new WhileCallback(function() {
            return FerozoDhm.profileVM().isDhm();
        }, function() {
            FerozoDhm.profileVM().isDhm() && self.listResellers();
        });
    };

    hostingsVM.prototype.listResellers = function() {
        var self = this;
        var theData = { "params": {
        }};

        //self.inprocess(1);
        $.postJSON("/dhm/account/reseller/list", theData, function(data) {
            self.resellers([]);
            self.resellersFilter([]);

            self.resellers.push(new Reseller({id: 0, username: self.labelStrings.none}));
            self.resellersFilter.push(new Reseller({id: 0, username: self.labelStrings.all}));
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.resellers.push(new Reseller(obj));
                    self.resellersFilter.push(new Reseller(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            //self.inprocess(0);
        });
    };

    hostingsVM.prototype.listPackages = function() {
        var self = this;
        var theData = { "params": {

        }};

        //self.inprocess(1);
        self.packages([]);
        $.postJSON("/dhm/package/list", theData, function(data) {
            self.packages([]);
            self.editionPackages([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.packages.push(new Package(obj));
                    self.editionPackages()[obj.id]=new Package(obj);
                });
            }
            //self.packages.push(new Package({id: self.labelStrings.customResellerId, description: self.labelStrings.customResellerName}));
        }).fail(function(data) {
        }).always(function(data) {
            //self.inprocess(0);
        });
    };

    hostingsVM.prototype.listpacks = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/server/packlist", theData, function(data) {
            self.packlist([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.packlist()[obj.id] = obj.name;
                });
            }
            
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });

    };

    hostingsVM.prototype.list = function() {
        this.inprocess(0);
        return this.listPaginated();
        //var self = this;
        //var theData = { "params": {
        //
        //}};
        //
        //self.inprocess(1);
        //$.postJSON("/dhm/account/hosting/list", theData, function(data) {
        //    self.data([]);
        //    if (data.result) {
        //        ko.utils.arrayForEach(data.result, function(obj) {
        //            var hosting = new Hosting(obj);
        //            hosting.basePackage(new Package(obj));
        //            self.data.push(hosting);
        //        });
        //    }
        //}).fail(function(data) {
        //}).always(function(data) {
        //    self.inprocess(0);
        //});
    };

    hostingsVM.prototype.openModal = function() {
        $('#modal-create').modal('show');
        this.tempCreation(new Hosting());
    };

    hostingsVM.prototype.openModalChangePassword = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(cloned.password(''));
        $('#modal-password').modal('show');
    };

    hostingsVM.prototype.openModalFixServices = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(cloned);
        $('#modal-fix').modal('show');
    };

    hostingsVM.prototype.openModalSuspend = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        cloned.suspensionMotives = ko.observableArray(cloned.suspensionMotives());
        this.temp(cloned);
        this.temp().suspensionMotives.valueHasMutated();
        $('#modal-suspend').modal('show');
    };

    hostingsVM.prototype.openModalEditPackage = function(entity, event) {
        var self = this;
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        self.temp(new Hosting(cloned));
        self.temp().selectedEditionPackage(self.packages()[0]);
        //console.log(self.packages()[0]);
        // self.temp().basePackage().description(self.labelStrings.customResellerName);
        // self.temp().basePackage().id(self.labelStrings.customResellerId);


        // var lastPkg = self.editionPackages().slice(0).pop();
        // if (lastPkg && lastPkg.description && lastPkg.description() === self.labelStrings.customResellerName) {
        //    self.editionPackages.pop();
        // }

        // self.editionPackages.push(self.temp().basePackage());
        // self.temp().selectedEditionPackage(self.temp().basePackage());

        $('#modal-package').modal('show');
    };

    hostingsVM.prototype.openModalRename = function(entity, event) {
        var self = this;
        var cloned = ko.mapping.fromJS(ko.toJS(entity));

        self.temp(new Hosting(cloned));

        $('#modal-rename').modal('show');
    };

    hostingsVM.prototype.openModalAlias = function(entity, event) {
        var self = this;
        var cloned = ko.mapping.fromJS(ko.toJS(entity));

        self.temp(new Hosting(cloned));

        $('#modal-alias').modal('show');
    };

    hostingsVM.prototype.getUserData = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/account/getinfo", function(data) { 
            self.checkedDhm(data.result.RequirePpalDomain);
            self.checkedReseller(data.result.ResellerRequirePpalDomain);
            self.isQuotaActive(data.result.IsQuotaActive);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    hostingsVM.prototype.genPass = function() {
        this.password($.passGen({'length' : 10, 'numeric' : true, 'lowercase' : true, 'uppercase' : true, 'special' : true}) );
    };

    return hostingsVM;
});