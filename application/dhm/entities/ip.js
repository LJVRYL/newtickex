define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var IP = function(data) {

        this.regStatus = ko.observable(1);
        this.rowstatus = ko.observable();

        this.id = ko.observable();
        this.broadcast = ko.observable();
        this.ipaddress = ko.observable();
        this.name = ko.observable();
        this.netmask = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    IP.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return IP;
});