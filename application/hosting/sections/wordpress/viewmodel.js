define(['knockout', 'availableapp', 'webapp', 'wpstage', 'domain', 'email', 'translate', 'sort'], function(ko, AvailableApp, WebApp, WpStage, Domain, Email, Translate,Sort) {

    var wordpressVM = function() {
        var self = this;
        mediator.installTo(this);
        this.inprocess = ko.observable(0);
        this.availableApps = ko.observableArray([]);
        this.wpStages = ko.observableArray([]);
        this.wpInstallations = ko.observableArray([]);
        this.data = ko.observableArray([]);
        this.emails = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.temp = ko.observable(new AvailableApp());
        this.tempEdit = ko.observable(new WebApp());
        this.tempWp = ko.observable(new WebApp());
        this.tempStaging = ko.observable(new WpStage());
        this.hasWordpress = ko.observable(0);
        this.hasAlterDomain = ko.observable(0);
        this.step = ko.observable(1);
        this.subdomain = ko.observable('');
        this.serviceId = ko.observable('');
        this.flagFolder = ko.observable(false);
        this.installFlag = ko.observable(false);
        this.loadingFlag = ko.observable(false);
        this.showPassword = ko.observable(false);
        this.userName = ko.observable('');
        this.pwd = ko.observable('');
        this.sslTypes = [
            {"value": "", "label": "#trans-ssl-no"},
            {"value": "ssl", "label": "#trans-ssl-yes"}
            //{"value": "sslwww", "label": "#trans-ssl-www"}
        ];            
        this.readyView =  ko.computed(function(){
            if (this.inprocess() >= 1 && this.loadingFlag() ) return "LOADING";
            if ((this.inprocess() < 1 && !this.loadingFlag() ) && this.domains().length <= 0  ) return "WO-DOMAINS";
            if ((this.inprocess() < 1 && !this.loadingFlag() ) && this.domains().length > 0  ) return "W-DOMAINS";
        },this);
        this.setStep =  ko.computed({
            read: function () {
                return false;
            },
            write: function(action) {
                if (action == "+") {
                    self.step(self.step()+1);
                } else {
                    if(self.step() <= 0 ) {
                        self.step(0);
                    } else {
                        self.step(self.step()-1);
                    }
                }
            }
        },this);
        this.setInprocess =  ko.computed({
            read: function () {
                return false;
            },
            write: function(action) {
                if (action == "+") {
                    self.inprocess(self.inprocess()+1);
                } else {
                    self.inprocess(self.inprocess()-1);
                }
            }
        },this);
        this.resetType = ko.observable(1);
        this.resetTypeList= [
            {label: Translate('#trans-select-cambiar'), value: 0},
            {label: Translate('#trans-select-regenerar'), value: 1}
        ]

        /** FILTRO DE TABLA POR JAVASCRIPT **/
        this.search = function(value) {
            value = typeof value === 'string' && value.trim() || '';
            var regex = new RegExp(value);
            ko.utils.arrayForEach(self.data(), function(obj) {
                obj.visible(false);
                if (obj.webApp().searchField().match(regex)) {
                    obj.visible(true);
                }
            });
        };
        this.query = ko.observable('');
        this.query.subscribe(this.search);
        /** /FILTRO DE TABLA POR JAVASCRIPT **/
        
        this.subscribe('emailListUpdated', function(emails) {
            'use strict';
            var self = this;
            var mapping = {
                create: function(options) {
                    return new Email(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(emails, mapping, self.emails);
        });        
    };

    wordpressVM.prototype.init = function() {
        var self = this;
        self.inprocess(0); //Si no vuelvo a inicializarlo, por algun motivo al cambiar de pantalla a esta altura esta en 1

        this.list();
        this.listAvailableApps();
        this.listDomains();
        mediator.publish('refreshEmailList');

        

        $.postJSON("/hosting/account/getinfo", function(data) {
           self.serviceId(data.result.idService);
           self.userName(data.result.UserName);
           self.pwd(data.result.Password);
           var isLinux = data.result.Server.OpSystem === 'Linux';
           
           if (isLinux && data.result && data.result.wpStaging == 1 && Object.values(data.result.FeaturesEnabled).indexOf('wpStaging') > 0) {
                setTimeout(function() {
                    self.loadingFlag(true);
                    self.listWpStage();
                });
            }

            if(isLinux) {
                $.postJSON("/hosting/account/mysqlversion", function(data) {
                    if (data.result) {
                        if(self.cmpVersion(data.result, 5.6) >= 0)
                            self.flagFolder(true);
                    }
                });
            }

        });
        
        // self.setInprocess("-");
    };

    wordpressVM.prototype.cmpVersion = function(a, b) {
        var i, cmp, len;
        a = (a + '').split('.');
        b = (b + '').split('.');
        len = Math.max(a.length, b.length);
        for( i = 0; i < len; i++ ) {
            if( a[i] === undefined ) {
                a[i] = '0';
            }
            if( b[i] === undefined ) {
                b[i] = '0';
            }
            cmp = parseInt(a[i], 10) - parseInt(b[i], 10);
            if( cmp !== 0 ) {
                return (cmp < 0 ? -1 : 1);
            }
        }
        return 0;
    }

    wordpressVM.prototype.listAvailableApps = function() {
        var self = this;
        var theData = { "params": {
        }};

        self.setInprocess("+");
        $.postJSON("/hosting/webapp/listwebapps", theData, function(data) {
            if (data.result) {
                self.availableApps([]);
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.availableApps.push(new AvailableApp(obj));
                });
            }
        }).always(function() {
            self.setInprocess("-");
        });
    };

    wordpressVM.prototype.changeStepFwd = function() {
        FerozoHosting.wordpressVM().setStep("+");
    };

    wordpressVM.prototype.changeStepBwd = function() {
        FerozoHosting.wordpressVM().setStep("-");
    };

    wordpressVM.prototype.resetStep = function() {
        FerozoHosting.wordpressVM().step(1);
    };

    wordpressVM.prototype.listWpStage = function() {
        var self = this;
        var theData = { "params": {
        }};
        self.setInprocess("+");
        $.postJSON("/hosting/webapp/liststaging", theData, function(data) {
            self.wpStages([]);
            if (data.result != '') {
                self.wpStages.push(new WpStage(data.result));
            }
        }).always(function() {
            self.setInprocess("-");
            self.loadingFlag(false);
        });
    };

    wordpressVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {
        }};
        self.setInprocess("+");
        $.postJSON("/hosting/webapp/listinstalled", theData, function(data) {
            self.hasWordpress(0);
            if (data.result) {
                self.data([]);
                self.wpInstallations([]);
                ko.utils.arrayForEach(data.result, function(obj) {
                    var prot = 'http://';
                    if (obj.sslType !== '') {
                        prot = 'https://';
                    }
                    obj.domainDisplay = prot + obj.domain.domain;
                    if (obj.webApp.name == 'Wordpress') {
                        self.hasWordpress(1);
                        if (obj.domainPlain.indexOf('ferozo.') < 0) 
                            self.wpInstallations.push(new WebApp(obj));
                        else
                            self.hasAlterDomain(1);
                            
                    }
                    self.data.push(new WebApp(obj));
                });
            }
        }).always(function() {
            self.setInprocess("-");
        });
    };

    wordpressVM.prototype.listDomains = function() {
        var self = this;
        var theData = { "params": {

        }};
        self.setInprocess("+");
        $.postJSON("/hosting/domain/listdomains", theData, function(data) {
            self.domains([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    obj.regStatus === 1 && self.domains.push(new Domain(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
           self.setInprocess("-");
        });
    };

    wordpressVM.prototype.goToInstall = function() {
        var self = this;
        $('.modal').modal('hide');
        $('#app-list').trigger('click');
    };

    wordpressVM.prototype.goToStage = function() {
        var self = this;
        $('#wp-stage').trigger('click');
    };

    wordpressVM.prototype.switchInstalledTab = function(entity, event) {
        $('#app-installed').trigger('click');
    };

    wordpressVM.prototype.switchInstalled = function(entity, event) {
        var self = this;
        self.switchInstalledTab();
        self.query(entity.nameKey());
    };

    wordpressVM.prototype.openModalInstall = function(entity, event) {
        this.temp(entity);
        $('#modal-info').modal('hide');
        $('#installapp').modal('show');
    };

    wordpressVM.prototype.openModalInstallWp = function() {
        this.temp(this.availableApps()[0]);
        $('#modal-info').modal('hide');
        $('#installapp').modal('show');
    };

    wordpressVM.prototype.openModalEditPass = function(entity, event) {
        this.tempEdit(entity);
        this.tempEdit().password('');
        $('#editpass').modal('show');
    };

    wordpressVM.prototype.openModalEdit = function(entity, event) {
        //var cloned = ko.mapping.fromJS(ko.toJS(entity));
        //cloned.domain = ko.observable(cloned.domain);
        this.tempEdit(entity);
        $('#editapp').modal('show');
    };

    wordpressVM.prototype.openModalInfo = function(entity, event) {
        this.temp(entity);
        $('#modal-info').modal('show');
    };

    wordpressVM.prototype.openModalNewStage = function() {
        $('#create-stage').modal('show');
    };
    
    wordpressVM.prototype.stagePublishConfirm = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        FerozoHosting.wordpressVM().tempStaging(cloned);
        $("#staging-confirm-publish").modal('show');
    };

    wordpressVM.prototype.renderChart = function(sizeorigin, availablespace) {
        var spaceOrigin = parseFloat(sizeorigin).toFixed(2);
            var spaceAvailable = parseFloat(availablespace).toFixed(2);
            var total = spaceOrigin + spaceAvailable;

            var chartSpaceUsed = [];

            if(spaceOrigin > 1024){
                var labelspaceOrigin = spaceOrigin / 1024;
                labelspaceOrigin = parseFloat(labelspaceOrigin).toFixed(2);
                var unitOrigin = "GB";
            }else{
                var labelspaceOrigin = spaceOrigin
                var unitOrigin = "MB";
            }

            if(spaceAvailable > 1024){
                var labelspaceAvailable = spaceAvailable / 1024;
                labelspaceAvailable = parseFloat(labelspaceAvailable).toFixed(2);
                var unitAvailable = "GB";
            }else{
                var labelspaceAvailable = spaceAvailable
                var unitAvailable = "MB";
            }                

            chartSpaceUsed.push(
                {   
                    "label" : "Espacio del Stage:" + ' ['+labelspaceOrigin + unitOrigin + ']',
                    "data" : spaceOrigin
                },
                {
                    "label" : "Espacio Disponible:" + ' ['+labelspaceAvailable + unitAvailable + ']',
                    "data" : spaceAvailable
                }
            );
            self.fixEmptySizes(chartSpaceUsed);
            self.renderChart("#chart-spaceinfo", chartSpaceUsed);
    };

    self.fixEmptySizes = function(chartDbSize) {
        for (var item in chartDbSize) {
            if(chartDbSize[item].data == 0)
                chartDbSize[item].data = 1;
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
        // $('.accntinfo-chart').css('min-height', '100px').css('height', 'auto').css('width', '450px');
    }; 

    self.absorbEnter = function(data, event) {
        return event.keyCode !== 13;  
    }

    wordpressVM.prototype.redirectMc = function(entity, event) {
        var self = this;
        window.open('https://micuenta.donweb.com/xx-xx/servicios/sitios/'+self.serviceId()+'/configurar/wordpress-stage', '_blank');
    };

    wordpressVM.prototype.redirectMcSpace = function(entity, event) {
        var self = this;
        window.open('https://micuenta.donweb.com/xx-xx/servicios/sitios/'+FerozoHosting.wordpressVM().serviceId()+'/configurar/cambio-servicio', '_blank');
    };

    wordpressVM.prototype.importWP = function(entity, event) {
        var self = this;
        window.location.href = '/#/tools/wordpressmigrator';
    };

    wordpressVM.prototype.redirectDomain = function(subdomain) {
        var self = this;
        console.log(subdomain);
        window.open(subdomain, '_blank');
    };

    wordpressVM.prototype.openModalWpRedirect = function(entity, event) {
        var self = this;
        self.tempWp(entity);
        var urlWp = '/hosting/webapp/autologin/'+self.tempWp().id(); 
        if (self.tempWp().domainPlain().indexOf('.ferozo.') > 0 && self.tempWp().webApp().nameKey() == 'wordpress') {
            $('#alertWpRedirectModal').modal('show'); 
        } else {
            window.open(urlWp, '_blank');
        }
    };

    return wordpressVM;
});
