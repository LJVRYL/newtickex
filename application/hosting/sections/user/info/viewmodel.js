define(['knockout'], function(ko) {
    var InfoVM = function() {
        'use strict';
        var self = this;

        self.data = ko.observableArray();
        self.dataAccntSize = ko.observableArray();
        self.dataDbInfo = ko.observableArray();

        self.listAccountInfo = function() {
            var result = [];
            for (var item in self.data().AccountInfo) {
                item !== 'Plan' && item !== 'Package' && result.push({
                    "key": item,
                    "value": self.data().AccountInfo[item]
                });
            }
            return result;
        };

        self.listServerInfo = function() {
            var result = [];
            for (var item in self.data().ServerInfo) {
                result.push({
                    "key": item,
                    "value": self.data().ServerInfo[item]
                });
            }
            return result;
        };

        mediator.installTo(this);

        self.init = function() {
            'use strict';
            self.updateAccountInfo();
            var cron = window.setInterval(function() {
                self.updateDatabasesInfo();
                setTimeout( function() {
                    self.updateAccountSizeInfo();
                }, 6000);
                window.clearInterval(cron);
            }, 1000);
        };

        self.updateAccountInfo = function() {
            $.postJSON("/hosting/account/getfullinfo", function(data) {
                self.data(data.result);
            });
        };

        self.updateAccountSizeInfo = function() {
            if (self.isWin()) {
                $.postJSON("/hosting/account/getaccountsizeinfo", function(data) {

                    var spaceHome = parseFloat(data.result.web).toFixed(2);
                    var spaceEmail = parseFloat(data.result.email).toFixed(2);
                    var total = spaceEmail + spaceHome;

                    var chartQuotaUsed = [];

                    if(spaceHome > 1024){
                        var labelspaceHome = spaceHome / 1024;
                        labelspaceHome = parseFloat(labelspaceHome).toFixed(2);
                        var unithome = "GB";
                    }else{
                        var labelspaceHome = spaceHome
                        var unithome = "MB";
                    }

                    if(spaceEmail > 1024){
                        var labelspaceEmail = spaceEmail / 1024;
                        labelspaceEmail = parseFloat(labelspaceEmail).toFixed(2);
                        var unitEmail = "GB";
                    }else{
                        var labelspaceEmail = spaceEmail
                        var unitEmail = "MB";
                    }                

                    chartQuotaUsed.push(
                        {   
                            "label" : "Web:" + ' ['+labelspaceHome + unithome + ']',
                            "data" : spaceHome
                        },
                        {
                            "label" : "Email:" + ' ['+labelspaceEmail + unitEmail + ']',
                            "data" : spaceEmail
                        }
                    );
                    self.fixEmptySizes(chartQuotaUsed);
                    self.renderChart("#chartwin-diskinfo", chartQuotaUsed);
                });
                //var spaceHome=FerozoHosting.profileVM().user().UsedSpaceHome();
                //var spaceEmail=FerozoHosting.profileVM().user().UsedSpaceEmail();

                
            }else{
                $.postJSON("/hosting/account/getaccountsizeinfo", function(data) {
                    self.dataAccntSize(data.result);

                    var chartDiskSize = [];
                    var chartBwUsage = [];
                    if (self.dataAccntSize()) {

                        var bwUsage = self.dataAccntSize().transferenciatotal;
                        var bwUsageLabel = self.dataAccntSize().transferenciausada + 'MB';
                        if (bwUsage === 'Ilimitado') {
                            bwUsage = 100000 * 100000;
                            bwUsageLabel = self.dataAccntSize().transferenciatotal;
                        }   

                        if(self.dataAccntSize().megabytesusadosweb > 1024){
                            var labelweb = self.dataAccntSize().megabytesusadosweb / 1024;
                            labelweb = parseFloat(labelweb).toFixed(2);
                            var unitweb = "GB";
                        }else{
                            var labelweb = self.dataAccntSize().megabytesusadosweb;
                            var unitweb = "MB";
                        }
                        
                        if(self.dataAccntSize().megabytesusadosmail > 1024){
                            var labelemail = self.dataAccntSize().megabytesusadosmail / 1024;
                            labelemail = parseFloat(labelemail).toFixed(2);
                            var unitemail = "GB";
                        }else{
                            var labelemail = self.dataAccntSize().megabytesusadosmail;
                            var unitemail = "MB";
                        }

                        if(self.dataAccntSize().megabytesusadosbackups > 1024){
                            var labelbkp = self.dataAccntSize().megabytesusadosbackups / 1024;
                            labelbkp = parseFloat(labelbkp).toFixed(2);
                            var unitbkp = "GB";
                        }else{
                            var labelbkp = self.dataAccntSize().megabytesusadosbackups;
                            var unitbkp = "MB";
                        }

                        if(self.dataAccntSize().megabytesusadosotros > 1024){
                            var labelotros = self.dataAccntSize().megabytesusadosotros / 1024;
                            labelotros = parseFloat(labelotros).toFixed(2);
                            var unitotros = "GB";
                        }else{
                            var labelotros = self.dataAccntSize().megabytesusadosotros;
                            var unitotros = "MB";
                        }


                        chartDiskSize.push(
                            {
                                "label": "Web" + ' ['+labelweb+unitweb+']',
                                "data": self.dataAccntSize().megabytesusadosweb
                            },
                            {
                                "label": "Email" + ' ['+labelemail+unitemail+']',
                                "data": self.dataAccntSize().megabytesusadosmail
                            },
                            {
                                "label": "Backups" + ' ['+labelbkp+unitbkp+']',
                                "data": self.dataAccntSize().megabytesusadosbackups
                            },
                            {
                                "label": "Otros" + ' ['+labelotros+unitotros+']',
                                "data": self.dataAccntSize().megabytesusadosotros
                            }
                        );
                        chartBwUsage.push(
                            {
                                "label": "Usado" + ' ['+self.dataAccntSize().transferenciausada+'MB]',
                                "data": self.dataAccntSize().transferenciausada
                            },
                            {
                                "label": "Total" + ' ['+ bwUsageLabel +']',
                                "data": bwUsage
                            }
                        );
                        self.renderChart("#chart-diskinfo", chartDiskSize);
                        // self.renderChart("#chart-bwinfo", chartBwUsage);
                    }
                });
            }
        };

        self.updateDatabasesInfo = function() {
            
            var params = {"params": {"reSync": true}};
            $.postJSON("/hosting/database/databasesinfo", params, function(data) {
                self.dataDbInfo(data.result);
                var chartDbSize = [];
                var total = 0;
                if (self.dataDbInfo()) {
                    for (var item in self.dataDbInfo().databases) {
                        chartDbSize.push({
                            "label": self.dataDbInfo().databases[item].name + ' ['+self.dataDbInfo().databases[item].usedQuota+'MB]',
                            "data": self.dataDbInfo().databases[item].usedQuota
                        });
                        total += self.dataDbInfo().databases[item].usedQuota;
                    }
                }
                
                if (chartDbSize.length) {
                    chartDbSize = self.fixEmptySizes(chartDbSize);
                    self.renderChart("#chart-dbinfo", chartDbSize);
                } else {
                    $("#chart-dbinfo").find('.loading-data').hide();
                    $("#chart-dbinfo").find('.alert').show();
                }
            });
        };

        self.fixEmptySizes = function(chartDbSize) {
            for (var item in chartDbSize) {
                if(chartDbSize[item].data == 0)
                    chartDbSize[item].data = 1;
            }
            return chartDbSize;
        };

        self.fixEmptySizesQuota = function(chartDbSize) {
            for (var item in chartDbSize) {
                if(chartDbSize[item].data === '0')
                    chartDbSize[item].data = '1';
            }
            return chartDbSize;
        };

        self.renderChart = function(element, data) {
            $(element).off();
            $.plot($(element), data, {
                "series": { "pie": {
                    "innerRadius": 0.5,
                    "show": true,
                    "label": {
                        "show":false
                    }
                }}, "legend": {
                    "show":true
                }, "canvas" : true
            });
            if (self.isWin()) {
                $('.accntinfo-chart').css('min-height', '100px').css('height', 'auto').css('width', '450px');
            } else {
                $('.accntinfo-chart').css('min-height', '100px').css('height', 'auto');
            }
        };
        
        self.isWin = function() {
            if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
                return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
            } else {
                return false;
            }        
        };        
    };
    
    return InfoVM;
});