define(['knockout', 'ko.mapping'], function(ko, mapping) {

    var Hotlink = function(data) {
        'use strict';
        var self = this;

        mediator.installTo(self);
        ko.mapping = mapping;

        self.id = ko.observable();
        self.url = ko.observable();

        self.regStatus = ko.observable(1);
        self.rowstatus = ko.observable('0');//0=nada;1=delete

        ko.mapping.fromJS(data, {}, this);
    };

    Hotlink.prototype.getHotlinksVM = function() {
        return window.FerozoHosting.hotlinksVM();
    };

    Hotlink.prototype.remove = function() {
        this.regStatus(4);
        return this.getHotlinksVM().configure('remove', this);
    };

    Hotlink.prototype.save = function() {
        this.regStatus(2);
        this.getHotlinksVM().data().push(this);
        return this.getHotlinksVM().configure('save', this);
    };

    return Hotlink;
});