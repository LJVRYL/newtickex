define(['knockout'], function(ko) {
    /* rawMode Omite el agregado de inprocess a cada elemento del array de respuesta */
    var TreeNavigation = function(listUrl) {
        var self = this;

        this.listUrl = listUrl || '/hosting/filemanager/customlist';
        this.createFolderUrl = '/hosting/filemanager/item/folder/create';
        this.onlyFolders = true;
        this.onlyExtensions = [];
        this.allowSelectedFiles = false;
        this.allowSelectedFolders = true;
        this.rawMode = false;

        this.inprocess = ko.observable(0);
        this.selected = ko.observable("");
        this.currentArray = ko.observable();
        this.actualFolder = ko.observable("/public_html");
        this.data = ko.observableArray();
        this.createFolderName = ko.observable('');

        this.init = function() {
        };

        this.reset = function() {
            this.selected("");
            this.currentArray();
            this.actualFolder("/public_html");
            this.data([]);
        };

        this.isSelected = function(folderItem) {
            if (folderItem.type === 'dir' && self.allowSelectedFolders) {
                return true;
            }
            if (folderItem.type !== 'dir' && self.allowSelectedFiles) {
                return true;
            }
            return false;
        };

        this.list = function() {
            'use strict';

            var theData = { "params": {
                "path": self.actualFolder(),
                "folderPath": self.actualFolder(),
                "onlyFolders": self.onlyFolders,
                "onlyExtensions": self.onlyExtensions
            }};

            self.inprocess(1);
            $.postJSON(self.listUrl, theData, function(data) {
                self.data([]);
                if (self.rawMode) {
                    self.data(data.result);
                } else {
                    for (var item in data.result) {
                        item = data.result[item];
                        item.inprocess = ko.observable(0);
                        item.visible = ko.observable(true);
                        self.data.push(item);
                    }
                }
                self.currentArray(self.actualFolder().split('/'));
            }).always(function() {
                self.inprocess(0);
            });
        };

        this.selectThis = function(entity, event) {
            var folderClicked = (typeof entity === "object") ? entity.name : entity;
            var target = self.actualFolder() + '/' + folderClicked;
            self.data([]);
            self.actualFolder(target);
            self.list();
        };

        this.selectItem = function(callback, entity, event) {
            var folderClicked = typeof entity === 'object' ? entity.name : entity;
            var target = self.actualFolder() + '/' + folderClicked;
            self.data([]);
            if (entity.type === 'dir') {
                self.actualFolder(target);
            }
            self.selected(target);

            typeof callback === 'function' && callback(self);
        };

        this.gotoDir = function(index) {
            var path = "";
            for (var i = 1; i < self.currentArray().length; i++) {
                if (i <= index) {
                    path += "/" + self.currentArray()[i];
                }
            }
            self.data([]);
            self.actualFolder(path);
            self.list();
        };

        this.upDir = function() {
            var actFolder = self.currentArray();
            self.data([]);
            actFolder.pop();
            self.actualFolder(actFolder.join().replace(/,/g, "/"));
            self.list();
        };

        this.createFolder = function() {
            if (! self.createFolderName().trim()) {
                return false;
            }

            var theData = { "params": {
                "path": self.actualFolder(),
                "name": self.createFolderName()
            }};

            self.inprocess(1);
            $.postJSON(self.createFolderUrl, theData, function(data) {
                if (data.result) {
                    self.list();
                    self.createFolderName('');
                }
            }).always(function() {
                self.inprocess(0);
            });;
        };
    };

    return TreeNavigation;
});