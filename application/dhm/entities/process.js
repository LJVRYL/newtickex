define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping) {
    var Process = function(data) {
        this.regStatus = ko.observable(1);

        this.pid = ko.observable();
        this.user = ko.observable();
        this.priority = ko.observable();
        this.nice = ko.observable();
        this.size = ko.observable();
        this.phmemory = ko.observable();
        this.shmemory = ko.observable();
        this.status = ko.observable();
        this.cpupercent = ko.observable();
        this.memorypercent = ko.observable();
        this.time = ko.observable();
        this.command = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Process.prototype.getServicesVM = function() {
        return FerozoDhm && FerozoDhm.servicesVM();
    };

    return Process;
});