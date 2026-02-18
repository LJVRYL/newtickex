define(['knockout', 'domain', 'email', 'ko.mapping' ], function(ko, Domain, Email, mapping) {
    var emailaccountsmigrationVM = function() {
        'use strict';
        var self = this;

        ko.mapping = mapping;
        this.title = "";
        this.data = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.domainsDestination = {"domains":ko.observableArray(),"subdomains":ko.observableArray()};
        this.temp = ko.observable(new Email());
        this.inprocess = ko.observable(0);
        this.domainselected = ko.observable('');
        this.domain1 = ko.observable();
        this.domain2 = ko.observable();
        this.migrationInitialized = ko.observable(0);
        this.emailsCheckResult = ko.observableArray([]);
        this.aliasCheckResult = ko.observableArray([]);
        this.subdomainsDomains = {"domains":ko.observableArray(),"subdomains":ko.observableArray()};
        this.subdomainsDomainsIn = {"domains":ko.observableArray(),"subdomains":ko.observableArray()};
        this.allowMigration = ko.observable(0);



        mediator.installTo(this);

        this.init = function() {
            'use strict';
            FerozoHosting.emailaccountsmigrationVM() && FerozoHosting.emailaccountsmigrationVM().inprocess(1);
            FerozoHosting.emailaccountsmigrationVM().loadSubdomainDomain();
            //cuando termina la migración, si se quedo en la pantalla, permite volver ejecutar otra
            FerozoHosting.emailaccountsmigrationVM().migrationInitialized(0);
        };

        this.loadSubdomainDomain = function() {
            var self = this;
            $.postJSON("/hosting/domain/listsubdomaindomains", function(data) {
                if (data.result) {
                    var _dl = [];
                    $.each(data.result.domains, function(index, val) {
                        if (this.name.indexOf('ferozo.com') < 0 ) {
                            if (this.name.indexOf('ferozo.net') < 0) {
                                _dl.push(this);
                            }
                        }
                    });
                    var _sdl = [];
                    $.each(data.result.subdomains, function(index, val) {
                        if (this.name.indexOf('.ferozo.com') < 0 ) {
                            if (this.name.indexOf('.ferozo.net') < 0) {
                                _sdl.push(this);
                            }
                        }
                    });
                    self.subdomainsDomains.domains(_dl);
                    self.subdomainsDomains.subdomains(_sdl);
                    self.subdomainsDomainsIn.domains(data.result.domains);
                    self.subdomainsDomainsIn.subdomains(data.result.subdomains);
                }
                FerozoHosting.emailaccountsmigrationVM().domain1(FerozoHosting.emailaccountsmigrationVM().domainsDestination.domains()[0]);
                FerozoHosting.emailaccountsmigrationVM().domainChanged();
                FerozoHosting.emailaccountsmigrationVM().domain2(FerozoHosting.emailaccountsmigrationVM().domainsDestination.domains()[0]);
                FerozoHosting.emailaccountsmigrationVM() && FerozoHosting.emailaccountsmigrationVM().inprocess(0);
            });
        };

        this.verify = function() {
            'use strict';
            var theData = { params: {
                "idDomainFrom":FerozoHosting.emailaccountsmigrationVM().domain1().id,
                "idDomainFromType":FerozoHosting.emailaccountsmigrationVM().domain1().type,
                "idDomainTo":FerozoHosting.emailaccountsmigrationVM().domain2().id,
                "idDomainToType":FerozoHosting.emailaccountsmigrationVM().domain2().type
            }};
            self.allowMigration(0);
            FerozoHosting.emailaccountsmigrationVM() && FerozoHosting.emailaccountsmigrationVM().inprocess(1);
             $.postJSON("/hosting/email/checkdomainmigration", theData,function(data) {
                 if (data.result) {
                    var mappingRules = {
                        create: function(options) {
                            return new Mysqldb(options.data);
                        },
                        key: function(item) {
                            return ko.utils.unwrapObservable(item.id);
                        }
                    };
                    self.emailsCheckResult(data.result.emails);
                    self.aliasCheckResult(data.result.alias)

                    $.each(self.emailsCheckResult(), function(i, value) {
                        if(value.allowMigration == true){
                            self.allowMigration(1);
                        }
                    });

                }
            }).always(function() {
                FerozoHosting.emailaccountsmigrationVM() && FerozoHosting.emailaccountsmigrationVM().inprocess(0);
            });
        };

        this.migrate = function() {
            'use strict';
            FerozoHosting.emailaccountsmigrationVM() && FerozoHosting.emailaccountsmigrationVM().inprocess(1);
            var _PARAMS = {
                params: {
                    "idDomainFrom":FerozoHosting.emailaccountsmigrationVM().domain1().id,
                    "idDomainFromType":FerozoHosting.emailaccountsmigrationVM().domain1().type,
                    "idDomainTo":FerozoHosting.emailaccountsmigrationVM().domain2().id,
                    "idDomainToType":FerozoHosting.emailaccountsmigrationVM().domain2().type
                }
            };

             $.postJSON("/hosting/email/migratedomain", _PARAMS,function(data) {
                if (data.result) {
                    FerozoHosting.emailaccountsmigrationVM().migrationInitialized(1);
                }
            }).always(function() {
                FerozoHosting.emailaccountsmigrationVM() && FerozoHosting.emailaccountsmigrationVM().inprocess(0);
            });
        };
        
        this.domainChanged = function() {
            'use strict';
            //clone objetos
            FerozoHosting.emailaccountsmigrationVM().domainsDestination.domains(FerozoHosting.emailaccountsmigrationVM().subdomainsDomains.domains().slice(0));
            FerozoHosting.emailaccountsmigrationVM().domainsDestination.subdomains(FerozoHosting.emailaccountsmigrationVM().subdomainsDomains.subdomains().slice(0));
            //elimino de la lista
            FerozoHosting.emailaccountsmigrationVM().domainsDestination.domains.remove(FerozoHosting.emailaccountsmigrationVM().domain1());
            FerozoHosting.emailaccountsmigrationVM().domainsDestination.subdomains.remove(FerozoHosting.emailaccountsmigrationVM().domain1());
            FerozoHosting.emailaccountsmigrationVM().domain2(FerozoHosting.emailaccountsmigrationVM().domainsDestination.domains()[0]);
            FerozoHosting.emailaccountsmigrationVM().emailsCheckResult([]);
            FerozoHosting.emailaccountsmigrationVM().aliasCheckResult([]);
        };

    };
    return emailaccountsmigrationVM;
});