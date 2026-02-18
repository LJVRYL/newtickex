define(['knockout', 'ko.mapping', 'treeNavigation'], function(ko, mapping, TreeNavigation) {

    var indexhandlersVM = function() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;
        this.inprocess = ko.observable(0);
        this.treeNavigation = new TreeNavigation('/hosting/filemanager/handlers/index/list');
        
        /** FILTRO DE TABLA POR JAVASCRIPT **/
        this.search = function(value) {
            value = typeof value === 'string' && value.trim() || '';
            var regex = new RegExp(value);
            ko.utils.arrayForEach(self.treeNavigation.data(), function(obj) {
                obj.visible(false);
                if (obj.name.match(regex)) {
                    obj.visible(true);
                }
            });
        };
        this.query = ko.observable('');
        this.query.subscribe(this.search);
        /** /FILTRO DE TABLA POR JAVASCRIPT **/ 
        
    };

    indexhandlersVM.prototype.applyThis = function(indexType, folderItem, event) {
        'use strict';
        var self = this;

        var folderClicked = typeof folderItem === 'object' ? folderItem.name : folderItem;
        var target = self.treeNavigation.actualFolder() + '/' + folderClicked;
        var theData = { "params": {
            "indexType": parseInt(indexType),
            "path": target
        }};
        var actionsContainer = $(event.target).parent('.index-handlers-actions');
        var actionsContainerSaving = $(event.target).parents(".right").find(".index-handlers-actions-saving");

        actionsContainer.hide(0);
        actionsContainer.find('.btn').removeClass('btn-primary');
        actionsContainerSaving.show(0);

        $.postJSON('/hosting/tools/handlers/index/set', theData, function() {
            $(event.target).addClass('btn-primary');
        }).always(function() {
            actionsContainerSaving.hide(0);
            actionsContainer.show(0);
        });
    };

    indexhandlersVM.prototype.init = function() {
        this.treeNavigation.list();
    };

    return indexhandlersVM;
});
//-------- / indexhandlersVM