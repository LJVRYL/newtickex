define(['knockout', 'ko.mapping', 'dhm/sections/server/services/viewmodel'], function(ko, mapping, servicesVM) {

    var Disk = function(data) {
        this.capacity = ko.observable();
        this.device = ko.observable();
        this.geometry = ko.observable();
        this.model = ko.observable();
        this.type = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    var Processor = function(data) {
        this.cache = ko.observable();
        this.model = ko.observable();
        this.speed = ko.observable();
        this.vendor = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    var Partition = function(data) {
        var self = this;
        this.assigned = ko.observable();
        this.avail = ko.observable();
        this.mounted = ko.observable();
        this.percent = ko.observable();
        this.used = ko.observable();

        this.totalsum = ko.computed(function() {
            return parseInt(self.avail()) + parseInt(self.used());
        });
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    var Memory = function(data) {
        var self = this;
        this.buffers = ko.observable();
        this.cached = ko.observable();
        this.free = ko.observable();
        this.key = ko.observable();
        this.shared = ko.observable();
        this.total = ko.observable();
        this.used = ko.observable();
        this.totalsum = ko.computed(function() {
            if (self.total()) {
                return parseInt(self.total());
            } else {
                return parseInt(self.free()) + parseInt(self.used());
            }
        });

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };


    var serverinfoVM = function() {
        this.inprocess = ko.observable(0);
        this.data = ko.observableArray();

        this.processors = ko.observableArray([]);
        this.partitions = ko.observableArray([]);
        this.disks = ko.observableArray([]);
        this.memories = ko.observableArray([]);

        this.loadAverage = ko.observable();
        this.loadAverageValue = ko.computed(function() {
            return this;
        });

        this.realInitted = ko.observable();
    };

    serverinfoVM.prototype.init = function() {
        if (! this.realInitted()) {
            this.list();
            this.listUptime();
            this.realInitted(true);
        } else {
            this.inprocess(0);
        }
        return this;
    };

    serverinfoVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {
        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/server/status", theData, function(data) {

            if (data.result) {
                data = data.result;
                self.partitions([]); self.memories([]); self.disks([]); self.processors([]);

                //por si no es array
                data.processor = data.processor && data.processor.push ? data.processor : [data.processor];
                data.disk = data.disk && data.disk.push ? data.disk : [data.disk];

                data.partition && ko.utils.arrayForEach(data.partition, function(obj) {
                    self.partitions.push(new Partition(obj));
                });

                data.memory && ko.utils.arrayForEach(data.memory, function(obj) {
                    self.memories.push(new Memory(obj));
                });

                data.disk && ko.utils.arrayForEach(data.disk, function(obj) {
                    self.disks.push(new Disk(obj));
                });

                data.processor && ko.utils.arrayForEach(data.processor, function(obj) {
                    self.processors.push(new Processor(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    serverinfoVM.prototype.listUptime = function() {
        var self = this;
        var theData = { "params": {
        }};

        //self.inprocess(1);
        $.postJSON("/dhm/serverconfig/uptime/list", theData, function(data) {

            if (data.result) {
                self.loadAverage(data.result.loadAverage);
            }

        }).fail(function(data) {
        }).always(function(data) {
            //self.inprocess(0);
        });
    };

    return serverinfoVM;
});