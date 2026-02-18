define(['knockout', 'ko.mapping', 'domain'], function(ko, mapping, Domain) {

    var wordpressmigratorVM = function() {
        'use strict';

        this.migrationCode = ko.observable('');
        this.wpUrl = ko.observable('');
        this.folder = ko.observable('');
        this.domainSelected = ko.observable('');
        this.migrationPercentage = ko.observable(0);
        this.migrationInProgress = ko.observable(false);
        this.migrationLogs = ko.observable('');
        this.migrationStatus = ko.observable('');
        this.domains = ko.observableArray([]);
        this.createFolderName = ko.observable(false);
        this.newFolder = ko.observable('');
        this.inprocess = ko.observable();
        this.data = ko.observable('');
    };

    wordpressmigratorVM.prototype.getMigrationCodeFromClipboard = function() {
        'use strict';
        var self = this;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.readText()
                .then(function(text) {
                    if (text.startsWith("fz+")) {
                        var urlIndex = text.indexOf('+url+');
                        if (urlIndex !== -1) {
                            var migrationCode = text.substring(3, urlIndex);
                            self.migrationCode(migrationCode);
                            var wpUrl = text.substring(urlIndex + 5); 
                            self.wpUrl(wpUrl);
                        }
                    }
                })
        }
    };
    
    wordpressmigratorVM.prototype.init = function() {
        'use strict';
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/webapp/wpmactive", function(data) {
            if (data.result) {
                self.migrationInProgress(data.result.active);
                self.listDomains();
                if (!self.migrationInProgress()) {
                    self.migrationCode('');
                    self.wpUrl('');
                    self.folder('');
                    self.migrationPercentage(0);
                    self.migrationStatus('');
                    self.getMigrationCodeFromClipboard();
                    self.getFolderTree();
                } else {
                    self.migrationCode(data.result.migrationCode);
                    self.wpUrl(data.result.wpUrl);
                    self.getMigrationStatus();  
                }
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    wordpressmigratorVM.prototype.listDomains = function() {
        var self = this;
        var theData = { "params": {

        }};
        self.inprocess(1);
        $.postJSON("/hosting/domain/listdomains", theData, function(data) {
            self.domains([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    obj.regStatus === 1 && self.domains.push(new Domain(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    wordpressmigratorVM.prototype.isMigrationActive = function() {
        var self = this;
        var theData = { "params": {
        }};
        self.inprocess(1);
        $.postJSON("/hosting/webapp/wpmactive", theData, function(data) {
            if (data.result) {
                self.migrationInProgress(data.result.active);
                if (self.migrationInProgress()) {
                    self.migrationCode(data.result.migrationCode);
                    self.wpUrl(data.result.wpUrl);
                    self.getMigrationStatus();
                }
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };
    
    wordpressmigratorVM.prototype.startMigration = function() {
        var self = this;
        var theData = { "params": {
            "wpUrl": self.wpUrl(),
            "migrationCode": self.migrationCode(),
            "folder": self.folder(),
            "idDomain": self.domainSelected().id
        }};
        self.inprocess(1);
        $('#form-wp input').next('.help-block.error').html('');
        $.postJSON("/hosting/webapp/wpmimport", theData, function(data) {
            if (data.error) {
                if (data.error.data.inputException) {
                    $.each(data.error.data.inputException, function() {
                        $('#form-wp input[data-bind="value: '+this.field+'"]').next('.help-block.error').html(this.errorDesc);
                    });
                }
            } else {
                self.isMigrationActive();
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    wordpressmigratorVM.prototype.getMigrationStatus = function() {
        var self = this;
        var loop = setInterval(function() {
            var theData = { "params": {
            }};
            $.postJSON("/hosting/webapp/wpmstatus", theData, function(data) {
                if (data.result) {
                    if (data.result.porcentaje != '') {
                        if (data.result.porcentaje > self.migrationPercentage()) {
                            self.migrationPercentage(data.result.porcentaje);
                            self.migrationStatus(data.result.description);
                        }
                        if (data.result.porcentaje == 100) 
                            clearInterval(loop);
                    }
                } else {
                    clearInterval(loop);
                }
            });
        },10000); 
    };

    wordpressmigratorVM.prototype.getMigrationLog = function() {
        var self = this;
        var theData = { "params": {
            "limit": 10 
        }};
        self.inprocess(1);
        self.migrationLogs('Loading...');
        $("#wp-migrate-log").modal();
        $('.modal select').change();
        $.postJSON("/hosting/webapp/wpmlog", theData, function(data) {
            if (data.result != '') {
                self.migrationLogs(data.result);
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    wordpressmigratorVM.prototype.getFullLog = function() {
        var self = this;
        var theData = { "params": {
            "limit": -1
        }};
        self.inprocess(1);
        $.postJSON("/hosting/webapp/wpmlog", theData, function(data) {
            if (data.result != '') {
                self.migrationLogs(data.result);
                $("#wp-migrate-log").modal();
                $('.modal select').change();
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    wordpressmigratorVM.prototype.showModalFolder = function() {
        var self = this;
        $('#fileNavigator').modal('show');
    };

    wordpressmigratorVM.prototype.getFolderTree = function(data) {
        'use strict';
        var self = this;
        var theData = { "params": {
            "folderPath": "/public_html",
            "onlyExtensions": [],
            "onlyFolders": true,
            "path": "/public_html"
        }};
        $.postJSON("/hosting/filemanager/customlist", theData, function(data) {
            if (data.result) {
                self.data(data.result);
            }
        });
    };

    wordpressmigratorVM.prototype.createFolder = function() {
        'use strict';
        var self = this;
        self.createFolderName(true);
    };

    wordpressmigratorVM.prototype.selectNewFolder = function() {
        'use strict';
        var self = this;
        FerozoHosting.wordpressmigratorVM().folder(FerozoHosting.wordpressmigratorVM().newFolder());
        FerozoHosting.wordpressmigratorVM().newFolder('');
        FerozoHosting.wordpressmigratorVM().createFolderName(false);
        $('#fileNavigator').modal('hide');
    };

    wordpressmigratorVM.prototype.selectPublic = function() {
        'use strict';
        var self = this;
        FerozoHosting.wordpressmigratorVM().folder('');
        $('#fileNavigator').modal('hide');
    };

    wordpressmigratorVM.prototype.selectFolder = function(name) {
        'use strict';
        var self = this;
        FerozoHosting.wordpressmigratorVM().folder(name.name);
        $('#fileNavigator').modal('hide');
    };

    return wordpressmigratorVM;
});