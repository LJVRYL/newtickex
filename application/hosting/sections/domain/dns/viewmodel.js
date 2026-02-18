define(['knockout', 'domain', 'dnsmx', 'dnsapp', 'dnsrecord'], function(ko, Domain, DnsMx, DnsApp, Dnsrecord) {
    /**
     * Esto funciona un poco diferente que las demas vistas ya que la seccion DNS
     * No trabaja con entities y regstatus en el backend (pero si de forma asincronica con comandos)
     */
    var DnsVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        this.inprocess = ko.observable(1);
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
        this.readyView =  ko.computed(function(){
            if (this.inprocess() >= 1  ) return "LOADING";
            if (this.inprocess() < 1 && this.domains().length <= 0  ) return "WO-DOMAINS";
            if (this.inprocess() < 1 && this.domains().length > 0  ) return "W-DOMAINS";
            return "LOADING";
        },this);
        this.domains = ko.observableArray([]);
        this.data = ko.observableArray([]);
        this.dnsRecordsTypes = ko.observableArray(['A', 'AAAA', 'CNAME', 'MX', 'NS', 'SOA', 'SRV', 'TXT']);
        this.dnsRecordsTypesShared = ko.observableArray(['A', 'AAAA', 'CAA', 'CNAME', 'MX', 'NS', 'SOA', 'SRV', 'TXT']);
        this.domainselected = ko.observable(new Domain());
        this.comesFromDomain = ko.observable(false);
        this.flagCFDomain = ko.observable(false);
        this.sortDirection = ko.observable('asc');
        this.errorLoading = ko.observable('');
        this.temp = ko.observable(new Dnsrecord({
            "domain": self.domainselected
        }));
        this.restoredAsyncWait = ko.observable(false);
        this.firstList = ko.observable(false);

        //MX y DNSApp
        this.mxConfiguration = ko.observable();
        this.mxCode = ko.observable();
        this.bloggerConfiguration = ko.observable({
            "name": ko.observable(),
            "content": ko.observable(),
            "appName": ko.observable()
        });

        this.mxProviders = [
            {"value": "default", "label": "#trans-dns-mx-restore-default", "requireCode": false},
            {"value": "google", "label": "#trans-dns-mx-configure-google", "requireCode": false},
            {"value": "hotmail", "label": "#trans-dns-mx-configure-live", "requireCode": true},
            {"value": "office", "label": "#trans-dns-mx-configure-office", "requireCode": true},
            {"value": "other", "label": "#trans-dns-mx-configure-other", "requireCode": true}
        ];

        this.dkim = ko.observable();

        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.content() == right.content() ? 0 : (left.content() < right.content() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.content() == right.content() ? 0 : (left.content() > right.content() ? -1 : 1);
                });
            }
        };
        this.sortDirectionInColName = ko.observable('asc');
        this.sortDataByName = function() {
            var self = this;
            if (self.sortDirectionInColName() === 'des') {
                self.sortDirectionInColName('asc');
                self.data.sort(function(left, right) {
                    return left.name() == right.name() ? 0 : (left.name() < right.name() ? -1 : 1);
                });
            } else {
                self.sortDirectionInColName('des');
                self.data.sort(function(left, right) {
                    return left.name() == right.name() ? 0 : (left.name() > right.name() ? -1 : 1);
                });
            }
        };
        this.sortDirectionInColType = ko.observable('asc');
        this.sortDataByType = function() {
            var self = this;
            if (self.sortDirectionInColType() === 'des') {
                self.sortDirectionInColType('asc');
                self.data.sort(function(left, right) {
                    return left.type() == right.type() ? 0 : (left.type() < right.type() ? -1 : 1);
                });
            } else {
                self.sortDirectionInColType('des');
                self.data.sort(function(left, right) {
                    return left.type() == right.type() ? 0 : (left.type() > right.type() ? -1 : 1);
                });
            }
        };

        this.subscribe('domainListUpdated', function(domainList) {
            'use strict';
            var self = this;
            var _dl = [];
            $.each(domainList, function(index, val) {
                if (this.regStatus == 1 && this.domain.indexOf('.ferozo.com') < 0) {
                    _dl.push(this);
                }
            });
            var mapping = {
                create: function(options) {
                    return new Domain(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item && item.id);
                }
            };

            ko.mapping.fromJS(_dl, mapping, self.domains);
            self.setInprocess("-");            
            
        });
    };

    DnsVM.prototype.list = function() {
        var self = this;
        var request = { "params": {
            "domain": self.domainselected().domain.content()
        }};

        //Reviso si la cuenta tiene Token para CF
        $.postJSON('/hosting/cloudflare/getcurrenttoken', function(data) { 
            if(data.result){
                $.postJSON('/hosting/cloudflare/listzones',request, function(data) {
                    //Tengo zonaDNS CloudFlare - muestro alert
                    if(data.result.length > 0){
                        self.flagCFDomain(true);
                    }
                    //No tengo zonaDNS CloudFlare - no hago nada
                    else {  
                        self.flagCFDomain(false);
                    }
                });
            }
        });

        self.firstList(true);
        //self.setInprocess("+");
        self.errorLoading('');
        self.data([]);
        self.dkim(false);
        $.postJSON('/hosting/dns/zone/get', request, function(data) {
            self.data([]);
            if (data.result && data.result.Records) {
                $.each(data.result.Records, function(i, e) {
                    var record = new Dnsrecord(e);
                    record.domain = self.domainselected().domain.content();
                    self.data.push(record);
                    if (record.type == 'TXT' && record.name == 'mail._domainkey.'+record.domain) {
                        self.dkim(true);
                    }
                });
            }
        }).fail(function() {
            self.errorLoading();
        }).always(function(data) {
            if (data.error && data.error.message) {
                self.errorLoading(data.error.message);
            }
           FerozoHosting.dnsVM().setInprocess("-");
        });
    };

    DnsVM.prototype.restoreDnsZone = function() {
        var self = this;
        var request = { "params": {
            "domain": self.domainselected().domain.content()
        }};
        self.setInprocess("+");
        self.restoredAsyncWait(false);
        self.data([]);
        $.postJSON('/hosting/dns/zone/restore', request, function(data) {
            if (data.result) {
                var status = 0;
                try {
                    status = data.result.status;
                } catch (error) {}
                if (data.result.async) {
                    $('#restore-dns-zone-modal').find('.init-restore-msg').hide(300);
                    $('#restore-dns-zone-modal').find('.async-restore-msg').show(300);
                    $('#restore-dns-zone-modal').find('.modal-footer button').attr('disabled', 'disabled');
                    self.restoredAsyncWait(true);
                }
                if (status == 200) {
                    $('#restore-dns-zone-modal').modal('hide');
                    self.list();
                }
            }
        }).fail(function() {
            self.errorLoading();
            
        }).always(function(data) {
            if (data.error && data.error.message) {
                self.errorLoading(data.error.message);
            }
            self.setInprocess("-");
            self.init();
            $('#restore-dns-zone-modal').modal('hide');
        });
        
    };


    DnsVM.prototype.openModal = function(mode) {
        'use strict';
        if (mode === 'edit') {
            FerozoHosting.dnsVM().temp(this);
        } else {
            FerozoHosting.dnsVM().temp(new Dnsrecord({
                "domain": this.domainselected
            }));
        }
        $('#modal-manage').modal('show');
    };

    DnsVM.prototype.openModalRestoreDns = function() {
        'use strict';
        $('#restore-dns-zone-modal').find('.init-restore-msg').show();
        $('#restore-dns-zone-modal').find('.async-restore-msg').hide();
        $('#restore-dns-zone-modal').find('.modal-footer button').removeAttr('disabled');
        $('#restore-dns-zone-modal').modal({backdrop: 'static'}, 'show');
    };

    DnsVM.prototype.closeModalRestoreDns = function() {
        'use strict';
        $('#restore-dns-zone-modal').modal('hide');

        require(['sections/domain/domains/viewmodel'], function(DomainsVM) {
            FerozoHosting.domainsVM(new DomainsVM());
            FerozoHosting.domainsVM().init();
            FerozoHosting.activeSection("domains");
        });
    };

    DnsVM.prototype.updateUI = function(data, mode, record) {
        var status = data.result ? data.result.status : 0;
        if (data.result && data.result.async) {
            if (mode === 'add') {
                var actualRecords = FerozoHosting.dnsVM().data();
                actualRecords.push(record);
            }
        }

        if (status == 200) {
            FerozoHosting.dnsVM().list();
        }
        $('#modal-manage input').next('.help-block.error').html('');
        if (data.error) {
            if (data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    $('#modal-manage input[data-bind="value: '+this.field+'"]').next('.help-block.error').html(this.errorDesc);
                });
            }
            return;
        }
        $('#modal-manage').modal('hide');
    };

    DnsVM.prototype.change = function() {
        var self = this;
        //genera un buclen el get al intentar ver la zona con el boton al lado del dominio
        // FerozoHosting.dnsVM().setInprocess("+");
        self.list();
    };

    DnsVM.prototype.init = function() {
        var self = this;
        self.comesFromDomain(false);
        if (self.data() && self.data().length > 0) {
            self.data([]);
            self.list();
        }
        self.data([]);
        mediator.publish('refreshDomainList',this);
        self.setInprocess("-");
        //el list lo hace en el template con (change:{change} y value:change())
        //workaround para trabajar con domainVM.goDnsZone
    };

    DnsVM.prototype.openModalConfigureBlogger = function() {
        this.bloggerConfiguration().name(null);
        this.bloggerConfiguration().content(null);
        this.bloggerConfiguration().appName(null);
        $('#configure-blogger').modal('show');
    };

    DnsVM.prototype.openModalConfigureMX = function() {
        this.mxConfiguration(null);
        this.mxCode(null);
        $('#configure-mx').modal('show');
    };

    DnsVM.prototype.configureMX = function() {
        var self = this;
        var theData = { "params": {
            "domain": self.domainselected().domain.content(),
            "vendor": self.mxConfiguration(),
            "code": self.mxCode()
        }};

        self.setInprocess("+");
        $('.help-block.error').html('');
        $.postJSON('/hosting/dns/records/mx/set', theData, function(data) {
            if (data.error) {
                if (data.error.data.inputException) {
                    $.each(data.error.data.inputException, function() {
                        this.field = this.field === 'code' ? 'mxCode' : this.field;
                        this.field = this.field === 'vendor' ? 'mxConfiguration' : this.field;
                        $('.modal input[name^="'+this.field+'"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                    });
                }
            } else if (data.result) {
                var status = data.result ? data.result.status : 0;
                if (status === 200) {
                    self.list();
                    $('#configure-mx').modal('hide');
                }
            }
         }).always(function(data) {
             self.setInprocess("-");
             self.init();
         });
    };

    DnsVM.prototype.configureBlogger = function() {
        var self = this;
        var theData = { "params": {
            "domain": self.domainselected().domain.content(),
            "appName": self.bloggerConfiguration().appName(),
            "googleDomainName": self.bloggerConfiguration().name(),
            "googleDomainContent": self.bloggerConfiguration().content()
        }};

        self.setInprocess("+");
        $('.help-block.error').html('');
        $.postJSON('/hosting/dns/apps/google/set', theData, function(data) {
            if (data.error) {
                if (data.error.data.inputException) {
                    $.each(data.error.data.inputException, function() {
                        this.field = this.field === 'googleDomainName' ? 'name' : this.field;
                        this.field = this.field === 'googleDomainContent' ? 'content' : this.field;
                        $('#configure-blogger input[name^="'+this.field+'"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                    });
                }
            } else if (data.result) {
                var status = data.result ? data.result.status : 0;
                if (status === 200) {
                    self.list();
                    $('#configure-blogger').modal('hide');
                }
            }
         }).always(function(data) {
             self.setInprocess("-");
         });
    };
    
    DnsVM.prototype.openModalSetDkim = function() {
        'use strict';
        $('#set-dkim-modal').find('.init-restore-msg').show();
        $('#set-dkim-modal').find('.async-restore-msg').hide();
        $('#set-dkim-modal').find('.modal-footer button').removeAttr('disabled');
        $('#set-dkim-modal').modal({backdrop: 'static'}, 'show');
    };    
    
    DnsVM.prototype.openModalUnsetDkim = function() {
        'use strict';
        $('#unset-dkim-modal').find('.init-restore-msg').show();
        $('#unset-dkim-modal').find('.async-restore-msg').hide();
        $('#unset-dkim-modal').find('.modal-footer button').removeAttr('disabled');
        $('#unset-dkim-modal').modal({backdrop: 'static'}, 'show');
    };    

    DnsVM.prototype.openModalSetDmarc = function() {
        'use strict';
        var self = this;
        var found = self.data().some(function(item) {
            return item.content().includes('DMARC');
        });
        if(!found) {
            $('#set-dmarc-modal').find('.init-restore-msg').show();
            $('#set-dmarc-modal').find('.async-restore-msg').hide();
            $('#set-dmarc-modal').find('.modal-footer button').removeAttr('disabled');
            $('#set-dmarc-modal').modal({backdrop: 'static'}, 'show');
        } else {
            $('#already-set-dmarc-modal').modal({backdrop: 'static'}, 'show');
        }
    };  
    

    DnsVM.prototype.confDkim = function() {
        var self = this;
        var request = { "params": {
            "domain": self.domainselected().domain.content(),
            "unset": self.dkim(),
        }};
        self.setInprocess("+");
        self.data([]);
        $.postJSON('/hosting/dns/records/dkim/conf', request, function(data) {
            if (data.result) {
                var status = 0;
                try {
                    status = data.result.status;
                } catch (error) {}
                if (status == 200) {
                    $('#set-dkim-modal').modal('hide');
                    $('#unset-dkim-modal').modal('hide');
                }
            }
        }).fail(function() {
            self.errorLoading();
        }).always(function(data) {
            if (data.error && data.error.message) {
                self.errorLoading(data.error.message);
            }
            self.setInprocess("-");
            self.list();
        });
    };

    DnsVM.prototype.confDmarc = function() {
        var self = this;
        var request = { "params": {
            "domain": self.domainselected().domain.content(),
            "name": '_dmarc.' + self.domainselected().domain.content(),
            "type": 'TXT',
            "content": 'v=DMARC1; p=none',
            "ttl": 14400,
            "prio": 0,
            "proxied": false
        }};
        self.setInprocess("+");
        self.data([]);
        $.postJSON('/hosting/dns/records/add', request, function(data) {
            if (data.result) {
                var status = 0;
                try {
                    status = data.result.status;
                } catch (error) {}
                if (status == 200) {
                    $('#set-dmarc-modal').modal('hide');
                }
            }
        }).fail(function() {
            self.errorLoading();
        }).always(function(data) {
            if (data.error && data.error.message) {
                self.errorLoading(data.error.message);
            }
            self.setInprocess("-");
            self.list();
        });
    };
    
    return DnsVM;
});