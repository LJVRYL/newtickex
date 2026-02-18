define(['knockout', 'ko.mapping'], function(ko, mapping) {
    /* ------------ DnsApp -----------------*/
    var DnsApp = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };
    //no va mas
    return DnsApp;
});