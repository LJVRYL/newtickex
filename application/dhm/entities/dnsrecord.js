define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var DnsRecord = function(data) {

        var self = this;

        self.regStatus = ko.observable(1);
        self.rowstatus = ko.observable();

        self.id = ko.observable();
        self.idHosting = ko.observable('');
        self.domain = ko.observable('');

        self.name = ko.observable();
        self.type = ko.observable();
        self.content = ko.observable('');
        self.ttl = ko.observable();
        self.prio = ko.observable();

        self.contentLess = ko.computed(function(length, append) {
            length = length || 50;
            append = append || ' ...';
            return self.content().length < length ?
                self.content() : (self.content().substr(0, length) + append);
        });

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, self);
    };

    DnsRecord.prototype.getDnsVM = function() {
        return FerozoDhm.dnsVM && FerozoDhm.dnsVM();
    };

    DnsRecord.prototype.remove = function(entity, event) {
        var self = this;
        var theData = {
            params: self.toJS()
        };

        var row = $(event.target).parents('tr').find('td');
        self.regStatus(4);
        return $.postJSON('/dhm/dns/record/delete', theData, function(data) {
            if (data.result && data.result.status === 200) {
                row.fadeOut(200, function() {
                    row.parents('tr').remove();
                });
            }
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
        });
    };

    DnsRecord.prototype.save = function(entity, event) {
        'use strict';
        var self = this;
        var url = self.id() ? '/dhm/dns/record/edit' : '/dhm/dns/record/add';
        var theData = {
            params: self.toJS()
        };

        ko.utils.clearObservableErrors.bind(self).apply();
        self.getDnsVM() && self.getDnsVM().inprocess(1);
        self.id() ? self.regStatus(3) : self.regStatus(2);
        return $.postJSON(url, theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            if (data.result) {
                $('.modal').modal('hide');
                self.getDnsVM().list();
            }
        }).always(function() {
            self.getDnsVM().inprocess(0);
        });
    };

    DnsRecord.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return DnsRecord;
});