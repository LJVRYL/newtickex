define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var Input = function(data) {
        'use strict';
        ko.mapping = mapping;
        this.type = 'Input';
        this.content = ko.observable('');
        this.error = ko.observable('');
        ko.mapping.fromJS(data, {}, this);
    };

    Input.prototype.clearErrors = function() {
        this.error('');
    };

    Input.prototype.reset = function() {
        this.error('');
        this.content('');
    };

    return Input;
});