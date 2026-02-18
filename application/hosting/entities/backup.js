define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var Backup = function(data) {
        'use strict';
        var self = this;

        mediator.installTo(self);
        ko.mapping = mapping;

        self.id = ko.observable();
        self.created = ko.observable();
        self.name = ko.observable('...');
        self.type = ko.observable();
        self.includeLogs = ko.observable();
        self.regStatus = ko.observable(1);
        self.rowstatus = ko.observable('0');//0=nada;1=delete

        self.osType = ko.observable('Linux');
        self.backupType = ko.observable('Text');
        self.notifyEmail = ko.observable();
        self.sqlBackupMode = ko.observable();

        self.file = ko.observable('');

        self.winBehaviour = {
            loadingDetailsZip: ko.observable(false),
            loadingDetailsBackup: ko.observable(false),
            contentOfZip: ko.observableArray([]),
            restoreTodbName: ko.observable(),
            detailsOfBackup: ko.observable(),
            fileNameSelected: ko.observable(),
            detailsOfBackupList: function() {
                var list = [];
                var orig = this.detailsOfBackup();
                for (var i in orig) {
                    if (orig.hasOwnProperty(i)) {
                        list.push({
                            "key": i,
                            "value": orig[i]
                        });
                    }
                }
                return list;
            }
        };

        self.backupDownloadUrl = function() {
            var mainDomain = FerozoHosting.profileVM().user().PpalDomain.Name();

            var folder = '';
            if (self.type() === 'Emails') folder = 'email';
            if (self.type() === 'MySql') folder = 'db';
            if (self.type() === 'Full') folder = 'full';

            return 'ftp://' + mainDomain + '/backup/' + folder + '/' + self.name();
        };

        self.restoreUrl = '/hosting/tools/backup/linux/restore';

        ko.mapping.fromJS(data, {}, this);

        self.linuxTypes = [{
                "value": "MySql",
                "label": "DB MySQL"
            }, {
                "value": "Emails",
                "label": "Emails"
            }, {
                "value": "Full",
                "label": "Full (emails, db, cont.)"
            }
        ];

        self.windowsTypes = [{
                "value": "MySql",
                "label": "DB MySQL"
            }, {
                "value": "SqlServer2016",
                "label": "DB SQL Server 2016"
            }, {
                "value": "SqlServer2012",
                "label": "DB SQL Server 2012"
            }, {
                "value": "SqlServer2008",
                "label": "DB SQL Server 2008"
            }, {
                "value": "SqlServer2005",
                "label": "DB SQL Server 2005"
            }, {
                "value": "SqlServer2000",
                "label": "DB SQL Server 2000"
            }
        ];

        self.backupModes = [{
                "value": "Binary",
                "label": "Binary"
            }, {
                "value": "Text",
                "label": "Text"
            }
        ];

        self.backupModes2019 = [{
            "value": "Binary",
            "label": "Binary"
        }
    ];
    };

    Backup.prototype.getBackupVM = function() {
        return window.FerozoHosting.backupVM();
    };

    Backup.prototype.remove = function(entity, event) {
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};
        self.regStatus(4);
        self.getBackupVM().inprocess(1);
        $.postJSON('/hosting/tools/backup/remove', theData, function(data) {
            if (data.error && data.error.data.inputException) {
            } else if (data.result) {
                //self.getBackupVM().list();
            }
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            self.getBackupVM().inprocess(0);
            data.error && self.regStatus(1);
        });
    };

    Backup.prototype.listContentOfZip = function() {
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "idBackup": self.id()
        }};

        if (! /(.zip)$/.test(self.name())) {
            return;
        }

        self.winBehaviour.loadingDetailsZip(true);
        self.winBehaviour.contentOfZip([]);
        self.winBehaviour.restoreTodbName('');
        self.getBackupVM().inprocess(1);
        $.postJSON('/hosting/tools/backup/windows/get-files', theData, function(data) {
            if (data.error && data.error.data.inputException) {
            } else if (data.result) {
                data.result.ZipFileContents &&
                self.winBehaviour.contentOfZip(data.result.ZipFileContents);
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.winBehaviour.loadingDetailsZip(false);
            self.getBackupVM().inprocess(0);
        });
    };

    Backup.prototype.listDetailsOfBackup = function(entity, event) {
        var self = this;
        //self.winBehaviour.fileNameSelected(ko.utils.peekObservable(entity.BackupFileName));
        var theData = { "params": {
            "id": self.id(),
            "idBackup": self.id(),
            "backupFileName": self.winBehaviour.fileNameSelected()
        }};

        if (! /\.(bak|sql|BAK)$/.test(self.winBehaviour.fileNameSelected())) {
            return;
        }
       
        self.winBehaviour.loadingDetailsBackup(true);
        self.winBehaviour.restoreTodbName('');
        self.winBehaviour.detailsOfBackup(undefined);
        self.getBackupVM().inprocess(1);
        $.postJSON('/hosting/tools/backup/windows/get-file-info', theData, function(data) {
            if (data.error && data.error.data.inputException) {
            } else if (data.result) {
                data.result.BackupInfo &&
                self.winBehaviour.detailsOfBackup(data.result.BackupInfo);
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.winBehaviour.loadingDetailsBackup(false);
            self.getBackupVM().inprocess(0);
        });
    };

    Backup.prototype._saveWindows = function() {
        var self = this;

        var theData = { "params": {
            "dbNames": FerozoHosting.backupVM() && FerozoHosting.backupVM().dbsSelected(),
            "backupType": self.type(),
            "notifyEmail": self.notifyEmail(),
            "includeLogs": self.includeLogs(),
            "sqlBackupMode": self.sqlBackupMode()
        }};

        self.getBackupVM().inprocess(1);
        $('.help-block.error').html('');
        $.postJSON('/hosting/tools/backup/windows/generate', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $('.help-block.error').html('');
                $.each(data.error.data.inputException, function() {
                    this.field = this.field === 'backupType' ? 'type' : this.field;
                    $('input[name^="' + this.field + '"], span#'+this.field).parent().parent().find('.help-block.error').html(this.errorDesc);
                });
            } else if (data.result) {
                self.getBackupVM().list();
                $('#modal-create-backup').modal('hide');
            }
        }).always(function() {
            self.getBackupVM().inprocess(0);
            //self.regStatus(1);
        });
    };

    Backup.prototype.isWin = function() {
        try {
            return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
        } catch(e) {
        }
    };

    Backup.prototype._saveLinux = function() {
        var self = this;
        var theData = { "params": {
            "backupType": self.type(),
            "notifyEmail": self.notifyEmail(),
            "includeLogs": self.includeLogs()
        }};

        self.getBackupVM().inprocess(1);
        $.postJSON('/hosting/tools/backup/linux/generate', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $('.help-block.error').html('');
                $.each(data.error.data.inputException, function() {
                    this.field = this.field === 'backupType' ? 'type' : this.field;
                    $('input[name^="' + this.field + '"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                });
            } else if (data.result) {
                self.getBackupVM().list();
                $('#modal-create-backup').modal('hide');
            }
        }).always(function() {
            self.getBackupVM().inprocess(0);
            //self.regStatus(1);
        });
    };

    Backup.prototype.save = function() {
        return this.isWin() ? this._saveWindows() : this._saveLinux();
    };

    Backup.prototype.restore = function() {
        var self = this;
        var theData = { "params": {
            "idBackup": self.id(),
            "notifyEmail": self.notifyEmail()
        }};

        self.getBackupVM().inprocess(1);
        $.postJSON(self.restoreUrl, theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $('.help-block.error').html('');
                $.each(data.error.data.inputException, function() {
                    $('input[name^="' + this.field + '"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                });
            } else if (data.result) {
                Notifications.success($('#backup-msg-restore-launched').html());
                window.location.href = '/logout';
                $('#modal-restore-backup-confirm').modal('hide');
            }
        }).fail(function() {
            Notifications.error($('#backup-msg-restore-error').html());
        }).always(function() {
            self.getBackupVM().inprocess(0);
        });
    };

    Backup.prototype.restoreWindowsBeahaviour = function() {
        var self = this;

        var theData = { "params": {
            "idBackup": self.id(),
            "backupFileName": self.winBehaviour.fileNameSelected(),
            "databaseName": self.winBehaviour.restoreTodbName(),
            "notifyEmail": self.notifyEmail()
        }};

        self.getBackupVM().inprocess(1);
        ko.utils.clearObservableErrors.bind(self.winBehaviour).apply();
        $.postJSON('/hosting/tools/backup/windows/restore', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self.winBehaviour, data.error.data.inputException, function(obj) {
                    obj.field === 'databaseName' && (obj.field = 'restoreTodbName');
                }).apply();
            } else if (data.result) {
                Notifications.success($('#backup-msg-restore-launched').html());
                //window.location.href = '/logout';
                $('#modal-restore-backup-confirm').modal('hide');
            }
        }).fail(function() {
            Notifications.error($('#backup-msg-restore-error').html());
        }).always(function() {
            self.getBackupVM().inprocess(0);
        });
    };

    Backup.prototype.restoreFromFile = function(form) {
        var self = this;
        var restoreFileUploadUrl = self.isWin() ? '/hosting/tools/backup/windows/upload/file' : '/hosting/tools/backup/linux/restore/file';
        
        if (! self.file()) {
            return;
        }

        $('.help-block.error').html('');

        var formFile = new FormData(form);
        var fileData = Array.from(formFile.entries());

        if(fileData[0][1].size > 10000000) {
            FerozoHosting.backupVM().changeMaxFile();
            return;
        }

        self.getBackupVM().inprocess(1);
        $.ajax({
            "url": restoreFileUploadUrl,
            "type": 'POST',
            success: function(data) {
                if (data.error) {
                    if (data.error.data && data.error.data.userException) {
                        Notifications.error(data.error.data.userException.value);
                    }
                    if (data.error.data && data.error.data.inputException) {
                        $.each(data.error.data.inputException, function() {
                            $('input[name^="' + this.field + '"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                        });
                    }
                } else {
                    if (!self.isWin()) {
                        Notifications.success($('#backup-msg-restore-launched-file').html());
                        window.location.href = '/logout';
                    }
                    Notifications.success($('#backup-msg-upload-success').html());
                    $('#modal-restore-backup').modal('hide');
                }
                self.isWin() && self.getBackupVM().list();
                self.getBackupVM().inprocess(0);
            }, error: function() {
                Notifications.error($('#backup-msg-error-upload').html());
                self.getBackupVM().inprocess(0);
            },
            headers: { accept: 'application/json' },
            "data": new FormData(form),
            "cache": false,
            "contentType": false,
            "processData": false,
            "accepts": {
                "text": 'application/json',
                "json": 'application/json'
            }
        }, 'json');

    };

    return Backup;
});