define(['knockout', 'domain', 'email', 'emailalias', 'ko.mapping'], function(ko, Domain, Email, EmailAlias, mapping) {

    var EmailAliasVM = function() {
        'use strict';

        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";
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
        
        this.data = ko.observableArray([]);
        this.emails = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.temp = ko.observable(new EmailAlias());
        this.redirection = ko.observable(''); // Datos de la redirección
        this.emailsource = ko.observable('');
        this.subdomainsDomains = {"domains":ko.observableArray(),"subdomains":ko.observableArray()};

        this.sortDirection = ko.observable('asc');
        this.sortDirectionAlias = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.emailAccount.account.user() == right.emailAccount.account.user() ? 0 : (left.emailAccount.account.user() < right.emailAccount.account.user() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.emailAccount.account.user() == right.emailAccount.account.user() ? 0 : (left.emailAccount.account.user() > right.emailAccount.account.user() ? -1 : 1);
                });
            }
        };
        this.sortDataAlias = function() {
            var self = this;
            if (self.sortDirectionAlias() === 'des') {
                self.sortDirectionAlias('asc');
                self.data.sort(function(left, right) {
                    return left.emailAlias() == right.emailAlias() ? 0 : (left.emailAlias() < right.emailAlias() ? -1 : 1);
                });
            } else {
                self.sortDirectionAlias('des');
                self.data.sort(function(left, right) {
                    return left.emailAlias() == right.emailAlias() ? 0 : (left.emailAlias() > right.emailAlias() ? -1 : 1);
                });
            }
        };

        this.existEmailActive=function() {
            var _return=false;
            $.each(this.emails(),function(i,v) {
                if (v.regStatus() == 1) { _return=true;return;}
            });
            return _return;
        };

        this.loadSubdomainDomain = function() {
            var self = this;
            self.setInprocess("+");
            $.postJSON("/hosting/domain/listsubdomaindomains", function(data) {
                if (data.result) {
                    self.subdomainsDomains.domains(data.result.domains);
                    self.subdomainsDomains.subdomains(data.result.subdomains);
                    self.temp().subdomainDomain(self.subdomainsDomains.domains()[0]);
                }
            }).always(function() {
                self.setInprocess("-");
            });
        };

        /* <=[ Subscriptions ]=> */
        this.subscribe('domainListUpdated', function(domainList) {
            'use strict';
            var self = this;


            var mapping = {
                    create: function(options) {
                        return new Domain(options.data);
                    },
                    key: function(item) {
                        return ko.utils.unwrapObservable(item.id);
                    }
            };

            ko.mapping.fromJS(domainList, mapping, self.domains);

        });

        this.subscribe('emailListUpdated', function(emails) {
            'use strict';
            var self = this;
            var mapping = {
                    create: function(options) {
                        return new Email(options.data);
                    },
                    key: function(item) {
                        return ko.utils.unwrapObservable(item.id);
                    }
            };

            ko.mapping.fromJS(emails, mapping, self.emails);
            FerozoHosting.profileVM().user().updateEmails(emails);
            self.setInprocess("-");
        });

        this.subscribe('refreshEmailAlias', function() {
            var self = this;
            self.setInprocess("+");
            $.postJSON("/hosting/email/listemailalias", function(data) {
                if (data.result) {
                    self.data([]);
                    $.each(data.result, function() {
                        self.data.push(new EmailAlias(this));
                    });
                }
            }).always(function(data) {
                self.setInprocess("-");
            });
        });

        this.subscribe('emailAliasDeleted', function() {
                var self = this;
                mediator.publish('refreshEmailAlias');
        });
    };

    EmailAliasVM.prototype.openNew = function() {
        //this.temp(new EmailAlias());
        this.temp().emailAlias().reset();
        $('#crearCuentaEmail').modal('show');
    };

    EmailAliasVM.prototype.init = function() {
        'use strict';
        var self = this;

       mediator.publish('refreshEmailList');
       mediator.publish('refreshDomainList');
       mediator.publish('refreshEmailAlias');
       FerozoHosting.emailaliasVM().loadSubdomainDomain();
       self.setInprocess("-");
    };
    return EmailAliasVM;

});
