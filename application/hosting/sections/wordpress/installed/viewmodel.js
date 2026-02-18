//define(['knockout', 'app', 'ko.mapping', 'domain'], function(ko, App, mapping, Domain) {
//    return function InstalledVM() {
//        'use strict';
//
//        mediator.installTo(this);
//
//        this.inprocess = ko.observable(1);
//        this.data = ko.observableArray([]);
//        this.domains = ko.observableArray([]);
//        this.temp = ko.observable(new App());
//
//        ko.mapping = mapping;
//
//        this.subscribe('domainListUpdated', function(domainList) {
//            'use strict';
//            var self = this;
//            var
//            _dl = [];
//            $.each(domainList, function(index, val) {
//                if (this.regStatus == 1) {
//                   _dl.push(this);
//                }
//            });
//
//            var mapping = {
//                create: function(options) {
//                    return new Domain(options.data);
//                }, key: function(item) {
//                    return ko.utils.unwrapObservable(item.id);
//                }
//            };
//            ko.mapping.fromJS(_dl, mapping, self.domains);
//        });
//
//        this.opennew = function(dom, idmodal, event) {
//            'use strict';
//            var self = this;
//            self.temp(new App());
//            $('#' + idmodal).modal('show');
//        };
//
//        this.edit = function(dom, event) {
//            FerozoHosting.installedVM().temp(dom);
//            $('#editar-app').modal('show');
//        };
//
//        this.savenew = function(callback, evento) {
//            'use strict';
//            var self = this;
//	        var _temp = self.temp();
//
//            var theData = { "params": {
//                "domain":_temp.domain().content(),
//                "id":_temp.id
//            }};
//
//            self.inprocess(1);
//            $.postJSON('/hosting/domain/parkdomain', theData, function(response) {
//                if (response.error && response.error.data.inputException) {
//                    $.each(response.error.data.inputException, function() {
//                        self.temp()[this.field]().error(this.errorDesc);
//                    });
//                } else {
//                    self.data.push(self.temp());
//                    self.init();
//                    $('.modal').modal('hide');
//                }
//            }).always(function() {
//                self.inprocess(0);
//            });
//        };
//
//        this.subscribe('installedAppsUpdated', function(appsInstalled) {
//            'use strict';
//            var self = this;
//            var mapping = { update: function(options) {
//                return new App(options.data);
//            }};
//
//            ko.mapping.fromJS(appsInstalled, mapping, self.data);
//            FerozoHosting.profileVM().user().updateWebapps(appsInstalled);
//            self.inprocess(0);
//        });
//
//        this.init = function() {
//            mediator.publish('refreshInstalledApps');
//            mediator.publish('refreshDomainList');
//        };
//    };
//});