define(['knockout', 'hosting', 'domain', 'sort', 'fzPaginatorAjax', 'dnsrecord', 'dhm/sections/domain/dns/viewmodel', 'translate'], function(ko, Hosting, Domain, Sort, fzPaginatorAjax, DnsRecord, DnsVM, Translate) {
    var domainsVM = function() {
        var self = this;

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.packages = ko.observableArray([]);
        this.hostings = ko.observableArray([]);
        this.hostingsFilter = ko.observableArray([]);
        this.temp = ko.observable(new Domain());

        this.sortByHosting = new Sort(this.data, 'hosting');
        this.sortByDomain = new Sort(this.data, 'domain');

        this.listPaginated = function() {
            var theData = { filter: {
                domain: self.pagination.query(),
                hosting: self.pagination.selectedHosting()
            }};
            self.pagination.ajaxViewModelListing(this, Hosting, "/dhm/domain/parked/list", theData, this.paginationPushCallback.bind(this));
        };
        this.pagination = new fzPaginatorAjax(self.listPaginated.bind(this));
        this.pagination.selectedHosting = ko.observable(0);
    };

    domainsVM.prototype.paginationPushCallback = function(obj) {
        this.data.push(new Domain(obj));
    };

    domainsVM.prototype.init = function() {
        var self = this;

        self.listPaginated();
        self.listHostings();
        self.inprocess(0);
    };

    domainsVM.prototype.impersonate = function(entity, event) {
        window.location.href = '/dhm/impersonate?_switch_user=' + entity.username();
    };

    domainsVM.prototype.goDnsZone = function(entity, event) {
        !FerozoDhm.dnsVM() && FerozoDhm.dnsVM(new DnsVM());
        FerozoDhm.dnsVM().domainSelected(entity);
        FerozoDhm.dnsVM().comesFromDomain(true);
        FerozoDhm.dnsVM().data([]);
        FerozoDhm.dnsVM().list();
        FerozoDhm.activeSection("dns");
    };

    domainsVM.prototype.listHostings = function() {
        var self = this;
        var theData = { "params": {
        }};

        // self.inprocess(1);
        $.postJSON("/dhm/account/hosting/list", theData, function(data) {
            self.hostings([]);
            self.hostingsFilter([]);
            self.hostingsFilter.push(new Hosting({id: 0, username: Translate("#trans-all-hostings")}));
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.hostings.push(new Hosting(obj));
                    self.hostingsFilter.push(new Hosting(obj));
                });
                self.inprocess(0);
            }
        }).fail(function(data) {
        }).always(function(data) {
            //self.inprocess(0);
        });
    };

    domainsVM.prototype.list = function() {
        return this.listPaginated();
    };

    domainsVM.prototype.openModal = function() {
        $('#modal-create').modal('show');
        this.temp(new Domain());
    };

    domainsVM.prototype.isFzCom = function(domain) {
        if ((domain().indexOf('.ferozo.com') > 0) || (domain().indexOf('.ferozo.net') > 0)){
            return true;
        } else {
            return false;
        }        
    };

    return domainsVM;
});