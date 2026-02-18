define(['knockout', 'ko.mapping', 'treeNavigation'], function(ko, mapping, TreeNavigation) {

    var webappsconfigVM = function() {
        'use strict';
        mediator.installTo(this);
        ko.mapping = mapping;

        this.treeNavigation = new TreeNavigation('/hosting/tools/webapplications/get');
    };

    webappsconfigVM.prototype.init = function() {
        'use strict';
        this.treeNavigation.list();
    };

    webappsconfigVM.prototype.enable = function(item) {
        var self = this;
        var theData = { "params": {
            "folderPath": self.treeNavigation.actualFolder() + '/' + item.name,
            "enable": !item.enabled
        }};

        item.inprocess(1);
        $.postJSON('/hosting/tools/webapplications/configure', theData, function(response) {
            if (response.error && response.error.data.inputException) {
            } else {
            }
            self.treeNavigation.list();
        }).always(function() {
            item.inprocess(0);
        });
    };

    return webappsconfigVM;
});