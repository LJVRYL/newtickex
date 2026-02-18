define(['knockout', 'ko.mapping', 'hosting', 'notifications', 'sort', 'translate'], function(ko, mapping, Hosting, Notifications, Sort, Translate) {
    var restoreVM = function() {
        var self = this;

        ko.mapping = mapping;
        

        this.inprocess = ko.observable(1);
        this.hostings = ko.observableArray([]);
        this.backUpType = ko.observable();
        this.userName = ko.observable();
        this.lastUpdate = ko.observable();
        this.updateFile = ko.observable();
        this.showResult = ko.observable(0);

        this.dailyWeeklyMontly = [
            {label: Translate('#trans-daily'), value: 'daily'},
            {label: Translate('#trans-weekly'), value: 'weekly'},
            {label: Translate('#trans-montly'), value: 'monthly'}
        ];

    };

    restoreVM.prototype.init = function() {
        this.listHostings();
    };

    restoreVM.prototype.listHostings = function() {
        var self = this;
        var theData = { "params": {
        }};

        self.inprocess(1);
        $.postJSON("/dhm/account/hosting/list", theData, function(data) {
            self.hostings([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    obj.visible = true;
                    self.hostings.push(new Hosting(obj));
                });
            }
        }).fail(function(data) {
            Notifications.error(Translate('#trans-cannot-get-config').getValue());
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    restoreVM.prototype.checkBackup = function () {
        var self = this;
        var theData = {"params": {
            "userName" : self.userName()[0],
            "backUpType" : self.backUpType()
        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/server/checkbkp", theData, function (data){
            self.lastUpdate(data.result.updateDate);
            self.updateFile(data.result.bkppath);
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
            self.showResult(1);
        });
    }

    restoreVM.prototype.restoreBackup = function () {
        var self = this;
        var theData = {"params": {
            "userName" : self.userName()[0],
            "backUpFile" : self.updateFile()
        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/server/restore", theData, function (data){
            //self.lastUpdate(data.result.updateDate);
            //self.updateFile(data.result.bkppath);
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
            self.showResult(0);
        });
    }
    return restoreVM;
});