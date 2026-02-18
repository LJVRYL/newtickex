define(['knockout', 'ko.mapping', 'treeNavigation'], function(ko, mapping, TreeNavigation) {

    var diskusageVM = function() {
        'use strict';
        mediator.installTo(this);
        ko.mapping = mapping;

        //this.data = ko.observableArray();
        this.treeNavigation = new TreeNavigation('/hosting/tools/disk/size/get');
        this.treeNavigation.rawMode = true;
    };

    diskusageVM.prototype.init = function() {
        'use strict';
        this.treeNavigation.list(true);
    };

    return diskusageVM;
});