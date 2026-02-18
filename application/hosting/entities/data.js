define(['knockout', 'ko.mapping'], function(ko, mapping) {
    /* ------------ INPUT -----------------*/
    var Data = function() {
        'use strict';

        ko.mapping = mapping;
        this.type = 'Data';
        this.content = ko.observableArray([]);
        this.isLoaded = ko.observable(0);
        this.error = ko.observable('');
    };

    Data.prototype.load = function(data) {
        var self = this;
        ko.mapping.fromJS(data, {}, self.content);
        self.isLoaded(1);
    };

    Data.prototype.reset = function() {
        var self = this;
        this.content([]);
    };

    return Data;
});