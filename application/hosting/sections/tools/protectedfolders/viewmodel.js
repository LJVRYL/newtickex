define(['knockout', 'ko.mapping', 'folder', 'input'], function(ko, mapping, Folder, Input) {

    var ProtectedFoldersVM = function() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;
        this.data = ko.observableArray([]);

        this.temp = ko.observable();
        this.tempUsers = ko.observableArray();
        this.inprocessAddUser = ko.observable(false);
        this.inprocess = ko.observable(false);
        this.tempUser = {
            "user": new Input(),
            "pass": new Input()
        };

        this.currentArray = ko.observable();
        self.actualFolder = ko.observable("/public_html");

        this.saveDisabled = ko.observable();
        
        /** FILTRO DE TABLA POR JAVASCRIPT **/
        this.search = function(value) {
            value = typeof value === 'string' && value.trim() || '';
            var regex = new RegExp(value);
            ko.utils.arrayForEach(self.data(), function(obj) {
                obj.visible(false);
                if (obj.name.match(regex)) {
                    obj.visible(true);
                }
            });
        };
        this.query = ko.observable('');
        this.query.subscribe(this.search);
        /** /FILTRO DE TABLA POR JAVASCRIPT **/        
        
        this.selectThis = function(folderItem, click) {
            'use strict';
            var folderClicked = (typeof folderItem == "object") ? folderItem.name : folderItem;
            var target = self.actualFolder() + '/' + folderClicked;
            self.data([]);
            self.actualFolder(target);
            self.list();
        };

        this.gotoDir = function(index) {
            FerozoHosting.protectedfoldersVM().currentArray();
            var path = "";
            for(var i = 1; i < FerozoHosting.protectedfoldersVM().currentArray().length; i++) {
                if (i <= index) {
                    path += "/" + FerozoHosting.protectedfoldersVM().currentArray()[i];
                }
            }
            self.data([]);
            self.actualFolder(path);
            self.list();
        };


        this.upDir = function(folderItem, click) {
            'use strict';
            self.data([]);
            FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocess(1);
            var actFolder = self.currentArray(); actFolder.pop();
            self.actualFolder(actFolder.join().replace(/,/g, "/"));
            //mediator.publish('refreshFolder');
            self.list();
        };

        this.removeUser = function(entity, event) {
            var theData = { "params": {
                "name": entity,
                "path": self.actualFolder()+"/"+self.temp().name
            }};
            $.postJSON("/hosting/tools/removefolderuser", theData, function(data) {
                self.list();
                self.tempUsers.remove(entity);
            });
        };

        this.addUser = function(entity, event) {
            FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocessAddUser(1);
            var theData = { "params": {
                "name": self.tempUser.user.content(),
                "pass": self.tempUser.pass.content(),
                "path": self.actualFolder()+"/"+self.temp().name
            }};

            $('.help-block.error').html('');
            $.postJSON("/hosting/tools/addfolderuser", theData, function(data) {
                if (data.error && data.error.data.inputException) {
                    $.each(data.error.data.inputException, function() {
                        $('input[name^="' + this.field + '"]').parent().find('.help-block.error').html(this.errorDesc);
                    });
                } else if (data.result) {
                    self.tempUsers.push(
                        self.tempUser.user.content()
                 );
                    self.tempUser.user.reset();
                    self.tempUser.pass.reset();
                }
            }).always(function(data) {
                FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocessAddUser(0);
            });
        };

        this.save = function(entity, event) {
            FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocess(1);
            var theData = { "params": {
                "enabled": self.temp().extras.protectedfolders.enabled(),
                "authName": self.temp().extras.protectedfolders.name(),
                "path": self.actualFolder()+"/"+self.temp().name
            }};
            $.postJSON("/hosting/tools/protectfolder", theData, function(data) {
                self.closeModal();
                FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocess(0);
                self.list();
            }).always(function(data) {
                FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocess(0);
            });
        };

        this.cancel = function() {
            self.list();
            self.closeModal();
        };
        
        this.disableSave = function() {
            self.saveDisabled(true);
        };
        
        this.enableSave = function() {
            self.saveDisabled(false);
        };
        
    };
    
    ProtectedFoldersVM.prototype.init = function() {
        'use strict';
        FerozoHosting.protectedfoldersVM().list();
    };

    ProtectedFoldersVM.prototype.list = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "path": self.actualFolder(),
            "onlyFolders": true
        }};
        FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocess(1);
        $.postJSON("/hosting/filemanager/protectedfolders/list", theData, function(data) {
            if (data.result) {
                self.data([]);
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.data.push(new Folder(obj));
                });
            }            
            self.currentArray(self.actualFolder().split('/'));
        }).always(function(data) {
            FerozoHosting.protectedfoldersVM() && FerozoHosting.protectedfoldersVM().inprocess(0);
        });
    };

    ProtectedFoldersVM.prototype.openModal = function(entity, event) {
        FerozoHosting.protectedfoldersVM().disableSave();
        if (entity.extras.protectedfolders.name === "") {
            entity.extras.protectedfolders.name = entity.name;
        }
        FerozoHosting.protectedfoldersVM().temp(entity);
        FerozoHosting.protectedfoldersVM().tempUsers(entity.extras.protectedfolders.users);
        FerozoHosting.protectedfoldersVM().tempUser.user.reset();
        FerozoHosting.protectedfoldersVM().tempUser.pass.reset();
        $('#manage-protectedfolder').modal('show');
    };

    ProtectedFoldersVM.prototype.closeModal = function(entity, event) {
        $('#manage-protectedfolder').modal('hide');
    };

    return ProtectedFoldersVM;
});