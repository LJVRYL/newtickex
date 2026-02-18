define(['knockout', 'ko.mapping', 'hosting', 'notifications', 'sort', 'backupconfig', 'translate'], function(ko, mapping, Hosting, Notifications, Sort, BackupConfig, Translate) {
    var backupsVM = function() {
        var self = this;

        ko.mapping = mapping;
        

        this.inprocess = ko.observable(1);
        this.backupConfig = ko.observable(new BackupConfig());
        this.hostings = ko.observableArray([]);
        this.hostingsSelected = ko.observableArray([]);
        this.actualExclude = ko.observableArray([]);
        this.flagDays = ko.observable(0);

        this.sortByHostings = new Sort(this.hostings, 'user');

        /** FILTRO DE TABLA POR JAVASCRIPT **/
        this.search = function(value) {
            value = typeof value === 'string' && value.trim() || '';
            var regex = new RegExp(value);
            ko.utils.arrayForEach(self.hostings(), function(hosting) {
                hosting.visible(false);
                if (hosting.user().match(regex)) {
                    hosting.visible(true);
                }
            });
        };
        this.query = ko.observable('');
        this.query.subscribe(self.search);
        /** /FILTRO DE TABLA POR JAVASCRIPT **/

        this.enableDisable = [
            {label: Translate('#trans-disabled'), value: 'disabled'},
            {label: Translate('#trans-enabled'), value: 'enabled'}
        ];

        this.daysOfWeek = [
            {label: Translate('#trans-monday'), value: 'Mon'},
            {label: Translate('#trans-tuesday'), value: 'Tue'},
            {label: Translate('#trans-wednesday'), value: 'Wed'},
            {label: Translate('#trans-thursday'), value: 'Thu'},
            {label: Translate('#trans-friday'), value: 'Fri'},
            {label: Translate('#trans-saturday'), value: 'Sat'},
            {label: Translate('#trans-sunday'), value: 'Sun'}
        ];

        this.dailyWeeklyMontly = [
            {label: Translate('#trans-daily'), value: 'daily'},
            {label: Translate('#trans-weekly'), value: 'weekly'},
            {label: Translate('#trans-montly'), value: 'monthly'}
        ];

    };

    backupsVM.prototype.init = function() {
        this.list();
        this.listHostings();
    };

    backupsVM.prototype.selectAllHostings = function() {
        var self = this;
        this.hostingsSelected([]);
        ko.utils.arrayForEach(self.hostings(), function(obj) {
            self.hostingsSelected.push(obj.id().toString());
            
        });
    };

    backupsVM.prototype.unselectAllHostings = function() {
        this.hostingsSelected([]);
    };

    backupsVM.prototype.getHostingsSelectedIds = function() {
        var ids = [];
        ko.utils.arrayForEach(this.hostingsSelected(), function(hosting) {
            hosting.id && ids.push(hosting.id());
        });
        return ids;
    };

    backupsVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/backup/config/list", theData, function(data) {
            if (data.result && data.result.configValue) {
                self.backupConfig(new BackupConfig(data.result.configValue));
                if (data.result.configValue.days == '') {
                    self.flagDays(1);
                }
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    backupsVM.prototype.listHostings = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/account/hosting/list", theData, function(data) {
            self.hostings([]);
            self.actualExclude([]);
            self.hostingsSelected([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    obj.visible = true;
                    self.hostings.push(new Hosting(obj));

                    if(obj.excludeFromBackup){
                        self.actualExclude.push(obj.user);
                        self.hostingsSelected.push(obj.id.toString());
                    }
                });
            }
        }).fail(function(data) {
            Notifications.error(Translate('#trans-cannot-get-config').getValue());
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    backupsVM.prototype.openModalSkippeds = function() {
        $('#modal-hostings').modal('show');
    };

    backupsVM.prototype.saveSkippeds = function() {
        var self = this;
        var theData = { "params": { 
            "exclude" : self.hostingsSelected()
        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/backup/config/exclude/save", theData, function(data) {
            if (data.result) {
                $('#modal-hostings').modal('hide'); //EJEMPLO
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
            self.init();
        });
    };

    return backupsVM;
});