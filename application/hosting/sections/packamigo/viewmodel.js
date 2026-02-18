define(['knockout', 'availableapp', 'webapp', 'domain', 'email', 'translate', 'sort'], function(ko, AvailableApp, WebApp, Domain, Email, Translate,Sort) {

    var packamigoVM = function() {
        var self = this;
        mediator.installTo(this);
        this.inprocess = ko.observable(1);
        this.availableApps = ko.observableArray([]);
        this.data = ko.observableArray([]);
        this.emails = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.temp = ko.observable(new AvailableApp());
        this.tempEdit = ko.observable(new WebApp());
        this.sslTypes = [
            {"value": "", "label": "#trans-ssl-no"},
            {"value": "ssl", "label": "#trans-ssl-yes"}
            //{"value": "sslwww", "label": "#trans-ssl-www"}
        ];            
        this.readyView =  ko.computed(function(){
            if (this.inprocess() >= 1  ) return "LOADING";
            if (this.inprocess() < 1 && this.domains().length <= 0  ) return "WO-DOMAINS";
            if (this.inprocess() < 1 && this.domains().length > 0  ) return "W-DOMAINS";
        },this);
        this.setInprocess =  ko.computed({
            read: function () {
                return false;
            },
            write: function(action) {
                if (action == "+") {
                    self.inprocess(self.inprocess()+1);
                } else {
                    if(self.inprocess() <= 0 ) {
                        self.inprocess(0);
                    } else {
                         self.inprocess(self.inprocess()-1);
                    }
                }
            }
        },this);
        this.resetType = ko.observable(1);
        this.resetTypeList= [
            {label: Translate('#trans-select-cambiar'), value: 0},
            {label: Translate('#trans-select-regenerar'), value: 1}
        ]

        /** FILTRO DE TABLA POR JAVASCRIPT **/
        this.search = function(value) {
            value = typeof value === 'string' && value.trim() || '';
            var regex = new RegExp(value);
            ko.utils.arrayForEach(self.data(), function(obj) {
                obj.visible(false);
                if (obj.webApp().searchField().match(regex)) {
                    obj.visible(true);
                }
            });
        };
        this.query = ko.observable('');
        this.query.subscribe(this.search);
        /** /FILTRO DE TABLA POR JAVASCRIPT **/
        
        this.subscribe('emailListUpdated', function(emails) {
            'use strict';
            var self = this;
            var mapping = {
                create: function(options) {
                    return new Email(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(emails, mapping, self.emails);
        });        
    };

    packamigoVM.prototype.init = function() {
        var self = this;
        this.list();
        this.listAvailableApps();
        this.listDomains();
        mediator.publish('refreshEmailList');
        self.setInprocess("-");
    };

    packamigoVM.prototype.listAvailableApps = function() {
        var self = this;
        var theData = { "params": {
        }};

        self.setInprocess("+");
        $.postJSON("/hosting/webapp/listwebapps", theData, function(data) {
            if (data.result) {
                self.availableApps([]);
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.availableApps.push(new AvailableApp(obj));
                });
            }
        }).always(function() {
            self.setInprocess("-");
        });
    };

    packamigoVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {
        }};

        self.setInprocess("+");
        $.postJSON("/hosting/webapp/listinstalled", theData, function(data) {
            if (data.result) {
                self.data([]);
                ko.utils.arrayForEach(data.result, function(obj) {
                    var prot = 'http://';
                    if (obj.sslType !== '') {
                        prot = 'https://';
                    }
                    obj.domainDisplay = prot + obj.domain.domain;
                    self.data.push(new WebApp(obj));
                });
            }
        }).always(function() {
            self.setInprocess("-");
        });
    };

    packamigoVM.prototype.listDomains = function() {
        var self = this;
        var theData = { "params": {

        }};
        self.setInprocess("+");
        $.postJSON("/hosting/domain/listdomains", theData, function(data) {
            self.domains([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    obj.regStatus === 1 && self.domains.push(new Domain(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
           self.setInprocess("-");
        });
    };

    packamigoVM.prototype.switchInstalledTab = function(entity, event) {
        $('#app-installed').trigger('click');
    };

    packamigoVM.prototype.switchInstalled = function(entity, event) {
        var self = this;
        self.switchInstalledTab();
        self.query(entity.nameKey());
    };

    packamigoVM.prototype.openModalInstall = function(entity, event) {
        this.temp(entity);
        $('#modal-info').modal('hide');
        $('#installapp').modal('show');
    };

    packamigoVM.prototype.openModalEditPass = function(entity, event) {
        this.tempEdit(entity);
        this.tempEdit().password('');
        $('#editpass').modal('show');
    };

    packamigoVM.prototype.openModalEdit = function(entity, event) {
        //var cloned = ko.mapping.fromJS(ko.toJS(entity));
        //cloned.domain = ko.observable(cloned.domain);
        this.tempEdit(entity);
        $('#editapp').modal('show');
    };

    packamigoVM.prototype.openModalInfo = function(entity, event) {
        this.temp(entity);
        $('#modal-info').modal('show');
    };

    return packamigoVM;
});
