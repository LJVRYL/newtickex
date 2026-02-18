define(['knockout', 'domain', 'ko.mapping', 'dnsrecord', 'hosting/sections/domain/dns/viewmodel', 'fzPaginatorAjax'], function(ko, Domain, mapping, Dnsrecord, DnsVM, fzPaginatorAjax) {

    return function DomainsVM() {
        'use strict';
        var self = this;
        this.activeList = ko.observable(0);
        this.inprocess = ko.observable(1);
        this.currentDomains = ko.observable('');
        this.serviceId = ko.observable();
        this.maxDomains = ko.observable('');
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
            if (this.inprocess() < 1 && this.data().length <= 0  ) return "WO-DOMAINS";
            if (this.inprocess() < 1 && this.data().length > 0  ) return "W-DOMAINS";
            return "LOADING";
        },this);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new Domain());
        this.sslStatusDesc = ko.observable();
        ko.mapping = mapping;

        mediator.installTo(this);
        this.pagination = new fzPaginatorAjax(function() {
            self.listPaginated();
        });

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.domain.content() == right.domain.content() ? 0 : (left.domain.content() < right.domain.content() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.domain.content() == right.domain.content() ? 0 : (left.domain.content() > right.domain.content() ? -1 : 1);
                });
            }
        };

        this.opennew = function(dom, idmodal, event) {
            'use strict';
            var self = this;
            self.temp(new Domain());
            $('#' + idmodal).modal('show');
        };

        this.goDnsZone = function(entity) {
            !FerozoHosting.dnsVM() && FerozoHosting.dnsVM(new DnsVM());
            FerozoHosting.dnsVM().temp(new Dnsrecord({"domain": entity}));
            FerozoHosting.dnsVM().domainselected(entity);
            FerozoHosting.dnsVM().init();
            FerozoHosting.dnsVM().comesFromDomain(1);
            FerozoHosting.activeSection("dns");
        };

        this.savenew = function(callback, evento) {
            'use strict';
            var self = this;
            self.setInprocess("+");
            var _temp = self.temp();

            var newDomain = {
                "params": {
                    "domain":_temp.domain.content(),
                    "id":_temp.id
                }
            };
            $.postJSON('/hosting/domain/parkdomain', newDomain, function(response) {
                if (response.error && response.error.data.inputException) {
                    $.each(response.error.data.inputException, function() {
                        self.temp()[this.field].error(this.errorDesc);
                    });
                } else {
                    self.setInprocess("+");
                    self.init();
                    $('.modal').modal('hide');
                }
            }).always(function() {
                self.setInprocess("-");
            });

        };


        this.subscribe('refreshDomainListAjax', function() {
            this.listPaginated();
        });

        this.listPaginated = function() {
            self.pagination.ajaxViewModelListing(this, Domain, "/hosting/domain/listdomains");
        };

        this.init = function() {
            'use strict';
            var self = this;
            self.quotaCheck();
            mediator.publish('refreshDomainListAjax');
            self.domainsSsl();
            self.setInprocess("-");
        };

        this.quotaCheck = function() {
            self= this;
            self.setInprocess("+");
            $.postJSON("/hosting/account/getinfo", function(data) {
                self.serviceId(data.result.idService);
                self.currentDomains(data.result.Limites.domains.usado);
                self.maxDomains(data.result.Limites.domains.total);
            }).always(function() {
                self.setInprocess("-");
            });
        };

        this.redirectMcSpace = function(entity, event) {
            var self = this;
            window.open('https://micuenta.donweb.com/xx-xx/servicios/sitios/'+FerozoHosting.domainsVM().serviceId()+'/configurar/cambio-servicio', '_blank');
        };

        this.modify = function(Domain) {
            'use strict';
            FerozoHosting.domainsVM().temp(Domain);
            $('#apuntar-nuevo').modal('show');
        };

        this.newdomain = function() {
            $("#apuntar-nuevo").modal();
        };

        this.renamedomain = function(entity, event) {
            var cloned = ko.mapping.fromJS(ko.toJS(entity));
            FerozoHosting.domainsVM().temp(cloned);
            $("#rename").modal('show');
        };

        this.forzarhttps = function(entity, event) {
            // var cloned = ko.mapping.fromJS(ko.toJS(entity));
            // FerozoHosting.domainsVM().temp(cloned);
            // $("#forzarhttps").modal('show');
            domain.regStatus(3);
            this.init();
            domain.changeForce();
        };
        
        this.domainsSsl = function(){
            'use strict';
            var self = this;
            $.postJSON('/hosting/domain/listdomains', function(response) {
                $.each(response.result, function() {
                    var domain = this;
                    if( domain.sslCert != null){
                        self.activeList(1);
                    }
                });
            })
        };

        this.checkdomains = function() {
            'use strict';
            var self = this;
            $.each(self.data(), function() {
                var domain = this;
                domain.parkstatus({"code": 100, "message": "Comprobando..."});

                var theData = { "params": {
                    "id": this.id
                }};
                $.postJSON('/hosting/domain/isdomainresolve', theData, function(response) {
                    domain.parkstatus(response.result);
                }).always(function() {

                });
            });
        };
        
        this.domainDeleteConfirm = function(entity, event) {
            FerozoHosting.domainsVM().temp(entity);
            $("#confirm-delete").modal('show');
        };
     
        this.isLinux = function() {
            if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
                return FerozoHosting.profileVM().user().Server.OpSystem() == 'Linux';
            } else {
                return false;
            }
        };
        
        this.showSSL = function() {
            if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
                return FerozoHosting.profileVM().user().Server.Sni;
            } else {
                return false;
            }
        };
        
        this.parseDateTime = function(dateTimeString) {
            const dateTimeParts = dateTimeString.split(' ');
            const datePart = dateTimeParts[0];
            const timePart = dateTimeParts[1];
        
            const dateValues = datePart.split('-');
            const year = dateValues[0];
            const month = dateValues[1];
            const day = dateValues[2];
        
            const timeValues = timePart.split(':');
            const hours = timeValues[0];
            const minutes = timeValues[1];
            const seconds = timeValues[2];
        
            return new Date(year, month - 1, day, hours, minutes, seconds);
        }
        
        this.isFutureDate = function(dateTimeString) {
            if(dateTimeString) {
                const givenDate = this.parseDateTime(dateTimeString());
                const now = new Date();
            
                return givenDate >= now;
            }
        }

        this.sslInfo = function(domain) {
            if(domain.sslCert().sslStatus() < 2) {
                window.location.href = '/#/domain/ssl';
            } else {
                var theData = 
                    { "params": {
                        "idCert": domain.sslCert().id()
                    }};
                $.postJSON('/hosting/domain/detailSslStatus', theData, function(data) { 
                    if (data.result) {
                        FerozoHosting.domainsVM().sslStatusDesc(data.result);
                    }
                });
                var cloned = ko.mapping.fromJS(ko.toJS(domain));
                FerozoHosting.domainsVM().temp(cloned);
                $("#sslinfo").modal('show');
            }
        };

    };
});