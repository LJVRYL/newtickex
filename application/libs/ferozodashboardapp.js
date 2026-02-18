define(['knockout', 'notifications', 'mediator'], function(ko, Notifications, mediator) {

    var FerozoDashboardApp = function() {

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

        this.indexVM = ko.observable();
        this.loginVM = ko.observable();
        //this.panelalertVM = ko.observable();
        this.panelalertsVM = ko.observable();
        this.alertsgraphVM = ko.observable();

        this.title = ko.computed(function() {
            var title = 'Dashboard de Panel de control';
//            try {
//                if (this.tasksVM().count() > 0) {
//                    title = "(" + this.tasksVM().count() + ") " + title;
//                }
//            } catch (error) {
//            }
            return title;
        }, this);
    };

    FerozoDashboardApp.prototype.clearError = function(entity, event) {
        var self = this;
        var div = $(event.target).parents('.alert-notification');
        Notifications._hide(div, function() {
            self.errors.remove(entity);
        });
        return self;
    };

    FerozoDashboardApp.prototype.rand = function(from, to) {
        return Math.floor(Math.random() * to) + from;
    };

    FerozoDashboardApp.prototype.range = function(from, to) {
        return Array.apply(null, Array(to - from + 1)).map(function (_, i) {
            return from + i;
        });
    };

    FerozoDashboardApp.prototype.childsOf = function(sec) {
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

    FerozoDashboardApp.prototype.getActiveSectionVM = function() {
        var viewModel = this.activeSection() + 'VM';
        return typeof this[viewModel] === 'function' && this[viewModel].apply();
    };

    FerozoDashboardApp.prototype.getViewIcon = function(tplName) {
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

    FerozoDashboardApp.prototype.appendError = function(tplName, tplValue , tplError) {
        this.errors.push({
            "tplName": tplName || '',
            "tplValue": tplValue || '',
            "tplError": tplError || '',
            "time": new Date(),
            "userAgent": window.navigator.userAgent
        });
        return this;
    };

    FerozoDashboardApp.prototype.isBrowserTabInactive = function() {
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

    FerozoDashboardApp.prototype.arrayPartition = function(array, chunkSize) {
        var result = [];
        for (var i = 0; array[i]; i += chunkSize) {
            result.push(array.slice(i, i + chunkSize));
        }
        return result;
    };

    FerozoDashboardApp.prototype.formatDate = function(dateInput, appendTime) {
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

    FerozoDashboardApp.prototype.onHashChange = function(data) {
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
        require(['dashboard/sections/' + Section.combinedname + '/viewmodel'], function(module) {
            if (typeof module === 'undefined') {
                self.activeSection('error404');
                return;
            };
            self.initVM(Section.combinedname, viewModel, Section.action);
        });

        self.activeSection(Section.clearname);
        return self;
    };

    FerozoDashboardApp.prototype.initVM = function(name, viewModel, action) {
        var self = this;
        require(['dashboard/sections/' + name + '/viewmodel'], function(module) {
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

    FerozoDashboardApp.prototype.initMainVMs = function() {
        var self = this;
        require([
                'dashboard/sections/index/viewmodel', 'dashboard/sections/login/viewmodel'
            ], function(IndexVM, LoginVM) {

            self.indexVM(new IndexVM());
            self.indexVM().init();

            self.loginVM(new LoginVM());
        });
        return self;
    };

    FerozoDashboardApp.prototype.initRouters = function() {
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

    FerozoDashboardApp.prototype.init = function() {
        'use strict';
        var self = this;

        mediator.installTo(self);

        self.subscribe('hashIsChanged', self.onHashChange);

        self.initMainVMs().initRouters();
    };

    return FerozoDashboardApp;
});