define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var DnsMx = function(data) {
        'use strict';
        mediator.installTo(this);
        ko.mapping = mapping;
        this.code = ko.observable('');
        var map = {
            'status': {
                create: function(options) {
                    if (options.data) {
                        return 'active';
                    } else {
                        return '';
                    }
                }
            }
        };
        ko.mapping.fromJS(data, map, this);
    };
    //no va mas
    return DnsMx;
});