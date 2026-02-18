define(['knockout', 'ko.mapping', 'accessrecord'], function(ko, mapping, AccessRecord) {

    var statisticsVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        self.statisticsStatus = ko.observable();
        self.statisticsEnableMode = ko.observable();

        self.accessRecord = ko.observable(new AccessRecord);

        this.statisticWindowsSummary = ko.observable();
        this.statisticWindowsFiles = ko.observableArray([]);
        this.statisticWindowsFileSelected = ko.observable();

        this.inprocess = ko.observable();
        this.isDefault = ko.observable(true);

        // this.subscribe('refreshStatisticsStatus', statisticsVM.prototype.getStatisticsStatus);
        // this.subscribe('refreshAccessRcdConfig', statisticsVM.prototype.getAccessRecordsConfig);
    };

    statisticsVM.prototype.downloadSummary = function() {
        var ctrlPath = '/hosting/tools/webstatistics/windows/download/';
        try {
            if (this.statisticWindowsSummary().filename) {
                window.open(ctrlPath + this.statisticWindowsSummary().filename, "_blank");
            }
        } catch (err) {}
    };

    statisticsVM.prototype.downloadFile = function() {
        var ctrlPath = '/hosting/tools/webstatistics/windows/download/';
        try {
            if (this.statisticWindowsFileSelected().filename) {
                window.open(ctrlPath + this.statisticWindowsFileSelected().filename, "_blank");
            }
        } catch (err) {}
    };

    // statisticsVM.prototype.getStatisticsStatus = function() {
    //     var self = this;
    //     self.inprocess(1);
    //     $.postJSON("/hosting/tools/statistics/webstats/list", function(data) {
    //         if (data.result) {
    //             self.isDefault(!!data.result.isDefault);
    //             self.statisticsEnableMode(parseInt(data.result.configValue.enableMode));
    //             self.statisticsStatus(parseInt(data.result.regStatus));
    //         }
    //         // if (FerozoHosting.profileVM().user().Server.OpSystem() === 'Windows') {
    //         //     self.getStatisticsWindows();
    //         // }
    //     }).always(function(data) {
    //         self.inprocess(0);
    //     });
    // };

    // statisticsVM.prototype.getStatisticsWindows = function() {
    //     var self = this;
    //     self.inprocess(1);
    //     $.postJSON("/hosting/tools/webstatistics/windows/list", function(data) {
    //         if (data.result) {
    //             if (data.result.summary) {
    //                 self.statisticWindowsSummary(data.result.summary);
    //             }
    //             if (data.result.webstatistics) {
    //                 $.each(data.result.webstatistics, function() {
    //                     self.statisticWindowsFiles.push(this);
    //                 });
    //             }
    //         }
    //     }).always(function(data) {
    //         self.inprocess(0);
    //     });
    // };

    statisticsVM.prototype.getWebalizerLink = function() {
        var self = this;
        if (self.statisticsEnableMode()) {
            var domain = FerozoHosting.profileVM().user().PpalDomain.Name();
            var user = FerozoHosting.profileVM().user().UserName();
            var pass = FerozoHosting.profileVM().user().Password();
            return {
                "action": 'https://' + domain + ':2092/webstat/webalizer.php',
                "username": user,
                "fzpassword": pass
            };
        }
    };

    statisticsVM.prototype.delay = function(time, callback) {
        var pid;
        pid = setInterval(function() {
            typeof callback === 'function' && callback.apply();
            clearInterval(pid);
        }, time);
    };

    statisticsVM.prototype.saveStatisticsStatus = function() {
        var self = this;
        self.delay(300, function() {
            var theData = { "params": {
                "enableMode": self.statisticsEnableMode()
            }};
            self.inprocess(1);
            $.postJSON("/hosting/tools/statistics/webstats/configure", theData, function(data) {
                // self.getStatisticsStatus();
            }).always(function(data) {
                self.inprocess(0);
            });
        });
    };

    /**********************************************************************************/
    /**********************************************************************************/
    /**********************************************************************************/
    /**********************************************************************************/
    /**********************************************************************************/
    // statisticsVM.prototype.getAccessRecordsConfig = function() {
    //     var self = this;
    //     self.inprocess(1);
    //     $.postJSON("/hosting/tools/statistics/accessrecords/list", function(data) {
    //         if (data.result) {
    //             self.accessRecord(new AccessRecord(data.result.configValue));
    //         }
    //     }).always(function(data) {
    //         self.inprocess(0);
    //     });
    // };

    statisticsVM.prototype.downloadAccessRecords = function() {
        window.location.href = '/hosting/tools/statistics/accessrecords/download';
    };

    statisticsVM.prototype.init = function() {
        'use strict';
        mediator.publish('refreshStatisticsStatus');
        mediator.publish('refreshAccessRcdConfig');
   };

    return statisticsVM;
});