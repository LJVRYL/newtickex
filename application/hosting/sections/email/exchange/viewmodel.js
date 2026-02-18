define(['knockout', 'domain', 'exchange', 'ko.mapping', 'fzPaginator'], function(ko, Domain, Exchange, mapping, fzPaginator) {
    var exchangeVM = function() {
        'use strict';
        var self = this;

        ko.mapping = mapping;
        self.title = "";
        self.data = ko.observableArray([]);
        self.domains = ko.observableArray([]);
        self.temp = ko.observable(new Exchange());
        self.inprocess = ko.observable(0);

        self.domainselected = ko.observable();

        mediator.installTo(self);

        self.sortDirection = ko.observable('asc');
        self.sortData = function() {
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() < right.account.user() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() > right.account.user() ? -1 : 1);
                });
            }
        };

        self.subscribe('domainListUpdated', function(domainList) {
            'use strict';
            var theseDomains = [];
            $.each(domainList, function(i, e) {
                if (this.regStatus === 1) {
                    theseDomains.push(this);
                }
            });

            var mapping = {
                create: function(options) {
                    return new Domain(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(theseDomains, mapping, self.domains);
        });

        self.subscribe('refreshExchangeList', function() {
            self.list();
        });
    };

    exchangeVM.prototype.list = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/email/listexchangeaccounts", function(data) {
            self.data([]);
            if (data.result) {
                $.each(data.result, function() {
                    self.data.push(new Exchange(this));
                });
            }
        }).always(function() {
            self.inprocess(0);
        });
    };

    exchangeVM.prototype.newmail = function() {
        'use strict';
        FerozoHosting.exchangeVM().temp(new Exchange());
        $("#crearCuentaExchange").modal();
    };

    exchangeVM.prototype.init = function() {
        'use strict';
        mediator.publish('refreshDomainList');
        mediator.publish('refreshExchangeList');
    };

    return exchangeVM;
});