define(['knockout', 'caller', 'notifications', 'mediator'], function(ko, Caller, Notifications, mediator) {

    var FerozoHostingApp = function() {

        this.timeDefaultPolling = 25000;
        this.timeCommandPolling = 6000;
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

        this.emailVM = ko.observable();
        this.tasksVM = ko.observable();
        this.indexVM = ko.observable();
        this.profileVM = ko.observable();
        this.infoVM = ko.observable();
        this.domainVM = ko.observable();
        this.domainsVM = ko.observable();
        this.subdomainsVM = ko.observable();
        this.dnsVM = ko.observable();
        this.sslVM = ko.observable();
        this.cloudflareVM = ko.observable();
        this.loginVM = ko.observable();
        this.websiteVM = ko.observable();
        this.ftpVM = ko.observable();
        this.gitVM = ko.observable();
        this.uploadsiteVM = ko.observable();
        this.errorpagesVM = ko.observable();
        this.optimizeVM = ko.observable();
        this.backupVM = ko.observable();
        this.emailaccountsVM = ko.observable();
        this.emailaccountsmigrationVM = ko.observable();
        this.exchangeVM = ko.observable();
        this.emailforwardingVM = ko.observable();
        this.emailaliasVM = ko.observable();
        this.emailautoreplyVM = ko.observable();
        this.antispamVM = ko.observable();
        this.antispamtemplateVM = ko.observable();
        this.antispammultipleVM = ko.observable();
        this.databaseVM = ko.observable();
        this.mysqlVM = ko.observable();
        this.mssqlVM = ko.observable();
        this.wordpressVM = ko.observable();
        this.installedVM = ko.observable();
        this.toolsVM = ko.observable();
        this.statisticsVM = ko.observable();
        this.wordpressmigratorVM = ko.observable();
        this.scheduledtasksVM = ko.observable();
        this.ipsblockVM = ko.observable();
        this.apachehandlersVM = ko.observable();
        this.cgiconfigurationVM = ko.observable();
        this.diskusageVM = ko.observable();
        this.indexhandlersVM = ko.observable();
        this.protectedfoldersVM = ko.observable();
        this.phpconfigVM = ko.observable();
        this.accessredirectionsVM = ko.observable();
        this.hotlinksVM = ko.observable();
        this.webappsconfigVM = ko.observable();
        this.suggestionsVM = ko.observable();
        this.cpanelmigratorVM = ko.observable();
        this.domainRegVM = ko.observable();

        this.title = ko.computed(function() {
            var title = 'Panel de control de hosting';
            try {
                if (this.tasksVM().count() > 0) {
                    title = "(" + this.tasksVM().count() + ") " + title;
                }
            } catch (error) {
            }
            return title;
        }, this);

        this.userData = {
            domains: ko.observable(),
            hideDefaultDomain: ko.observable()
        };
    };

    FerozoHostingApp.prototype.clearError = function(entity, event) {
        var self = this;
        var div = $(event.target).parents('.alert-notification');
        Notifications._hide(div, function() {
            self.errors.remove(entity);
        });
        return self;
    };

    FerozoHostingApp.prototype.rand = function(from, to) {
        return Math.floor(Math.random() * to) + from;
    };

    FerozoHostingApp.prototype.range = function(from, to) {
        return Array.apply(null, Array(to - from + 1)).map(function (_, i) {
            return from + i;
        });
    };

    FerozoHostingApp.prototype.childsOf = function(sec) {
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

    FerozoHostingApp.prototype.getActiveSectionVM = function() {
        var viewModel = this.activeSection() + 'VM';
        return typeof this[viewModel] === 'function' && this[viewModel].apply();
    };

    FerozoHostingApp.prototype.getViewIcon = function(tplName) {
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

    FerozoHostingApp.prototype.appendError = function(tplName, tplValue , tplError) {
        this.errors.push({
            "tplName": tplName || '',
            "tplValue": tplValue || '',
            "tplError": tplError || '',
            "time": new Date(),
            "userAgent": window.navigator.userAgent
        });
        return this;
    };

    FerozoHostingApp.prototype.isBrowserTabInactive = function() {
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

    FerozoHostingApp.prototype.arrayPartition = function(array, chunkSize) {
        var result = [];
        for (var i = 0; array[i]; i += chunkSize) {
            result.push(array.slice(i, i + chunkSize));
        }
        return result;
    };

    FerozoHostingApp.prototype.formatDate = function(dateInput, appendTime) {
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

    FerozoHostingApp.prototype.startCommandPolling = function() {
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

    FerozoHostingApp.prototype.startDefaultPolling = function() {
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

    FerozoHostingApp.prototype.stopDefaultPolling = function() {
        window.clearInterval(this.clockDefaultPolling);
        return this;
    };

    FerozoHostingApp.prototype.stopCommandPolling = function() {
        window.clearInterval(this.clockCommandPolling);
        return this;
    };

    FerozoHostingApp.prototype.getActiveSectionSmart = function() {
        var self = this;
        return self.inArray(self.activeSection(), self.actionsRequireDomain) &&
        self.showHiddenDomainWarning() ? 'screen-warning-if-domain-hidden' : self.activeSection();
    };

    FerozoHostingApp.prototype.showHiddenDomainWarning = function(inprocess) {
        var self = this;
        inprocess = typeof inprocess === 'undefined' ? false : inprocess;
        try {
            return self.userData.domains() < 1 && self.userData.hideDefaultDomain();
        } catch (e) {
        }
        return false;
    };

    FerozoHostingApp.prototype.inArray = function(needle, array) {
        return array.indexOf(needle) !== -1;
    };

    FerozoHostingApp.prototype.actionsRequireDomain = [
        'emailaccounts',
        'emailautoreply',
        'emailforwarding',
        'emailalias',
        'antispam',
        'antispammultiple',
        'antispamtemplate',
        'emailaccountsmigration',
        'wordpress',
        'ftp',
        'subdomains',
        'dns',
        'ssl'
    ];

    FerozoHostingApp.prototype.onHashChange = function(data) {
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
        require(['hosting/sections/' + Section.combinedname + '/viewmodel'], function(module) {
            if (typeof module === 'undefined') {
                self.activeSection('error404');
                return;
            };

            if($("#"+Section.clearname).length){
                self.initVM(Section.combinedname, viewModel, Section.action);
            }else{
                self.activeSection('error404');
            }
            
        });

        self.sendAnalytics("pageview", location.pathname + window.location.hash);
        self.activeSection(Section.clearname);

        return self;
    };

    FerozoHostingApp.prototype.initVM = function(name, viewModel, action) {
        var self = this;
        require(['hosting/sections/' + name + '/viewmodel'], function(module) {
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

    FerozoHostingApp.prototype.initMainVMs = function() {
        var self = this;
        require([
                'hosting/sections/tasks/viewmodel', 'hosting/sections/user/profile/viewmodel',
                'hosting/sections/index/viewmodel', 'hosting/sections/login/viewmodel',
                'hosting/sections/user/suggestions/viewmodel','hosting/sections/domain/domainreg/viewmodel'
            ], function(TasksVM, ProfileVM, IndexVM, LoginVM, SuggestionsVM, DomainRegVM) {
            self.tasksVM(new TasksVM());
            self.tasksVM().init();

            self.profileVM(new ProfileVM());
            self.profileVM().initHome();

            self.indexVM(new IndexVM());
            self.indexVM().init();

            self.loginVM(new LoginVM());
            self.suggestionsVM(new SuggestionsVM());
            self.domainRegVM(new DomainRegVM());
        });
        return self;
    };

    FerozoHostingApp.prototype.initRouters = function() {
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

    FerozoHostingApp.prototype.init = function() {
        'use strict';
        var self = this;
        var caller = new Caller();

        mediator.installTo(self);

        self.subscribe('hashIsChanged', self.onHashChange);

        self.subscribe('refreshDomainList', function(oView) {
            if (typeof oView !=  "undefined" && typeof oView.setInprocess == "function") {
                oView.setInprocess("+");
            }
            caller.get('domainList').done(function(data) {
                mediator.publish('domainListUpdated', data);
            });
        });

        self.subscribe('refreshEmailList', function() {
            caller.get('emailList').done(function(data) {
                mediator.publish('emailListUpdated', data);
            });
        });

        self.subscribe('refreshMysqlDBList', function() {
            caller.get('mysqlDBList').done(function(data) {
                mediator.publish('mysqlDBListUpdated', data);
            });
        });

        self.subscribe('refreshMssqlDBList', function() {
            caller.get('mssqlDBList').done(function(data) {
                mediator.publish('mssqlDBListUpdated', data);
            });
        });


        self.subscribe('refreshInstalledApps', function() {
            $.postJSON('/hosting/webapp/listinstalled', function(response) {
                mediator.publish('installedAppsUpdated', response.result);
            });
        });

        self.subscribe('getIpBlocks', function() {
            $.postJSON('/hosting/tools/listblockip', function(response) {
                mediator.publish('IpBlockUpdated', response.result);
            });
        });

        self.initMainVMs().initRouters();
        self.startDefaultPolling().startCommandPolling();
    };

//para utilizar esa función. para registrar una visita solo pasar los 2 primeros parámetros. para una accion pasar todos
    FerozoHostingApp.prototype.sendAnalytics = function(action, url, element, typeevent) {

        if (action=="pageview") {
            ga('send', action, url);
        } else if (action=="event") {
            ga('send', action, element, typeevent, url);
        }
    };

    return FerozoHostingApp;
});