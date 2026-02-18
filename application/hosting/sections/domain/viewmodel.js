define(['knockout', 'domain'], function(ko, Domain) {
     /* [VM] DomainVM
     --------------------------------------------------
     */

    return function DomainVM() {
        'use strict';

        mediator.installTo(this);

        this.data = ko.observableArray([]);
        this.temp = ko.observable('');

        this.sections = ko.observableArray([]);

        this.savenew = function(callback, evento) {
            'use strict';
            var self = this;
            var newDomain = {
                "params": {
                    "domain": self.temp()
                }
            };
            $.postJSON('/hosting/domain/parkdomain', newDomain, function() {
                self.data.push(new Domain('', self.temp(), ''));
                self.init();
                $('.modal').modal('hide');
            });
        };

        this.init = function() {
        };

        this.sort = function() {
        };

        this.modify = function(e) {
        };
    };
});