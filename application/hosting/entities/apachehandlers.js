define(['knockout', 'input', 'hosting/entities/record', 'ko.mapping'], function(ko, Input, Record, mapping) {
    /* ------------ APACHEHANDLERS   -----------------*/
    var apachehandlers = function(data) {
        'use strict';

        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.entitiname = 'apachehandlers';
        this.regStatus = ko.observable(2);
        this.command = {};
        this.id = '';
        this.handler = ko.observable(new Input({
            "content": ''
        }));
        this.extension = ko.observable(new Input({
            "content": ''
        }));
        this._default = ko.observable("3");
        //this.ui_stat = ko.observable('');
        this.clearApacheHandlers = ko.computed(function() {
            if (typeof self.handler() != "function") {
                return self.handler().content();
            }
        });
        var mappingRules = {
            'handler': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                },
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            },
            'extension': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                },
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            },
            '_default': {
                create: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                },
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    apachehandlers.prototype = new Record({});

    apachehandlers.prototype.constructor = apachehandlers;

    apachehandlers.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id
        }};

        self.regStatus(4);
        FerozoHosting.apachehandlersVM() && FerozoHosting.apachehandlersVM().inprocess(1);
        $.postJSON('/hosting/tools/access/handlers/remove', theData, function(e) {
            mediator.publish('apachehandlersDeleted');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.apachehandlersVM() && FerozoHosting.apachehandlersVM().inprocess(0);
        });
    };

    apachehandlers.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "handler": self.handler().content(),
            "extension": self.extension().content(),
        }};

        FerozoHosting.apachehandlersVM() && FerozoHosting.apachehandlersVM().inprocess(1);
        $.postJSON('/hosting/tools/access/handlers/configure ', theData, function(data) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i] == "function") {self[i]().clearErrors()}});
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshApachehandlersList');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.apachehandlersVM() && FerozoHosting.apachehandlersVM().inprocess(0);
        });
    };

    return apachehandlers;
});