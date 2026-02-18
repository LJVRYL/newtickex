define(['knockout', 'sort'], function(ko, Sort) {

    var customObservable = function(val, needPassword) {
        this.value = ko.observable(val);
        this.selected = ko.observable(true);
        this.sync = ko.observable(false);
        
        if (needPassword) {
            var self = this;
            this.syncPassword = ko.observable('');
            this.selected.subscribe(function(mode) {
                mode === false && (self.sync(false), self.syncPassword(''));
            });
        }
    };

    var cpanelmigratorVM = function() {
        var self = this;

        self.inprocess = ko.observable(0);
        self.step = ko.observable(1);
        self.progress = ko.observable(0);
        self.initted = ko.observable(false);
        self.nombre=ko.observable();
        self.email=ko.observable();
        self.message=ko.observable();


        self.sortByUsername = new Sort(self.data, 'user');
        self.step1 = {
            url: ko.observable(''),
            username: ko.observable(),
            password: ko.observable(),
            email: ko.observable(),

            possibleUrls: ko.observableArray([])
        };

        self.step2 = {
            dbs: ko.observableArray([]),
            ftps: ko.observableArray([]),
            emails: ko.observableArray([]),
            emailsForwards: ko.observableArray([]),
            emailsAutoresponders: ko.observableArray([]),
            domains: ko.observableArray([]),
            subdomains: ko.observableArray([]),
            dbsUsers: ko.observableArray([]),
            syncFtp: ko.observable(true)
        };

        self.step3 = {
            dbs: ko.observableArray([]),
            ftps: ko.observableArray([]),
            emails: ko.observableArray([]),
            emailsForwards: ko.observableArray([]),
            emailsAutoresponders: ko.observableArray([]),
            domains: ko.observableArray([]),
            subdomains: ko.observableArray([]),
            dbsUsers: ko.observableArray([]),
            completed: ko.observable(false)
        };

        self.readyToSubmitStep1 = ko.computed(function() {return true;
            var url = self.step1.url();
            return self.step1.possibleUrls().indexOf(url) > -1;
        });
    
    self.readyView =  ko.computed(function(){
            if (this.inprocess() >= 1  ) return "LOADING";
            return "FREE";
        },this);

        cpanelmigratorVM.prototype.save = function(){
            
            var self = this;
            var sendData={ "params": {
                "name":self.nombre(),
                "email": self.email(),
                "message": self.message()
            }};
            self.inprocess(1);
            $.postJSON('/hosting/migrator/sendhelp', sendData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    //console.log(this);
                    //self[this.field].error(this.errorDesc);
                    $("#contacto-migrar").find('[name="'+ this.field +'"]').next('.help-block.error').html(this.errorDesc);
                });
            } else {
                $('.help-block.error').html("");
                self.nombre("");
                self.email("");
                self.message("");
                $('.modal').modal('hide');
           }

          }).always(function() {
            self.inprocess(0);
        });

            //console.log( sendData);
            //limpio los campos una vez enviado
            
        };
    };

    cpanelmigratorVM.prototype.resetStep2 = function() {
        var self = this;
        self.step2.dbs([]);
        self.step2.dbsUsers([]);
        self.step2.domains([]);
        self.step2.subdomains([]);
        self.step2.emails([]);
        self.step2.emailsAutoresponders([]);
        self.step2.emailsForwards([]);
        self.step2.ftps([]);
        self.step2.syncFtp(true);
    };

    cpanelmigratorVM.prototype.resetStep3 = function() {
        var self = this;
        self.step3.dbs([]);
        self.step3.dbsUsers([]);
        self.step3.domains([]);
        self.step3.subdomains([]);
        self.step3.emails([]);
        self.step3.emailsAutoresponders([]);
        self.step3.emailsForwards([]);
        self.step3.ftps([]);
        self.step3.completed(false);
    };

    cpanelmigratorVM.prototype.init = function() {
        var self = this;
        self.initStep3().success(function() {
            self.initted(true);
        });
    };

    cpanelmigratorVM.prototype.checkUrl = function() {
        var self = this;
        var theData = { "params": {
            url: self.step1.url()
        }};

        if (self.step1.url().length < 8) {
            return;
        }

        self.inprocess(1);
        self.step1.possibleUrls.removeAll();
        ko.utils.clearObservableErrors.bind(self.step1).apply();
        return $.postJSON("/hosting/migrator/step1/check-url", theData, function(data) {
            self.step1.possibleUrls.removeAll();
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.step1.possibleUrls.push(obj);
                });
            }
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self.step1, data.error.data.inputException).apply();
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    cpanelmigratorVM.prototype.submitStep1 = function() {
        var self = this;
        var theData = { "params": ko.toJS(self.step1) };

        self.inprocess(1);
        ko.utils.clearObservableErrors.bind(self.step1).apply();
        return $.postJSON("/hosting/migrator/step1/submit", theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self.step1, data.error.data.inputException).apply();
            }
            if (data.result) {
                self.step(2);
                self.progress(30);
                self.initStep2(data.result);
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.delayCallback(function() {
                self.inprocess(0);
            }, 1000);
        });
    };

    cpanelmigratorVM.prototype.openModal = function() {
        this.temp();
        $('#modal-create').modal('show');
    };

    cpanelmigratorVM.prototype.opennew = function(dom, idmodal, event) {
            'use strict';
            var self = this;
            $('#' + idmodal).modal('show');
        };

    cpanelmigratorVM.prototype.initStep2 = function(result) {
        var self = this;
        self.resetStep2();

        $('.nav-tabs > li > a').on('click', function() {
            var id = $(this).attr('href');
            var divs = $(id).parent().find('>div');
            divs.height() < 500 && divs.css('min-height', divs.height());
        });

        result.dbs && ko.utils.arrayForEach(result.dbs.db, function(obj) {
            self.step2.dbs.push(new customObservable(obj));
        });

        result.dbs && ko.utils.objectForEach(result.dbs.userInDb, function(key, value) {
            self.step2.dbsUsers.push(new customObservable(value));
        });

        result.domains && ko.utils.arrayForEach(result.domains, function(obj) {
            self.step2.domains.push(new customObservable(obj));
        });

        result.subdomains && ko.utils.arrayForEach(result.subdomains, function(obj) {
            self.step2.subdomains.push(new customObservable(obj));
        });

        result.emails && ko.utils.arrayForEach(result.emails, function(obj) {
            self.step2.emails.push(new customObservable(obj, true));
        });

        result.emails_autoresponders && ko.utils.arrayForEach(result.emails_autoresponders, function(obj) {
            self.step2.emailsAutoresponders.push(new customObservable(obj));
        });

        result.emails_forwards && ko.utils.arrayForEach(result.emails_forwards, function(obj) {
            self.step2.emailsForwards.push(new customObservable(obj));
        });

        result.ftps && ko.utils.arrayForEach(result.ftps, function(obj) {
            self.step2.ftps.push(new customObservable(obj));
        });
    };

    cpanelmigratorVM.prototype.validatePassword = function(pwdValue) {
        pwdValue = ko.utils.peekObservable(pwdValue);
        return typeof pwdValue === 'string' && pwdValue.match(new RegExp(/^(.{4,18})$/));
    };

    cpanelmigratorVM.prototype.prepareSelectedData = function() {
        var self = this;
        var result = {};
        var exceptionErrors = [];
        ko.utils.objectForEach(self.step2, function(key, value) {
            typeof result[key] === 'undefined' && (result[key] = []);
            ko.utils.arrayForEach(value(), function(obj) {
                ko.utils.clearObservableErrors.bind(obj).apply();
                if (obj.selected()) {
                    var jsObj = ko.toJS(obj.value);
                    jsObj.sync = obj.sync();
                    if (obj.syncPassword && obj.sync && obj.sync()) {
                        if (! self.validatePassword(obj.syncPassword)) {
                            exceptionErrors.push(obj);
                            obj.syncPassword.errors('Ingrese una contraseña valida');
                        }
                        jsObj.syncPassword = obj.syncPassword();
                    }
                    result[key].push(jsObj);
                }
            });
        });

        result.syncFtp  = self.step2.syncFtp();
        result.username = self.step1.username();
        result.password = self.step1.password();
        result.email    = self.step1.email();
        result.url      = self.step1.url();
        
        if (exceptionErrors.length) {
            throw exceptionErrors;
        };
        return result;
    };

    cpanelmigratorVM.prototype.submitStep2 = function() {
        var self = this;
        try {
            var theData = { "params": self.prepareSelectedData() };
        } catch (errors) {
            $('[href=#emails]').trigger('click');
            return;
        }

        self.inprocess(1);
        return $.postJSON("/hosting/migrator/step2/submit", theData, function(data) {
            if (data.result) {
                self.step(3);
                self.progress(50);
                self.initStep3();
            }
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.delayCallback(function() {
                self.inprocess(0);
            }, 1000);
        });
    };


    cpanelmigratorVM.prototype.initDataStep3 = function(result, completed) {
        var self = this;

        self.resetStep3();

        var isArray = function(array) {
            return array && typeof array.push === 'function';
        };

        isArray(result.dbs) && ko.utils.arrayForEach(result.dbs, function(obj) {
            self.step3.dbs.push(obj);
        });

        isArray(result.dbsUsers) && ko.utils.arrayForEach(result.dbsUsers, function(obj) {
            self.step3.dbsUsers.push(obj);
        });

        isArray(result.domains) && ko.utils.arrayForEach(result.domains, function(obj) {
            self.step3.domains.push(obj);
        });

        isArray(result.subdomains) && ko.utils.arrayForEach(result.subdomains, function(obj) {
            self.step3.subdomains.push(obj);
        });

        isArray(result.emails) && ko.utils.arrayForEach(result.emails, function(obj) {
            self.step3.emails.push(obj);
        });

        isArray(result.emailsAutoresponders) && ko.utils.arrayForEach(result.emailsAutoresponders, function(obj) {
            self.step3.emailsAutoresponders.push(obj);
        });

        isArray(result.emailsForwards) && ko.utils.arrayForEach(result.emailsForwards, function(obj) {
            self.step3.emailsForwards.push(obj);
        });

        isArray(result.ftps) && ko.utils.arrayForEach(result.ftps, function(obj) {
            self.step3.ftps.push(obj);
        });

        self.step3.completed(completed);
    };

    cpanelmigratorVM.prototype.initStep3 = function() {
        var self = this;
        var theData = { "params": {} };

        self.inprocess(1);
        ko.utils.clearObservableErrors.bind(self.step2).apply();
        return $.postJSON("/hosting/migrator/step3/imported/results/get", theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self.step2, data.error.data.inputException).apply();
            }
            if (data.result && data.result.configValue) {
                if (self.initted()) {
                    self.initDataStep3(data.result.configValue.results, data.result.configValue.completed);
                    self.step3.completed() && self.progress(100);
                }

                self.delayCallback(function() {
                    FerozoHosting.tasksVM && FerozoHosting.tasksVM().init();
                }, 1500, 12);
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.delayCallback(function() {
                self.inprocess(0);
            }, 1000);
        });
    };

    cpanelmigratorVM.prototype.delayCallback = function(callback, time, times) {
        var intTimes = 0;
        var timer = window.setTimeout(function() {
            typeof callback === 'function' && callback();
            intTimes++;
            if (intTimes >= (times || 0)) {
                typeof timer !== 'undefined' && window.clearTimeout(timer);
            }
        }, time);
    };


    return cpanelmigratorVM;
});