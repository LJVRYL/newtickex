define(['knockout', 'notifications', 'mediator'], function(ko, Notifications, mediator) {

    var FerozoDhmApp = function() {

        this.timeDefaultPolling = 25000;
        this.timeCommandPolling = 2000;
        this.clockDefaultPolling = 0;
        this.clockCommandPolling = 0;
        this.tasksCount = 0;

        this.menu = ko.observableArray(window.FerozoUtils && window.FerozoUtils.menu);
        this.activeSection = ko.observable('index');
        this.errors = ko.observableArray([]);
        this.connection = {
            "needlogin": ko.observable(0),
            "ispolling": ko.observable(0)
        };

        this.tasksVM = ko.observable();
        this.indexVM = ko.observable();
        this.profileVM = ko.observable();
        this.loginVM = ko.observable();

        this.hostingsVM = ko.observable();
        this.accountVM = ko.observable();
        this.packagesVM = ko.observable();
        this.resellerpackagesVM = ko.observable();
        this.resellersVM = ko.observable();
        this.serverVM = ko.observable();
        this.toolsVM = ko.observable();
        this.servicesVM = ko.observable();
        this.processesVM = ko.observable();
        this.backupsVM = ko.observable();
        this.restoreVM = ko.observable();
        this.updatesVM = ko.observable();
        this.serveripsVM = ko.observable();
        this.serverinfoVM = ko.observable();
        this.securityVM = ko.observable();
        this.webserversVM = ko.observable();
        this.whitelabelVM = ko.observable();
        this.skeletonVM = ko.observable();
        this.generalVM = ko.observable();
        this.configsVM = ko.observable();
        this.configVM = ko.observable();
        this.sslVM = ko.observable();
        this.domainVM = ko.observable();
        this.domainsVM = ko.observable();
        this.dnsVM = ko.observable();
        this.suggestionsVM = ko.observable();
        this.featuresVM = ko.observable();
        this.isUpdateAvailable = ko.observable(0);
        this.updateInProgress = ko.observable(false);

        this.title = ko.computed(function() {
            var title = 'Panel de control de reseller';
            try {
                if (this.tasksVM().count() > 0) {
                    title = "(" + this.tasksVM().count() + ") " + title;
                }
            } catch (error) {
            }
            return title;
        }, this);
    };

    FerozoDhmApp.prototype.clearError = function(entity, event) {
        var self = this;
        var div = $(event.target).parents('.alert-notification');
        Notifications._hide(div, function() {
            self.errors.remove(entity);
        });
        return self;
    };

    FerozoDhmApp.prototype.rand = function(from, to) {
        return Math.floor(Math.random() * to) + from;
    };

    FerozoDhmApp.prototype.range = function(from, to) {
        return Array.apply(null, Array(to - from + 1)).map(function (_, i) {
            return from + i;
        });
    };

    FerozoDhmApp.prototype.childsOf = function(sec) {
        var allItems = this.menu();
        var matchingItems = [];
        for (var i = 0; i < allItems.length; i++) {
            var current = allItems[i];
            if (ko.utils.unwrapObservable(current["serialname"]) === sec) {
                matchingItems.push(current["childs"]);
            }
        }
        return matchingItems[0];
    };

    FerozoDhmApp.prototype.getActiveSectionVM = function() {
        var viewModel = this.activeSection() + 'VM';
        return typeof this[viewModel] === 'function' && this[viewModel].apply();
    };

    FerozoDhmApp.prototype.getViewIcon = function(tplName) {
        if (! tplName) {
            tplName = this.activeSection();
        }
        var allItems = this.menu();
        for (var i in allItems) {
            if (allItems[i].serialname === tplName) {
                return allItems[i].icon;
            }

            var childs = this.childsOf(allItems[i].serialname);
            for (var ii in childs) {
                if (childs[ii].serialname === tplName) {
                    return childs[ii].icon;
                }
            }
        }
        return '';
    };

    FerozoDhmApp.prototype.appendError = function(tplName, tplValue , tplError) {
        this.errors.push({
            "tplName": tplName || '',
            "tplValue": tplValue || '',
            "tplError": tplError || '',
            "time": new Date(),
            "userAgent": window.navigator.userAgent
        });
        return this;
    };

    FerozoDhmApp.prototype.isBrowserTabInactive = function() {
        var hidden;
        if (typeof document.hidden !== "undefined") {
            // Opera 12.10 and Firefox 18 and later support
            hidden = "hidden";
        } else if (typeof document.mozHidden !== "undefined") {
            hidden = "mozHidden";
        } else if (typeof document.msHidden !== "undefined") {
            hidden = "msHidden";
        } else if (typeof document.webkitHidden !== "undefined") {
            hidden = "webkitHidden";
        }
        return document[hidden];
    };

    FerozoDhmApp.prototype.arrayPartition = function(array, chunkSize) {
        var result = [];
        for (var i = 0; array[i]; i += chunkSize) {
            result.push(array.slice(i, i + chunkSize));
        }
        return result;
    };

    FerozoDhmApp.prototype.formatDate = function(dateInput, appendTime) {
        if (typeof dateInput === 'object') {
            date = dateInput;
        } else {
            var t = dateInput.split(/[- :]/);
            date = new Date(t[0], t[1]-1, t[2], t[3], t[4], t[5]);
        }
        var sD = '/';
        var sT = ':';
        var dateStr = '';
        var formatNumber = function(number, pieces) {
            return ("0" + number).slice(- (pieces || 2));
        };
        appendTime = typeof appendTime === "undefined" ? true : appendTime;
        dateStr += formatNumber(date.getDate()) + sD;
        dateStr += formatNumber(parseInt(date.getMonth() + 1)) + sD;
        dateStr += date.getFullYear();
        if (appendTime) {
            dateStr += ' ' + date.getHours() + sT + date.getMinutes() + sT + date.getSeconds();
        }
        return dateStr;
    };

    FerozoDhmApp.prototype.startCommandPolling = function() {
        var self = this;
        self.clockCommandPolling = window.setInterval(function() {
            //Polling a partir de comandos pendientes y refrescos de VM
            try {
                self.tasksCount = self.tasksVM().count();
                if (!self.tasksVM() &&
                    !self.tasksVM().hasOwnProperty('count') &&
                    !self.getActiveSectionVM()) {
                    return;
                }
            } catch(error) {
            }

            if (! self.getActiveSectionVM()) {
                return;
            }
            if (self.tasksVM().count() && !self.isBrowserTabInactive() && !self.connection.needlogin()) {
                try {
                    self.connection.ispolling(true);
                    self.tasksVM().init(function() {
                        self.getActiveSectionVM().init.bind(self.getActiveSectionVM()).apply();
                    });
                } catch (error) {
                    console.log('[commandPolling] Unknown error', error.message);
                }
            } else {
                self.connection.ispolling(false);
            }
        }, self.timeCommandPolling);
        return self;
    };

    FerozoDhmApp.prototype.startDefaultPolling = function() {
        var self = this;
        self.clockDefaultPolling = window.setInterval(function() {
            //Polling sin necesidad de comandos pendientes
            if (!self.connection.needlogin() && self.getActiveSectionVM() && !self.isBrowserTabInactive()) {
                try {
                    self.tasksVM().init(function() {
                        self.getActiveSectionVM().init.bind(self.getActiveSectionVM()).apply();
                    });
                    self.profileVM().initHome();
                } catch (error) {
                    console.log('[defaultPolling] Unknown error', error.message);
                }
            }
        }, self.timeDefaultPolling);
        return self;
    };

    FerozoDhmApp.prototype.stopDefaultPolling = function() {
        window.clearInterval(this.clockDefaultPolling);
        return this;
    };

    FerozoDhmApp.prototype.stopCommandPolling = function() {
        window.clearInterval(this.clockCommandPolling);
        return this;
    };

    FerozoDhmApp.prototype.onHashChange = function(data) {
        var self = this;
        var Section = (function(section, subsection, action) {
            return {
                "type": (subsection) ? 'sub' : 'main',
                "clearname": (subsection) ? subsection : section,
                "combinedname": (subsection) ? section + '/' + subsection : section,
                "action": (action) ? action : ""
            };
        })(data.section, data.subsection, data.action);

        var viewModel = Section.clearname + 'VM';

        //fix para cuando en el caso de que una pantalla ya este precargada, haga que no parpadeé el listado
        if (self[viewModel] && self[viewModel]() && self[viewModel]().inprocess) {
            self[viewModel]().inprocess(1);
        }
        require(['dhm/sections/' + Section.combinedname + '/viewmodel'], function(module) {
            if (typeof module === 'undefined') {
                self.activeSection('error404');
                return;
            };
            self.sendAnalytics("pageview", location.pathname + window.location.hash);
            self.initVM(Section.combinedname, viewModel, Section.action);
        });

        self.activeSection(Section.clearname);
        return self;
    };

    FerozoDhmApp.prototype.checkFzUpdate = function () {
        var self = this;
        $.postJSON("/dhm/checkfzversion", function(data) { 
            self.isUpdateAvailable(data.result);
        });

        return this;
    }


    FerozoDhmApp.prototype.checkUpdateInp = function () {
        var self = this;
        $.postJSON("/dhm/checkupdatefzpanelinprogress", function(data) { 
            self.updateInProgress(data.result);
        });

        return this;
    }

    FerozoDhmApp.prototype.initVM = function(name, viewModel, action) {
        var self = this;
        require(['dhm/sections/' + name + '/viewmodel'], function(module) {
            if (self[viewModel]) {
                if (typeof self[viewModel]() !== 'object') {//|| ! self[viewModel]().hasOwnProperty('_initted')
                    self[viewModel](new (module));
                    //Test temporal para ver como resulta, puede que haya problemas
                    //con los refresh luego de completado un command si se esta en otra seccion
                }
                self[viewModel]().init();
                self[viewModel]()._initted = true;
                if (action && typeof action !== "undefined" && typeof self[viewModel]()[action] === "function") {
                    //apertura de modales automatica
                    self[viewModel]()[action]();
                }
            }
        });
        return self[viewModel] && self[viewModel]();
    };

    FerozoDhmApp.prototype.initMainVMs = function() {
        var self = this;
        require([
                'dhm/sections/tasks/viewmodel', 'dhm/sections/user/profile/viewmodel',
                'dhm/sections/user/suggestions/viewmodel','dhm/sections/index/viewmodel', 'dhm/sections/login/viewmodel'  
            ], function(TasksVM, ProfileVM, SuggestionsVM, IndexVM, LoginVM ) {
            self.tasksVM(new TasksVM());
            self.tasksVM().init();

            self.profileVM(new ProfileVM());
            self.profileVM().initHome();

            self.indexVM(new IndexVM());
            self.indexVM().init();
            self.suggestionsVM(new SuggestionsVM());    
            self.loginVM(new LoginVM());
        });
        return self;
    };

    FerozoDhmApp.prototype.initRouters = function() {
        var self = this;
        jHash.route('/action/{section}/{subsection}/{action}', function() {
            mediator.publish('hashIsChanged', {
                "section": this.section,
                "subsection": this.subsection,
                "action": this.action
            });
        });

        jHash.route('{section}/{subsection}', function() {
            mediator.publish('hashIsChanged', {
                "section": this.section,
                "subsection": this.subsection,
                "action": ""
            });
        });

        jHash.route('{section}', function() {
            mediator.publish('hashIsChanged', {
                "section": this.section,
                "subsection": "",
                "action": ""
            });
        });

        jHash.defaultRoute("#/" + self.activeSection());
        jHash.processRoute();
    };

    FerozoDhmApp.prototype.init = function() {
        'use strict';
        var self = this;

        mediator.installTo(self);

        self.subscribe('hashIsChanged', self.onHashChange);

        self.initMainVMs().initRouters();
        self.startDefaultPolling().startCommandPolling();
        self.checkFzUpdate();
        self.checkUpdateInp();
    };

//para utilizar esa función. para registrar una visita solo pasar los 2 primeros parámetros. para una accion pasar todos
    FerozoDhmApp.prototype.sendAnalytics = function(action, url, element, typeevent) {

        if (action=="pageview") {
            ga('send', action, url);    
        } else if (action=="event") {
            ga('send', action, element, typeevent, url);
        }
    };

    return FerozoDhmApp;
});