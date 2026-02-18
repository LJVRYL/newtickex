define(['knockout', 'ko.mapping'], function(ko, mapping) {

    var Folder = function(data) {

        ko.mapping = mapping;

        this.name = null;
        this.day = null;
        this.extras = null;
        this.group = null;
        this.month = null;
        this.number = null;
        this.rights = null;
        this.size = null;
        this.time = null;
        this.type = null;
        this.user = null;
        this.visible = ko.observable(true);

        ko.mapping.fromJS(data, {}, this);
    };

    return Folder;
});