define(['knockout', 'accessredirection', 'ko.mapping'], function(ko, AccessRedirection, mapping) {

    var accessredirectionsVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        this.data = ko.observableArray([]);
        this.temp = ko.observable(new AccessRedirection());
        this.inprocess = ko.observable(1);

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.redirection() == right.redirection() ? 0 : (left.redirection() < right.redirection() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.redirection() == right.redirection() ? 0 : (left.redirection() > right.redirection() ? -1 : 1);
                });
            }
        };

        this.subscribe('refreshAccessRedirections', function() {
            self.list();
        });
    };

    accessredirectionsVM.prototype.modalCreate = function() {
        this.temp(new AccessRedirection());
        $('#modal-create').modal('show');
    };

    accessredirectionsVM.prototype.list = function() {
        var self = this;
        self.inprocess(1);
        self.data([]);
        $.postJSON("/hosting/tools/access/redirection/list", function(data) {
            if (data.result) {
                $.each(data.result, function() {
                    self.data.push(new AccessRedirection(this));
                });
            }
        }).always(function(data) {
            self.inprocess(0);
        });;
    };

    accessredirectionsVM.prototype.init = function() {
        'use strict';
        mediator.publish('refreshAccessRedirections');
    };

    accessredirectionsVM.prototype.getHideDefaultDomain = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().HideDefaultDomain();
        } else {
            return false;
        }
    };

    accessredirectionsVM.prototype.getHostingPpalDomain = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().PpalDomain.Name();
        } else {
            return false;
        }
    };

    accessredirectionsVM.prototype.getHostingFirstDomain = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Domains()[0];
        } else {
            return false;
        }
    };    
    
    accessredirectionsVM.prototype.getHostingDomains = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Domains();
        } else {
            return false;
        }
    };
    
    return accessredirectionsVM;
});