define(['knockout', 'domain', 'apachehandlers', 'ko.mapping'], function(ko, Domain, ApacheHandlers, mapping) {

    var cgiconfigurationVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        self.data = ko.observableArray();
        self.temp = ko.observable(new ApacheHandlers());

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

        this.execAction = ko.computed(function() {
            if (self.inprocess() == 1 || self.regStatus() == 2)
            {
               return 'Guardando...';
            }
            else
            {
                return 'Guardar';
            }
        });

        this.subscribe('refreshCgiConfiguration', function() {
            var self = this;
            $.postJSON("/hosting/tools/apache/cgi/list", function(data) {
                ko.mapping.fromJS(data.result, {}, self);
            }).always(function(data) {
                FerozoHosting.cgiconfigurationVM() && FerozoHosting.cgiconfigurationVM().inprocess(0);
            });
        });
    };

    cgiconfigurationVM.prototype.init = function() {
        'use strict';
        var self = this;
        mediator.publish('refreshCgiConfiguration');
    };

    cgiconfigurationVM.prototype.switchExec = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "allowWrite": self.allowWrite(),
            "allowExec": self.allowExec()
        }};

        FerozoHosting.cgiconfigurationVM() && FerozoHosting.cgiconfigurationVM().inprocess(1);
        $.postJSON("/hosting/tools/apache/cgi/configure", theData, function(data) {
            mediator.publish('refreshCgiConfiguration');
        }).always(function(data) {
            FerozoHosting.cgiconfigurationVM() && FerozoHosting.cgiconfigurationVM().inprocess(0);
        });
   };



    return cgiconfigurationVM;
});
//-------- / DomainVM


