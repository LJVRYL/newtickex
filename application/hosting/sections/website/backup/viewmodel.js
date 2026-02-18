define(['knockout', 'ko.mapping', 'backup', 'mssqldb', 'mysqldb'], function(ko, mapping, Backup, Mssqldb, Mysqldb) {

    var BackupsVM = function() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new Backup());
        this.mysqlDbs = ko.observableArray([]);
        this.mssqlDbs = ko.observableArray([]);
        this.dbsSelected = ko.observableArray([]);
        this.maxFile = ko.observable(false);

        self.resetFlagMaxFile = function () {
            self.maxFile = ko.observable(false);
        }

        // self.worker = ko.computed(function () {
        //     if (self.temp().file()) self.resetFlagMaxFile();
        // }, this);

        this.dbsAvailableByFilter = ko.computed(function() {
            var dbs = [];
            self.dbsSelected([]);
            ko.utils.arrayForEach(self.mssqlDbs(), function(db) {
                (self.temp().type() === 'SqlServer2000' && db.databaseType() === 2 ||
                self.temp().type() === 'SqlServer2005' && db.databaseType() === 3 ||
                self.temp().type() === 'SqlServer2008' && db.databaseType() === 4 ||
                self.temp().type() === 'SqlServer2012' && db.databaseType() === 5 ||
                self.temp().type() === 'SqlServer2016' && db.databaseType() === 6) &&
                dbs.push(db);
            });
            ko.utils.arrayForEach(self.mysqlDbs(), function(db) {
                self.temp().type() === 'MySql' && db.databaseType() === 1 &&
                dbs.push(db);
            });
            return dbs;
        });

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.name == right.name ? 0 : (left.name < right.name ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.name == right.name ? 0 : (left.name > right.name ? -1 : 1);
                });
            }
        };

        this.subscribe('mysqlDBListUpdated', function(mysqlDBList) {
            var dbs = [];
            $.each(mysqlDBList, function() {
                this.regStatus === 1 && dbs.push(this);
            });

            var mapping = {
                create: function(options) {
                    return new Mysqldb(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(dbs, mapping, self.mysqlDbs);
        });

        this.subscribe('mssqlDBListUpdated', function(mssqlDBList) {
            var dbs = [];
            $.each(mssqlDBList, function() {
                this.regStatus === 1 && dbs.push(this);
            });

            var mapping = {
                create: function(options) {
                    return new Mssqldb(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(dbs, mapping, self.mssqlDbs);
        });

        this.init = function() {
            self.list();
            self.publish('refreshMssqlDBList');
            self.publish('refreshMysqlDBList');
        };

        this.restoreConfirm = function(entity, event, preventListDetails) {
            self.temp(entity);

            try {
                entity.winBehaviour.contentOfZip([]);
                entity.winBehaviour.restoreTodbName('');
                entity.winBehaviour.restoreTodbName.errors('');
                entity.winBehaviour.fileNameSelected('');
                entity.winBehaviour.detailsOfBackup(undefined);
            } catch (e) {
            }

            if (self.isWin()) {
                !preventListDetails && entity.listContentOfZip && entity.listContentOfZip();
                !preventListDetails && /\.(sql|bak)$/.test(entity.name()) && entity.listDetailsOfBackup({BackupFileName: entity.name()});
                /\.(sql|bak)$/.test(entity.name()) && entity.winBehaviour.fileNameSelected(entity.name());
            }
            self.temp().notifyEmail(FerozoHosting.profileVM().user().Contact());
            $('#modal-restore-backup-confirm').modal({backdrop: 'static'}, 'show');
        };
    };

    BackupsVM.prototype.changeMaxFile = function() {
        this.maxFile(true);
    };

    BackupsVM.prototype.displayBehaviour = function() {
        return /(.zip)$/.test(this.temp().name()) && this.isWin();
    };

    BackupsVM.prototype.displayDetails = function() {
        return /\.(bak|sql)$/.test(this.temp().name()) && this.isWin();
    };
    
    BackupsVM.prototype.isWin = function() {
        try {
            return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
        } catch(e) {
        }
    };

    BackupsVM.prototype.openCreate = function(entity, event) {
        this.temp(new Backup());
        $('#modal-create-backup').modal('show');
    };

    BackupsVM.prototype.openUpload = function(entity, event) {
        this.temp(new Backup());
        $('#modal-restore-backup').modal('show');
    };

    BackupsVM.prototype.copyToClipboard = function() {
		'use strict';
		var self = this;
        if (navigator.clipboard && window.isSecureContext) {
            if(this.isWin()) {
                switch (FerozoHosting.backupVM().temp().type()) {
                    case 'SqlServer2000':
                        navigator.clipboard.writeText('backup/mssql2000'); 
                        break;
                    case 'SqlServer2005':
                        navigator.clipboard.writeText('backup/mssql2005'); 
                        break;
                    case 'SqlServer2008':
                        navigator.clipboard.writeText('backup/mssql2008'); 
                        break;
                    case 'SqlServer2012':
                        navigator.clipboard.writeText('backup/mssql2012'); 
                        break;
                    case 'SqlServer2016':
                        navigator.clipboard.writeText('backup/mssql2016'); 
                        break;
                    case 'MySql':
                        navigator.clipboard.writeText('backup/mysql50'); 
                        break;
                }
            }
            else {
                switch (FerozoHosting.backupVM().temp().type()) {
                    case 'Full':
                        navigator.clipboard.writeText('backup/full'); 
                        break;
                    case 'Emails':
                        navigator.clipboard.writeText('backup/email'); 
                        break;
                    case 'MySql':
                        navigator.clipboard.writeText('backup/db'); 
                        break;
                }
            }  
        }
        else {
            const textarea = document.createElement('textarea');

            if(this.isWin()) {
                switch (FerozoHosting.backupVM().temp().type()) {
                    case 'SqlServer2000':
                        textarea.value = 'backup/mssql2000'; 
                        break;
                    case 'SqlServer2005':
                        textarea.value = 'backup/mssql2005'; 
                        break;
                    case 'SqlServer2008':
                        textarea.value = 'backup/mssql2008'; 
                        break;
                    case 'SqlServer2012':
                        textarea.value = 'backup/mssql2012'; 
                        break;
                    case 'SqlServer2016':
                        textarea.value = 'backup/mssql2016'; 
                        break;
                    case 'MySql':
                        textarea.value = 'backup/mysql50'; 
                        break;
                }
            }
            else {
                switch (FerozoHosting.backupVM().temp().type()) {
                    case 'Full':
                        textarea.value = 'backup/full'; 
                        break;
                    case 'Emails':
                        textarea.value = 'backup/email'; 
                        break;
                    case 'MySql':
                        textarea.value = 'backup/db'; 
                        break;
                }
            }
            
            textarea.style.position = 'absolute';
            textarea.style.left = '-99999999px';
            document.body.prepend(textarea);
            textarea.select();
          
            try {
              document.execCommand('copy');
            } catch (err) {
              console.log(err);
            } finally {
              textarea.remove();
            }
        }
    };

    BackupsVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {
        }};

        self.inprocess(1);
        $.postJSON('/hosting/tools/backup/list', theData, function(data) {
            self.data([]);
            if (data.result) {
                $.each(data.result, function(i, e) {
                    //e.regStatus =FerozoHosting.range(1,4);
                    self.data.push(new Backup(e));
                });
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

	return BackupsVM;
});