define(['knockout', 'ko.mapping', 'input'], function(ko, mapping, Input) {

    var phpconfigVM = function(data) {
        var self = this;
        ko.mapping = mapping;

        self.inprocess = ko.observable(1);
        self.phpVersions = ko.observableArray();
        self.acceptDeprecated = ko.observable(false);
        self.flagDeprecated = ko.observable(false);
        self.loadingCurrentValues = ko.observable(1);
        self.webFolderVersions = ko.observableArray();
        self.selectedPHPVersionId = ko.observable();
        self.selectedPHPVersionDsc = ko.observable();
        self.selectedWebFolderConfigId = ko.observable();
        self.selectedWebFolderConfigDsc = ko.observable();
        self.mysqlUsersWithOldPassword = ko.observable('');

        /* PHP configuracion opciones */
        self.displayErrors = ko.observable(0);
        self.logErrors = ko.observable(0);
        self.selectedErrorReportingId = ko.observable('');
        self.selectedMaxExecutionTime = ko.observable('');        
        self.selectedMaxInputTime = ko.observable('');        
        self.selectedMaxInputVars = ko.observable('');
        self.selectedMemoryLimit = ko.observable('');        
        self.selectedPostMaxSize = ko.observable('');        
        self.selectedUploadMaxFilesize = ko.observable('');
        self.errorLog = new Input();
        self.memoryLimit = new Input();
        self.errorReporting = new Input();
        self.maxExecutionTime = new Input();
        self.maxInputTime = new Input();
        self.postMaxSize = new Input();
        self.uploadMaxFilesize = new Input();
        self.maxInputVars = new Input();
        self.dateTimezone = new Input();
        var mappingRules = {
            'dateTimezone': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'errorLog': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'memoryLimit': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'errorReporting': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'maxExecutionTime': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'maxInputTime': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'postMaxSize': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'uploadMaxFilesize': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
            'maxInputVars': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            },
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    phpconfigVM.prototype.sort = function() {
        var self = this;
        self.webFolderVersions.sort(function(left, right) {
            return left.description === right.description ? 0 : (left.description < right.description ? -1 : 1);
        });
        self.phpVersions.sort(function(left, right) {
            return left.description === right.description ? 0 : (left.description < right.description ? -1 : 1);
        });
    };

    phpconfigVM.prototype.isWin = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined" && typeof FerozoHosting.profileVM().user().Server.OpSystem() !== "undefined"){
            return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
        } else {
            return false;
        }  
    };

    phpconfigVM.prototype.init = function() {
        var self = this;
        var cron;
        self.clearErrors();
        cron = window.setInterval(function() {
            if (self.isWin()) {
                self.listWebFolderConfigs();
                self.listWebFoldersCurrentConfig();
                self.listPHPVersions();
                self.listPHPCurrentConfig();
            } else {
                self.listPHPVersions();
                self.listPHPCurrentConfig();
                self.listPHPOptionsAllowedValues();
                self.listSitePHPOptionsCurrentConfig();
                self.getMysqlUsersWithOldPassword();
            }
            window.clearInterval(cron);
        }, 500);
    };

    phpconfigVM.prototype.clearErrors = function() {
        var self = this;
        self.errorLog.clearErrors();
        self.memoryLimit.clearErrors();
        self.errorReporting.clearErrors();
        self.maxExecutionTime.clearErrors();
        self.maxInputTime.clearErrors();
        self.postMaxSize.clearErrors();
        self.uploadMaxFilesize.clearErrors();
        self.maxInputVars.clearErrors();
        self.dateTimezone.clearErrors();
    }

    phpconfigVM.prototype.listWebFolderConfigs = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/tools/listwebfolderconfigs", function(data) {
            if (data.result) {
                self.webFolderVersions(data.result);
            }
        }).always(function(data) {
            self.inprocess(0);
            self.sort();
        });
    };

    phpconfigVM.prototype.listPHPVersions = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/tools/listphpversions", function(data) {
            if (data.result) {
                self.phpVersions(data.result);
                const hasDeprecated = data.result.some(function (version) {
                    return version.supportedVersion === false;
                });
                self.flagDeprecated(hasDeprecated);
            }
        }).always(function(data) {
            self.inprocess(0);
            self.sort();
        });
    };


    /*****************************************************************/
    phpconfigVM.prototype.listPHPCurrentConfig = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/tools/listsitephpconfig", function(data) {
            if (data.result) {
                self.selectedPHPVersionId(data.result.phpVersion.id);
                self.selectedPHPVersionDsc(data.result.phpVersion.description);
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    phpconfigVM.prototype.listWebFoldersCurrentConfig = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/tools/listsitedotnetconfig", function(data) {
            if (data.result) {
                self.selectedWebFolderConfigId(data.result.webFolderConfig.id);
                self.selectedWebFolderConfigDsc(data.result.webFolderConfig.description);
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    phpconfigVM.prototype.getMysqlUsersWithOldPassword = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/tools/getmysqluserswitholdpassword", function(data) {
            if (data.result) {
                if (data.result.length > 0) {
                    self.mysqlUsersWithOldPassword(data.result.join(', '));
                } else {
                    self.mysqlUsersWithOldPassword('');
                }
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };
    
    /*********************************************************************/
    phpconfigVM.prototype.changePhpVersion = function() {
        var self = this;
        var theData = { "params": {
            "id": self.selectedPHPVersionId()
        }};

        self.inprocess(1);
        $.postJSON('/hosting/tools/changesitephpconfig', theData, function() {
            self.init();
        }).always(function(data) {
            self.inprocess(0);
        });
   };

//    phpconfigVM.prototype.changeWebFolderConfig = function() {
//        var self = this;
//        var theData = { "params": {
//            "idWebFolderConfig": self.selectedWebFolderConfigId(),
//            "test": "ok"
//        }};
//
//        self.inprocess(1);
//        $.postJSON('/hosting/tools/changesitedotnetconfig', theData, function() {
//            self.init();
//        }).always(function(data) {
//            self.inprocess(0);
//        });
//   };

    phpconfigVM.prototype.changeSiteConfig = function() {
        var self = this;
        var theData = { "params": {
            "idWebFolderConfig": self.selectedWebFolderConfigId(),
            "idPhpVersion": self.selectedPHPVersionId(),
            "test": "ok"
        }};

        self.inprocess(1);
        $.postJSON('/hosting/tools/changesiteconfig', theData, function() {
            self.init();
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    /****************** PHP configuracion opciones *************************/
    phpconfigVM.prototype.listSitePHPOptionsCurrentConfig = function() {
        var self = this;
        self.inprocess(1);
        self.loadingCurrentValues(1);
        $.postJSON("/hosting/tools/phpoptionscurrentconfig/list", function(data) {
            if (data.result) {
                if (!data.result.saved_on_file.hasOwnProperty('fz')) {
                    data.result.saved_on_file.fz = [];
                }

                var userIni = data.result.saved_on_file.fz;
                var webPhpInfo = data.result.web_phpinfo;
                
                if (!userIni.hasOwnProperty('date.timezone')) {
                    userIni['date.timezone'] = webPhpInfo['date.timezone'];
                }
                if (!userIni.hasOwnProperty('display_errors')) {
                    userIni.display_errors = webPhpInfo.display_errors;
                }
                if (!userIni.hasOwnProperty('log_errors')) {
                    userIni.log_errors = webPhpInfo.log_errors;
                }
                if (!userIni.hasOwnProperty('error_reporting')) {
                    userIni.error_reporting = webPhpInfo.error_reporting;
                }
                if (!userIni.hasOwnProperty('max_execution_time')) {
                    userIni.max_execution_time = webPhpInfo.max_execution_time;
                }
                if (!userIni.hasOwnProperty('max_input_time')) {
                    if (webPhpInfo.max_input_time == -1) {
                        userIni.max_input_time = webPhpInfo.max_execution_time;
                    } else {
                        userIni.max_input_time = webPhpInfo.max_input_time;
                    }
                }
                if (!userIni.hasOwnProperty('max_input_vars')) {
                    userIni.max_input_vars = webPhpInfo.max_input_vars;
                }
                if (!userIni.hasOwnProperty('memory_limit')) {
                    userIni.memory_limit = webPhpInfo.memory_limit;
                }
                if (!userIni.hasOwnProperty('post_max_size')) {
                    userIni.post_max_size = webPhpInfo.post_max_size;
                }
                if (!userIni.hasOwnProperty('upload_max_filesize')) {
                    userIni.upload_max_filesize = webPhpInfo.upload_max_filesize;
                }

                self.dateTimezone.content(userIni['date.timezone']);
                self.displayErrors(parseInt(userIni.display_errors || 0));
                self.logErrors(parseInt(userIni.log_errors || 0));
                self.errorLog.content(data.result.defaultErrorLog);              

                if (!self.isValueInArrayProperty(self.errorReporting.content(), 'id', userIni.error_reporting)) {
                    self.errorReporting.content().push({id:' ',description:''});
                    self.selectedErrorReportingId(' ');
                } else {
                    self.selectedErrorReportingId(userIni.error_reporting);
                }
                
                if (!(self.maxExecutionTime.content().includes(userIni.max_execution_time))) {
                    self.maxExecutionTime.content().push(' ');
                    self.selectedMaxExecutionTime(' ');
                } else {
                    self.selectedMaxExecutionTime(userIni.max_execution_time);
                }
                
                if (!(self.maxInputTime.content().includes(userIni.max_input_time))) {
                    self.maxInputTime.content().push(' ');
                    self.selectedMaxInputTime(' ');
                } else {
                    self.selectedMaxInputTime(userIni.max_input_time);
                }

                if (!(self.maxInputVars.content().includes(userIni.max_input_vars))) {
                    self.maxInputVars.content().push(' ');
                    self.selectedMaxInputVars(' ');
                } else {
                    self.selectedMaxInputVars(userIni.max_input_vars);
                }

                if (!(self.memoryLimit.content().includes(userIni.memory_limit))) {
                    self.memoryLimit.content().push(' ');
                    self.selectedMemoryLimit(' ');
                } else {
                    self.selectedMemoryLimit(userIni.memory_limit);
                }

                if (!(self.postMaxSize.content().includes(userIni.post_max_size))) {
                    self.postMaxSize.content().push(' ');
                    self.selectedPostMaxSize(' ');
                } else {
                    self.selectedPostMaxSize(userIni.post_max_size);
                }
                
                if (!(self.uploadMaxFilesize.content().includes(userIni.upload_max_filesize))) {
                    self.uploadMaxFilesize.content().push(' ');
                    self.selectedUploadMaxFilesize(' ');
                } else {
                    self.selectedUploadMaxFilesize(userIni.upload_max_filesize);
                }

            }
        }).always(function(data) {
            self.inprocess(0);
            self.loadingCurrentValues(0);
        });

    };

    phpconfigVM.prototype.listPHPOptionsAllowedValues = function() {
        var self = this;
        self.inprocess(1);
        $.getJSON("/hosting/tools/phpoptionsallowedvalues/list", function(data){
            if (data.result) {
                self.memoryLimit.content(data.result.memory_limit.split(','));
                var aTemp = [];
                data.result.error_reporting.split(';').forEach(function(element) {
                    aTemp.push(JSON.parse(element))
                });
                self.errorReporting.content(aTemp);
                self.maxExecutionTime.content(data.result.max_execution_time.split(','));
                self.maxInputTime.content(data.result.max_input_time.split(','));
                self.postMaxSize.content(data.result.post_max_size.split(','));
                self.uploadMaxFilesize.content(data.result.upload_max_filesize.split(','));
                self.maxInputVars.content(data.result.max_input_vars.split(','));
            }
        }).always(function(data){
            self.inprocess(0);
        });

    };

    phpconfigVM.prototype.listPHPOptionsDefaultValues = function() {
        var self = this;
        self.inprocess(1);
        $.getJSON("/hosting/tools/phpoptionsdefaultvalues/list", function(data){
            if (data.result) {
                self.dateTimezone.content(data.result['date.timezone']);
                self.displayErrors(parseInt(data.result.display_errors));
                self.logErrors(parseInt(data.result.log_errors));
                self.errorLog.content(data.result.error_log);
                self.selectedErrorReportingId(data.result.error_reporting);
                self.selectedMaxExecutionTime(data.result.max_execution_time);
                self.selectedMaxInputTime(data.result.max_input_time);
                self.selectedMaxInputVars(data.result.max_input_vars);
                self.selectedMemoryLimit(data.result.memory_limit);
                self.selectedPostMaxSize(data.result.post_max_size);                
                self.selectedUploadMaxFilesize(data.result.upload_max_filesize);                
            }
        }).always(function(data){
            self.inprocess(0);
        });
    }
    
    phpconfigVM.prototype.setPHPOptionsConfig = function() {
        'use strict';
        var self = this;
        self.inprocess(1);
        self.clearErrors();
        if (!self.displayErrors()) self.displayErrors(0);
        if (!self.logErrors()) self.logErrors(0);
        var params = { "params": {
            "dateTimezone": self.dateTimezone.content(),
            "displayErrors": self.displayErrors(),
            "logErrors": self.logErrors(),
            "errorLog": self.errorLog.content(),
            "errorReporting": self.selectedErrorReportingId(),
            "maxExecutionTime": self.selectedMaxExecutionTime(),
            "maxInputTime": self.selectedMaxInputTime(),
            "maxInputVars": self.selectedMaxInputVars(),
            "memoryLimit": self.selectedMemoryLimit(),
            "postMaxSize": self.selectedPostMaxSize(),
            "uploadMaxFilesize": self.selectedUploadMaxFilesize(),
        }};

        $.postJSON("/hosting/tools/phpoptionscurrentconfig/set", params, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                    self.inprocess(0);
                });
            } else {
                // self.init();
            }
            
        }).always(function(data) {
            
        });
    };

    phpconfigVM.prototype.isValueInArrayProperty = function(array, property, value) {
        var result = false;
        for(var i=0; i < array.length; i++) {
            if (result) {break;}
            if (array[i][property] == value) {
                result = true;
            }
        }

        return result;
    };

    return phpconfigVM;
});