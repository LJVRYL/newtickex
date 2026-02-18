define(['knockout', 'ko.mapping', 'notifications', 'translate'], function(ko, mapping, Notifications, Translate) {
    var BackupConfig = function(data) {
        this.regStatus = ko.observable(1);

        this.status = ko.observable();
        this.period = ko.observable();
        this.hour = ko.observable();
        this.minute = ko.observable();
        this.days = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    BackupConfig.prototype.getBackupConfigVM = function() {
        return FerozoDhm && FerozoDhm.backupsVM();
    };

    BackupConfig.prototype.save = function() {
        var self = this;
        var theData = { "params": self.toJS() };
        ko.utils.clearObservableErrors.bind(self).apply();

        self.getBackupConfigVM().inprocess(1);
        $.postJSON('/dhm/serverconfig/backup/config/set', theData, function(data) {
            if (data.result) {
                Notifications.success(Translate('#trans-save-success').getValue());
                self.getBackupConfigVM().flagDays(0);
            }
        }).fail(function(data) {
        }).always(function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            self.getBackupConfigVM().inprocess(0);
        });
    };

    BackupConfig.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return BackupConfig;
});