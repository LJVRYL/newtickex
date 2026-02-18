define(['knockout', 'dnsrecord', 'domain', 'sort'], function(ko, DnsRecord, Domain, Sort) {
    var dnsVM = function() {
        var self = this;

        self.inprocess = ko.observable(0);
        self.data = ko.observableArray([]);
        self.domains = ko.observableArray([]);
        self.temp = ko.observable(new DnsRecord());
        self.domainSelected = ko.observable(new Domain());
        self.domainSelected.subscribe(function() {
            self.domains().length && self.data([]) && self.list();
        });

        //MX y DNSApp
        self.mxConfig = ko.observable();
        self.mxCode = ko.observable();
        self.bloggerConfig = ({
            name: ko.observable(),
            content: ko.observable(),
            appName: ko.observable()
        });

        self.comesFromDomain = ko.observable();
        self.sortByName = new Sort(self.data, 'name');
        self.sortByType = new Sort(self.data, 'type');
        self.sortByContent = new Sort(self.data, 'content');

        self.dkim = ko.observable();
    };

    dnsVM.prototype.dnsRecordsTypes = [
        'A', 'AAAA', 'CNAME', 'MX', 'NS', 'SOA', 'SRV', 'TXT'
    ];

    dnsVM.prototype.mxProviders = [
        {"value": "default", "label": "#trans-dns-mx-restore-default", "requireCode": false},
        {"value": "google", "label": "#trans-dns-mx-configure-google", "requireCode": false},
        {"value": "hotmail", "label": "#trans-dns-mx-configure-live", "requireCode": true},
        {"value": "office", "label": "#trans-dns-mx-configure-office", "requireCode": true},
        {"value": "other", "label": "#trans-dns-mx-configure-other", "requireCode": true}
    ];

    dnsVM.prototype.init = function() {
        var self = this;
        self.comesFromDomain(false);
        self.listDomains();
    };

    dnsVM.prototype.listDomains = function() {
        var self = this;
        var theData = { "params": {
        }};

        self.inprocess(1);
        return $.postJSON("/dhm/domain/parked/list", theData, function(data) {
            self.domains([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    if(obj.domain.indexOf('.ferozo.com') < 0 ){
                        if(obj.domain.indexOf('.ferozo.net') < 0){
                            self.domains.push(new Domain(obj));
                        }
                    }
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
            //el inprocess y list se hace en el domainSelected.subscribe
        });
    };

    dnsVM.prototype.list = function() {
        var self = this;

        if (! self.domainSelected()) {
            return;
        }

        var theData = { "params": {
            "domain": self.domainSelected().domain(),
            "idHosting": self.domainSelected().hosting()
        }};

        self.inprocess(1);
        return $.postJSON("/dhm/dns/list", theData, function(data) {
            self.data([]);
            self.dkim(false);
            if (data.result && data.result.Records) {
                
                ko.utils.arrayForEach(data.result.Records, function(obj) {
                    obj.domain = self.domainSelected().domain();
                    obj.idHosting = self.domainSelected().hosting();
                    self.data.push(new DnsRecord(obj));
                    if (obj.type == 'TXT' && obj.name == 'mail._domainkey.'+obj.domain) {
                        self.dkim(true);
                    }

                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };


    dnsVM.prototype.restoreDnsZone = function() {
        var self = this;
        var theData = { "params": {
            "domain": self.domainSelected().domain(),
            "idHosting": self.domainSelected().hosting()
        }};
        self.inprocess(1);
        self.data([]);
        return $.postJSON('/dhm/dns/restore', theData, function(data) {
            if (data.result) {
                self.list().success(function() {
                    $('#restore-dns-zone-modal').modal('hide');
                });
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    dnsVM.prototype.configureMX = function() {
        var self = this;
        var theData = { "params": {
            "idHosting": self.domainSelected().hosting(),
            "domain": self.domainSelected().domain(),
            "vendor": self.mxConfig(),
            "code": self.mxCode()
        }};

        ko.utils.clearObservableErrors.bind(self).apply();
        self.inprocess(1);
        return $.postJSON('/dhm/dns/record/mx/set', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException, function(obj) {
                    obj.field === 'code' && (obj.field = 'mxCode');
                    obj.field === 'vendor' && (obj.field = 'mxConfig');
                }).apply();
            }
            if (data.result) {
                self.list().success(function() {
                    $('#configure-mx').modal('hide');
                });
            }
        }).always(function(data) {
           self.inprocess(0);
        });
    };

    dnsVM.prototype.configureBlogger = function() {
        var self = this;
        var theData = { "params": {
            "idHosting": self.domainSelected().hosting(),
            "domain": self.domainSelected().domain(),
            "appName": self.bloggerConfig.appName(),
            "googleDomainName": self.bloggerConfig.name(),
            "googleDomainContent": self.bloggerConfig.content()
        }};

        self.inprocess(1);
        ko.utils.clearObservableErrors.bind(self).apply();
        ko.utils.clearObservableErrors.bind(self.bloggerConfig).apply();
        return $.postJSON('/dhm/dns/apps/google/set', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException, function(obj) {
                    obj.field === 'appName' && (obj.field = 'bloggerConfig.appName');
                    obj.field === 'googleDomainName' && (obj.field = 'bloggerConfig.name');
                    obj.field === 'googleDomainContent' && (obj.field = 'bloggerConfig.content');
                }).apply();
            }
            if (data.result) {
                self.list().success(function() {
                    $('#configure-blogger').modal('hide');
                });
            }
        }).always(function(data) {
           self.inprocess(0);
        });
    };

    dnsVM.prototype.openModal = function() {
        var self = this;
        if (! self.domainSelected()) {
            return;
        }
        self.temp(new DnsRecord());
        self.temp().domain(self.domainSelected().domain());
        self.temp().idHosting(self.domainSelected().hosting());
        $('#modal-manage').modal('show');
    };

    dnsVM.prototype.openModalEdit = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(cloned);
        $('#modal-manage').modal('show');
    };

    dnsVM.prototype.openModalRestoreDns = function() {
        $('#restore-dns-zone-modal').modal({backdrop: 'static'}, 'show');
    };

    dnsVM.prototype.openModalConfigureBlogger = function() {
        this.bloggerConfig.name(null);
        this.bloggerConfig.content(null);
        this.bloggerConfig.appName(null);
        $('#configure-blogger').modal('show');
    };

    dnsVM.prototype.openModalConfigureMX = function() {
        this.mxConfig(null);
        this.mxCode(null);
        $('#configure-mx').modal('show');
    };

    dnsVM.prototype.openModalUnSetDkim = function(){
        $('#unset-dkim-modal').modal('show');  
    };

    dnsVM.prototype.openModalSetDkim = function(){
        $('#set-dkim-modal').modal('show');  
    };

    dnsVM.prototype.confDkim = function() {
        var self = this;
        var data = { "params": {
            "domain": self.domainSelected().domain(),
            "unset": self.dkim(),
            "idHosting": self.domainSelected().hosting()
        }};
        self.inprocess(1);

        $.postJSON("/dhm/dns/dkim/set", data, function(response) {
            if (response.result) {
                var status = 0;
                try {
                    status = response.result.status;
                } catch (error) {}
                if (status == 200) {
                    $('#set-dkim-modal').modal('hide');
                    $('#unset-dkim-modal').modal('hide');
                }
            }
        }).always(function(data) {
            self.inprocess(0);
            self.list();
        });

    };

    return dnsVM;
});