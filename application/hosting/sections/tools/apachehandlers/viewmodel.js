define(['knockout', 'domain', 'apachehandlers', 'ko.mapping'], function(ko, Domain, ApacheHandlers, mapping) {

    var apachehandlersVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        self.data = ko.observableArray();
        self.temp = ko.observable(new ApacheHandlers());
        this.inprocess = ko.observable(1);
        this.regStatus = ko.observable(1);
        this.listApachehandlers = ko.observableArray([]);
        this.handler = ko.observable(''); // Datos de la redirección
        this.extension = ko.observable('');
        this._default = ko.observable('2');

        this.sortDirection = ko.observable('asc');
        this.sortHandler = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.handler() == right.handler() ? 0 : (left.handler() < right.handler() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.handler() == right.handler() ? 0 : (left.handler() > right.handler() ? -1 : 1);
                });
            }
        };
        this.sortExtension = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.extension() == right.extension() ? 0 : (left.extension() < right.extension() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.extension() == right.extension() ? 0 : (left.extension() > right.extension() ? -1 : 1);
                });
            }
        };

         this.subscribe('refreshApachehandlersList', function() {
            var self = this;
            $.postJSON("/hosting/tools/access/handlers/list", function(data) {
                if (data.result) {
                    self.data([]);
                    $.each(data.result, function() {
                       if (typeof this._default == "undefined") {
                            this._default = false;
                        } else {
                            this.regStatus = 1;
                        }
                        self.data.push(new ApacheHandlers(this));
                    });
                }
            }).always(function(data) {
                self.inprocess(0);
            });
        });

        this.subscribe('apachehandlersDeleted', function() {
            'use strict';
            var self = this;
            mediator.publish('refreshApachehandlersList');
        });

    };

    apachehandlersVM.prototype.openModal = function(mode) {
        'use strict';
         if (mode === 'edit') {
            FerozoHosting.apachehandlersVM().temp(this);
        } else {
            FerozoHosting.apachehandlersVM().temp(new ApacheHandlers());
        }
        $('#apuntar-nuevo').modal('show');
    };

    apachehandlersVM.prototype.init = function() {
        'use strict';
        var self = this;
        mediator.publish('refreshApachehandlersList');
   };


    return apachehandlersVM;
});
//-------- / DomainVM