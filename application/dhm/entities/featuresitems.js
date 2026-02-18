define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var FeaturesItems = function(data) {
        var self = this;

        this.id = ko.observable();
        this.feature = ko.observable();
        
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    return FeaturesItems;
});