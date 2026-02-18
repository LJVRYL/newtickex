define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var Dnsrecord = function(data) {
        'use strict';
        var self = this;

        mediator.installTo(self);
        ko.mapping = mapping;

        self.rowstatus = ko.observable('0');//0=nada;1=delete
        self.id = '';
        self.domain = '';
        self.name = ko.observable('');
        self.type = ko.observable('');
        self.content = ko.observable('');
        self.ttl = '14400';
        self.prio = '0';
        self.proxied = ko.observable(false);
        self.contentLess = function(length, append) {
            length = length || 50;
            append = append || ' ...';
            return self.content().length < length ?
                self.content : (self.content().substr(0, length) + append);
        };
        self.regStatus = ko.observable(1);

        ko.mapping.fromJS(data, {}, this);
    };

    Dnsrecord.prototype.remove = function(entity, event) {
        var self = this;

        if (typeof self.domain === "object") {
            self.domain = self.domain.domain.content();
        } else if (typeof self.domain === "function") {
            self.domain = self.domain();
        }

        // var theData = {
        //     params: self
        // };
        var theData = { "params": {
            "id": self.id,
            "domain": self.domain,
            "name": self.name() == "@" ? self.domain : self.name(),
            "type": self.type(),
            "content": self.content(),
            "ttl": self.ttl,
            "prio": self.prio,
            "proxied": self.proxied()
        }};
        FerozoHosting.dnsVM() && FerozoHosting.dnsVM().inprocess(1);
        var row = $(event.target).parents('tr').find('td');
        self.regStatus(4);
        $.postJSON('/hosting/dns/records/delete', theData, function(data) {
            if (data.result && data.result.status == 200) {
                row.fadeOut(200, function() {
                    row.parents('tr').remove();
                });
            }
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            FerozoHosting.dnsVM() && FerozoHosting.dnsVM().list();
            FerozoHosting.dnsVM() && FerozoHosting.dnsVM().inprocess(0);
            data.error && self.regStatus(1);
        });
    };

    Dnsrecord.prototype.save = function(entity, event) {
        'use strict';
        var self = this;
        if (typeof self.domain === "object") {
            self.domain = self.domain.domain.content();
        } else if (typeof self.domain === "function") {
            self.domain = self.domain();
        }
        // var theData = {
        //     params: self
        // };

        var theData = { "params": {
            "id": self.id,
            "domain": self.domain,
            "name": self.name() == "@" ? self.domain : self.name(),
            "type": self.type(),
            "content": self.content(),
            "ttl": self.ttl,
            "prio": self.prio,
            "proxied": self.proxied()
        }};

        FerozoHosting.dnsVM() && FerozoHosting.dnsVM().inprocess(1);
        if (! self.id) {
            self.regStatus(4);
            $.postJSON('/hosting/dns/records/add',theData, function() {}).done(function(data) {
                $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].clearErrors == "function") {self[i].clearErrors();}});
                FerozoHosting.dnsVM().updateUI(data, 'add', self);
            }).always(function() {
                FerozoHosting.dnsVM() && FerozoHosting.dnsVM().inprocess(0);
            });
        } else {
            $.postJSON('/hosting/dns/records/edit', theData, function() {}).done(function(data) {
                $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].clearErrors == "function") {self[i].clearErrors();}});
                FerozoHosting.dnsVM().updateUI(data, 'edit', self);
            }).always(function() {
                FerozoHosting.dnsVM() && FerozoHosting.dnsVM().inprocess(0);
            });
        }

    };

    return Dnsrecord;
});