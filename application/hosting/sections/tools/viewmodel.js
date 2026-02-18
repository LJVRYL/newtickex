//'use strict';
define(['knockout'], function(ko) {

    var toolsVM = function() {
        'use strict';

        mediator.installTo(this);

        this.data = ko.observableArray([]);
        this.temp = ko.observable('');

        this.init = function() {
            'use strict';
            var self = this;
        };
    };

    return toolsVM;

});
