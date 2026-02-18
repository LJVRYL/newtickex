define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var Service = function(data) {
        var self = this;
        this.regStatus = ko.observable(1);
        this.rowstatus = ko.observable(0);
        this.name = ko.observable();
        this.status = ko.observable();
        this.type = ko.observable();
        this.allowReload = ko.observable();
        this.type = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Service.prototype.getServicesVM = function() {
        return FerozoDhm && FerozoDhm.servicesVM();
    };

    Service.prototype.restart = function() {
        var self = this;

        if (! self.allowReload()) {
            Notifications.error('El servicio indicado no permite el reinicio');
            return;
        }

        var theData = { "params": self.toJS() };

        self.getServicesVM().inprocess(1);
        $.postJSON('/dhm/serverconfig/restart/service', theData, function(data) {
            if (data.result) {
                Notifications.success('Se comenzara con el reinicio del servicio');
            }
        }).fail(function(data) {
        }).always(function(data) {
            if (data.error) {
            }
            self.getServicesVM().inprocess(0);
        });
    };

    Service.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return Service;
});