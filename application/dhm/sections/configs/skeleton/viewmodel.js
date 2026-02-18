define(['knockout', 'notifications'], function(ko) {
    var skeletonVM = function() {
        this.inprocess =  ko.observable(0);
    };

    skeletonVM.prototype.init = function() {
    };

    return skeletonVM;
});