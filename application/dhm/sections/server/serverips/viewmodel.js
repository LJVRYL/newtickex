define(['knockout', 'ko.mapping', 'ip', 'sort'], function(ko, mapping, IP, Sort) {
    var serveripsVM = function() {
        var self = this;

        ko.mapping = mapping;

        this.inprocess = ko.observable(1);
        this.data = ko.observableArray([]);

        this.sortByIp = new Sort(this.data, 'ipaddress');

    };

    serveripsVM.prototype.init = function() {
        this.list();
    };

    serveripsVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/ip/list", theData, function(data) {
            self.data([]);
            if (data.result && data.result.networkdevices) {
                ko.utils.arrayForEach(data.result.networkdevices, function(obj) {
                    self.data.push(new IP(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    return serveripsVM;
});