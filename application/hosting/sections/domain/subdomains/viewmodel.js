define(['knockout', 'domain', 'subdomain', 'ko.mapping', 'translate', 'dnsrecord', 'hosting/sections/domain/dns/viewmodel', 'treeNavigation'], function(ko, Domain, SubDomain, mapping, Translate, Dnsrecord, DnsVM, TreeNavigation) {
    var SubdomainsVM = function SubdomainsVM() {
        'use strict';
        var self = this;


        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";
        this.data = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.currentSubdomains = ko.observable('');
        this.serviceId = ko.observable();
        this.maxSubdomains = ko.observable('');
        this.temp = ko.observable(new SubDomain());
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
            //console.log('testingsub', this.inprocess());
            if (this.inprocess() >= 1  ) return "LOADING";
            if (this.inprocess() < 1 && this.domains().length <= 0  ) return "WO-DOMAINS";
            if (this.inprocess() < 1 && this.domains().length > 0  ) return "W-DOMAINS";
            return "LOADING";
        },this);
        // Datos de la redirección
        this.domainselected = ko.observable('');
        this.sortDirection = ko.observable('asc');

        this.treeNavigation = new TreeNavigation();
        this.treeNavigation.onlyFolders = true;

        this.sortData = function() {
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.name.content() == right.name.content() ? 0 : (left.name.content() < right.name.content() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.name.content() == right.name.content() ? 0 : (left.name.content() > right.name.content() ? -1 : 1);
                });
            }
        };

        this.subdomainTypes = [
            {label: Translate('#trans-without-redirection'), actionLabel: '', value: 1, placeholder: ''},
            {label: Translate('#trans-redirection'), actionLabel: Translate('#trans-enter-redirection'), value: 2, placeholder: 'http://www.google.com/blog'},
            {label: Translate('#trans-domain'), actionLabel: Translate('#trans-enter-domain'), value: 3, placeholder: 'google.com'},
            {label: Translate('#trans-ip'), actionLabel: Translate('#trans-enter-ip'), value: 4, placeholder: '173.194.42.78'},
            {label: Translate('#trans-folder'), actionLabel: Translate('#trans-enter-folder'), value: 5, placeholder: 'includes'}
        ];

        this.getSubdomainTypeByValue = function(value) {
            for (var i in this.subdomainTypes) {
                if (this.subdomainTypes[i].value == value) {
                    return this.subdomainTypes[i];
                }
            }
        };

        this.modify = function(entity) {
            'use strict';
        };

        this.showMoreOpts = function(target, unexpTxt, expTxt, entity, event) {
            //Hay un problema o bug con knockout que se suscribe a los collapse y re-bindea areas
            //La forma mas viable para evitar eso queda asi
            var txt = $(event.target).html() == expTxt ? unexpTxt : expTxt;
            $(target).toggle(300, function() {
                $(event.target).html(txt);
            });
        };

        this.subscribe('refreshSubDomainList', function() {
            self.sortDirection('asc');
            $.postJSON("/hosting/domain/listsubdomains", function(data) {
                if (data.result) {
                    var mapping = { update: function(options) {
                        return new SubDomain(options.data);
                    }};
                    ko.mapping.fromJS(data.result, mapping, self.data);
                }
            }).always(function() {
                 self.setInprocess("-");
            });
        });

        this.subscribe('subDomainDeleted', function() {
            this.publish('refreshSubDomainList');
        });

        this.subscribe('domainListUpdated', function(domainList) {
            var _dl = [];
            $.each(domainList, function(index, val) {
                if (this.regStatus == 1 && this.domain.indexOf('.ferozo.') < 0) {
                    _dl.push(this);
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
            self.setInprocess("-");
        }),

        this.init = function() {
            'use strict';
            mediator.publish('refreshSubDomainList');
            mediator.publish('refreshDomainList',this);
            self.quotaCheck();
        };

        this.quotaCheck = function() {
            self= this;
            self.setInprocess("+");
            $.postJSON("/hosting/account/getinfo", function(data) {
                self.serviceId(data.result.idService);
                self.currentSubdomains(data.result.Limites.subdomains.usado);
                self.maxSubdomains(data.result.Limites.subdomains.total);
            }).always(function() {
                self.setInprocess("-");
            });
        };

        this.redirectMcSpace = function(entity, event) {
            var self = this;
            window.open('https://micuenta.donweb.com/xx-xx/servicios/sitios/'+FerozoHosting.subdomainsVM().serviceId()+'/configurar/cambio-servicio', '_blank');
        };

        this.sort = function() {
            'use strict';
            self.domains.sort();
            self.data.sort();
        };

        this.openModalEdit = function(entity) {
            var cloned = ko.mapping.fromJS(ko.toJS(entity));
            self.temp(cloned);
            $('#editar-subdominio').modal('show');
        };
    };

    SubdomainsVM.prototype.showSSL = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Server.Sni;
        } else {
            return false;
        }
    }; 

    SubdomainsVM.prototype.openModalCreate = function() {
        this.temp(new SubDomain());
        $('#apuntar-nuevo').modal('show');
    };

    SubdomainsVM.prototype.goDnsZone = function(entity) {
        !FerozoHosting.dnsVM() && FerozoHosting.dnsVM(new DnsVM());
        FerozoHosting.dnsVM().temp(new Dnsrecord({"domain": entity.domain}));
        FerozoHosting.dnsVM().domainselected(entity.domain);
        FerozoHosting.dnsVM().init();
        FerozoHosting.dnsVM().comesFromDomain(2);
        FerozoHosting.activeSection("dns");
    };

    SubdomainsVM.prototype.showModalSelectFile = function() {
        var self = this;

        $('#fileNavigator').modal('show');
        self.treeNavigation.reset();
        self.treeNavigation.list();
    };

    SubdomainsVM.prototype.onFileSelection = function() {
        var self = this;
        var path = self.treeNavigation.selected().replace(/^\/public_html\//, '');
        self.temp().redirection.content(path);
        $('#fileNavigator').modal('hide');
    };

    SubdomainsVM.prototype.sslInfo = function(subdomain) {
        if(subdomain.sslCert().sslStatus() <= 4) {
            window.location.href = '/#/domain/ssl';
        } else {
            $("#sslinfo").modal('show');
        }
    };
    
    return SubdomainsVM;
});