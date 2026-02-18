define(['knockout', 'domain', 'subdomain', 'sslcert', 'ko.mapping'], function(ko, Domain, SubDomain, SslCert, mapping) {
    var SslCertsVM = function SslCertsVM() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;
        //this.title = "";
        this.data = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.SubdomainsDomains = {"domains":ko.observableArray(),"subdomains":ko.observableArray()};
        this.temp = ko.observable(new SslCert());
        this.autoSslList = ko.observableArray([]);
        this.inprocess = ko.observable(1);
        this.sslData = ko.observable();
        this.sslInfo = ko.observable();
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
        this.readyView =  ko.computed(function() {
            if (this.inprocess() >= 1  ) return "LOADING";
            if (this.inprocess() < 1 && this.domains().length <= 0  ) return "WO-DOMAINS";
            if (this.inprocess() < 1 && this.domains().length > 0  ) return "W-DOMAINS";
            return "LOADING";
        },this);

        this.openModalInfo = function(ssl) {
            self.sslData(ssl);
            var theData = 
                { "params": {
                    "idCert": self.sslData().id()
                }};
            $.postJSON('/hosting/domain/detailSslStatus', theData, function(data) { 
                if (data.result) {
                    self.sslData().www(data.result);
                }
            });
            $('#sslinfo').modal('show');
        };

        this.addSsl = function(ssl) {
            var theData = 
            { "params": {
                "idCert": ssl.id()
            }};
            FerozoHosting.sslVM() && FerozoHosting.sslVM().setInprocess("+");
            $.postJSON('/hosting/domain/retrygenssl', theData, function(data) { 
            }).fail(function(data) {
            }).always(function(data) {
                FerozoHosting.sslVM().init();
                setTimeout( function() {
                    FerozoHosting.sslVM() && FerozoHosting.sslVM().setInprocess("-");
                }, 2000);
            });
        };

        this.sortDirection = ko.observable('asc');

        this.sortData = function() {
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.description.content() == right.description.content() ? 0 : (left.description.content() < right.description.content() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.description.content() == right.description.content() ? 0 : (left.description.content() > right.description.content() ? -1 : 1);
                });
            }
        };

//        this.modify = function(entity) {
//            'use strict';
//        };

        this.subscribe('refreshSslCertList', function() {
            self.sortDirection('asc');
            $.postJSON("/hosting/domain/listsslcerts", function(data) {
                if (data.result) {
                    var mapping = { update: function(options) {
                        return new SslCert(options.data);
                    }};
                    ko.mapping.fromJS(data.result, mapping, self.data);
                }
            }).always(function() {
                self.setInprocess("-");
            });
        });

        this.subscribe('domainListUpdated', function(domainList) {
            var _dl = [];
            var _dlselect = [];
            self.autoSslList([]);
            $.each(domainList, function(index, val) {
                if (this.regStatus == 1) {
                    if(this.domain.indexOf('.ferozo.com') < 0 ){
                        if(this.domain.indexOf('.ferozo.net') < 0){
                            _dl.push(this);
                            _dlselect.push(this);
                        }
                    }
                    if( this.sslCert != null){
                        if( this.sslCert.issuer == this.sslCert.description){
                           self.autoSslList.push(' '+this.domain);
                        }
                    }
                }
            });

            var mapping = {
                create: function(options) {
                    return new Domain(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(_dl, mapping, self.domains);
            self.SubdomainsDomains.domains(_dlselect);
            self.temp().domainselected(self.SubdomainsDomains.domains()[0]);
            self.setInprocess("-");
        });

        this.init = function() {
            'use strict';
            mediator.publish('refreshDomainList',this);
            mediator.publish('refreshSslCertList');
        };

        /*this.sort = function() {
            'use strict';
            self.domains.sort();
            self.data.sort();
        };*/
        
    };

    SslCertsVM.prototype.openModalCheck = function() {
        this.temp(new SslCert());
        $('#check-new').modal('show');
    };

    SslCertsVM.prototype.openModalGen = function() {
        // this.temp(new SslCert());
        $('#gen-new').modal('show');
    };

    SslCertsVM.prototype.showSSL = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Server.Sni;
        } else {
            return false;
        }
    };

    SslCertsVM.prototype.parseDateTime = function(dateTimeString) {
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
    
    SslCertsVM.prototype.isFutureDate = function(dateTimeString) {
        const givenDate = this.parseDateTime(dateTimeString);
        const now = new Date();
    
        return givenDate >= now;
    }

    return SslCertsVM;
});