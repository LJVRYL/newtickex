define(['knockout', 'apachehandlers', 'ko.mapping'], function(ko, ApacheHandlers, mapping) {

    var optimizeVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        self.data = ko.observableArray();

        self.inprocess = ko.observable(0);
        this.regStatus = ko.observable();
        this.configValue = ko.observable();
        this.isEnabled = ko.computed(function() {
              if (self.inprocess() == 1 || self.regStatus() == 2) {
                  return false;
              }else{
                  return true;
              }
        });

        this.subscribe('refreshOptimizeConfiguration', function() {
            var self = this;
            $.postJSON("/hosting/tools/gzip/list", function(data) {
                ko.mapping.fromJS(data.result, {}, self);
            }).always(function(data) {
                FerozoHosting.optimizeVM() && FerozoHosting.optimizeVM().inprocess(0);
            });
        });
    };

    optimizeVM.prototype.init = function() {
        'use strict';
        var self = this;
        mediator.publish('refreshOptimizeConfiguration');
    };

    optimizeVM.prototype.switchExec = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "enabled": self.enabled()
        }};

        FerozoHosting.optimizeVM() && FerozoHosting.optimizeVM().inprocess(1);
        $.postJSON("/hosting/tools/gzip/configure", theData, function(data) {
            mediator.publish('refreshOptimizeConfiguration');
        }).always(function(data) {
            FerozoHosting.optimizeVM() && FerozoHosting.optimizeVM().inprocess(0);
        });
   };

    return optimizeVM;
});
